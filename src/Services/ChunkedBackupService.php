<?php

namespace Dgtlss\Capsule\Services;

use Dgtlss\Capsule\Models\BackupLog;
use Dgtlss\Capsule\Notifications\NotificationManager;
use Dgtlss\Capsule\Storage\StorageManager;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Log;
use Exception;
use ZipArchive;

class ChunkedBackupService
{
    protected Application $app;
    protected StorageManager $storageManager;
    protected NotificationManager $notificationManager;
    protected ConcurrentUploadManager $uploadManager;
    protected array $chunks = [];
    protected array $chunkData = [];
    protected string $backupId;
    protected bool $verbose = false;
    protected bool $parallel = false;
    protected int $compressionLevel = 1;
    protected bool $encryption = false;
    protected bool $verification = false;
    protected ?string $lastError = null;
    protected $outputCallback = null;
    /** @var array<int, array<string, mixed>> */
    protected array $manifestEntries = [];

    public function __construct(Application $app)
    {
        $this->app = $app;
        $this->storageManager = new StorageManager();
        $this->notificationManager = new NotificationManager();
        $this->backupId = uniqid('backup_', true);
        
        $maxConcurrent = config('capsule.chunked_backup.max_concurrent_uploads', 3);
        $this->uploadManager = new ConcurrentUploadManager($this->storageManager, $maxConcurrent);
        $this->compressionLevel = (int) config('capsule.backup.compression_level', 1);
    }

    public function setVerbose(bool $verbose): void
    {
        $this->verbose = $verbose;
    }

    public function setParallel(bool $parallel): void
    {
        $this->parallel = $parallel;
    }

    public function setCompressionLevel(int $level): void
    {
        $this->compressionLevel = max(1, min(9, $level));
    }

    public function setEncryption(bool $encryption): void
    {
        $this->encryption = $encryption;
    }

    public function setVerification(bool $verification): void
    {
        $this->verification = $verification;
    }

    public function setOutputCallback(callable $callback): void
    {
        $this->outputCallback = $callback;
    }

    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    protected function log(string $message): void
    {
        if ($this->verbose && $this->outputCallback) {
            call_user_func($this->outputCallback, $message);
        }
    }

    public function run(): bool
    {
        $this->log('Chunked backup process started.');
        $backupLog = BackupLog::create([
            'started_at' => now(),
            'status' => 'running',
            'metadata' => ['backup_id' => $this->backupId, 'chunked' => true],
        ]);

        try {
            $this->createChunkedBackup();
            
            $this->log('Uploading chunks concurrently...');
            $uploadResults = $this->uploadChunksConcurrently();
            $uploadStats = $this->uploadManager->getUploadStats();
            
            $this->log('Collating chunks into final backup...');
            $finalBackupPath = $this->collateChunks();
            
            $this->log('Updating backup log...');
            $backupLog->update([
                'completed_at' => now(),
                'status' => 'success',
                'file_path' => $finalBackupPath,
                'file_size' => $this->getRemoteFileSize($finalBackupPath),
                'metadata' => array_merge($backupLog->metadata ?? [], [
                    'chunks_count' => count($this->chunks),
                    'chunked_method' => 'concurrent_streaming',
                    'concurrent_uploads' => $uploadStats,
                    'max_concurrent' => config('capsule.chunked_backup.max_concurrent_uploads', 3),
                ]),
            ]);

            $this->log('Cleaning up remote chunks...');
            $this->cleanup();
            $this->notificationManager->sendSuccessNotification($backupLog);

            return true;
        } catch (Exception $e) {
            $this->lastError = $e->getMessage();
            $backupLog->update([
                'completed_at' => now(),
                'status' => 'failed',
                'error_message' => $e->getMessage(),
                'metadata' => array_merge($backupLog->metadata ?? [], [
                    'chunks_attempted' => count($this->chunks),
                ]),
            ]);

            Log::error('Chunked backup failed: ' . $e->getMessage(), [
                'exception' => $e,
                'backup_id' => $this->backupId
            ]);
            
            $this->cleanupChunks();
            $this->notificationManager->sendFailureNotification($backupLog, $e);

            return false;
        }
    }

