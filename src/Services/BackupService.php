<?php

namespace Dgtlss\Capsule\Services;

use Dgtlss\Capsule\Database\DatabaseDumper;
use Dgtlss\Capsule\Models\BackupLog;
use Dgtlss\Capsule\Notifications\NotificationManager;
use Dgtlss\Capsule\Storage\StorageManager;
use Dgtlss\Capsule\Support\BackupContext;
use Dgtlss\Capsule\Support\Helpers;
use Dgtlss\Capsule\Support\ManifestBuilder;
use Dgtlss\Capsule\Contracts\FileFilterInterface;
use Dgtlss\Capsule\Contracts\StepInterface;
use Dgtlss\Capsule\Events\{BackupStarting, DatabaseDumpStarting, DatabaseDumpCompleted, FilesCollectStarting, FilesCollectCompleted, ArchiveFinalizing, BackupUploaded, BackupSucceeded, BackupFailed};
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use ZipArchive;
use Exception;

class BackupService
{
    protected Application $app;
    protected StorageManager $storageManager;
    protected NotificationManager $notificationManager;
    protected DatabaseDumper $databaseDumper;
    protected ManifestBuilder $manifestBuilder;
    protected bool $verbose = false;
    protected bool $parallel = false;
    protected int $compressionLevel = 1;
    protected bool $encryption = false;
    protected bool $verification = false;
    protected ?string $lastError = null;
    protected $outputCallback = null;
    protected bool $backupLogPersisted = false;
    protected ?string $lastRemotePath = null;
    protected ?int $lastFileSize = null;
    protected ?string $tag = null;

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

    public function run(): bool
    {
        $this->log('🔧 Validating configuration...');
        // Validate configuration before starting
        $this->validateConfiguration();
        
        // Preflight: ensure DB is reachable if DB backup is enabled
        if (config('capsule.database.enabled', true)) {
            $failedConnections = $this->probeDatabaseConnections();
            if (!empty($failedConnections)) {
                $reason = 'Database unreachable for connection(s): ' . implode(', ', array_map(fn($f) => $f['connection'], $failedConnections));
                $this->lastError = $reason;
                $this->log('❌ ' . $reason);

                $pseudoLog = new BackupLog([
                    'started_at' => now(),
                    'completed_at' => now(),
                    'status' => 'failed',
                    'error_message' => $reason,
                    'metadata' => [
                        'failure_stage' => 'preflight',
                        'failed_connections' => $failedConnections,
                    ],
                ]);

                $this->notificationManager->sendFailureNotification($pseudoLog, new Exception($reason));
                return false;
            }
        }
        
        $this->log('📝 Creating backup log entry...');
        $context = new BackupContext('normal');
        $context->verbose = $this->verbose;
        $context->compressionLevel = $this->compressionLevel;
        $context->encryption = $this->encryption || (bool) config('capsule.security.encrypt_backups', false);
        $context->verification = $this->verification;
        event(new BackupStarting($context));

        // Execute pre-steps
        $this->executeSteps((array) config('capsule.extensibility.pre_steps', []), $context, 'pre');
        // Try to persist backup log, but degrade gracefully if DB is unavailable
        try {
            $backupLog = BackupLog::create([
                'started_at' => now(),
                'status' => 'running',
                'tag' => $this->tag,
            ]);
            $this->backupLogPersisted = true;
        } catch (\Throwable $e) {
            Log::warning('BackupLog could not be persisted; continuing without DB logging', ['exception' => $e]);
            $backupLog = new BackupLog([
                'started_at' => now(),
                'status' => 'running',
            ]);
            $this->backupLogPersisted = false;
        }

        try {
            $this->log('📦 Creating backup archive...');
            $localBackupPath = $this->createBackup();
            $context->localArchivePath = $localBackupPath;
            
            $fileSize = filesize($localBackupPath);
            
            // Verify backup integrity if requested
            if ($this->verification) {
                $this->log('🔍 Verifying backup integrity...');
                $this->verifyBackup($localBackupPath);
            }

            $this->log("Uploading backup to storage (" . Helpers::formatBytes($fileSize) . ")...");
            $remotePath = $this->storageManager->store($localBackupPath);
            $context->remotePath = $remotePath;
            $this->lastRemotePath = $remotePath;
            event(new BackupUploaded($context, $remotePath));
            
            $this->log('✅ Updating backup log...');
            $backupLog->completed_at = now();
            $backupLog->status = 'success';
            $backupLog->file_path = $remotePath;
            $backupLog->file_size = $fileSize;
            $this->lastFileSize = $fileSize;
            if ($this->backupLogPersisted) {
                try {
                    $backupLog->save();
                } catch (\Throwable $e) {
                    Log::warning('Failed to persist success BackupLog update', ['exception' => $e]);
                }
            }

            // Clean up local file if not using local disk
            if (config('capsule.default_disk') !== 'local') {
                $this->log('🧹 Cleaning up local backup file...');
                unlink($localBackupPath);
            }

            // Run retention cleanup only if DB available
            if ($this->backupLogPersisted) {
                $this->log('🧹 Running retention cleanup...');
                $this->cleanup();
            }
            
            $this->log('📧 Sending success notification...');
            $this->notificationManager->sendSuccessNotification($backupLog);
            event(new BackupSucceeded($context));

            // Execute post-steps (success)
            $this->executeSteps((array) config('capsule.extensibility.post_steps', []), $context, 'post');

            return true;
        } catch (Exception $e) {
            $backupLog->completed_at = now();
            $backupLog->status = 'failed';
            $backupLog->error_message = $e->getMessage();
            if ($this->backupLogPersisted) {
                try {
                    $backupLog->save();
                } catch (\Throwable $ex) {
                    Log::warning('Failed to persist failed BackupLog update', ['exception' => $ex]);
                }
            }

            Log::error('Backup failed: ' . $e->getMessage(), ['exception' => $e]);
            $this->notificationManager->sendFailureNotification($backupLog, $e);
            event(new BackupFailed($context, $e));

            // Execute post-steps (failure) – still run to allow cleanup steps
            $this->executeSteps((array) config('capsule.extensibility.post_steps', []), $context, 'post');

            return false;
        }
    }

