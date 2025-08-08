<?php

namespace Dgtlss\Capsule\Commands;

use Illuminate\Console\Command;
use Dgtlss\Capsule\Health\Checks\BackupHealthCheck;

class HealthCommand extends Command
{
    protected $signature = 'capsule:health {--format=json : json only}';
    protected $description = 'Emit Capsule backup health snapshot';

    public function handle(): int
    {
        $age = BackupHealthCheck::lastSuccessAgeDays();
        $failures = BackupHealthCheck::recentFailuresCount();
        $usage = BackupHealthCheck::storageUsageBytes();

        $payload = [
            'last_success_age_days' => $age,
            'recent_failures_7d' => $failures,
            'storage_usage_bytes' => $usage,
        ];

        $this->line(json_encode($payload, JSON_PRETTY_PRINT));
        return self::SUCCESS;
    }
}
