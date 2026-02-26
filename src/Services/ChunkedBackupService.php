<?php

namespace Dgtlss\Capsule\Services;

use Dgtlss\Capsule\Database\DatabaseDumper;
use Dgtlss\Capsule\Models\BackupLog;
use Dgtlss\Capsule\Notifications\NotificationManager;
use Dgtlss\Capsule\Storage\StorageManager;
use Dgtlss\Capsule\Support\Helpers;
use Dgtlss\Capsule\Support\ManifestBuilder;
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
    protected DatabaseDumper $databaseDumper;
    protected ManifestBuilder $manifestBuilder;
    protected array $chunks = [];
    protected array $chunkMetadata = [];
    protected string $backupId;
    protected bool $verbose = false;
    protected bool $parallel = false;
    protected int $compressionLevel = 1;
    protected bool $encryption = false;
    protected bool $verification = false;
    protected ?string $lastError = null;
    protected $outputCallback = null;

    public function __construct(
        Application $app,
        StorageManager $storageManager = null,
        NotificationManager $notificationManager = null,
        DatabaseDumper $databaseDumper = null,
        ManifestBuilder $manifestBuilder = null,
    ) {
        $this->app = $app;
        $this->storageManager = $storageManager ?? new StorageManager();
        $this->notificationManager = $notificationManager ?? new NotificationManager();
        $this->databaseDumper = $databaseDumper ?? new DatabaseDumper();
        $this->manifestBuilder = $manifestBuilder ?? new ManifestBuilder();
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
        $connections = $this->databaseDumper->resolveConnections();

        foreach ($connections as $connection) {
            $this->log("Streaming database '{$connection}' to chunks...");
            $this->streamDatabaseToChunks($connection, $timestamp);
        }
    }

    protected function streamDatabaseToChunks(string $connection, string $timestamp): void
    {
        $chunkSize = config('capsule.chunked_backup.chunk_size', 10485760);
        $tempPrefix = config('capsule.chunked_backup.temp_prefix', 'capsule_chunk_');

        $streamInfo = $this->databaseDumper->buildStreamCommand($connection);

        try {
            $this->streamCommandToChunks($streamInfo['command'], "db_{$connection}_{$timestamp}", $chunkSize, $tempPrefix);
        } finally {
            if (!empty($streamInfo['cleanup']) && file_exists($streamInfo['cleanup'])) {
                @unlink($streamInfo['cleanup']);
            }
        }
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


    protected function prepareFileForChunk(string $filePath, string $baseDir): string
    {
        $relativePath = substr($filePath, strlen($baseDir) + 1);
        
        $this->manifestBuilder->addDeferredEntry('files/' . $relativePath, $filePath);
        
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
        $this->manifestBuilder->addDeferredEntry('files/' . $relativePath, $filePath);
        
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

        $encryptionEnabled = $this->encryption || (bool) config('capsule.security.encrypt_backups', false);
        $manifest = $this->manifestBuilder->build(true, $this->compressionLevel, $encryptionEnabled);
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

}
