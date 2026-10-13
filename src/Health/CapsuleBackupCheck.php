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

        $metadata = [
            'last_success_age_days' => $ageDays,
            'recent_failures_7d' => $failures,
            'storage_usage_bytes' => $usage,
        ];
        $result->meta($metadata);

        $maxAge = (int) config('capsule.health.max_last_success_age_days', 2);
        $maxFailures = (int) config('capsule.health.max_recent_failures', 0);
        $warnPercent = (int) config('capsule.health.warn_storage_percent', 90);
        $budgetMb = (int) (config('capsule.retention.max_storage_mb') ?? 0);

        if ($ageDays === null) {
            return $result->failed('No successful backups yet.');
        }

        $warnings = [];
        if ($ageDays > $maxAge) {
            $warnings[] = "Last successful backup is {$ageDays} days old";
        }

        if ($failures > $maxFailures) {
            $warnings[] = "Recent failures (7d): {$failures}";
        }

        if ($budgetMb > 0) {
            $percent = ($usage / ($budgetMb * 1024 * 1024)) * 100;
            $metadata['storage_usage_percent_of_budget'] = round($percent, 2);
            $result->meta($metadata);
            if ($percent >= $warnPercent) {
                $warnings[] = 'Storage usage near budget: ' . round($percent) . '%';
            }
        }

        return $warnings !== []
            ? $result->warning(implode('; ', $warnings))
            : $result->ok('Backups healthy');
    }
}
