<?php

namespace Dgtlss\Capsule\Services;

use Dgtlss\Capsule\Models\BackupLog;
use Dgtlss\Capsule\Notifications\NotificationManager;
use Dgtlss\Capsule\Storage\StorageManager;
use Dgtlss\Capsule\Support\MemoryMonitor;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Log;
use Exception;
use ZipArchive;

class ChunkedBackupService
{
    protected Application $app;
    protected StorageManager $storageManager;
    protected NotificationManager $notificationManager;
    protected array $chunks = [];
    protected array $chunkMetadata = []; // Store minimal metadata for collation
    protected array $manifestEntries = []; // Store manifest entries
    protected string $backupId;
    protected bool $verbose = false;
    protected bool $parallel = false;
    protected int $compressionLevel = 1;
    protected bool $encryption = false;
    protected bool $verification = false;
    protected ?string $lastError = null;
    protected $outputCallback = null;

    public function __construct(Application $app)
    {
        $this->app = $app;
        $this->storageManager = new StorageManager();
        $this->notificationManager = new NotificationManager();
        $this->backupId = uniqid('backup_', true);
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
        MemoryMonitor::checkpoint('backup_start');
        MemoryMonitor::logMemoryUsage('Backup started');
        
        $this->log('Chunked backup process started.');
        $backupLog = BackupLog::create([
            'started_at' => now(),
            'status' => 'running',
            'metadata' => ['backup_id' => $this->backupId, 'chunked' => true],
        ]);

        try {
            MemoryMonitor::checkpoint('before_backup');
            $this->createChunkedBackup();
            MemoryMonitor::checkpoint('after_backup');
            MemoryMonitor::logMemoryUsage('After backup creation');
            
            $this->log('Collating chunks into final backup...');
            MemoryMonitor::checkpoint('before_collation');
            $finalBackupPath = $this->collateChunks();
            MemoryMonitor::checkpoint('after_collation');
            MemoryMonitor::logMemoryUsage('After collation');
            
            $this->log('Updating backup log...');
            
            // Get memory statistics
            $memoryStats = MemoryMonitor::compareCheckpoints('backup_start', 'after_collation');
            
            $backupLog->update([
                'completed_at' => now(),
                'status' => 'success',
                'file_path' => $finalBackupPath,
                'file_size' => $this->getRemoteFileSize($finalBackupPath),
                'metadata' => array_merge($backupLog->metadata ?? [], [
                    'chunks_count' => count($this->chunkMetadata),
                    'chunked_method' => 'direct_streaming',
                    'memory_stats' => $memoryStats['formatted'] ?? null,
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
                    'chunks_attempted' => count($this->chunkMetadata),
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
            case 'mariadb':
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
        
        // Determine which dump command to use
        $dumpCommand = $this->getMysqlDumpCommand();
        
        $configContent = sprintf(
            "[%s]\nuser=%s\npassword=%s\n",
            $dumpCommand,
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
                '%s --defaults-extra-file=%s %s %s %s',
                $dumpCommand,
                escapeshellarg($configFile),
                $safeFlags,
                escapeshellarg($dbName),
                $tables
            );
        } else {
            $command = sprintf(
                '%s --defaults-extra-file=%s %s %s %s',
                $dumpCommand,
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
        $chunkBuffer = '';

        while (!feof($process)) {
            $data = fread($process, 8192); // Read in 8KB blocks
            if ($data === false) break;

            $chunkBuffer .= $data;
            $currentChunkSize += strlen($data);

            if ($currentChunkSize >= $chunkSize) {
                $this->uploadChunkDirectly($chunkBuffer, $baseName, $chunkIndex, $tempPrefix);
                $chunkBuffer = '';
                $currentChunkSize = 0;
                $chunkIndex++;
                
                // Force garbage collection every 10 chunks
                if ($chunkIndex % 10 === 0) {
                    gc_collect_cycles();
                }
            }
        }

        // Upload the last chunk if there's remaining data
        if ($currentChunkSize > 0) {
            $this->uploadChunkDirectly($chunkBuffer, $baseName, $chunkIndex, $tempPrefix);
            $chunkIndex++;
        }

        pclose($process);
        unset($chunkBuffer); // Free buffer memory
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
        $chunkBuffer = '';
        $baseName = "files_" . basename($dir) . "_{$timestamp}";
        $fileCount = 0;

        foreach ($iterator as $file) {
            $filePath = $file->getRealPath();
            
            if ($this->shouldExcludePath($filePath, $excludePaths)) {
                continue;
            }

            if ($file->isFile()) {
                $fileSize = $file->getSize();
                
                // If single file is larger than chunk size, handle it separately
                if ($fileSize > $chunkSize) {
                    // Upload current chunk if it has data
                    if ($currentChunkSize > 0) {
                        $this->uploadChunkDirectly($chunkBuffer, $baseName, $chunkIndex, $tempPrefix);
                        $chunkBuffer = '';
                        $currentChunkSize = 0;
                        $chunkIndex++;
                    }
                    
                    // Stream large file directly
                    $this->streamLargeFileToChunks($filePath, $dir, $baseName, $chunkSize, $tempPrefix, $chunkIndex);
                    $chunkIndex += ceil($fileSize / $chunkSize);
                    continue;
                }

                // Add file to current chunk buffer
                $fileData = $this->prepareFileForChunk($filePath, $dir);
                $fileDataSize = strlen($fileData);

                if ($currentChunkSize + $fileDataSize > $chunkSize && $currentChunkSize > 0) {
                    // Upload current chunk and start a new one
                    $this->uploadChunkDirectly($chunkBuffer, $baseName, $chunkIndex, $tempPrefix);
                    $chunkBuffer = '';
                    $currentChunkSize = 0;
                    $chunkIndex++;
                }

                $chunkBuffer .= $fileData;
                $currentChunkSize += $fileDataSize;
                
                // Free file data immediately
                unset($fileData);
                $fileCount++;
                
                // Force garbage collection every 50 files
                if ($fileCount % 50 === 0) {
                    gc_collect_cycles();
                }
            }
        }

        // Upload the last chunk if there's remaining data
        if ($currentChunkSize > 0) {
            $this->uploadChunkDirectly($chunkBuffer, $baseName, $chunkIndex, $tempPrefix);
        }
        
        unset($chunkBuffer); // Free buffer memory
    }

    protected function streamFileToChunks(string $filePath, string $timestamp, int $chunkSize, string $tempPrefix): void
    {
        $baseName = "file_" . basename($filePath) . "_{$timestamp}";
        $handle = fopen($filePath, 'r');
        
        if (!$handle) {
            throw new Exception("Cannot open file for chunked backup: {$filePath}");
        }

        $chunkIndex = 0;
        $chunkBuffer = '';
        
        while (!feof($handle)) {
            $data = fread($handle, min(8192, $chunkSize - strlen($chunkBuffer)));
            if ($data === false) break;
            
            $chunkBuffer .= $data;
            
            if (strlen($chunkBuffer) >= $chunkSize) {
                $this->uploadChunkDirectly($chunkBuffer, $baseName, $chunkIndex, $tempPrefix);
                $chunkBuffer = '';
                $chunkIndex++;
            }
        }
        
        // Upload the last chunk if there's remaining data
        if (strlen($chunkBuffer) > 0) {
            $this->uploadChunkDirectly($chunkBuffer, $baseName, $chunkIndex, $tempPrefix);
        }

        fclose($handle);
    }

    protected function appendManifestEntry(string $zipPath, string $sourceFilePath): void
    {
        // For memory efficiency, we'll calculate hash and size during manifest building
        // instead of storing all entries in memory
        $this->manifestEntries[] = [
            'path' => $zipPath,
            'source_file' => $sourceFilePath,
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

        // Process manifest entries to calculate size and hash
        $processedEntries = [];
        foreach ($this->manifestEntries as $entry) {
            $size = @filesize($entry['source_file']) ?: 0;
            $hash = @hash_file('sha256', $entry['source_file']) ?: '';
            $processedEntries[] = [
                'path' => $entry['path'],
                'size' => $size,
                'sha256' => $hash,
            ];
        }

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
            'entries' => $processedEntries,
        ];
    }

    protected function prepareFileForChunk(string $filePath, string $baseDir): string
    {
        $relativePath = substr($filePath, strlen($baseDir) + 1);
        
        // Add to manifest without loading file content yet
        $this->appendManifestEntry('files/' . $relativePath, $filePath);
        
        // Stream file content instead of loading all at once
        $fileHandle = fopen($filePath, 'r');
        if (!$fileHandle) {
            throw new Exception("Cannot open file: {$filePath}");
        }
        
        $pathLength = strlen($relativePath);
        $contentLength = filesize($filePath);
        
        // Create header
        $result = pack('N', $pathLength) . $relativePath . pack('N', $contentLength);
        
        // Stream file content
        while (!feof($fileHandle)) {
            $data = fread($fileHandle, 8192);
            if ($data !== false) {
                $result .= $data;
            }
        }
        
        fclose($fileHandle);
        return $result;
    }


    protected function streamLargeFileToChunks(string $filePath, string $baseDir, string $baseName, int $chunkSize, string $tempPrefix, int &$chunkIndex): void
    {
        $relativePath = substr($filePath, strlen($baseDir) + 1);
        $this->appendManifestEntry('files/' . $relativePath, $filePath);
        
        $fileHandle = fopen($filePath, 'r');
        if (!$fileHandle) {
            throw new Exception("Cannot open large file for streaming: {$filePath}");
        }

        $pathLength = strlen($relativePath);
        $pathData = pack('N', $pathLength) . $relativePath;
        $pathDataSize = strlen($pathData);
        
        $chunkBuffer = '';
        
        while (!feof($fileHandle)) {
            // Start new chunk with path data
            if (empty($chunkBuffer)) {
                $chunkBuffer = $pathData;
            }
            
            // Read file content until chunk size reached
            while (strlen($chunkBuffer) < $chunkSize && !feof($fileHandle)) {
                $data = fread($fileHandle, min(8192, $chunkSize - strlen($chunkBuffer)));
                if ($data === false) break;
                $chunkBuffer .= $data;
            }
            
            if (strlen($chunkBuffer) > $pathDataSize) {
                // Calculate content length and insert it after path data
                $contentLength = strlen($chunkBuffer) - $pathDataSize;
                $chunkBuffer = $pathData . pack('N', $contentLength) . substr($chunkBuffer, $pathDataSize);
                
                $this->uploadChunkDirectly($chunkBuffer, $baseName, $chunkIndex, $tempPrefix);
                $chunkIndex++;
                $chunkBuffer = '';
            }
        }

        fclose($fileHandle);
    }


    protected function uploadChunkDirectly(string $data, string $baseName, int $chunkIndex, string $tempPrefix): void
    {
        $chunkName = "{$tempPrefix}{$baseName}.part{$chunkIndex}";
        $dataSize = strlen($data);
        
        $this->log("Uploading chunk {$chunkIndex} for {$baseName} directly...");
        
        // Upload chunk directly to storage using stream
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $data);
        rewind($stream);
        
        try {
            $this->storageManager->storeStream($stream, $chunkName);
            
            // Store minimal metadata for collation
            $this->chunkMetadata[] = [
                'name' => $chunkName,
                'index' => $chunkIndex,
                'base_name' => $baseName,
                'size' => $dataSize,
            ];
            
            $this->log("Chunk {$chunkIndex} uploaded successfully");
            
            // Free memory immediately after upload
            unset($data);
        } catch (Exception $e) {
            fclose($stream);
            throw new Exception("Failed to upload chunk {$chunkIndex}: " . $e->getMessage());
        }
        
        fclose($stream);
    }


    protected function collateChunks(): string
    {
        $timestamp = now()->format('Y-m-d_H-i-s');
        $finalBackupName = "backup_{$timestamp}.zip";

        // Build manifest and upload it as a chunk
        $manifest = $this->buildManifest(true);
        $manifestJson = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        
        // Upload manifest chunk directly
        $manifestChunkName = "capsule_chunk_manifest.json.part0";
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $manifestJson);
        rewind($stream);
        $this->storageManager->storeStream($stream, $manifestChunkName);
        fclose($stream);
        
        // Add manifest to metadata
        $this->chunkMetadata[] = [
            'name' => $manifestChunkName,
            'index' => 0,
            'base_name' => 'manifest.json',
            'size' => strlen($manifestJson),
        ];
        
        // Free manifest data immediately
        unset($manifest, $manifestJson);

        $this->log("Collating chunks into '{$finalBackupName}'...");
        $finalPath = $this->storageManager->collateChunksAdvanced(
            $this->chunkMetadata,
            $finalBackupName,
            $this->compressionLevel,
            $this->encryption || (bool) config('capsule.security.encrypt_backups', false),
            (string) (config('capsule.security.backup_password') ?? env('CAPSULE_BACKUP_PASSWORD'))
        );
        
        $this->log("Final backup file created at: {$finalPath}");
        return $finalPath;
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
        foreach ($this->chunkMetadata as $chunk) {
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

    /**
     * Determine the appropriate MySQL/MariaDB dump command to use.
     * Tries mysqldump first, then falls back to mariadb-dump.
     * 
     * @return string The dump command to use
     * @throws Exception If neither command is available
     */
    protected function getMysqlDumpCommand(): string
    {
        // Try mysqldump first (preferred for compatibility)
        if ($this->commandExists('mysqldump')) {
            $this->log('Using mysqldump command');
            return 'mysqldump';
        }
        
        // Fall back to mariadb-dump for newer MariaDB versions
        if ($this->commandExists('mariadb-dump')) {
            $this->log('mysqldump not found, using mariadb-dump command');
            return 'mariadb-dump';
        }
        
        // Neither command is available
        $errorMessage = 'Neither mysqldump nor mariadb-dump command found. Please install MySQL or MariaDB client tools.';
        
        // Send notification if enabled
        if (config('capsule.notifications.enabled', false)) {
            $this->notificationManager->sendFailureNotification(
                null, 
                new Exception($errorMessage)
            );
        }
        
        throw new Exception($errorMessage);
    }

    /**
     * Check if a command exists in the system PATH
     * 
     * @param string $command The command to check
     * @return bool True if command exists, false otherwise
     */
    protected function commandExists(string $command): bool
    {
        $returnCode = 0;
        $output = [];
        exec("which {$command} 2>/dev/null", $output, $returnCode);
        return $returnCode === 0;
    }
}