    protected function createChunkedBackup(): void
    {
        $timestamp = now()->format('Y-m-d_H-i-s');
        
        if (config('capsule.database.enabled')) {
            $this->log('Creating chunked database backup...');
            $this->createChunkedDatabaseBackup($timestamp);
        }

        if (config('capsule.files.enabled')) {
            $this->log('Creating chunked file backup...');
            $this->createChunkedFileBackup($timestamp);
        }
        // Manifest will be embedded during collation
    }

    protected function createChunkedDatabaseBackup(string $timestamp): void
    {
        $connections = config('capsule.database.connections');
        
        // Auto-detect current database if not specified
        if ($connections === null) {
            $connections = [config('database.default')];
        } else {
            $connections = is_string($connections) ? [$connections] : $connections;
        }

        foreach ($connections as $connection) {
            $this->log("Streaming database '{$connection}' to chunks...");
            $this->streamDatabaseToChunks($connection, $timestamp);
        }
    }

    protected function streamDatabaseToChunks(string $connection, string $timestamp): void
    {
        $config = config("database.connections.{$connection}");
        $driver = $config['driver'];
        
        $chunkSize = config('capsule.chunked_backup.chunk_size', 10485760); // 10MB
        $tempPrefix = config('capsule.chunked_backup.temp_prefix', 'capsule_chunk_');
        
        switch ($driver) {
            case 'mysql':
                $this->streamMysqlToChunks($config, $connection, $timestamp, $chunkSize, $tempPrefix);
                break;
            case 'pgsql':
                $this->streamPostgresToChunks($config, $connection, $timestamp, $chunkSize, $tempPrefix);
                break;
            case 'sqlite':
                $this->streamSqliteToChunks($config, $connection, $timestamp, $chunkSize, $tempPrefix);
                break;
            default:
                throw new Exception("Unsupported database driver for chunked backup: {$driver}");
        }
    }

    protected function streamMysqlToChunks(array $config, string $connection, string $timestamp, int $chunkSize, string $tempPrefix): void
    {
        // Create a temporary config file for secure password handling
        $configFile = tempnam(sys_get_temp_dir(), 'mysql_config_');
        $escape = function ($value) {
            $value = (string) $value;
            $value = str_replace(["\\", "\n", "\r", '"'], ["\\\\", "\\n", "\\r", '\\"'], $value);
            return '"' . $value . '"';
        };
        $configContent = sprintf(
            "[mysqldump]\nuser=%s\npassword=%s\n",
            $escape($config['username'] ?? ''),
            $escape($config['password'] ?? '')
        );
        if (!empty($config['unix_socket'])) {
            $configContent .= 'socket=' . $escape($config['unix_socket']) . "\n";
        } else {
            $configContent .= sprintf(
                "host=%s\nport=%s\n",
                $escape($config['host'] ?? 'localhost'),
                $escape($config['port'] ?? 3306)
            );
        }
        file_put_contents($configFile, $configContent);
        chmod($configFile, 0600);

        $includeTables = (array) (config('capsule.database.include_tables', []) ?? []);
        $excludeTables = (array) (config('capsule.database.exclude_tables', []) ?? []);

        $safeFlags = '--single-transaction --routines --triggers --hex-blob';
        $dbName = $config['database'];
        $ignoreFlags = '';
        if (empty($includeTables) && !empty($excludeTables)) {
            foreach ($excludeTables as $table) {
                $ignoreFlags .= ' --ignore-table=' . escapeshellarg($dbName . '.' . $table);
            }
        }

        if (!empty($includeTables)) {
            $tables = implode(' ', array_map('escapeshellarg', $includeTables));
            $command = sprintf(
                'mysqldump --defaults-extra-file=%s %s %s %s',
                escapeshellarg($configFile),
                $safeFlags,
                escapeshellarg($dbName),
                $tables
            );
        } else {
            $command = sprintf(
                'mysqldump --defaults-extra-file=%s %s %s %s',
                escapeshellarg($configFile),
                $safeFlags,
                $ignoreFlags,
                escapeshellarg($dbName)
            );
        }

        $this->streamCommandToChunks($command, "db_{$connection}_{$timestamp}", $chunkSize, $tempPrefix);

        // Clean up the temporary config file
        @unlink($configFile);
    }

