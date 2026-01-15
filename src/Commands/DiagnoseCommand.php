<?php

namespace Dgtlss\Capsule\Commands;

use Dgtlss\Capsule\Models\BackupLog;
use Dgtlss\Capsule\Support\Formatters;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class DiagnoseCommand extends Command
{
    protected $signature = 'capsule:diagnose 
    {--detailed : Show detailed performance and security analysis}
    {--fix : Attempt to fix common issues automatically}
    {--format=table : Output format (table, json)}
    {--v : Verbose output}';
    
    protected $description = 'Diagnose Capsule configuration and system requirements';

    public function handle(): int
    {
        $detailed = $this->option('detailed');
        $format = $this->option('format');
        $fix = $this->option('fix');
        $verbose = $this->option('v');

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
        $results = [
            'timestamp' => now()->toISOString(),
            'checks' => [
                'basic' => $this->checkBasicConfig($fix, false),
                'storage' => $this->checkStorageConfig($fix, false),
                'database' => $this->checkDatabaseConfig($fix, false),
                'files' => $this->checkFileConfig($fix, false),
                'system' => $this->checkSystemRequirements($fix, false),
            ]
        ];

        if ($detailed) {
            $results['checks']['performance'] = $this->checkPerformance(false);
            $results['checks']['security'] = $this->checkSecurity(false);
            $results['checks']['backup_history'] = $this->checkBackupHistory(false);
        }

        $results['overall_status'] = collect($results['checks'])->every(fn($result) => $result) ? 'passed' : 'failed';

        $this->line(json_encode($results, JSON_PRETTY_PRINT));

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
                $this->call('vendor:publish', ['--tag' => 'capsule-config']);
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

        $this->info("  📁 Using disk: {$diskName}");
        $this->info("  📂 Backup path: {$backupPath}");

        try {
            $filesystemDisks = config('filesystems.disks', []);
            
            if (!isset($filesystemDisks[$diskName])) {
                $available = implode(', ', array_keys($filesystemDisks));
                $this->error("  ❌ Disk '{$diskName}' not found in filesystems.php");
                $this->error("  Available disks: {$available}");
                return false;
            }

            $this->info('  ✅ Storage disk configuration found');

            // Test storage access
            try {
                $disk = Storage::disk($diskName);
                $testFile = $backupPath . '/test_' . uniqid() . '.txt';
                $disk->put($testFile, 'test');
                
                if ($disk->exists($testFile)) {
                    $disk->delete($testFile);
                    $this->info('  ✅ Storage disk is writable');
                } else {
                    $this->error('  ❌ Failed to write test file to storage');
                    return false;
                }
            } catch (\Exception $e) {
                $this->error("  ❌ Storage test failed: {$e->getMessage()}");
                return false;
            }

        } catch (\Exception $e) {
            $this->error("  ❌ Storage configuration error: {$e->getMessage()}");
            return false;
        }

        return true;
    }

    protected function checkDatabaseConfig(bool $fix = false, bool $verbose = true): bool
    {
        $this->line('🗄️  Database Configuration');
        
        $dbEnabled = config('capsule.database.enabled', true);
        
        if (!$dbEnabled) {
            $this->comment('  ⏭️  Database backup disabled');
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
            $this->info("  🔗 Checking connection: {$connection}");
            
            // Resolve 'default' to the actual default connection name
            $resolvedConnection = $connection === 'default' ? config('database.default') : $connection;
            $dbConfig = config("database.connections.{$resolvedConnection}");
            if (!$dbConfig) {
                $this->error("    ❌ Connection '{$resolvedConnection}' not found");
                $allGood = false;
                continue;
            }

            $driver = $dbConfig['driver'] ?? 'unknown';
            $this->info("    📊 Driver: {$driver}");

            // Check database tools
            switch ($driver) {
                case 'mysql':
                    if ($this->commandExists('mysqldump')) {
                        $this->info('    ✅ mysqldump available');
                    } else {
                        $this->error('    ❌ mysqldump not found');
                        $allGood = false;
                    }
                    break;
                case 'pgsql':
                    if ($this->commandExists('pg_dump')) {
                        $this->info('    ✅ pg_dump available');
                    } else {
                        $this->error('    ❌ pg_dump not found');
                        $allGood = false;
                    }
                    break;
                case 'sqlite':
                    $dbPath = $dbConfig['database'] ?? '';
                    if (file_exists($dbPath)) {
                        $this->info('    ✅ SQLite database file exists');
                    } else {
                        $this->error("    ❌ SQLite file not found: {$dbPath}");
                        $allGood = false;
                    }
                    break;
                default:
                    $this->comment("    ⚠️  Unsupported driver: {$driver}");
                    break;
            }

            // Test database connection
            if ($fix && in_array($driver, ['mysql', 'pgsql'])) {
                // Defer actual connection test; inform about graceful degrade
                $this->comment('    ℹ️  Will gracefully continue without DB logging if connection fails at runtime');
            } else {
                try {
                    \DB::connection($resolvedConnection)->getPdo();
                    $this->info('    ✅ Database connection successful');
                } catch (\Exception $e) {
                    $this->error("    ❌ Database connection failed: {$e->getMessage()}");
                    $allGood = false;
                }
            }
        }

        return $allGood;
    }

    protected function checkFileConfig(bool $fix = false, bool $verbose = true): bool
    {
        $this->line('📁 File Backup Configuration');
        
        $filesEnabled = config('capsule.files.enabled', true);
        
        if (!$filesEnabled) {
            $this->comment('  ⏭️  File backup disabled');
            return true;
        }

        $paths = config('capsule.files.paths', []);
        $excludePaths = config('capsule.files.exclude_paths', []);

        if (empty($paths)) {
            $this->error('  ❌ No file paths configured');
            return false;
        }

        $this->info('  📂 Checking backup paths:');
        $allGood = true;

        foreach ($paths as $path) {
            if (file_exists($path)) {
                $size = $this->getDirectorySize($path);
                $this->info("    ✅ {$path} ({$size})");
            } else {
                $this->error("    ❌ {$path} (not found)");
                $allGood = false;
            }
        }

        if (!empty($excludePaths)) {
            $this->info('  🚫 Exclude paths:');
            foreach ($excludePaths as $path) {
                $this->comment("    ⏭️  {$path}");
            }
        }

        return $allGood;
    }

    protected function checkSystemRequirements(bool $fix = false, bool $verbose = true): bool
    {
        $this->line('⚙️  System Requirements');
        
        $allGood = true;

        // Check PHP version
        $phpVersion = PHP_VERSION;
        if (version_compare($phpVersion, '8.1', '>=')) {
            $this->info("  ✅ PHP version: {$phpVersion}");
        } else {
            $this->error("  ❌ PHP 8.1+ required, found: {$phpVersion}");
            $allGood = false;
        }

        // Check required extensions
        $extensions = ['zip', 'json'];
        foreach ($extensions as $ext) {
            if (extension_loaded($ext)) {
                $this->info("  ✅ {$ext} extension loaded");
            } else {
                $this->error("  ❌ {$ext} extension missing");
                $allGood = false;
            }
        }

        // Check directory permissions
        $storageDir = storage_path('app');
        if (is_writable($storageDir)) {
            $this->info("  ✅ Storage directory writable: {$storageDir}");
        } else {
            $this->error("  ❌ Storage directory not writable: {$storageDir}");
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
            if ($verbose) $this->warn("  ⚠️  Large dataset detected (" . Formatters::bytes($estimatedSize) . "). Consider using --no-local flag");
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
        $this->line('❤️  Capsule Health Summary');
        try {
            $age = \Dgtlss\Capsule\Health\Checks\BackupHealthCheck::lastSuccessAgeDays();
            $failures = \Dgtlss\Capsule\Health\Checks\BackupHealthCheck::recentFailuresCount();
            $usage = \Dgtlss\Capsule\Health\Checks\BackupHealthCheck::storageUsageBytes();

            $this->info('  Last success age: ' . ($age === null ? 'none' : ($age . ' day(s)')));
            if ($failures > 0) {
                $this->warn("  Recent failures (7d): {$failures}");
            } else {
                $this->info('  Recent failures (7d): 0');
            }
            $this->info('  Storage usage: ' . Formatters::bytes($usage));
            return true;
        } catch (\Throwable $e) {
            $this->error('  ❌ Failed to compute health summary: ' . $e->getMessage());
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
            $this->info("  📦 Average backup size: " . Formatters::bytes($avgSize));
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

    protected function commandExists(string $command): bool
    {
        $result = shell_exec("which {$command}");
        return !empty($result);
    }

    protected function getDirectorySize(string $path): string
    {
        if (!is_dir($path)) {
            return is_file($path) ? Formatters::bytes(filesize($path)) : '0 B';
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

        return Formatters::bytes($size);
    }
}