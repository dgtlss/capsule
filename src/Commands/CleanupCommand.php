<?php

namespace Dgtlss\Capsule\Commands;

use Dgtlss\Capsule\Models\BackupLog;
use Dgtlss\Capsule\Storage\StorageManager;
use Dgtlss\Capsule\Notifications\NotificationManager;
use Illuminate\Console\Command;

class CleanupCommand extends Command
{
    protected $signature = 'capsule:cleanup 
    {--dry-run : Show what would be deleted without actually deleting} 
    {--days= : Override retention days from config}
    {--failed : Also clean up failed backup records}
    {--storage : Clean up orphaned files in storage}
    {--v : Verbose output}
    {--format=table : Output format (table|json)}';
    
    protected $description = 'Clean up old backup files according to retention policy';

    public function handle(): int
    {
        $this->info('Starting cleanup process...');

        $daysOption = $this->option('days');
        $retentionDays = $daysOption !== null ? (int) $daysOption : config('capsule.retention.days', 30);
        $retentionCount = config('capsule.retention.count', 10);
        $maxStorageMb = (int) (config('capsule.retention.max_storage_mb') ?? 0);
        $minKeep = (int) config('capsule.retention.min_keep', 3);
        $isDryRun = $this->option('dry-run');
        $cleanFailed = $this->option('failed');
        $cleanStorage = $this->option('storage');
        $verbose = $this->option('v');

        if ($daysOption !== null) {
            $this->info("Using command override: {$retentionDays} days (ignoring config and count-based retention)");
        } else {
            $this->info("Retention policy: {$retentionDays} days, maximum {$retentionCount} backups");
        }

        if ($isDryRun) {
            $this->warn('DRY RUN MODE - No files will be deleted');
        }

        $totalDeleted = 0;
        $totalSize = 0;

        // Clean up successful backups (time/count based)
        $result = $this->cleanupSuccessfulBackups($retentionDays, $retentionCount, $isDryRun, $verbose);
        $totalDeleted += $result['count'];
        $totalSize += $result['size'];

        // Enforce budget if configured
        if ($maxStorageMb > 0) {
            $result = $this->enforceStorageBudget($maxStorageMb, $minKeep, $isDryRun, $verbose);
            $totalDeleted += $result['count'];
            $totalSize += $result['size'];
        }

        // Clean up failed backups if requested
        if ($cleanFailed) {
            $result = $this->cleanupFailedBackups($retentionDays, $isDryRun, $verbose);
            $totalDeleted += $result['count'];
            $totalSize += $result['size'];
        }

        // Clean up orphaned storage files if requested
        if ($cleanStorage) {
            $result = $this->cleanupOrphanedFiles($isDryRun, $verbose);
            $totalDeleted += $result['count'];
            $totalSize += $result['size'];
        }

        $format = $this->option('format');
        if ($format === 'json') {
            $this->line(json_encode([
                'dry_run' => (bool) $isDryRun,
                'deleted_count' => $totalDeleted,
                'deleted_size_bytes' => $totalSize,
            ], JSON_PRETTY_PRINT));
        } else {
            if ($totalDeleted === 0) {
                $this->info('No items to clean up.');
            } else {
                $action = $isDryRun ? 'Would delete' : 'Deleted';
                $this->info("{$action} {$totalDeleted} items ({$this->formatBytes($totalSize)}) total");
            }
        }

        // Send notification if items were actually cleaned (not dry-run and count > 0)
        if (!$isDryRun && $totalDeleted > 0) {
            $notificationManager = new NotificationManager();
            $notificationManager->sendCleanupNotification($totalDeleted, $totalSize);
        }

        return self::SUCCESS;
    }

