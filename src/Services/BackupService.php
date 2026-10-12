<?php

namespace Dgtlss\Capsule\Services;

use Dgtlss\Capsule\Database\DatabaseDumper;
use Dgtlss\Capsule\Database\DumpValidator;
use Dgtlss\Capsule\Services\AuditLogger;
use Dgtlss\Capsule\Models\BackupLog;
use Dgtlss\Capsule\Notifications\NotificationManager;
use Dgtlss\Capsule\Storage\StorageManager;
use Dgtlss\Capsule\Support\BackupContext;
use Dgtlss\Capsule\Support\Helpers;
use Dgtlss\Capsule\Support\FileSelection;
use Dgtlss\Capsule\Support\Lock;
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
    protected bool $incremental = false;
    protected MetricsCollector $metricsCollector;
    protected IncrementalService $incrementalService;
    protected array $currentFileIndex = [];
    protected string $backupType = 'full';
    protected ?int $baseSnapshotId = null;
    protected ?array $baseArchive = null;
    protected array $deletedFiles = [];
    protected ?string $stagingDirectory = null;

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
        $this->metricsCollector = new MetricsCollector();
        $this->incrementalService = new IncrementalService();
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

    public function setIncremental(bool $incremental): void
    {
        $this->incremental = $incremental;
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
        $this->lastRemotePath = null;
        $this->lastFileSize = null;
        $this->backupLogPersisted = false;
        $this->manifestBuilder->reset();
        $this->currentFileIndex = [];
        $this->baseArchive = null;
        $this->deletedFiles = [];
        $this->backupType = 'full';
        $this->baseSnapshotId = null;
        $lock = null;
        try {
            if (!$force && !($lock = Lock::acquire('capsule:backup'))) {
                throw new Exception('Another backup or cleanup operation is already running.');
            }
            return $this->runBackup();
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();
            Log::error('Backup failed: ' . $e->getMessage());
            return false;
        } finally {
            if ($lock) {
                $lock->release();
            }
        }
    }

    protected function runBackup(): bool
    {
        $this->applyPerformanceLimits();
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

        AuditLogger::log('backup.started', [
            'trigger' => 'artisan',
            'status' => 'started',
            'details' => [
                'mode' => $this->incremental ? 'incremental' : 'full',
                'tag' => $this->tag,
                'encryption' => $context->encryption,
            ],
        ]);

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

        $this->metricsCollector = new MetricsCollector();
        $this->metricsCollector->start();

        try {
            $this->log('Creating backup archive...');
            $localBackupPath = $this->createBackup($context);
            $context->localArchivePath = $localBackupPath;
            
            $fileSize = filesize($localBackupPath);
            
            // Never publish a ZIP whose payload differs from its manifest.
            $this->verifyBackup($localBackupPath);

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
            $backupLog->metadata = [
                'schema_version' => 2,
                'storage' => ['disk' => config('capsule.default_disk', 'local'), 'backup_path' => config('capsule.backup_path', 'backups'), 'additional_disks' => (array) config('capsule.additional_disks', [])],
                'policy' => config('capsule._policy_name'),
                'retention' => (array) config('capsule.retention', []),
                'archive_sha256' => hash_file('sha256', $localBackupPath),
                'backup_type' => $this->backupType,
                'base_archive' => $this->baseArchive,
            ];
            $this->lastFileSize = $fileSize;
            if ($this->backupLogPersisted) {
                try {
                    $backupLog->save();
                } catch (\Throwable $e) {
                    Log::warning('Failed to persist success BackupLog update', ['exception' => $e]);
                }
            }

            if ($this->backupLogPersisted) {
                $this->metricsCollector->persist($backupLog);

                $anomalyDetector = new AnomalyDetector();
                $backupLog->load('metric');
                $anomalies = $anomalyDetector->analyze($backupLog);
                if (!empty($anomalies)) {
                    $this->log('Anomalies detected:');
                    foreach ($anomalies as $a) {
                        $this->log("  [{$a['severity']}] {$a['message']}");
                    }
                    $backupLog->metadata = array_merge($backupLog->metadata ?? [], ['anomalies' => $anomalies]);
                    try { $backupLog->save(); } catch (\Throwable $e) { }
                }

                if (config('capsule.files.enabled')) {
                    try {
                        $this->incrementalService->saveSnapshot($backupLog, $this->currentFileIndex, $this->backupType, $this->baseSnapshotId);
                    } catch (\Throwable $e) {
                        Log::warning('Failed to save backup snapshot', ['exception' => $e]);
                    }
                }
            }

            // StorageManager has verified the persisted object; staging is never the destination.
            unlink($localBackupPath);
            if ($this->stagingDirectory) {
                @rmdir($this->stagingDirectory);
            }

            // Run retention cleanup only if DB available
            if ($this->backupLogPersisted) {
                $this->log('🧹 Running retention cleanup...');
                $this->cleanup();
            }
            
            AuditLogger::log('backup.completed', [
                'backup_log_id' => $backupLog->id ?? null,
                'status' => 'completed',
                'details' => [
                    'type' => $this->backupType,
                    'file_size' => $fileSize,
                    'file_path' => $remotePath,
                    'tag' => $this->tag,
                ],
            ]);

            $this->notificationManager->sendSuccessNotification($backupLog);
            event(new BackupSucceeded($context));

            // Execute post-steps (success)
            $this->executeSteps((array) config('capsule.extensibility.post_steps', []), $context, 'post');

            return true;
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();
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

            AuditLogger::log('backup.failed', [
                'backup_log_id' => $backupLog->id ?? null,
                'status' => 'failed',
                'details' => ['error' => $e->getMessage(), 'tag' => $this->tag],
            ]);

            try {
                $this->notificationManager->sendFailureNotification($backupLog, $e instanceof Exception ? $e : new Exception($e->getMessage(), 0, $e));
                event(new BackupFailed($context, $e));
                $this->executeSteps((array) config('capsule.extensibility.post_steps', []), $context, 'post');
            } catch (\Throwable $observerError) {
                Log::warning('Backup failure observer also failed', ['exception' => $observerError]);
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

    protected function createBackup(BackupContext $context): string
    {
        $runId = now()->format('Y-m-d_H-i-s') . '_' . bin2hex(random_bytes(16));
        $this->stagingDirectory = sys_get_temp_dir() . '/capsule-' . bin2hex(random_bytes(16));
        if (!mkdir($this->stagingDirectory, 0700, true)) {
            throw new Exception('Cannot create a private backup staging directory.');
        }
        $backupPath = $this->stagingDirectory . "/backup_{$runId}.zip";
        $zip = new ZipArchive();
        if ($zip->open($backupPath, ZipArchive::CREATE | ZipArchive::EXCL) !== true) {
            throw new Exception('Cannot create backup archive.');
        }
        $closed = false;
        try {
            if (config('capsule.database.enabled')) {
                event(new DatabaseDumpStarting($context));
                $this->addDatabaseToBackup($zip, $runId);
                event(new DatabaseDumpCompleted($context));
            }
            if (config('capsule.files.enabled')) {
                event(new FilesCollectStarting($context));
                $this->currentFileIndex = $this->incrementalService->buildCurrentIndex(true, $context);
                $baseSnapshot = $this->incremental ? $this->incrementalService->getLatestFullSnapshot() : null;
                if ($baseSnapshot) {
                    $diff = $this->incrementalService->diff($this->currentFileIndex, $baseSnapshot);
                    $this->addSpecificFilesToBackup($zip, $this->incrementalService->getChangedFiles($diff));
                    $this->backupType = 'incremental';
                    $this->baseSnapshotId = $baseSnapshot->id;
                    $base = $baseSnapshot->backupLog;
                    $this->baseArchive = [
                        'disk' => $base->metadata['storage']['disk'],
                        'path' => $base->file_path,
                        'sha256' => $base->metadata['archive_sha256'],
                    ];
                    $this->deletedFiles = array_keys($diff['removed']);
                } else {
                    $this->addSpecificFilesToBackup($zip, array_keys($this->currentFileIndex));
                }
                event(new FilesCollectCompleted($context));
            }
            $manifest = $this->manifestBuilder->build(false, $this->compressionLevel, $this->encryptionEnabled());
            $manifest['backup_type'] = $this->backupType;
            $manifest['base_archive'] = $this->baseArchive;
            $manifest['deleted_files'] = $this->deletedFiles;
            if (!$zip->addFromString('manifest.json', ManifestBuilder::encode($manifest))) {
                throw new Exception('Cannot add backup manifest.');
            }
            $this->applyCompressionAndEncryption($zip, 'manifest.json');
            event(new ArchiveFinalizing($context));
            $closed = $zip->close();
            if (!$closed) {
                throw new Exception('Failed to finalize ZIP archive.');
            }
            chmod($backupPath, 0600);
            return $backupPath;
        } catch (\Throwable $e) {
            if (!$closed) {
                @$zip->close();
            }
            @unlink($backupPath);
            throw $e;
        } finally {
            // This private directory contains only this run's database snapshots.
            foreach (glob($this->stagingDirectory . '/db_*') ?: [] as $temporary) {
                @unlink($temporary);
            }
            if (!file_exists($backupPath)) {
                @rmdir($this->stagingDirectory);
            }
        }
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
            $dumpPath = $this->stagingDirectory . '/db_' . hash('sha256', $connection) . '.sql';
            $this->databaseDumper->dump($connection, $dumpPath);

            $driver = config("database.connections.{$connection}.driver", 'unknown');
            DumpValidator::validate($dumpPath, $driver);

            $dumpSize = filesize($dumpPath);
            $entryName = 'database/' . $connection . ($driver === 'sqlite' ? '.sqlite' : '.sql');
            $this->log("   Adding {$entryName} (" . Helpers::formatBytes($dumpSize) . ") to archive");
            if (!$zip->addFile($dumpPath, $entryName)) {
                throw new Exception("Cannot add database dump: {$connection}");
            }
            $this->applyCompressionAndEncryption($zip, $entryName);
            $this->manifestBuilder->addStreamEntry($entryName, $dumpSize, hash_file('sha256', $dumpPath), [
                'connection' => $connection, 'driver' => $driver, 'format' => $driver === 'sqlite' ? 'sqlite' : 'sql',
            ]);
            $this->metricsCollector->addDatabaseDump($dumpSize);
            $tempFiles[] = $dumpPath;
        }

        return $tempFiles;
    }

    protected function addDatabasesParallel(ZipArchive $zip, array $connections, string $timestamp): array
    {
        $processes = [];
        $tempFiles = [];
        try {
            foreach ($connections as $connection) {
                $dumpPath = $this->stagingDirectory . '/db_' . hash('sha256', $connection) . '.sql';
                $tempFiles[] = $dumpPath;
                $process = $this->databaseDumper->startDumpProcess($connection, $dumpPath);
                if ($process !== null && !is_resource($process)) {
                    throw new Exception("Cannot start database dump: {$connection}");
                }
                $processes[$connection] = ['process' => $process, 'path' => $dumpPath];
            }
            foreach ($processes as $connection => &$info) {
                if (is_resource($info['process'])) {
                    $code = proc_close($info['process']);
                    $info['process'] = null;
                    if ($code !== 0) {
                        throw new Exception("Parallel database dump failed: {$connection} (exit {$code})");
                    }
                }
                DumpValidator::validate($info['path'], config("database.connections.{$connection}.driver", 'unknown'));
                $driver = config("database.connections.{$connection}.driver", 'unknown');
                $entry = 'database/' . $connection . ($driver === 'sqlite' ? '.sqlite' : '.sql');
                if (!$zip->addFile($info['path'], $entry)) {
                    throw new Exception("Cannot add database dump: {$connection}");
                }
                $this->applyCompressionAndEncryption($zip, $entry);
                $this->manifestBuilder->addStreamEntry($entry, filesize($info['path']), hash_file('sha256', $info['path']), [
                    'connection' => $connection, 'driver' => $driver, 'format' => $driver === 'sqlite' ? 'sqlite' : 'sql',
                ]);
                $this->metricsCollector->addDatabaseDump(filesize($info['path']));
            }
            unset($info);
            return $tempFiles;
        } finally {
            unset($info);
            foreach ($processes as $info) {
                if (is_resource($info['process'])) {
                    proc_close($info['process']);
                }
            }
        }
    }

    protected function addSpecificFilesToBackup(ZipArchive $zip, array $archivePaths): void
    {
        foreach ($archivePaths as $archivePath) {
            $entry = $this->currentFileIndex[$archivePath];
            $source = $entry['source'];
            if (!is_file($source) || !$zip->addFile($source, $archivePath)) {
                throw new Exception("Cannot add backup file: {$source}");
            }
            $this->applyCompressionAndEncryption($zip, $archivePath);
            $this->manifestBuilder->addStreamEntry($archivePath, $entry['size'], $entry['hash']);
            $this->metricsCollector->addFile($source, $entry['size']);
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

    protected function encryptionEnabled(): bool
    {
        return $this->encryption || (bool) config('capsule.security.encrypt_backups', false);
    }

    protected function applyCompressionAndEncryption(ZipArchive $zip, string $entryName): void
    {
        if (!$zip->setCompressionName($entryName, ZipArchive::CM_DEFLATE, $this->compressionLevel)) {
            throw new Exception("Cannot compress archive entry: {$entryName}");
        }
        if ($this->encryptionEnabled()) {
            $password = config('capsule.security.backup_password') ?? env('CAPSULE_BACKUP_PASSWORD');
            if (!is_string($password) || $password === '' || str_contains($password, "\0") || !$zip->setPassword($password) ||
                !$zip->setEncryptionName($entryName, ZipArchive::EM_AES_256)) {
                throw new Exception("Cannot encrypt archive entry: {$entryName}");
            }
        }
    }

    protected function shouldExcludePath(string $path, array $excludePaths): bool
    {
        $normalizedPath = rtrim($path, '/');

        foreach ($excludePaths as $excludePath) {
            $normalizedExcludePath = rtrim($excludePath, '/');

            if ($normalizedPath === $normalizedExcludePath) {
                return true;
            }

            if (strpos($normalizedPath, $normalizedExcludePath . '/') === 0) {
                return true;
            }

            // Only match on basename when the exclude entry itself has no
            // directory separators (i.e., it's a filename-only pattern like
            // ".DS_Store" or "Thumbs.db"), not a full path like "/var/www/vendor".
            if (!str_contains($excludePath, '/')) {
                if (basename($path) === $excludePath) {
                    return true;
                }
            }
        }

        return false;
    }


    protected function cleanup(): void
    {
        if (config('capsule.retention.cleanup_enabled', true)) {
            $result = (new RetentionService())->cleanup(['scope' => RetentionService::currentScope()]);
            foreach ($result['errors'] ?? [] as $error) {
                Log::warning('Retention cleanup failed', ['error' => $error]);
            }
        }
    }

    protected function validateConfiguration(): void
    {
        if ($this->encryptionEnabled()) {
            $password = config('capsule.security.backup_password') ?? env('CAPSULE_BACKUP_PASSWORD');
            if (!is_string($password) || $password === '') {
                throw new Exception('Backup encryption requires a non-empty password.');
            }
            if (str_contains($password, "\0")) {
                throw new Exception('Backup encryption passwords cannot contain NUL bytes.');
            }
            if (!method_exists(ZipArchive::class, 'setEncryptionName') || !defined('ZipArchive::EM_AES_256') ||
                (method_exists(ZipArchive::class, 'isEncryptionMethodSupported') && !ZipArchive::isEncryptionMethodSupported(ZipArchive::EM_AES_256, true))) {
                throw new Exception('AES-256 ZIP encryption is not supported by this PHP/libzip installation.');
            }
        }
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
        $zip = new ZipArchive();
        if ($zip->open($backupPath, ZipArchive::CHECKCONS) !== true) {
            throw new Exception('Backup ZIP integrity check failed.');
        }
        try {
            if ($this->encryptionEnabled()) {
                $zip->setPassword(config('capsule.security.backup_password') ?? env('CAPSULE_BACKUP_PASSWORD'));
            }
            $manifest = json_decode($zip->getFromName('manifest.json') ?: '', true, 512, JSON_THROW_ON_ERROR);
            foreach ($manifest['entries'] as $entry) {
                $stream = $zip->getStream($entry['path']);
                if ($stream === false) {
                    throw new Exception("Cannot read archived entry: {$entry['path']}");
                }
                try {
                    $hash = hash_init('sha256');
                    $size = hash_update_stream($hash, $stream);
                    if ($size !== $entry['size'] || !hash_equals($entry['sha256'], hash_final($hash))) {
                        throw new Exception("Backup file changed during capture: {$entry['path']}");
                    }
                } finally {
                    fclose($stream);
                }
            }
        } finally {
            $zip->close();
        }
    }

    protected function registerSignalHandlers(): void
    {
        if (!function_exists('pcntl_signal')) {
            return;
        }

        $handler = function (int $signal) {
            $this->log("Received signal {$signal}, aborting backup...");
            Log::warning("Capsule backup interrupted by signal {$signal}");
            throw new Exception("Backup interrupted by signal {$signal}");
        };

        pcntl_signal(SIGTERM, $handler);
        pcntl_signal(SIGINT, $handler);
    }

    protected function applyPerformanceLimits(): void
    {
        $memoryLimit = config('capsule.performance.memory_limit');
        if ($memoryLimit && $memoryLimit > 0) {
            @ini_set('memory_limit', $memoryLimit . 'M');
        }

        $maxTime = config('capsule.performance.max_execution_time');
        if ($maxTime !== null) {
            @set_time_limit((int) $maxTime);
        }
    }
}
