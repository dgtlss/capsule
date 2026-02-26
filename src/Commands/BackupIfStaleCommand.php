<?php

namespace Dgtlss\Capsule\Commands;

use Dgtlss\Capsule\Models\BackupLog;
use Illuminate\Console\Command;

class BackupIfStaleCommand extends Command
{
    protected $signature = 'capsule:backup-if-stale
        {--hours=24 : Only backup if last success is older than this many hours}
        {--policy= : Run a specific policy}
        {--tag= : Tag the backup}';

    protected $description = 'Run a backup only if the last successful backup is older than the threshold';

    public function handle(): int
    {
        $hours = (int) $this->option('hours');
        $cutoff = now()->subHours($hours);

        $lastSuccess = BackupLog::successful()
            ->orderByDesc('created_at')
            ->first();

        if ($lastSuccess && $lastSuccess->created_at->greaterThan($cutoff)) {
            $age = $lastSuccess->created_at->diffForHumans();
            $this->info("Last successful backup was {$age}. Skipping (threshold: {$hours}h).");
            return self::SUCCESS;
        }

        $args = [];
        if ($this->option('policy')) {
            $args['--policy'] = $this->option('policy');
        }
        if ($this->option('tag')) {
            $args['--tag'] = $this->option('tag');
        }

        $reason = $lastSuccess
            ? "Last backup is " . $lastSuccess->created_at->diffForHumans() . " (threshold: {$hours}h)"
            : "No successful backups found";

        $this->info("{$reason}. Starting backup...");

        return $this->call('capsule:backup', $args);
    }
}