    protected function probeDatabaseConnections(): array
    {
        $connections = $this->databaseDumper->resolveConnections();
        $failures = [];

        foreach ($connections as $connection) {
            try {
                \DB::connection($connection)->getPdo();
            } catch (\Throwable $e) {
                $failures[] = [
                    'connection' => $connection,
                    'error' => $e->getMessage(),
                ];
            }
        }

        return $failures;
    }

    protected function createBackup(): string
    {
        $timestamp = now()->format('Y-m-d_H-i-s');
        $backupDir = storage_path('app/backups');
        
        if (!is_dir($backupDir)) {
            $this->log('📁 Creating backup directory...');
            mkdir($backupDir, 0755, true);
        }

        $backupPath = $backupDir . "/backup_{$timestamp}.zip";
        $this->log("📦 Creating ZIP archive: backup_{$timestamp}.zip");
        $zip = new ZipArchive();

        if ($zip->open($backupPath, ZipArchive::CREATE) !== TRUE) {
            throw new Exception("Cannot create backup archive: {$backupPath}");
        }

        $tempFiles = [];

        if (config('capsule.database.enabled')) {
            $this->log('🗄️  Adding database to backup...');
            event(new DatabaseDumpStarting(new BackupContext('normal')));
            $tempFiles = array_merge($tempFiles, $this->addDatabaseToBackup($zip, $timestamp));
            event(new DatabaseDumpCompleted(new BackupContext('normal')));
        }

        if (config('capsule.files.enabled')) {
            $this->log('📁 Adding files to backup...');
            event(new FilesCollectStarting(new BackupContext('normal')));
            $this->addFilesToBackup($zip);
            event(new FilesCollectCompleted(new BackupContext('normal')));
        }

        $this->log('Writing manifest...');
        $encryptionEnabled = $this->encryption || (bool) config('capsule.security.encrypt_backups', false);
        $manifest = $this->manifestBuilder->build(false, $this->compressionLevel, $encryptionEnabled);
        $manifestJson = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $zip->addFromString('manifest.json', $manifestJson);
        $this->applyCompressionAndEncryption($zip, 'manifest.json');

        $this->log('🔄 Finalizing ZIP archive...');
        event(new ArchiveFinalizing(new BackupContext('normal')));
        
        // Use a callback to show progress during closing
        $startTime = microtime(true);
        $result = $zip->close();
        $closeTime = round(microtime(true) - $startTime, 2);
        
        if (!$result) {
            throw new Exception("Failed to finalize ZIP archive. Error: " . $zip->getStatusString());
        }
        
        $this->log("✅ ZIP archive finalized in {$closeTime} seconds");

        // Clean up temporary files after ZIP is closed
        if (!empty($tempFiles)) {
            $this->log('🧹 Cleaning up temporary database files...');
            foreach ($tempFiles as $tempFile) {
                if (file_exists($tempFile)) {
                    unlink($tempFile);
                }
            }
        }

        return $backupPath;
    }

