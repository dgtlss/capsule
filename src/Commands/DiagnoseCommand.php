<?php

namespace Dgtlss\Capsule\Commands;

use Dgtlss\Capsule\Models\BackupLog;
use Dgtlss\Capsule\Support\Helpers;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class DiagnoseCommand extends Command
{
    protected $signature = 'capsule:diagnose 
    {--detailed : Show detailed performance and security analysis}
    {--fix : Attempt to fix common issues automatically}
    {--format=table : Output format (table, json)}';
    
    protected $description = 'Diagnose Capsule configuration and system requirements';

    public function handle(): int
    {
        $detailed = $this->option('detailed');
        $format = $this->option('format');
        $fix = $this->option('fix');
        $verbose = true;

        if ($format === 'json') {
            return $this->handleJsonOutput($detailed, $fix);
        }

        $this->info('🔍 Capsule Configuration Diagnosis');
        $this->newLine();

        $results = [];
        
        // Core checks
        $results['basic'] = $this->checkBasicConfig($fix, $verbose);
        $this->newLine();

        $results['storage'] = $this->checkStorageConfig($fix, $verbose);
        $this->newLine();

        $results['database'] = $this->checkDatabaseConfig($fix, $verbose);
        $this->newLine();

        $results['files'] = $this->checkFileConfig($fix, $verbose);
        $this->newLine();

        $results['system'] = $this->checkSystemRequirements($fix, $verbose);
        $this->newLine();

        $results['health'] = $this->checkHealthSummary($verbose);
        $this->newLine();

        // Additional detailed checks
        if ($detailed) {
            $results['performance'] = $this->checkPerformance($verbose);
            $this->newLine();

            $results['security'] = $this->checkSecurity($verbose);
            $this->newLine();

            $results['backup_history'] = $this->checkBackupHistory($verbose);
            $this->newLine();
        }

        $allGood = collect($results)->every(fn($result) => $result);

        if ($allGood) {
            $this->info('✅ All checks passed! Capsule should work correctly.');
        } else {
            $this->error('❌ Some issues were found. Fix the above errors before running backups.');
        }

        return $allGood ? self::SUCCESS : self::FAILURE;
    }

    protected function handleJsonOutput(bool $detailed, bool $fix): int
    {
        $checks = [
            'basic' => fn () => $this->checkBasicConfig($fix, false),
            'storage' => fn () => $this->checkStorageConfig($fix, false),
            'database' => fn () => $this->checkDatabaseConfig($fix, false),
            'files' => fn () => $this->checkFileConfig($fix, false),
            'system' => fn () => $this->checkSystemRequirements($fix, false),
            'health' => fn () => $this->checkHealthSummary(false),
        ];
        if ($detailed) {
            $checks += [
                'performance' => fn () => $this->checkPerformance(false),
                'security' => fn () => $this->checkSecurity(false),
                'backup_history' => fn () => $this->checkBackupHistory(false),
            ];
        }

        $results = ['timestamp' => now()->toISOString(), 'checks' => [], 'errors' => []];
        foreach ($checks as $name => $check) {
            try {
                $results['checks'][$name] = $check();
            } catch (\Throwable $e) {
                $results['checks'][$name] = false;
                // Adapter/database exceptions may contain credentials or other
                // private configuration. Do not serialize their messages.
                $results['errors'][$name] = 'Check could not complete; inspect the configuration for this check.';
            }
        }
        $results['overall_status'] = in_array(false, $results['checks'], true) ? 'failed' : 'passed';
        $this->line(json_encode($results, JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE));

        return $results['overall_status'] === 'passed' ? self::SUCCESS : self::FAILURE;
    }

    protected function checkBasicConfig(bool $fix = false, bool $verbose = true): bool
    {
        if ($verbose) {
            $this->line('📋 Basic Configuration');
        }
        
        $configExists = file_exists(config_path('capsule.php'));
        
        if ($configExists) {
            if ($verbose) $this->info('  ✅ Config file exists');
        } else {
            if ($verbose) $this->error('  ❌ Config file missing. Run: php artisan vendor:publish --tag=capsule-config');
            if ($fix) {
                if ($verbose) $this->info('  🔧 Attempting to publish config...');
                $verbose ? $this->call('vendor:publish', ['--tag' => 'capsule-config']) : $this->callSilent('vendor:publish', ['--tag' => 'capsule-config']);
                $configExists = file_exists(config_path('capsule.php'));
                if ($configExists && $verbose) {
                    $this->info('  ✅ Config file published successfully');
                }
            }
            if (!$configExists) return false;
        }

        $dbEnabled = config('capsule.database.enabled', true);
        $filesEnabled = config('capsule.files.enabled', true);

        if ($dbEnabled || $filesEnabled) {
            if ($verbose) $this->info('  ✅ At least one backup type enabled');
        } else {
            if ($verbose) $this->error('  ❌ Both database and file backups are disabled');
            return false;
        }

        return true;
    }

    protected function checkStorageConfig(bool $fix = false, bool $verbose = true): bool
    {
        if ($verbose) {
            $this->line('💾 Storage Configuration');
        }
        
        $diskName = config('capsule.default_disk', 'local');
        $backupPath = config('capsule.backup_path', 'backups');

        if ($verbose) $this->info("  📁 Using disk: {$diskName}");
        if ($verbose) $this->info("  📂 Backup path: {$backupPath}");

        try {
            $filesystemDisks = config('filesystems.disks', []);
            
            if (!isset($filesystemDisks[$diskName])) {
                $available = implode(', ', array_keys($filesystemDisks));
                if ($verbose) $this->error("  ❌ Disk '{$diskName}' not found in filesystems.php");
                if ($verbose) $this->error("  Available disks: {$available}");
                return false;
            }

            if ($verbose) $this->info('  ✅ Storage disk configuration found');

            // Test storage access
            try {
                $disk = Storage::disk($diskName);
                $testFile = $backupPath . '/test_' . uniqid() . '.txt';
                $disk->put($testFile, 'test');
                
                if ($disk->exists($testFile)) {
                    $disk->delete($testFile);
                    if ($verbose) $this->info('  ✅ Storage disk is writable');
                } else {
                    if ($verbose) $this->error('  ❌ Failed to write test file to storage');
                    return false;
                }
            } catch (\Exception $e) {
                if ($verbose) $this->error("  ❌ Storage test failed: {$e->getMessage()}");
                return false;
            }

        } catch (\Exception $e) {
            if ($verbose) $this->error("  ❌ Storage configuration error: {$e->getMessage()}");
            return false;
        }

        return true;
    }

    protected function checkDatabaseConfig(bool $fix = false, bool $verbose = true): bool
    {
        if ($verbose) $this->line('🗄️  Database Configuration');
        
        $dbEnabled = config('capsule.database.enabled', true);
        
        if (!$dbEnabled) {
            if ($verbose) $this->comment('  ⏭️  Database backup disabled');
            return true;
        }

        $connections = config('capsule.database.connections');
        
        // Auto-detect current database if not specified
        if ($connections === null) {
            $connections = [config('database.default')];
        } else {
            $connections = is_string($connections) ? [$connections] : $connections;
        }

        $allGood = true;

        foreach ($connections as $connection) {
            if ($verbose) $this->info("  🔗 Checking connection: {$connection}");
            
            // Resolve 'default' to the actual default connection name
            $resolvedConnection = $connection === 'default' ? config('database.default') : $connection;
            $dbConfig = config("database.connections.{$resolvedConnection}");
            if (!$dbConfig) {
                if ($verbose) $this->error("    ❌ Connection '{$resolvedConnection}' not found");
                $allGood = false;
                continue;
            }

            $driver = $dbConfig['driver'] ?? 'unknown';
            if ($verbose) $this->info("    📊 Driver: {$driver}");

            // Check database tools
            switch ($driver) {
                case 'mysql':
                    if (Helpers::commandExists('mysqldump') || Helpers::commandExists('mariadb-dump')) {
                        if ($verbose) $this->info('    ✅ mysqldump/mariadb-dump available');
                    } else {
                        if ($verbose) $this->error('    ❌ mysqldump/mariadb-dump not found');
                        $allGood = false;
                    }
                    break;
                case 'pgsql':
                    if (Helpers::commandExists('pg_dump')) {
                        if ($verbose) $this->info('    ✅ pg_dump available');
                    } else {
                        if ($verbose) $this->error('    ❌ pg_dump not found');
                        $allGood = false;
                    }
                    break;
                case 'sqlite':
                    $dbPath = $dbConfig['database'] ?? '';
                    if (file_exists($dbPath)) {
                        if ($verbose) $this->info('    ✅ SQLite database file exists');
                    } else {
                        if ($verbose) $this->error("    ❌ SQLite file not found: {$dbPath}");
                        $allGood = false;
                    }
                    break;
                default:
                    if ($verbose) $this->comment("    ⚠️  Unsupported driver: {$driver}");
                    break;
            }

            // Test database connection
            if ($fix && in_array($driver, ['mysql', 'pgsql'])) {
                // Defer actual connection test; inform about graceful degrade
                if ($verbose) $this->comment('    ℹ️  Will gracefully continue without DB logging if connection fails at runtime');
            } else {
                try {
                    \DB::connection($resolvedConnection)->getPdo();
                    if ($verbose) $this->info('    ✅ Database connection successful');
                } catch (\Exception $e) {
                    if ($verbose) $this->error("    ❌ Database connection failed: {$e->getMessage()}");
                    $allGood = false;
                }
            }
        }

        return $allGood;
    }

    protected function checkFileConfig(bool $fix = false, bool $verbose = true): bool
    {
        if ($verbose) $this->line('📁 File Backup Configuration');
        
        $filesEnabled = config('capsule.files.enabled', true);
        
        if (!$filesEnabled) {
            if ($verbose) $this->comment('  ⏭️  File backup disabled');
            return true;
        }

        $paths = config('capsule.files.paths', []);
        $excludePaths = config('capsule.files.exclude_paths', []);

        if (empty($paths)) {
            if ($verbose) $this->error('  ❌ No file paths configured');
            return false;
        }

        if ($verbose) $this->info('  📂 Checking backup paths:');
        $allGood = true;

        foreach ($paths as $path) {
            if (file_exists($path)) {
                $size = $this->getDirectorySize($path);
                if ($verbose) $this->info("    ✅ {$path} ({$size})");
            } else {
                if ($verbose) $this->error("    ❌ {$path} (not found)");
                $allGood = false;
            }
        }

        if (!empty($excludePaths)) {
            if ($verbose) $this->info('  🚫 Exclude paths:');
            foreach ($excludePaths as $path) {
                if ($verbose) $this->comment("    ⏭️  {$path}");
            }
        }

        return $allGood;
    }

    protected function checkSystemRequirements(bool $fix = false, bool $verbose = true): bool
    {
        if ($verbose) $this->line('⚙️  System Requirements');
        
        $allGood = true;

        // Check PHP version
        $phpVersion = PHP_VERSION;
        if (version_compare($phpVersion, '8.2', '>=')) {
            if ($verbose) $this->info("  ✅ PHP version: {$phpVersion}");
        } else {
            if ($verbose) $this->error("  ❌ PHP 8.2+ required, found: {$phpVersion}");
            $allGood = false;
        }

        // Check required extensions
        $extensions = ['zip', 'json', 'zlib', 'sodium'];
        foreach ($extensions as $ext) {
            if (extension_loaded($ext)) {
                if ($verbose) $this->info("  ✅ {$ext} extension loaded");
            } else {
                if ($verbose) $this->error("  ❌ {$ext} extension missing");
                $allGood = false;
            }
        }

        // Check directory permissions
        $storageDir = storage_path('app');
        if (is_writable($storageDir)) {
            if ($verbose) $this->info("  ✅ Storage directory writable: {$storageDir}");
        } else {
            if ($verbose) $this->error("  ❌ Storage directory not writable: {$storageDir}");
            $allGood = false;
        }

        return $allGood;
    }

    protected function checkPerformance(bool $verbose = true): bool
    {
        if ($verbose) {
            $this->line('⚡ Performance Analysis');
        }

        $issues = [];

        // Check compression level
        $compressionLevel = config('capsule.backup.compression_level', 6);
        if ($compressionLevel > 6) {
            if ($verbose) $this->warn("  ⚠️  High compression level ({$compressionLevel}) may slow backups");
            $issues[] = 'compression_level_high';
        } else {
            if ($verbose) $this->info("  ✅ Compression level optimized for speed ({$compressionLevel})");
        }

        // Check if chunked backup is configured for large datasets
        $paths = config('capsule.files.paths', []);
        $estimatedSize = 0;
        foreach ($paths as $path) {
            if (is_dir($path)) {
                $estimatedSize += $this->getDirectorySizeBytes($path);
            }
        }

        if ($estimatedSize > 5 * 1024 * 1024 * 1024) { // 5GB
            if ($verbose) $this->warn("  ⚠️  Large dataset detected ({Helpers::formatBytes($estimatedSize)}). Consider using --no-local flag");
            $issues[] = 'large_dataset';
        }

        // Check storage disk type
        $diskName = config('capsule.default_disk', 'local');
        $diskConfig = config("filesystems.disks.{$diskName}", []);
        $driver = $diskConfig['driver'] ?? 'unknown';
        
        if ($driver === 'local') {
            if ($verbose) $this->comment('  💡 Consider using cloud storage (S3, etc.) for better reliability');
        } else {
            if ($verbose) $this->info("  ✅ Using cloud storage ({$driver})");
        }

        return empty($issues);
    }

    protected function checkSecurity(bool $verbose = true): bool
    {
        if ($verbose) {
            $this->line('🔒 Security Analysis');
        }

        $issues = [];

        // Check if backup encryption is configured
        $encryptionPassword = config('capsule.backup_password') ?? env('CAPSULE_BACKUP_PASSWORD');
        if ($encryptionPassword) {
            if ($verbose) $this->info('  ✅ Backup encryption configured');
        } else {
            if ($verbose) $this->warn('  ⚠️  No backup encryption configured. Set CAPSULE_BACKUP_PASSWORD');
            $issues[] = 'no_encryption';
        }

        // Check if sensitive files are excluded
        $excludePaths = config('capsule.files.exclude_paths', []);
        $sensitivePatterns = ['.env', '.env.production', '.env.staging', 'storage/logs'];
        $protectedFiles = 0;
        foreach ($sensitivePatterns as $pattern) {
            foreach ($excludePaths as $excludePath) {
                if (str_contains($excludePath, $pattern)) {
                    $protectedFiles++;
                    break;
                }
            }
        }

        if ($protectedFiles >= 3) {
            if ($verbose) $this->info('  ✅ Sensitive files properly excluded');
        } else {
            if ($verbose) $this->warn('  ⚠️  Some sensitive files may not be excluded (.env, logs, etc.)');
            $issues[] = 'sensitive_files_included';
        }

        // Check storage disk security
        $diskName = config('capsule.default_disk', 'local');
        $diskConfig = config("filesystems.disks.{$diskName}", []);
        
        if (isset($diskConfig['visibility']) && $diskConfig['visibility'] === 'private') {
            if ($verbose) $this->info('  ✅ Storage visibility set to private');
        } else {
            if ($verbose) $this->warn('  ⚠️  Storage visibility not explicitly set to private');
            $issues[] = 'storage_not_private';
        }

        return empty($issues);
    }

    protected function checkHealthSummary(bool $verbose = true): bool
    {
        if ($verbose) $this->line('❤️  Capsule Health Summary');
        try {
            $age = \Dgtlss\Capsule\Health\Checks\BackupHealthCheck::lastSuccessAgeDays();
            $failures = \Dgtlss\Capsule\Health\Checks\BackupHealthCheck::recentFailuresCount();
            $usage = \Dgtlss\Capsule\Health\Checks\BackupHealthCheck::storageUsageBytes();

            if ($verbose) $this->info('  Last success age: ' . ($age === null ? 'none' : ($age . ' day(s)')));
            if ($failures > 0) {
                if ($verbose) $this->warn("  Recent failures (7d): {$failures}");
            } else {
                if ($verbose) $this->info('  Recent failures (7d): 0');
            }
            if ($verbose) $this->info('  Storage usage: ' . Helpers::formatBytes($usage));

            $staleTimeout = (int) config('capsule.lock.timeout_seconds', 900);
            $staleRunning = BackupLog::running()
                ->where('started_at', '<', now()->subSeconds($staleTimeout))
                ->count();
            if ($staleRunning > 0) {
                if ($verbose) $this->warn("  Stale 'running' backups: {$staleRunning} (started > " . ($staleTimeout / 60) . "min ago)");
                if ($verbose) $this->comment("  Run: capsule:cleanup --failed to clean these up");
            }

            return true;
        } catch (\Throwable $e) {
            if ($verbose) $this->error('  ❌ Failed to compute health summary: ' . $e->getMessage());
            return false;
        }
    }

    protected function checkBackupHistory(bool $verbose = true): bool
    {
        if ($verbose) {
            $this->line('📊 Backup History Analysis');
        }

        $recentBackups = BackupLog::where('created_at', '>=', now()->subDays(30))->get();
        $successfulBackups = $recentBackups->where('status', 'success');
        $failedBackups = $recentBackups->where('status', 'failed');

        if ($verbose) {
            $this->info("  📈 Last 30 days: {$recentBackups->count()} total backups");
            $this->info("  ✅ Successful: {$successfulBackups->count()}");
            
            if ($failedBackups->count() > 0) {
                $this->warn("  ❌ Failed: {$failedBackups->count()}");
            }
        }

        // Check backup frequency
        if ($successfulBackups->count() === 0) {
            if ($verbose) $this->error('  ❌ No successful backups in the last 30 days');
            return false;
        }

        $lastBackup = $successfulBackups->sortByDesc('created_at')->first();
        $daysSinceLastBackup = now()->diffInDays($lastBackup->created_at);
        
        if ($daysSinceLastBackup > 7) {
            if ($verbose) $this->warn("  ⚠️  Last successful backup was {$daysSinceLastBackup} days ago");
        } else {
            if ($verbose) $this->info("  ✅ Recent backup found ({$daysSinceLastBackup} days ago)");
        }

        // Check backup size trends
        $avgSize = $successfulBackups->avg('file_size');
        if ($avgSize && $verbose) {
            $this->info("  📦 Average backup size: {Helpers::formatBytes($avgSize)}");
        }

        return $daysSinceLastBackup <= 7;
    }

    protected function getDirectorySizeBytes(string $path): int
    {
        if (!is_dir($path)) {
            return is_file($path) ? filesize($path) : 0;
        }

        $size = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            try {
                if ($file->isFile()) {
                    $size += $file->getSize();
                }
            } catch (\RuntimeException $e) {
                continue;
            }
        }

        return $size;
    }

    protected function getDirectorySize(string $path): string
    {
        if (!is_dir($path)) {
            return is_file($path) ? Helpers::formatBytes(filesize($path)) : '0 B';
        }

        $size = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            try {
                // Skip files where getSize() fails (broken symlinks, etc.)
                if ($file->isFile()) {
                    $size += $file->getSize();
                }
            } catch (\RuntimeException $e) {
                // Skip files that can't be accessed
                continue;
            }
        }

        return Helpers::formatBytes($size);
    }

}
