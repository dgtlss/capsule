<?php

namespace Dgtlss\Capsule\Facades;

use Dgtlss\Capsule\Models\BackupLog;
use Dgtlss\Capsule\Services\BackupService;
use Dgtlss\Capsule\Services\PolicyRunner;
use Dgtlss\Capsule\Services\SimulationService;
use Illuminate\Support\Collection;

/**
 * Programmatic API for Capsule backup operations.
 *
 * Usage:
 *   use Dgtlss\Capsule\Facades\Capsule;
 *
 *   $success = Capsule::backup();
 *   $success = Capsule::backup(['tag' => 'pre-deploy', 'db_only' => true]);
 *   $simulation = Capsule::simulate();
 *   $backups = Capsule::list(10);
 *   $latest = Capsule::latest();
 *   $healthy = Capsule::isHealthy();
 */
class Capsule
{
    public static function backup(array $options = []): bool
    {
        if (!empty($options['db_only']) && !empty($options['files_only'])) {
            throw new \InvalidArgumentException('Cannot use db_only and files_only together.');
        }

        return PolicyRunner::withPolicy($options['policy'] ?? null, function (array $policy) use ($options): bool {
            if (!empty($options['db_only'])) {
                config(['capsule.files.enabled' => false]);
            }
            if (!empty($options['files_only'])) {
                config(['capsule.database.enabled' => false]);
            }

            $service = app(BackupService::class);
            $service->setTag($options['tag'] ?? ($policy['tag'] ?? ($options['policy'] ?? null)));
            $service->setIncremental((bool) ($options['incremental'] ?? config('capsule._policy_incremental', false)));
            $service->setEncryption((bool) ($options['encrypt'] ?? false));
            $service->setVerification((bool) ($options['verify'] ?? false));
            if (isset($options['compression_level'])) {
                $service->setCompressionLevel((int) $options['compression_level']);
            }
            return $service->run((bool) ($options['force'] ?? false));
        });
    }

    public static function simulate(): array
    {
        return app(SimulationService::class)->simulate();
    }

    public static function list(int $limit = 50): Collection
    {
        return BackupLog::orderByDesc('created_at')->limit($limit)->get();
    }

    public static function latest(): ?BackupLog
    {
        return BackupLog::successful()->orderByDesc('created_at')->first();
    }

    public static function isHealthy(int $maxAgeDays = 2): bool
    {
        $latest = self::latest();
        if (!$latest) {
            return false;
        }

        return $latest->created_at->greaterThan(now()->subDays($maxAgeDays));
    }

    public static function find(int $id): ?BackupLog
    {
        return BackupLog::find($id);
    }
}