    protected function streamPostgresToChunks(array $config, string $connection, string $timestamp, int $chunkSize, string $tempPrefix): void
    {
        $includeTables = (array) (config('capsule.database.include_tables', []) ?? []);
        $excludeTables = (array) (config('capsule.database.exclude_tables', []) ?? []);

        $safeFlags = '--no-owner --no-privileges --format=plain --no-password';
        $tableFlags = '';
        if (!empty($includeTables)) {
            foreach ($includeTables as $table) {
                $tableFlags .= ' -t ' . escapeshellarg($table);
            }
        } elseif (!empty($excludeTables)) {
            foreach ($excludeTables as $table) {
                $tableFlags .= ' --exclude-table=' . escapeshellarg($table);
            }
        }

        $command = sprintf(
            'PGPASSWORD=%s pg_dump --host=%s --port=%s --username=%s --dbname=%s %s %s',
            escapeshellarg($config['password']),
            escapeshellarg($config['host']),
            escapeshellarg($config['port'] ?? 5432),
            escapeshellarg($config['username']),
            escapeshellarg($config['database']),
            $safeFlags,
            $tableFlags
        );

        $this->streamCommandToChunks($command, "db_{$connection}_{$timestamp}", $chunkSize, $tempPrefix);
    }

    protected function streamSqliteToChunks(array $config, string $connection, string $timestamp, int $chunkSize, string $tempPrefix): void
    {
        if (!file_exists($config['database'])) {
            throw new Exception("SQLite database file not found: {$config['database']}");
        }

        $command = sprintf('cat %s', escapeshellarg($config['database']));
        $this->streamCommandToChunks($command, "db_{$connection}_{$timestamp}", $chunkSize, $tempPrefix);
    }

    protected function streamCommandToChunks(string $command, string $baseName, int $chunkSize, string $tempPrefix): void
    {
        $process = popen($command, 'r');
        if (!$process) {
            throw new Exception("Failed to execute command: {$command}");
        }

        $chunkIndex = 0;
        $currentChunkSize = 0;
        $currentChunkData = '';

        while (!feof($process)) {
            $data = fread($process, 8192); // Read in 8KB blocks
            if ($data === false) break;

            $currentChunkData .= $data;
            $currentChunkSize += strlen($data);

            if ($currentChunkSize >= $chunkSize) {
                $this->uploadChunk($currentChunkData, $baseName, $chunkIndex, $tempPrefix);
                $currentChunkData = '';
                $currentChunkSize = 0;
                $chunkIndex++;
            }
        }

        // Upload the last chunk if there's remaining data
        if ($currentChunkSize > 0) {
            $this->uploadChunk($currentChunkData, $baseName, $chunkIndex, $tempPrefix);
            $chunkIndex++;
        }

        pclose($process);
    }

    protected function createChunkedFileBackup(string $timestamp): void
    {
        $paths = config('capsule.files.paths', []);
        $excludePaths = config('capsule.files.exclude_paths', []);
        $chunkSize = config('capsule.chunked_backup.chunk_size', 10485760);
        $tempPrefix = config('capsule.chunked_backup.temp_prefix', 'capsule_chunk_');

        foreach ($paths as $path) {
            if (is_dir($path)) {
                $this->log("Streaming directory '{$path}' to chunks...");
                $this->streamDirectoryToChunks($path, $excludePaths, $timestamp, $chunkSize, $tempPrefix);
            } elseif (is_file($path)) {
                $this->log("Streaming file '{$path}' to chunks...");
                $this->streamFileToChunks($path, $timestamp, $chunkSize, $tempPrefix);
            }
        }
    }

    protected function streamDirectoryToChunks(string $dir, array $excludePaths, string $timestamp, int $chunkSize, string $tempPrefix): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        $chunkIndex = 0;
        $currentChunkSize = 0;
        $currentChunkData = '';
        $baseName = "files_" . basename($dir) . "_{$timestamp}";

        foreach ($iterator as $file) {
            $filePath = $file->getRealPath();
            
            if ($this->shouldExcludePath($filePath, $excludePaths)) {
                continue;
            }

            if ($file->isFile()) {
                $fileData = $this->prepareFileForChunk($filePath, $dir);
                $fileSize = strlen($fileData);

                if ($currentChunkSize + $fileSize > $chunkSize && $currentChunkSize > 0) {
                    // Upload current chunk and start a new one
                    $this->uploadChunk($currentChunkData, $baseName, $chunkIndex, $tempPrefix);
                    $currentChunkData = '';
                    $currentChunkSize = 0;
                    $chunkIndex++;
                }

                $currentChunkData .= $fileData;
                $currentChunkSize += $fileSize;
            }
        }

