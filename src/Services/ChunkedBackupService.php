<?php

namespace Dgtlss\Capsule\Services;

use Dgtlss\Capsule\Contracts\FileFilterInterface;
use Dgtlss\Capsule\Contracts\StepInterface;
use Dgtlss\Capsule\Database\DatabaseDumper;
use Dgtlss\Capsule\Events\{BackupStarting, DatabaseDumpStarting, DatabaseDumpCompleted, FilesCollectStarting, FilesCollectCompleted, ArchiveFinalizing, BackupUploaded, BackupSucceeded, BackupFailed};
use Dgtlss\Capsule\Models\BackupLog;
use Dgtlss\Capsule\Notifications\NotificationManager;
use Dgtlss\Capsule\Storage\StorageManager;
use Dgtlss\Capsule\Support\BackupContext;
use Dgtlss\Capsule\Support\FileSelection;
use Dgtlss\Capsule\Support\Lock;
use Dgtlss\Capsule\Database\DumpValidator;
use Dgtlss\Capsule\Support\ManifestBuilder;
use Dgtlss\Capsule\Support\MemoryMonitor;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Log;
use Exception;

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
    protected ?string $tag = null;
    protected $outputCallback = null;

    public function __construct(
        Application $app,
        ?StorageManager $storageManager = null,
        ?NotificationManager $notificationManager = null,
        ?DatabaseDumper $databaseDumper = null,
        ?ManifestBuilder $manifestBuilder = null,
    ) {
        $this->app = $app;
        $this->storageManager = $storageManager ?? new StorageManager();
        $this->notificationManager = $notificationManager ?? new NotificationManager();
        $this->databaseDumper = $databaseDumper ?? new DatabaseDumper();
        $this->manifestBuilder = $manifestBuilder ?? new ManifestBuilder();
        $this->backupId = bin2hex(random_bytes(16));
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

    public function setTag(?string $tag): void
    {
        $this->tag = $tag;
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

    public function run(bool $force = false): bool
    {
        $this->lastError = null;
        $lock = null;
        try {
            if (!$force) {
                $lock = Lock::acquire('capsule:backup');
                if (!$lock) {
                    $this->lastError = 'Another backup is currently running.';
                    return false;
                }
            }
            return $this->runBackup();
        } catch (\Throwable $e) {
            $this->lastError ??= $e->getMessage();
            Log::error('Chunked backup failed: ' . $e->getMessage(), ['exception' => $e]);
            return false;
        } finally {
            if ($lock) {
                try { $lock->release(); } catch (\Throwable $e) { Log::warning('Could not release backup lock.', ['exception' => $e]); }
            }
        }
    }

    protected function runBackup(): bool
    {
        $this->chunks = [];
        $this->chunkMetadata = [];
        $this->backupId = bin2hex(random_bytes(16));
        $this->manifestBuilder->reset();
        MemoryMonitor::checkpoint('backup_start');
        $context = new BackupContext('chunked');
        $context->verbose = $this->verbose;
        $context->compressionLevel = $this->compressionLevel;
        $context->encryption = $this->encryption || (bool) config('capsule.security.encrypt_backups', false);
        $context->verification = $this->verification;
        $metadata = [
            'backup_id' => $this->backupId, 'chunked' => true,
            'storage' => ['disk' => config('capsule.default_disk'), 'backup_path' => config('capsule.backup_path'),
                'additional_disks' => (array) config('capsule.additional_disks', [])],
            'policy' => config('capsule._policy_name'),
            'retention' => (array) config('capsule.retention', []),
        ];
        $backupLog = new BackupLog(['started_at' => now(), 'status' => 'running', 'tag' => $this->tag, 'metadata' => $metadata]);
        $persisted = false;
        try {
            // Validate encryption before creating any remote chunk or exposing plaintext.
            $this->validateConfiguration();
            if (config('capsule.database.enabled', true)) {
                $failed = $this->probeDatabaseConnections();
                if ($failed !== []) {
                    throw new Exception('Database unreachable for connection(s): ' . implode(', ', array_column($failed, 'connection')));
                }
            }
            try {
                $backupLog->save();
                $persisted = true;
            } catch (\Throwable $e) {
                Log::warning('BackupLog could not be persisted; continuing without DB logging', ['exception' => $e]);
            }
            event(new BackupStarting($context));
            $this->executeSteps((array) config('capsule.extensibility.pre_steps', []), $context, 'pre');
            $this->createChunkedBackup();
            event(new ArchiveFinalizing($context));
            $finalPath = $this->collateChunks();
            $context->remotePath = $finalPath;
            event(new BackupUploaded($context, $finalPath));
            $backupLog->completed_at = now();
            $backupLog->status = 'success';
            $backupLog->file_path = $finalPath;
            $backupLog->file_size = $this->getRemoteFileSize($finalPath);
            $backupLog->metadata = array_merge($metadata, [
                'chunks_count' => count($this->chunkMetadata),
                'chunked_method' => 'bounded_zip64_stream',
                'verification' => 'sha256_readback',
            ]);
            if ($persisted) {
                try { $backupLog->save(); } catch (\Throwable $e) { Log::warning('Failed to persist success BackupLog update', ['exception' => $e]); }
            }
            $this->cleanup();
            $this->notificationManager->sendSuccessNotification($backupLog);
            event(new BackupSucceeded($context));
            $this->executeSteps((array) config('capsule.extensibility.post_steps', []), $context, 'post');
            return true;
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();
            $backupLog->completed_at = now();
            $backupLog->status = 'failed';
            $backupLog->error_message = $e->getMessage();
            // Retain uploaded source chunks when final storage or validation fails.
            $backupLog->metadata = array_merge($metadata, ['recovery_chunks' => $this->chunkMetadata]);
            if ($persisted) {
                try { $backupLog->save(); } catch (\Throwable $ignored) { }
            }
            Log::error('Chunked backup failed: ' . $e->getMessage(), ['exception' => $e, 'backup_id' => $this->backupId]);
            $exception = $e instanceof Exception ? $e : new Exception($e->getMessage(), 0, $e);
            try {
                $this->notificationManager->sendFailureNotification($backupLog, $exception);
            } catch (\Throwable $secondary) {
                Log::warning('Failed to send backup failure notification.', ['exception' => $secondary]);
            }
            try {
                event(new BackupFailed($context, $e));
            } catch (\Throwable $secondary) {
                Log::warning('Backup failure listener threw.', ['exception' => $secondary]);
            }
            return false;
        }
    }

    protected function probeDatabaseConnections(): array
    {
        $connections = $this->databaseDumper->resolveConnections();
        $failures = [];

        foreach ($connections as $connection) {
            try {
                \Illuminate\Support\Facades\DB::connection($connection)->getPdo();
            } catch (\Throwable $e) {
                $failures[] = ['connection' => $connection, 'error' => $e->getMessage()];
            }
        }

        return $failures;
    }

    protected function validateConfiguration(): void
    {
        if ($this->encryption || (bool) config('capsule.security.encrypt_backups', false)) {
            throw new Exception('Encrypted streaming backups are not supported. Use regular backup mode without --no-local to create an encrypted archive.');
        }
        $chunkSize = (int) config('capsule.chunked_backup.chunk_size', 10485760);
        if ($chunkSize < 1 || $chunkSize > 67108864) {
            throw new Exception('Streaming chunk_size must be between 1 and 67108864 bytes.');
        }
        $dbEnabled = config('capsule.database.enabled', true);
        $filesEnabled = config('capsule.files.enabled', true);

        if (!$dbEnabled && !$filesEnabled) {
            throw new Exception('Both database and file backups are disabled. Enable at least one in config/capsule.php');
        }

        $diskName = config('capsule.default_disk', 'local');
        $filesystemDisks = config('filesystems.disks', []);
        if (!isset($filesystemDisks[$diskName])) {
            throw new Exception("Storage disk '{$diskName}' not found in config/filesystems.php. Available disks: " . implode(', ', array_keys($filesystemDisks)));
        }
    }

    protected function executeSteps(array $classes, BackupContext $context, string $phase): void
    {
        foreach ($classes as $class) {
            try {
                $instance = app($class);
                if ($instance instanceof StepInterface) {
                    $this->log("Executing {$phase}-step: {$class}");
                    $instance->handle($context);
                } else {
                    Log::warning('Configured step does not implement StepInterface: ' . $class);
                }
            } catch (\Throwable $e) {
                Log::error('Step execution failed: ' . $class, ['exception' => $e]);
                throw new Exception('Step failed: ' . $class . ' – ' . $e->getMessage(), 0, $e);
            }
        }
    }

    protected function createChunkedBackup(): void
    {
        $timestamp = now()->format('Y-m-d_H-i-s');
        $context = new BackupContext('chunked');

        if (config('capsule.database.enabled')) {
            $this->log('Creating chunked database backup...');
            event(new DatabaseDumpStarting($context));
            $this->createChunkedDatabaseBackup($timestamp);
            event(new DatabaseDumpCompleted($context));
        }

        if (config('capsule.files.enabled')) {
            $this->log('Creating chunked file backup...');
            event(new FilesCollectStarting($context));
            $this->createChunkedFileBackup($timestamp);
            event(new FilesCollectCompleted($context));
        }
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
        $chunkSize = (int) config('capsule.chunked_backup.chunk_size', 10485760);
        $driver = (string) config("database.connections.{$connection}.driver");
        $entryName = 'database/' . $connection . ($driver === 'sqlite' ? '.sqlite' : '.sql');
        $baseName = 'db_' . bin2hex(random_bytes(8));
        $streamInfo = $this->databaseDumper->buildStreamCommand($connection);
        try {
            $start = count($this->chunkMetadata);
            $result = $this->streamCommandToChunks($streamInfo['command'], $baseName, $chunkSize, 'capsule_chunk_');
            DumpValidator::validateContent($result['header'], $result['tail'], $result['size'], $driver);
            for ($i = $start; $i < count($this->chunkMetadata); $i++) {
                $this->chunkMetadata[$i]['entry_name'] = $entryName;
            }
            $this->manifestBuilder->addStreamEntry($entryName, $result['size'], $result['sha256'], [
                'connection' => $connection, 'driver' => $driver, 'format' => $driver === 'sqlite' ? 'sqlite' : 'sql',
            ]);
        } finally {
            if (!empty($streamInfo['cleanup'])) {
                @unlink($streamInfo['cleanup']);
            }
        }
    }

    protected function streamCommandToChunks(string $command, string $baseName, int $chunkSize, string $tempPrefix): array
    {
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            throw new Exception('Failed to start database dump process.');
        }
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $buffer = '';
        $index = 0;
        $size = 0;
        $header = '';
        $tail = '';
        $stderr = '';
        $hash = hash_init('sha256');
        try {
            while (!feof($pipes[1]) || !feof($pipes[2])) {
                $read = array_filter([$pipes[1], $pipes[2]], fn ($pipe) => !feof($pipe));
                $write = $except = null;
                if (stream_select($read, $write, $except, 1) === false) {
                    throw new Exception('Failed to read database dump process.');
                }
                foreach ($read as $pipe) {
                    $data = fread($pipe, 65536);
                    if ($data === false) {
                        throw new Exception('Failed to read database dump output.');
                    }
                    if ($pipe === $pipes[2]) {
                        $stderr = substr($stderr . $data, -8192);
                        continue;
                    }
                    $size += strlen($data);
                    hash_update($hash, $data);
                    $header = substr($header . $data, 0, 4096);
                    $tail = substr($tail . $data, -4096);
                    $this->appendChunkData($data, $buffer, $baseName, $index, $chunkSize, $tempPrefix);
                }
            }
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exit = proc_close($process);
            $process = null;
            if ($exit !== 0) {
                throw new Exception('Database dump failed with exit code ' . $exit . ($stderr !== '' ? ': ' . trim($stderr) : '.'));
            }
            if ($buffer !== '') {
                $this->uploadChunkDirectly($buffer, $baseName, $index, $tempPrefix);
            }
            return ['size' => $size, 'sha256' => hash_final($hash), 'header' => $header, 'tail' => $tail];
        } finally {
            foreach ($pipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            if (is_resource($process)) {
                proc_terminate($process);
                proc_close($process);
            }
        }
    }

    protected function createChunkedFileBackup(string $timestamp): void
    {
        $chunkSize = (int) config('capsule.chunked_backup.chunk_size', 10485760);
        $baseName = 'files_' . bin2hex(random_bytes(8));
        $index = 0;
        $buffer = '';
        $start = count($this->chunkMetadata);
        foreach (FileSelection::files() as $archivePath => $filePath) {
            $relative = substr($archivePath, strlen('files/'));
            $stream = @fopen($filePath, 'rb');
            if ($stream === false) {
                throw new Exception("Cannot read backup source: {$filePath}");
            }
            try {
                $stat = fstat($stream);
                if ($stat === false || $stat['size'] < 0) {
                    throw new Exception("Cannot determine backup source size: {$filePath}");
                }
                $size = $stat['size'];
                $hash = hash_init('sha256');
                $this->appendChunkData(pack('N', strlen($relative)) . $relative . pack('P', $size), $buffer, $baseName, $index, $chunkSize, 'capsule_chunk_');
                $remaining = $size;
                while ($remaining > 0) {
                    $data = fread($stream, min(65536, $remaining));
                    if ($data === false || $data === '') {
                        throw new Exception("Backup source changed or became unreadable: {$filePath}");
                    }
                    $remaining -= strlen($data);
                    hash_update($hash, $data);
                    $this->appendChunkData($data, $buffer, $baseName, $index, $chunkSize, 'capsule_chunk_');
                }
                if (fread($stream, 1) !== '') {
                    throw new Exception("Backup source changed while being read: {$filePath}");
                }
                $this->manifestBuilder->addStreamEntry($archivePath, $size, hash_final($hash));
            } finally {
                fclose($stream);
            }
        }
        if ($buffer !== '') {
            $this->uploadChunkDirectly($buffer, $baseName, $index, 'capsule_chunk_');
        }
        for ($i = $start; $i < count($this->chunkMetadata); $i++) {
            $this->chunkMetadata[$i]['format'] = 'framed-v2';
        }
    }

    protected function appendChunkData(string $data, string &$buffer, string $baseName, int &$index, int $chunkSize, string $prefix): void
    {
        if ($chunkSize < 1) {
            throw new Exception('Streaming chunk_size must be positive.');
        }
        $offset = 0;
        while ($offset < strlen($data)) {
            $take = min($chunkSize - strlen($buffer), strlen($data) - $offset);
            $buffer .= substr($data, $offset, $take);
            $offset += $take;
            if (strlen($buffer) === $chunkSize) {
                $this->uploadChunkDirectly($buffer, $baseName, $index++, $prefix);
                $buffer = '';
            }
        }
    }

    protected function resolveFileFilters(): array
    {
        $filters = [];
        foreach ((array) config('capsule.extensibility.file_filters', []) as $class) {
            $instance = app($class);
            if (!$instance instanceof FileFilterInterface) {
                throw new Exception('Configured file filter does not implement FileFilterInterface: ' . $class);
            }
            $filters[] = $instance;
        }
        return $filters;
    }

    protected function passesFilters(string $absolutePath, array $filters): bool
    {
        $context = new BackupContext('chunked');
        foreach ($filters as $filter) {
            if (!$filter->shouldInclude($absolutePath, $context)) {
                return false;
            }
        }
        return true;
    }

    protected function uploadChunkDirectly(string $data, string $baseName, int $chunkIndex, string $tempPrefix): void
    {
        // Object names are run-scoped and opaque; no connection, filename, or host leaks.
        $chunkName = 'capsule_chunk_' . $this->backupId . '_' . hash('sha256', $baseName) . '.part' . $chunkIndex;
        $stream = fopen('php://memory', 'w+b');
        try {
            $offset = 0;
            while ($offset < strlen($data)) {
                $written = fwrite($stream, substr($data, $offset));
                if ($written === false || $written === 0) {
                    throw new Exception('Cannot buffer backup chunk.');
                }
                $offset += $written;
            }
            rewind($stream);
            $this->storageManager->storeStream($stream, $chunkName);
            $this->chunkMetadata[] = [
                'name' => $chunkName, 'index' => $chunkIndex, 'base_name' => $baseName,
                'size' => strlen($data), 'sha256' => hash('sha256', $data),
            ];
        } finally {
            fclose($stream);
        }
    }

    protected function collateChunks(): string
    {
        $name = 'backup_' . now()->format('Y-m-d_H-i-s') . '_' . $this->backupId . '.zip';
        $manifest = $this->manifestBuilder->build(true, $this->compressionLevel, false);
        $json = ManifestBuilder::encode($manifest);
        $index = 0;
        $buffer = '';
        $this->appendChunkData($json, $buffer, 'manifest.json', $index, (int) config('capsule.chunked_backup.chunk_size', 10485760), 'capsule_chunk_');
        if ($buffer !== '') {
            $this->uploadChunkDirectly($buffer, 'manifest.json', $index, 'capsule_chunk_');
        }
        return $this->storageManager->collateChunksAdvanced($this->chunkMetadata, $name, $this->compressionLevel);
    }

    protected function getRemoteFileSize(string $fileName): int
    {
        return $this->storageManager->getFileSize($fileName);
    }

    protected function shouldExcludePath(string $path, array $excludePaths): bool
    {
        return FileSelection::shouldExclude($path, $excludePaths);
    }

    protected function cleanupChunks(): void
    {
        $this->log('Cleaning up remote chunks...');
        foreach ($this->chunkMetadata as $chunk) {
            try {
                $this->storageManager->delete($chunk['name']);
            } catch (\Throwable $e) {
                Log::warning("Failed to cleanup chunk: {$chunk['name']}", ['exception' => $e]);
            }
        }
    }

    protected function cleanup(): void
    {
        $this->cleanupChunks();
        if (config('capsule.retention.cleanup_enabled', true)) {
            try {
                app(RetentionService::class)->cleanup(['scope' => RetentionService::currentScope()]);
            } catch (\Throwable $e) {
                Log::warning('Retention cleanup failed after verified backup upload.', ['exception' => $e]);
            }
        }
    }
}