    protected function addDatabaseToBackup(ZipArchive $zip, string $timestamp): array
    {
        $connections = $this->databaseDumper->resolveConnections();

        $this->log("   Processing " . count($connections) . " database connection(s)...");

        if ($this->parallel && count($connections) > 1) {
            return $this->addDatabasesParallel($zip, $connections, $timestamp);
        }

        return $this->addDatabasesSequential($zip, $connections, $timestamp);
    }

    protected function addDatabasesSequential(ZipArchive $zip, array $connections, string $timestamp): array
    {
        $tempFiles = [];

        foreach ($connections as $connection) {
            $this->log("   Dumping database: {$connection}");
            $dumpPath = storage_path("app/backups/db_{$connection}_{$timestamp}.sql");
            $this->databaseDumper->dump($connection, $dumpPath);

            $dumpSize = filesize($dumpPath);
            $entryName = "database/{$connection}.sql";
            $this->log("   Adding {$entryName} (" . Helpers::formatBytes($dumpSize) . ") to archive");
            $zip->addFile($dumpPath, $entryName);
            $this->applyCompressionAndEncryption($zip, $entryName);
            $this->manifestBuilder->addEntry($entryName, $dumpPath);
            $tempFiles[] = $dumpPath;
        }

        return $tempFiles;
    }

    protected function addDatabasesParallel(ZipArchive $zip, array $connections, string $timestamp): array
    {
        $this->log("   Using parallel processing for database dumps");
        $tempFiles = [];
        $processes = [];

        foreach ($connections as $connection) {
            $this->log("   Starting parallel dump: {$connection}");
            $dumpPath = storage_path("app/backups/db_{$connection}_{$timestamp}.sql");
            $tempFiles[] = $dumpPath;

            $process = $this->databaseDumper->startDumpProcess($connection, $dumpPath);
            $processes[$connection] = ['process' => $process, 'path' => $dumpPath];
        }

        foreach ($processes as $connectionName => $processInfo) {
            if ($processInfo['process'] === null) {
                continue; // SQLite -- already done synchronously
            }

            $this->log("   Waiting for {$connectionName} dump to complete...");
            $returnCode = proc_close($processInfo['process']);

            if ($returnCode !== 0) {
                throw new Exception("Parallel database dump failed for connection: {$connectionName}");
            }

            $dumpSize = filesize($processInfo['path']);
            $entryName = "database/{$connectionName}.sql";
            $this->log("   Adding {$entryName} (" . Helpers::formatBytes($dumpSize) . ") to archive");
            $zip->addFile($processInfo['path'], $entryName);
            $this->applyCompressionAndEncryption($zip, $entryName);
            $this->manifestBuilder->addEntry($entryName, $processInfo['path']);
        }

        return $tempFiles;
    }

    protected function addFilesToBackup(ZipArchive $zip): void
    {
        $paths = config('capsule.files.paths', []);
        $excludePaths = config('capsule.files.exclude_paths', []);
        $filters = $this->resolveFileFilters();

        $this->log("   📁 Processing " . count($paths) . " file path(s)...");
        $this->log("   🚫 Excluding " . count($excludePaths) . " path(s)...");

        foreach ($paths as $path) {
            if (is_dir($path)) {
                $this->log("   📂 Scanning directory: " . basename($path));
                $this->addDirectoryToZip($zip, $path, $excludePaths, 'files/', $filters);
            } elseif (is_file($path)) {
                $fileSize = filesize($path);
                $relative = 'files/' . basename($path);
                if (!$this->passesFilters($path, $filters)) {
                    continue;
                }
                $this->log("   Adding file: " . basename($path) . " (" . Helpers::formatBytes($fileSize) . ")");
                $zip->addFile($path, $relative);
                $this->applyCompressionAndEncryption($zip, $relative);
                $this->manifestBuilder->addEntry($relative, $path);
            }
        }
    }

    protected function addDirectoryToZip(ZipArchive $zip, string $dir, array $excludePaths, string $zipPath = 'files/', array $filters = []): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        $fileCount = 0;
        $totalSize = 0;
        
