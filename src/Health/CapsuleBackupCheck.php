<?php

namespace Dgtlss\Capsule\Health;

use Dgtlss\Capsule\Health\Checks\BackupHealthCheck;
use Spatie\Health\Checks\Check;
use Spatie\Health\Checks\Result; 

class CapsuleBackupCheck extends Check
{
    public function run(): Result
    {
        $result = Result::make();

        $ageDays = BackupHealthCheck::lastSuccessAgeDays();
        $failures = BackupHealthCheck::recentFailuresCount();
        $usage = BackupHealthCheck::storageUsageBytes();

        $result->meta([
            'last_success_age_days' => $ageDays,
            'recent_failures_7d' => $failures,
            'storage_usage_bytes' => $usage,
        ]);

        $maxAge = (int) config('capsule.health.max_last_success_age_days', 2);
        $maxFailures = (int) config('capsule.health.max_recent_failures', 0);
        $warnPercent = (int) config('capsule.health.warn_storage_percent', 90);
        $budgetMb = (int) (config('capsule.retention.max_storage_mb') ?? 0);

        if ($ageDays === null) {
            return $result->failed('No successful backups yet.');
        }

        if ($ageDays > $maxAge) {
            $result->warning("Last successful backup is {$ageDays} days old");
        }

        if ($failures > $maxFailures) {
            $result->warning("Recent failures (7d): {$failures}");
        }

        if ($budgetMb > 0) {
            $percent = ($usage / ($budgetMb * 1024 * 1024)) * 100;
            $result->meta(['storage_usage_percent_of_budget' => round($percent, 2)]);
            if ($percent >= $warnPercent) {
                $result->warning('Storage usage near budget: ' . round($percent) . '%');
            }
        }

        return $result->ok('Backups healthy');
    }
}