    protected function enforceStorageBudget(int $maxStorageMb, int $minKeep, bool $isDryRun, bool $verbose): array
    {
        $storageManager = new StorageManager();
        $currentFiles = BackupLog::where('status', 'success')
            ->orderBy('created_at', 'desc')
            ->get(['id', 'file_path', 'file_size', 'created_at']);

        if ($currentFiles->isEmpty()) {
            return ['count' => 0, 'size' => 0];
        }

        $totalBytes = (int) $currentFiles->sum('file_size');
        $budgetBytes = $maxStorageMb * 1024 * 1024;
        if ($verbose) {
            $this->info('📊 Storage usage: ' . $this->formatBytes($totalBytes) . ' / ' . $this->formatBytes($budgetBytes));
        }

        if ($totalBytes <= $budgetBytes) {
            if ($verbose) $this->info('✅ Within budget; no budget-based pruning needed');
            return ['count' => 0, 'size' => 0];
        }

        // Start pruning from oldest while respecting min_keep
        $deletedCount = 0;
        $deletedSize = 0;
        $toConsider = $currentFiles->sortBy('created_at')->values();

        foreach ($toConsider as $index => $backup) {
            $remaining = $currentFiles->count() - ($deletedCount + 1);
            if ($remaining < $minKeep) {
                // Stop if we would drop below min_keep
                break;
            }

            $size = (int) ($backup->file_size ?? 0);
            if ($verbose) {
                $this->line("- Pruning (budget): " . $backup->created_at->format('Y-m-d H:i:s') . ' (' . $this->formatBytes($size) . ')');
            }

            if (!$isDryRun) {
                if (!empty($backup->file_path)) {
                    try {
                        $fileName = basename($backup->file_path);
                        $storageManager->delete($fileName);
                    } catch (\Exception $e) {
                        $this->warn("  Failed to delete storage file: {$e->getMessage()}");
                    }
                }
                $backup->delete();
            }

            $deletedCount++;
            $deletedSize += $size;
            $totalBytes -= $size;

            if ($totalBytes <= $budgetBytes) {
                break;
            }
        }

        if ($deletedCount > 0) {
            $action = $isDryRun ? 'Would delete (budget)' : 'Deleted (budget)';
            $this->info("{$action} {$deletedCount} items (" . $this->formatBytes($deletedSize) . ")");
        } else if ($verbose) {
            $this->info('No items pruned by budget (respecting min_keep).');
        }

        return ['count' => $deletedCount, 'size' => $deletedSize];
    }

    protected function cleanupSuccessfulBackups(int $retentionDays, int $retentionCount, bool $isDryRun, bool $verbose): array
    {
        if ($verbose) {
            $this->info('🔍 Scanning successful backups...');
        }

        if ($this->option('days') !== null) {
            $oldBackups = BackupLog::where('status', 'success')
                ->where('created_at', '<', now()->subDays($retentionDays))
                ->get();
        } else {
            $keepIds = BackupLog::where('status', 'success')
                ->orderBy('created_at', 'desc')
                ->limit($retentionCount)
                ->pluck('id')
                ->toArray();

            $oldBackups = BackupLog::where('status', 'success')
                ->where('created_at', '<', now()->subDays($retentionDays))
                ->when(!empty($keepIds), function ($q) use ($keepIds) {
                    $q->whereNotIn('id', $keepIds);
                })
                ->get();
        }

        if ($oldBackups->isEmpty()) {
            if ($verbose) {
                $this->info('No successful backups to clean up.');
            }
            return ['count' => 0, 'size' => 0];
        }

        $this->info("Found {$oldBackups->count()} successful backups to clean up:");

        $deletedCount = 0;
        $deletedSize = 0;
        $storageManager = new StorageManager();

        foreach ($oldBackups as $backup) {
            $size = $backup->file_size ?? 0;
            $this->line("- {$backup->created_at->format('Y-m-d H:i:s')} ({$this->formatBytes($size)})");

            if (!$isDryRun) {
                if ($backup->file_path) {
                    try {
                        $fileName = basename($backup->file_path);
                        $storageManager->delete($fileName);
                        if ($verbose) {
                            $this->line("  Deleted from storage: {$fileName}");
                        }
                    } catch (\Exception $e) {
                        $this->warn("  Failed to delete storage file: {$e->getMessage()}");
                    }
                }
                $backup->delete();
                $deletedCount++;
                $deletedSize += $size;
            }
        }

        if ($isDryRun) {
            $totalSize = $oldBackups->sum('file_size');
            $this->info("Would delete {$oldBackups->count()} successful backups ({$this->formatBytes($totalSize)})");
        } else {
            $this->info("Deleted {$deletedCount} successful backups ({$this->formatBytes($deletedSize)})");
        }

        return ['count' => $deletedCount, 'size' => $deletedSize];
    }