        foreach ($iterator as $file) {
            $filePath = $file->getRealPath();
            
            // Skip files where getRealPath() fails (broken symlinks, etc.)
            if ($filePath === false || empty($filePath)) {
                continue;
            }
            
            if ($this->shouldExcludePath($filePath, $excludePaths)) {
                continue;
            }
            if ($file->isFile() && !$this->passesFilters($filePath, $filters)) {
                continue;
            }

            // Calculate relative path correctly
            $normalizedDir = rtrim($dir, '/');
            $relativeFile = substr($filePath, strlen($normalizedDir) + 1);
            $relativePath = $zipPath . $relativeFile;

            if ($file->isDir()) {
                $zip->addEmptyDir($relativePath);
            } else {
                // Add file with immediate compression to reduce memory usage
                $zip->addFile($filePath, $relativePath);
                $this->applyCompressionAndEncryption($zip, $relativePath);
                $this->manifestBuilder->addEntry($relativePath, $filePath);
                
                $fileSize = $file->getSize();
                $totalSize += $fileSize;
                $fileCount++;
                
                // Show progress every 100 files to avoid spam
                if ($fileCount % 100 === 0) {
                    $this->log("   Added {$fileCount} files (" . Helpers::formatBytes($totalSize) . ")...");
                }
            }
        }
        
