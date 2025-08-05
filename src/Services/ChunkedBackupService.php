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

    public function __construct(Application $app)
    {
        $this->app = $app;
        $this->storageManager = new StorageManager();
        $this->notificationManager = new NotificationManager();
        $this->backupId = uniqid('backup_', true);
        
        $maxConcurrent = config('capsule.chunked_backup.max_concurrent_uploads', 3);
        $this->uploadManager = new ConcurrentUploadManager($this->storageManager, $maxConcurrent);
    }

    public function run(): bool
    {
        $backupLog = BackupLog::create([
            'started_at' => now(),
            'status' => 'running',
            'metadata' => ['backup_id' => $this->backupId, 'chunked' => true],
        ]);

        try {
            $this->createChunkedBackup();
            
            // Upload chunks concurrently
            $uploadResults = $this->uploadChunksConcurrently();
            $uploadStats = $this->uploadManager->getUploadStats();
            
            $finalBackupPath = $this->collateChunks();
            
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

            $this->cleanup();
            $this->notificationManager->sendSuccessNotification($backupLog);

            return true;
        } catch (Exception $e) {
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
            $this->createChunkedDatabaseBackup($timestamp);
        }

        if (config('capsule.files.enabled')) {
            $this->createChunkedFileBackup($timestamp);
        }
    }

    protected function createChunkedDatabaseBackup(string $timestamp): void
    {
        $connections = config('capsule.database.connections', 'default');
        $connections = is_string($connections) ? [$connections] : $connections;

        foreach ($connections as $connection) {
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
        $command = sprintf(
            'mysqldump --host=%s --port=%s --user=%s --password=%s --single-transaction --routines --triggers %s',
            escapeshellarg($config['host']),
            escapeshellarg($config['port'] ?? 3306),
            escapeshellarg($config['username']),
            escapeshellarg($config['password']),
            escapeshellarg($config['database'])
        );

        $this->streamCommandToChunks($command, "db_{$connection}_{$timestamp}", $chunkSize, $tempPrefix);
    }

    protected function streamPostgresToChunks(array $config, string $connection, string $timestamp, int $chunkSize, string $tempPrefix): void
    {
        $command = sprintf(
            'PGPASSWORD=%s pg_dump --host=%s --port=%s --username=%s --dbname=%s --no-password',
            escapeshellarg($config['password']),
            escapeshellarg($config['host']),
            escapeshellarg($config['port'] ?? 5432),
            escapeshellarg($config['username']),
            escapeshellarg($config['database'])
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
                $this->streamDirectoryToChunks($path, $excludePaths, $timestamp, $chunkSize, $tempPrefix);
            } elseif (is_file($path)) {
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

    protected function prepareFileForChunk(string $filePath, string $baseDir): string
    {
        $relativePath = substr($filePath, strlen($baseDir) + 1);
        $fileContent = file_get_contents($filePath);
        
        // Create a simple format: [PATH_LENGTH][PATH][CONTENT_LENGTH][CONTENT]
        $pathLength = strlen($relativePath);
        $contentLength = strlen($fileContent);
        
        return pack('N', $pathLength) . $relativePath . pack('N', $contentLength) . $fileContent;
    }

    protected function uploadChunk(string $data, string $baseName, int $chunkIndex, string $tempPrefix): void
    {
        $chunkName = "{$tempPrefix}{$baseName}.part{$chunkIndex}";
        
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
        
        // Group chunks by base name
        $chunkGroups = [];
        foreach ($this->chunks as $chunk) {
            $chunkGroups[$chunk['base_name']][] = $chunk;
        }
        
        // Sort chunks within each group by index
        foreach ($chunkGroups as &$group) {
            usort($group, fn($a, $b) => $a['index'] <=> $b['index']);
        }
        
        // Create the final backup by combining all chunks
        return $this->storageManager->collateChunks($chunkGroups, $finalBackupName);
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
            $retentionDays = config('capsule.retention.days', 30);
            $retentionCount = config('capsule.retention.count', 10);

            BackupLog::where('status', 'success')
                ->where('created_at', '<', now()->subDays($retentionDays))
                ->orWhere(function ($query) use ($retentionCount) {
                    $query->where('status', 'success')
                        ->whereNotIn('id', function ($subQuery) use ($retentionCount) {
                            $subQuery->select('id')
                                ->from('backup_logs')
                                ->where('status', 'success')
                                ->orderBy('created_at', 'desc')
                                ->limit($retentionCount);
                        });
                })
                ->each(function (BackupLog $backup) {
                    if ($backup->file_path) {
                        if (isset($backup->metadata['chunked']) && $backup->metadata['chunked']) {
                            // For chunked backups, delete from remote storage
                            $fileName = basename($backup->file_path);
                            $this->storageManager->delete($fileName);
                        } else {
                            // For regular backups, use storage manager for consistent handling
                            $fileName = basename($backup->file_path);
                            $this->storageManager->delete($fileName);
                        }
                    }
                    $backup->delete();
                });
        }
    }
}