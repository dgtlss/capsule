<?php

namespace Dgtlss\Capsule\Services;

use Dgtlss\Capsule\Models\BackupLog;
use Dgtlss\Capsule\Storage\StorageManager;
use Dgtlss\Capsule\Notifications\NotificationManager;
use Dgtlss\Capsule\Support\Formatters;
use Dgtlss\Capsule\Support\BackupContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use ZipArchive;
use Exception;
use Throwable;

class RestoreService
{
    protected StorageManager $storageManager;
    protected NotificationManager $notificationManager;
    protected bool $verbose = false;
    protected bool $dryRun = false;
    protected bool $dbOnly = false;
    protected bool $filesOnly = false;
    protected ?string $targetConnection = null;
    protected ?string $lastError = null;
    protected $outputCallback = null;

    public function __construct()
    {
        $this->storageManager = new StorageManager();
        $this->notificationManager = new NotificationManager();
    }

    public function setVerbose(bool $verbose): void
    {
        $this->verbose = $verbose;
    }

    public function setDryRun(bool $dryRun): void
    {
        $this->dryRun = $dryRun;
    }

    public function setDbOnly(bool $dbOnly): void
    {
        $this->dbOnly = $dbOnly;
    }

    public function setFilesOnly(bool $filesOnly): void
    {
        $this->filesOnly = $filesOnly;
    }