        // Upload the last chunk if there's remaining data
        if ($currentChunkSize > 0) {
            $this->uploadChunk($currentChunkData, $baseName, $chunkIndex, $tempPrefix);
        }
    }

    protected function streamFileToChunks(string $filePath, string $timestamp, int $chunkSize, string $tempPrefix): void
    {
        $baseName = "file_" . basename($filePath) . "_{$timestamp}";
        $handle = fopen($filePath, 'r');
        
        if (!$handle) {
            throw new Exception("Cannot open file for chunked backup: {$filePath}");
        }

        $chunkIndex = 0;
        while (!feof($handle)) {
            $chunkData = fread($handle, $chunkSize);
            if ($chunkData !== false && strlen($chunkData) > 0) {
                $this->uploadChunk($chunkData, $baseName, $chunkIndex, $tempPrefix);
                $chunkIndex++;
            }
        }

        fclose($handle);
    }

    protected function appendManifestEntry(string $zipPath, string $sourceFilePath): void
    {
        $size = @filesize($sourceFilePath) ?: 0;
        $hash = @hash_file('sha256', $sourceFilePath) ?: '';
        $this->manifestEntries[] = [
            'path' => $zipPath,
            'size' => $size,
            'sha256' => $hash,
        ];
    }

    protected function buildManifest(bool $isChunked): array
    {
        $now = now()->toISOString();
        $appName = config('app.name');
        $appEnv = app()->environment();
        $laravelVersion = app()->version();
        $disk = config('capsule.default_disk');
        $backupPath = config('capsule.backup_path');
        $dbConns = config('capsule.database.connections');
        $dbConns = $dbConns === null ? [config('database.default')] : (is_string($dbConns) ? [$dbConns] : $dbConns);

        return [
            'schema_version' => 1,
            'generated_at' => $now,
            'app' => [
                'name' => $appName,
                'env' => $appEnv,
                'laravel_version' => $laravelVersion,
                'host' => gethostname() ?: null,
            ],
            'capsule' => [
                'version' => null,
                'chunked' => $isChunked,
                'compression_level' => $this->compressionLevel,
                'encryption_enabled' => $this->encryption || (bool) config('capsule.security.encrypt_backups', false),
            ],
            'storage' => [
                'disk' => $disk,
                'backup_path' => $backupPath,
            ],
            'database' => [
                'connections' => $dbConns,
                'include_tables' => (array) (config('capsule.database.include_tables', []) ?? []),
                'exclude_tables' => (array) (config('capsule.database.exclude_tables', []) ?? []),
            ],
            'files' => [
                'paths' => (array) config('capsule.files.paths', []),
                'exclude_paths' => (array) config('capsule.files.exclude_paths', []),
            ],
            'entries' => $this->manifestEntries,
        ];
    }

    protected function prepareFileForChunk(string $filePath, string $baseDir): string
    {
        $relativePath = substr($filePath, strlen($baseDir) + 1);
        $fileContent = file_get_contents($filePath);
        $this->appendManifestEntry('files/' . $relativePath, $filePath);
        
        // Create a simple format: [PATH_LENGTH][PATH][CONTENT_LENGTH][CONTENT]
        $pathLength = strlen($relativePath);
        $contentLength = strlen($fileContent);
        
        return pack('N', $pathLength) . $relativePath . pack('N', $contentLength) . $fileContent;
    }

    protected function uploadChunk(string $data, string $baseName, int $chunkIndex, string $tempPrefix): void
    {
        $chunkName = "{$tempPrefix}{$baseName}.part{$chunkIndex}";
        
        $this->log("Preparing chunk {$chunkIndex} for {$baseName}...");
        
        // Store chunk data for concurrent upload
        $this->chunkData[] = [
            'name' => $chunkName,
            'data' => $data,
            'index' => $chunkIndex,
            'base_name' => $baseName,
            'size' => strlen($data),
        ];
        
        // Keep chunk metadata for collation
        $this->chunks[] = [
            'name' => $chunkName,
            'index' => $chunkIndex,
            'base_name' => $baseName,
            'size' => strlen($data),
        ];
    }

    protected function uploadChunksConcurrently(): array
    {
        if (empty($this->chunkData)) {
            return [];
        }

        $this->log("Starting concurrent upload of " . count($this->chunkData) . " chunks.");
        Log::info("Starting concurrent upload of " . count($this->chunkData) . " chunks", [
            'max_concurrent' => config('capsule.chunked_backup.max_concurrent_uploads', 3),
            'backup_id' => $this->backupId,
        ]);

        $results = $this->uploadManager->uploadChunks($this->chunkData);
        
        $stats = $this->uploadManager->getUploadStats();
        Log::info("Concurrent upload completed", [
            'stats' => $stats,
            'backup_id' => $this->backupId,
        ]);
        $this->log("Concurrent upload complete. Total: {$stats['total']}, Successful: {$stats['successful']}, Failed: {$stats['failed']}");

        // Check if any uploads failed
        $failedUploads = $this->uploadManager->getFailedUploads();
        if (!empty($failedUploads)) {
            $failedCount = count($failedUploads);
            $totalCount = count($this->chunkData);
            
            Log::warning("Some chunks failed to upload", [
                'failed_count' => $failedCount,
                'total_count' => $totalCount,
                'backup_id' => $this->backupId,
            ]);

            // If more than 50% failed, throw an exception
            if ($failedCount > ($totalCount / 2)) {
                throw new Exception("Too many chunk uploads failed ({$failedCount}/{$totalCount})");
            }
        }

        return $results;
    }

    protected function collateChunks(): string
    {
        $timestamp = now()->format('Y-m-d_H-i-s');
        $finalBackupName = "backup_{$timestamp}.zip";

        // Build manifest pseudo-chunk to include checksums/metadata in final ZIP
        $manifest = $this->buildManifest(true);
        $manifestJson = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $this->chunkData[] = [
            'name' => 'manifest.json.inline',
            'data' => $manifestJson,
            'index' => 0,
            'base_name' => 'manifest.json',
            'size' => strlen($manifestJson),
        ];
        $this->chunks[] = [
            'name' => 'manifest.json.inline',
            'index' => 0,
            'base_name' => 'manifest.json',
            'size' => strlen($manifestJson),
        ];

        // Upload all pending chunks (includes manifest)
        $this->uploadChunksConcurrently();

        $this->log("Collating chunks into '{$finalBackupName}'...");
        return $this->storageManager->collateChunksAdvanced(
            $this->chunks,
            $finalBackupName,
            $this->compressionLevel,
            $this->encryption || (bool) config('capsule.security.encrypt_backups', false),
            (string) (config('capsule.security.backup_password') ?? env('CAPSULE_BACKUP_PASSWORD'))
        );
    }

    protected function getRemoteFileSize(string $fileName): int
    {
        return $this->storageManager->getFileSize($fileName);
    }

    protected function shouldExcludePath(string $path, array $excludePaths): bool
    {
        foreach ($excludePaths as $excludePath) {
            if (strpos($path, $excludePath) === 0) {
                return true;
            }
        }
        return false;
    }

    protected function cleanupChunks(): void
    {
        $this->log('Cleaning up remote chunks...');
        foreach ($this->chunks as $chunk) {
            try {
                $this->storageManager->delete($chunk['name']);
            } catch (Exception $e) {
                Log::warning("Failed to cleanup chunk: {$chunk['name']}", ['exception' => $e]);
            }
        }
    }

    protected function cleanup(): void
    {
        // Clean up chunks after successful collation
        $this->cleanupChunks();
        
        // Run regular cleanup if enabled
        if (config('capsule.retention.cleanup_enabled', true)) {
            $this->log('Running retention cleanup...');
            $retentionDays = config('capsule.retention.days', 30);
            $retentionCount = config('capsule.retention.count', 10);

            // Get IDs of backups to keep (latest N successful backups)
            $keepIds = BackupLog::where('status', 'success')
                ->orderBy('created_at', 'desc')
                ->limit($retentionCount)
                ->pluck('id')
                ->toArray();

            // Find backups to delete
            $query = BackupLog::where('status', 'success')
                ->where('created_at', '<', now()->subDays($retentionDays));

            if (!empty($keepIds)) {
                $query->whereNotIn('id', $keepIds);
            }

            // Use eachById to avoid memory issues with large result sets
            $query->eachById(function (BackupLog $backup) {
                if ($backup->file_path) {
                    $fileName = basename($backup->file_path);
                    $this->storageManager->delete($fileName);
                }
                $backup->delete();
            });
        }
    }
}
