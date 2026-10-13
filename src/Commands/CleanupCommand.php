<?php

namespace Dgtlss\Capsule\Commands;

use Dgtlss\Capsule\Events\CleanupCompleted;
use Dgtlss\Capsule\Events\CleanupStarting;
use Dgtlss\Capsule\Notifications\NotificationManager;
use Dgtlss\Capsule\Services\RetentionService;
use Dgtlss\Capsule\Support\Helpers;
use Dgtlss\Capsule\Support\Lock;
use Illuminate\Console\Command;

class CleanupCommand extends Command
{
    protected $signature = 'capsule:cleanup
    {--dry-run : Show what would be deleted without actually deleting}
    {--days= : Override retention days and ignore count-based retention}
    {--policy= : Clean only backups created by this named policy}
    {--failed : Also clean up failed and stale backup records}
    {--storage : Clean up orphaned files in recorded storage locations}
    {--detailed : Verbose output}
    {--format=table : Output format (table|json)}';

    protected $description = 'Clean up old backup files according to retention policy';

    public function handle(RetentionService $retention): int
    {
        $json = $this->option('format') === 'json';
        $dryRun = (bool) $this->option('dry-run');
        $lock = null;
        $result = ['count' => 0, 'size' => 0, 'errors' => []];
        try {
            if (!in_array($this->option('format'), ['table', 'json'], true)) {
                throw new \InvalidArgumentException('Output format must be table or json.');
            }
            $days = $this->option('days');
            if ($days !== null && !ctype_digit((string) $days)) {
                throw new \InvalidArgumentException('Retention days must be a nonnegative integer.');
            }
            $lock = Lock::acquire('capsule:backup');
            if ($lock === null) {
                throw new \RuntimeException('Another backup or cleanup is currently running.');
            }
            event(new CleanupStarting($dryRun));
            $result = $retention->cleanup([
                'days' => $days === null ? null : (int) $days,
                'policy' => $this->option('policy'),
                'dry_run' => $dryRun,
                'failed' => (bool) $this->option('failed'),
                'storage' => (bool) $this->option('storage'),
                'output' => !$json && $this->option('detailed') ? fn ($message) => $this->line($message) : null,
            ]);
            event(new CleanupCompleted($result['count'], $result['size'], $dryRun));
            if (!$dryRun && $result['count'] > 0) {
                app(NotificationManager::class)->sendCleanupNotification($result['count'], $result['size']);
            }
        } catch (\Throwable $e) {
            $result['errors'][] = $e->getMessage();
        } finally {
            if ($lock !== null) {
                try {
                    $lock->release();
                } catch (\Throwable $e) {
                    $result['errors'][] = 'Failed to release cleanup lock: ' . $e->getMessage();
                }
            }
        }

        if ($json) {
            $this->line(json_encode([
                'status' => $result['errors'] === [] ? 'success' : 'failed',
                'dry_run' => $dryRun,
                'deleted_count' => $result['count'],
                'deleted_size_bytes' => $result['size'],
                'errors' => $result['errors'],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $action = $dryRun ? 'Would delete' : 'Deleted';
            $this->info("{$action} {$result['count']} items (" . Helpers::formatBytes($result['size']) . ') total');
            foreach ($result['errors'] as $error) {
                $this->error($error);
            }
        }
        return $result['errors'] === [] ? self::SUCCESS : self::FAILURE;
    }
}