        if ($fileCount > 0) {
            $this->log("   Completed: {$fileCount} files (" . Helpers::formatBytes($totalSize) . ") from " . basename($dir));
        }
    }

    protected function resolveFileFilters(): array
    {
        $filters = [];
        foreach ((array) config('capsule.extensibility.file_filters', []) as $class) {
            try {
                $instance = app($class);
                if ($instance instanceof FileFilterInterface) {
                    $filters[] = $instance;
                }
            } catch (\Throwable $e) {
                Log::warning('Failed to resolve file filter: ' . $class, ['exception' => $e]);
            }
        }
        return $filters;
    }

    protected function passesFilters(string $absolutePath, array $filters): bool
    {
        foreach ($filters as $filter) {
            if (!$filter->shouldInclude($absolutePath, new BackupContext('normal'))) {
                return false;
            }
        }
        return true;
    }

    protected function executeSteps(array $classes, BackupContext $context, string $phase): void
    {
        foreach ($classes as $class) {
            try {
                $instance = app($class);
                if ($instance instanceof StepInterface) {
                    $this->log("   ▶ Executing {$phase}-step: {$class}");
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

    protected function applyCompressionAndEncryption(ZipArchive $zip, string $entryName): void
    {
        // Compression per-entry
        if (method_exists($zip, 'setCompressionName')) {
            // CM_DEFLATE with specified level
            @$zip->setCompressionName($entryName, ZipArchive::CM_DEFLATE, $this->compressionLevel);
        }

        // Encryption per-entry if enabled
        $encryptionEnabled = $this->encryption || (bool) config('capsule.security.encrypt_backups', false);
        if ($encryptionEnabled) {
            $password = config('capsule.security.backup_password') ?? env('CAPSULE_BACKUP_PASSWORD');
            if (!empty($password)) {
                // Set archive password once
                @$zip->setPassword($password);
                // Encrypt this entry
                if (method_exists($zip, 'setEncryptionName')) {
                    // Suppress warnings if not supported by libzip
                    @$zip->setEncryptionName($entryName, ZipArchive::EM_AES_256);
                }
            }
        }
    }


    protected function shouldExcludePath(string $path, array $excludePaths): bool
    {
        // Normalize the path for consistent comparison
        $normalizedPath = rtrim($path, '/');
        
        foreach ($excludePaths as $excludePath) {
            $normalizedExcludePath = rtrim($excludePath, '/');
            
            // Exact path match
            if ($normalizedPath === $normalizedExcludePath) {
                return true;
            }
            
            // Path starts with exclude path (for subdirectories)
            if (strpos($normalizedPath, $normalizedExcludePath . '/') === 0) {
                return true;
            }
            
            // Check filename patterns (like .DS_Store, Thumbs.db)
            $filename = basename($path);
            $excludeFilename = basename($excludePath);
            if ($filename === $excludeFilename) {
                return true;
            }
        }

        return false;
    }


    protected function cleanup(): void
    {
        if (!config('capsule.retention.cleanup_enabled', true)) {
            return;
        }

        $retentionDays = config('capsule.retention.days', 30);
        $retentionCount = config('capsule.retention.count', 10);

        $keepIds = BackupLog::where('status', 'success')
            ->orderBy('created_at', 'desc')
            ->limit($retentionCount)
            ->pluck('id')
            ->toArray();

        BackupLog::where('status', 'success')
            ->where('created_at', '<', now()->subDays($retentionDays))
            ->when(!empty($keepIds), function ($q) use ($keepIds) {
                $q->whereNotIn('id', $keepIds);
            })
            ->eachById(function (BackupLog $backup) {
                if ($backup->file_path) {
                    $fileName = basename($backup->file_path);
                    $this->storageManager->delete($fileName);
                }
                $backup->delete();
            });
    }

    protected function validateConfiguration(): void
    {
        // Check if database or files backup is enabled
        $dbEnabled = config('capsule.database.enabled', true);
        $filesEnabled = config('capsule.files.enabled', true);
        
        if (!$dbEnabled && !$filesEnabled) {
            throw new Exception('Both database and file backups are disabled. Enable at least one in config/capsule.php');
        }

        // Check storage disk configuration
        $diskName = config('capsule.default_disk', 'local');
        $backupPath = config('capsule.backup_path', 'backups');
        
        if (!$diskName) {
            throw new Exception('No storage disk configured. Set CAPSULE_DEFAULT_DISK or configure default_disk in config/capsule.php');
        }

        // Validate filesystem disk exists
        try {
            $filesystemDisks = config('filesystems.disks', []);
            if (!isset($filesystemDisks[$diskName])) {
                throw new Exception("Storage disk '{$diskName}' not found in config/filesystems.php. Available disks: " . implode(', ', array_keys($filesystemDisks)));
            }
        } catch (Exception $e) {
            throw new Exception("Failed to validate storage configuration: {$e->getMessage()}");
        }

        // Check database configuration if enabled
        if ($dbEnabled) {
            $connections = config('capsule.database.connections');
            
            // Auto-detect current database if not specified
            if ($connections === null) {
                $connections = [config('database.default')];
            } else {
                $connections = is_string($connections) ? [$connections] : $connections;
            }
            
            foreach ($connections as $connection) {
                // Resolve 'default' to the actual default connection name
                $resolvedConnection = $connection === 'default' ? config('database.default') : $connection;
                $dbConfig = config("database.connections.{$resolvedConnection}");
                if (!$dbConfig) {
                    throw new Exception("Database connection '{$resolvedConnection}' not found in config/database.php");
                }
                
                // Check if required database tools are available
                $driver = $dbConfig['driver'] ?? 'unknown';
                switch ($driver) {
                    case 'mysql':
                    case 'mariadb':
                        if (!Helpers::commandExists('mysqldump') && !Helpers::commandExists('mariadb-dump')) {
                            throw new Exception('Neither mysqldump nor mariadb-dump command found. Install MySQL or MariaDB client tools.');
                        }
                        break;
                    case 'pgsql':
                        if (!Helpers::commandExists('pg_dump')) {
                            throw new Exception('pg_dump command not found. Install PostgreSQL client tools.');
                        }
                        break;
                    case 'sqlite':
                        if (!file_exists($dbConfig['database'])) {
                            throw new Exception("SQLite database file not found: {$dbConfig['database']}");
                        }
                        break;
                }
            }
        }

        // Check file paths if file backup is enabled
        if ($filesEnabled) {
            $paths = config('capsule.files.paths', []);
            if (empty($paths)) {
                throw new Exception('No file paths configured for backup in config/capsule.php');
            }
            
            foreach ($paths as $path) {
                if (!file_exists($path)) {
                    Log::warning("Backup path does not exist: {$path}");
                }
            }
        }
    }

    protected function verifyBackup(string $backupPath): void
    {
        $startTime = microtime(true);
        
        // Check if file exists and is readable
        if (!file_exists($backupPath) || !is_readable($backupPath)) {
            throw new Exception("Backup file verification failed: file not found or not readable");
        }

        // Verify ZIP archive integrity
        $zip = new ZipArchive();
        $result = $zip->open($backupPath, ZipArchive::CHECKCONS);
        
        if ($result !== TRUE) {
            $errorMessage = match($result) {
                ZipArchive::ER_NOZIP => 'Not a valid ZIP archive',
                ZipArchive::ER_INCONS => 'ZIP archive is inconsistent',
                ZipArchive::ER_CRC => 'CRC error in ZIP archive',
                ZipArchive::ER_MEMORY => 'Memory allocation failure',
                ZipArchive::ER_READ => 'Read error',
                default => "ZIP verification failed with code: {$result}"
            };
            throw new Exception("Backup verification failed: {$errorMessage}");
        }

        $numFiles = $zip->numFiles;
        $zip->close();

        $verifyTime = round(microtime(true) - $startTime, 2);
        $this->log("   ✅ Backup verified: {$numFiles} files, integrity check passed ({$verifyTime}s)");
    }

}