<?php

namespace Dgtlss\Capsule\Commands;

use Dgtlss\Capsule\Services\IntegrityMonitor;
use Dgtlss\Capsule\Support\Helpers;
use Illuminate\Console\Command;

class VerifyScheduledCommand extends Command
{
    protected $signature = 'capsule:verify-scheduled {--format=table : Output format (table|json)}';
    protected $description = 'Run automated backup integrity verification (picks an unverified backup)';

    public function handle(): int
    {
        $monitor = app(IntegrityMonitor::class);
        $result = $monitor->runScheduledVerification();

        if (!$result) {
            if ($this->option('format') === 'json') {
                $this->line(json_encode(['status' => 'skipped', 'message' => 'No backups need verification'], JSON_PRETTY_PRINT));
            } else {
                $this->info('No backups need verification at this time.');
            }
            return self::SUCCESS;
        }

        if ($this->option('format') === 'json') {
            $this->line(json_encode([
                'backup_id' => $result->backup_log_id,
                'status' => $result->status,
                'entries_checked' => $result->entries_checked,
                'entries_failed' => $result->entries_failed,
                'duration_seconds' => $result->duration_seconds,
                'error' => $result->error_message,
            ], JSON_PRETTY_PRINT));
        } else {
            $statusLabel = match ($result->status) {
                'passed' => '<fg=green>PASSED</>',
                'failed' => '<fg=red>FAILED</>',
                default => '<fg=yellow>ERROR</>',
            };

            $this->line("Backup #{$result->backup_log_id}: {$statusLabel} ({$result->entries_checked} entries checked, {$result->duration_seconds}s)");

            if ($result->error_message) {
                $this->error($result->error_message);
            }
        }

        return $result->status === 'passed' ? self::SUCCESS : self::FAILURE;
    }
}