    protected function cleanupFailedBackups(int $retentionDays, bool $isDryRun, bool $verbose): array
    {
        if ($verbose) {
            $this->info('🔍 Scanning failed backups...');
        }

        $failedBackups = BackupLog::where('status', 'failed')
            ->where('created_at', '<', now()->subDays($retentionDays))
            ->get();

        if ($failedBackups->isEmpty()) {
            if ($verbose) {
                $this->info('No failed backups to clean up.');
            }
            return ['count' => 0, 'size' => 0];
        }

        $this->info("Found {$failedBackups->count()} failed backup records to clean up:");

        $deletedCount = 0;

        foreach ($failedBackups as $backup) {
            $this->line("- {$backup->created_at->format('Y-m-d H:i:s')} (failed: {$backup->error_message})");

            if (!$isDryRun) {
                $backup->delete();
                $deletedCount++;
            }
        }

        if ($isDryRun) {
            $this->info("Would delete {$failedBackups->count()} failed backup records");
        } else {
            $this->info("Deleted {$deletedCount} failed backup records");
        }

        return ['count' => $deletedCount, 'size' => 0];
    }

    protected function cleanupOrphanedFiles(bool $isDryRun, bool $verbose): array
    {
        if ($verbose) {
            $this->info('🔍 Scanning for orphaned storage files...');
        }

        $storageManager = new StorageManager();
        $backupPath = config('capsule.backup_path', 'backups');
        
        try {
            $allFiles = $storageManager->listFiles($backupPath);
        } catch (\Exception $e) {
            $this->error("Failed to list storage files: {$e->getMessage()}");
            return ['count' => 0, 'size' => 0];
        }

        $knownFiles = BackupLog::whereNotNull('file_path')
            ->pluck('file_path')
            ->map(function ($path) {
                return basename($path);
            })
            ->toArray();

        $orphanedFiles = array_diff($allFiles, $knownFiles);

        if (empty($orphanedFiles)) {
            if ($verbose) {
                $this->info('No orphaned files found in storage.');
            }
            return ['count' => 0, 'size' => 0];
        }

        $this->info("Found " . count($orphanedFiles) . " orphaned files in storage:");

        $deletedCount = 0;
        $deletedSize = 0;

        foreach ($orphanedFiles as $filename) {
            try {
                $size = $storageManager->size($filename);
                $this->line("- {$filename} ({$this->formatBytes($size)})");

                if (!$isDryRun) {
                    $storageManager->delete($filename);
                    $deletedCount++;
                    $deletedSize += $size;
                    if ($verbose) {
                        $this->line("  Deleted orphaned file: {$filename}");
                    }
                }
            } catch (\Exception $e) {
                $this->warn("  Failed to process {$filename}: {$e->getMessage()}");
            }
        }

        if ($isDryRun) {
            $this->info("Would delete " . count($orphanedFiles) . " orphaned files ({$this->formatBytes($deletedSize)})");
        } else {
            $this->info("Deleted {$deletedCount} orphaned files ({$this->formatBytes($deletedSize)})");
        }

        return ['count' => $deletedCount, 'size' => $deletedSize];
    }

    protected function formatBytes(int $bytes): string
    {
        if ($bytes === 0) {
            return '0 B';
        }

        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $pow = floor(log($bytes, 1024));

        return round($bytes / (1024 ** $pow), 2) . ' ' . $units[$pow];
    }
}