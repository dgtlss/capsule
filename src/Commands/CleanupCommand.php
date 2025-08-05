<?php

namespace Dgtlss\Capsule\Commands;

use Dgtlss\Capsule\Models\BackupLog;
use Dgtlss\Capsule\Storage\StorageManager;
use Illuminate\Console\Command;

class CleanupCommand extends Command
{
    protected $signature = 'capsule:cleanup 
    {--dry-run : Show what would be deleted without actually deleting} 
    {--days= : Override retention days from config}
    {--failed : Also clean up failed backup records}
    {--storage : Clean up orphaned files in storage}
    {--v : Verbose output}';
    
    protected $description = 'Clean up old backup files according to retention policy';

    public function handle(): int
    {
        $this->info('Starting cleanup process...');

        $retentionDays = $this->option('days') ? (int) $this->option('days') : config('capsule.retention.days', 30);
        $retentionCount = config('capsule.retention.count', 10);
        $isDryRun = $this->option('dry-run');
        $cleanFailed = $this->option('failed');
        $cleanStorage = $this->option('storage');
        $verbose = $this->option('v');

        if ($this->option('days')) {
            $this->info("Using command override: {$retentionDays} days (ignoring config and count-based retention)");
        } else {
            $this->info("Retention policy: {$retentionDays} days, maximum {$retentionCount} backups");
        }

        if ($isDryRun) {
            $this->warn('DRY RUN MODE - No files will be deleted');
        }

        $totalDeleted = 0;
        $totalSize = 0;

        // Clean up successful backups
        $result = $this->cleanupSuccessfulBackups($retentionDays, $retentionCount, $isDryRun, $verbose);
        $totalDeleted += $result['count'];
        $totalSize += $result['size'];

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

        if ($totalDeleted === 0) {
            $this->info('No items to clean up.');
        } else {
            $action = $isDryRun ? 'Would delete' : 'Deleted';
            $this->info("{$action} {$totalDeleted} items ({$this->formatBytes($totalSize)}) total");
        }

        return self::SUCCESS;
    }

    protected function cleanupSuccessfulBackups(int $retentionDays, int $retentionCount, bool $isDryRun, bool $verbose): array
    {
        if ($verbose) {
            $this->info('🔍 Scanning successful backups...');
        }

        if ($this->option('days')) {
            $oldBackups = BackupLog::where('status', 'success')
                ->where('created_at', '<', now()->subDays($retentionDays))
                ->get();
        } else {
            // Get IDs of backups to keep (latest N successful backups)
            $keepIds = BackupLog::where('status', 'success')
                ->orderBy('created_at', 'desc')
                ->limit($retentionCount)
                ->pluck('id')
                ->toArray();

            // Delete old backups by date OR those not in the latest N
            $oldBackups = BackupLog::where('status', 'success')
                ->where(function ($query) use ($retentionDays, $keepIds) {
                    $query->where('created_at', '<', now()->subDays($retentionDays))
                        ->when(!empty($keepIds), function ($q) use ($keepIds) {
                            $q->orWhereNotIn('id', $keepIds);
                        });
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
                $storageDeleted = true;
                
                // Delete from storage first
                if ($backup->file_path) {
                    try {
                        $fileName = basename($backup->file_path);
                        $storageManager->delete($fileName);
                        if ($verbose) {
                            $this->line("  ✓ Deleted from storage: {$fileName}");
                        }
                    } catch (\Exception $e) {
                        $this->warn("  ✗ Failed to delete storage file: {$e->getMessage()}");
                        $storageDeleted = false;
                    }
                }
                
                // Only delete database record if storage deletion succeeded (or no file path)
                if ($storageDeleted || !$backup->file_path) {
                    try {
                        $backup->delete();
                        $deletedCount++;
                        $deletedSize += $size;
                        if ($verbose) {
                            $this->line("  ✓ Deleted database record");
                        }
                    } catch (\Exception $e) {
                        $this->warn("  ✗ Failed to delete database record: {$e->getMessage()}");
                    }
                } else {
                    $this->warn("  ⚠ Skipping database deletion due to storage deletion failure");
                }
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
        $storageManager = new StorageManager();

        foreach ($failedBackups as $backup) {
            $this->line("- {$backup->created_at->format('Y-m-d H:i:s')} (failed: {$backup->error_message})");

            if (!$isDryRun) {
                $storageDeleted = true;
                
                // Delete any partial files from storage first
                if ($backup->file_path) {
                    try {
                        $fileName = basename($backup->file_path);
                        $storageManager->delete($fileName);
                        if ($verbose) {
                            $this->line("  ✓ Deleted partial file from storage: {$fileName}");
                        }
                    } catch (\Exception $e) {
                        if ($verbose) {
                            $this->line("  ⚠ Partial file not found in storage (already cleaned): {$e->getMessage()}");
                        }
                        // Don't mark as failed since partial files might not exist
                    }
                }
                
                // Delete database record
                try {
                    $backup->delete();
                    $deletedCount++;
                    if ($verbose) {
                        $this->line("  ✓ Deleted failed backup record");
                    }
                } catch (\Exception $e) {
                    $this->warn("  ✗ Failed to delete database record: {$e->getMessage()}");
                }
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