    public function setTargetConnection(?string $connection): void
    {
        $this->targetConnection = $connection;
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

    public function restoreById(int|string $id, array $options = []): bool
    {
        $backup = BackupLog::findOrFail($id);

        if ($backup->status !== 'success') {
            $this->lastError = "Cannot restore backup with status: {$backup->status}";
            $this->log('❌ ' . $this->lastError);
            return false;
        }

        return $this->restoreFromBackup($backup, $options);
    }

    public function restoreLatest(array $options = []): bool
    {
        $backup = BackupLog::where('status', 'success')
            ->orderBy('created_at', 'desc')
            ->first();

        if (!$backup) {
            $this->lastError = 'No successful backups found';
            $this->log('❌ ' . $this->lastError);
            return false;
        }

        $this->log('📦 Restoring from latest backup: ' . $backup->file_path);
        return $this->restoreFromBackup($backup, $options);
    }

    protected function restoreFromBackup(BackupLog $backup, array $options = []): bool
    {
        $this->log('🔍 Preparing restore from backup #' . $backup->id);
        $this->log('📂 Backup file: ' . $backup->file_path);
        $this->log('📅 Created at: ' . $backup->created_at->format('Y-m-d H:i:s'));
        $this->log('💾 Size: ' . Formatters::bytes($backup->file_size ?? 0));

        $context = new BackupContext('restore');
        $context->backupLog = $backup;
        $context->dryRun = $this->dryRun;

        try {
            $localPath = $this->downloadBackup($backup);
            
            if ($this->dryRun) {
                $this->log('⚠️  Dry run mode: No changes will be made');
                $this->previewRestore($localPath);
                return true;
            }

            $result = $this->performRestore($localPath, $context);
            
            if ($result) {
                $this->log('✅ Restore completed successfully');
                $this->notificationManager->sendRestoreSuccessNotification($backup, $context);
                return true;
            }

            return false;
        } catch (Throwable $e) {
            $this->lastError = $e->getMessage();
            $this->log('❌ Restore failed: ' . $e->getMessage());
            Log::error('Restore failed', [
                'backup_id' => $backup->id,
                'exception' => $e,
            ]);
            $this->notificationManager->sendRestoreFailureNotification($backup, $e);
            return false;
        } finally {
            if (isset($localPath) && file_exists($localPath)) {
                $this->log('🧹 Cleaning up local backup file...');
                unlink($localPath);
            }
        }
    }

    protected function downloadBackup(BackupLog $backup): string
    {
        $fileName = basename($backup->file_path);
        $localPath = storage_path('app/backups/restore_' . $fileName);

        $this->log('📥 Downloading backup from storage...');
        $this->storageManager->download($fileName, $localPath);

        if (!file_exists($localPath)) {
            throw new Exception("Failed to download backup: {$localPath}");
        }

        $this->log('✅ Backup downloaded: ' . Formatters::bytes(filesize($localPath)));
        return $localPath;
    }

    protected function previewRestore(string $backupPath): void
    {
        $zip = new ZipArchive();
        if ($zip->open($backupPath) !== true) {
            throw new Exception('Cannot open backup archive');
        }

        $manifest = $this->extractManifest($zip);
        
        $this->log('');
        $this->log('📋 Restore Preview:');
        $this->log('   App: ' . ($manifest['app']['name'] ?? 'Unknown'));
        $this->log('   Environment: ' . ($manifest['app']['env'] ?? 'Unknown'));
        $this->log('   Laravel Version: ' . ($manifest['app']['laravel_version'] ?? 'Unknown'));
        
        if (isset($manifest['database']['connections'])) {
            $connections = implode(', ', $manifest['database']['connections']);
            $this->log('   Databases to restore: ' . $connections);
        }
        
        if (isset($manifest['entries'])) {
            $dbFiles = collect($manifest['entries'])->filter(fn($e) => str_starts_with($e['path'], 'database/'))->count();
            $fileCount = collect($manifest['entries'])->filter(fn($e) => str_starts_with($e['path'], 'files/'))->count();
            $this->log('   Database files: ' . $dbFiles);
            $this->log('   Files to restore: ' . $fileCount);
        }

        $zip->close();
    }

    protected function performRestore(string $backupPath, BackupContext $context): bool
    {
        $zip = new ZipArchive();
        if ($zip->open($backupPath) !== true) {
            throw new Exception('Cannot open backup archive');
        }

        $manifest = $this->extractManifest($zip);
        
        $this->log('🔍 Validating backup manifest...');
        if (!$this->validateManifest($manifest)) {
            return false;
        }

        $restoreDb = !$this->filesOnly;
        $restoreFiles = !$this->dbOnly;

        if ($restoreDb) {
            $this->log('🗄️  Restoring database...');
            if (!$this->restoreDatabase($zip, $manifest)) {
                return false;
            }
        }

        if ($restoreFiles) {
            $this->log('📁 Restoring files...');
            if (!$this->restoreFiles($zip, $manifest)) {
                return false;
            }
        }

        $zip->close();
        return true;
    }

    protected function extractManifest(ZipArchive $zip): array
    {
        $manifestIndex = $zip->locateName('manifest.json');
        if ($manifestIndex === false) {
            throw new Exception('Backup manifest not found');
        }

        $manifestJson = $zip->getFromIndex($manifestIndex);
        $manifest = json_decode($manifestJson, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('Invalid manifest JSON: ' . json_last_error_msg());
        }

        return $manifest;
    }

    protected function validateManifest(array $manifest): bool
    {
        $required = ['schema_version', 'generated_at', 'app', 'capsule'];
        foreach ($required as $key) {
            if (!isset($manifest[$key])) {
                $this->lastError = "Invalid manifest: missing '{$key}'";
                $this->log('❌ ' . $this->lastError);
                return false;
            }
        }

        $appEnv = $manifest['app']['env'] ?? null;
        $currentEnv = app()->environment();

        if ($appEnv !== $currentEnv) {
            $this->log('⚠️  Warning: Backup environment (' . $appEnv . ') differs from current (' . $currentEnv . ')');
        }

        return true;
    }

    protected function restoreDatabase(ZipArchive $zip, array $manifest): bool
    {
        $connections = $manifest['database']['connections'] ?? [];
        
        foreach ($connections as $connection) {
            $entryName = "database/{$connection}.sql";
            $index = $zip->locateName($entryName);

            if ($index === false) {
                $this->log('⚠️  Database dump not found: ' . $entryName);
                continue;
            }

            $targetConnection = $this->targetConnection ?? $connection;
            $this->log('   📥 Restoring database: ' . $targetConnection);

            $sqlContent = $zip->getFromIndex($index);
            $tempSqlFile = tempnam(sys_get_temp_dir(), 'restore_') . '.sql';
            file_put_contents($tempSqlFile, $sqlContent);

            try {
                DB::connection($targetConnection)->statement('SET FOREIGN_KEY_CHECKS=0');
                
                $config = config("database.connections.{$targetConnection}");
                $driver = $config['driver'] ?? 'mysql';

                switch ($driver) {
                    case 'mysql':
                    case 'mariadb':
                        $this->importMysqlDump($config, $tempSqlFile);
                        break;
                    case 'pgsql':
                        $this->importPostgresDump($config, $tempSqlFile);
                        break;
                    case 'sqlite':
                        $this->importSqliteDump($config, $tempSqlFile);
                        break;
                    default:
                        throw new Exception("Unsupported database driver: {$driver}");
                }

                DB::connection($targetConnection)->statement('SET FOREIGN_KEY_CHECKS=1');
                $this->log('   ✅ Database restored: ' . $targetConnection);
            } finally {
                if (file_exists($tempSqlFile)) {
                    unlink($tempSqlFile);
                }
            }
        }

        return true;
    }

    protected function importMysqlDump(array $config, string $sqlFile): void
    {
        $db = $config['database'];
        $command = sprintf(
            'mysql %s %s < %s 2>&1',
            !empty($config['host']) ? '-h ' . escapeshellarg($config['host']) : '',
            escapeshellarg($db),
            escapeshellarg($sqlFile)
        );

        $output = [];
        $returnCode = 0;
        exec($command, $output, $returnCode);

        if ($returnCode !== 0) {
            throw new Exception("MySQL import failed: " . implode("\n", $output));
        }
    }

    protected function importPostgresDump(array $config, string $sqlFile): void
    {
        $command = sprintf(
            'psql --host=%s --port=%s --username=%s --dbname=%s < %s 2>&1',
            escapeshellarg($config['host']),
            escapeshellarg($config['port'] ?? 5432),
            escapeshellarg($config['username']),
            escapeshellarg($config['database']),
            escapeshellarg($sqlFile)
        );

        $output = [];
        $returnCode = 0;
        exec($command, $output, $returnCode);

        if ($returnCode !== 0) {
            throw new Exception("PostgreSQL import failed: " . implode("\n", $output));
        }
    }

    protected function importSqliteDump(array $config, string $sqlFile): void
    {
        $targetDb = $config['database'];
        if (file_exists($targetDb)) {
            backup($targetDb);
        }
        
        if (!copy($sqlFile, $targetDb)) {
            throw new Exception("Failed to restore SQLite database to: {$targetDb}");
        }
    }

    protected function restoreFiles(ZipArchive $zip, array $manifest): bool
    {
        $baseDir = base_path();
        $filesToRestore = collect($manifest['entries'] ?? [])
            ->filter(fn($e) => str_starts_with($e['path'], 'files/'))
            ->values();

        $this->log('   📊 Found ' . $filesToRestore->count() . ' files to restore');

        foreach ($filesToRestore as $entry) {
            $zipPath = $entry['path'];
            $targetPath = $baseDir . '/' . substr($zipPath, 6);

            $this->log('   📄 Restoring: ' . substr($zipPath, 6));

            $targetDir = dirname($targetPath);
            if (!is_dir($targetDir)) {
                mkdir($targetDir, 0755, true);
            }

            $content = $zip->getFromName($zipPath);
            if ($content === false) {
                $this->log('   ⚠️  Failed to extract: ' . $zipPath);
                continue;
            }

            file_put_contents($targetPath, $content);
        }

        $this->log('   ✅ Files restored');
        return true;
    }
}
