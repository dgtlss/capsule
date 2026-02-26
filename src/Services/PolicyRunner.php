<?php

namespace Dgtlss\Capsule\Services;

use Dgtlss\Capsule\Support\Helpers;
use Illuminate\Support\Facades\Log;

class PolicyRunner
{
    /**
     * Get all defined policies, or generate a single "default" policy from global config.
     */
    public static function getPolicies(): array
    {
        $policies = config('capsule.policies', []);

        if (!empty($policies)) {
            return $policies;
        }

        return [
            'default' => [
                'database' => config('capsule.database.enabled', true),
                'files' => config('capsule.files.enabled', true),
                'disk' => config('capsule.default_disk', 'local'),
                'frequency' => config('capsule.schedule.frequency', 'daily'),
                'time' => config('capsule.schedule.time', '02:00'),
                'retention' => config('capsule.retention', []),
            ],
        ];
    }

    /**
     * Apply a named policy's overrides to the runtime config.
     */
    public static function applyPolicy(string $name): array
    {
        $policies = self::getPolicies();

        if (!isset($policies[$name])) {
            throw new \Exception("Backup policy '{$name}' not found. Available: " . implode(', ', array_keys($policies)));
        }

        $policy = $policies[$name];

        if (isset($policy['database'])) {
            config(['capsule.database.enabled' => (bool) $policy['database']]);
        }

        if (isset($policy['files'])) {
            config(['capsule.files.enabled' => (bool) $policy['files']]);
        }

        if (isset($policy['disk'])) {
            config(['capsule.default_disk' => $policy['disk']]);
        }

        if (isset($policy['paths'])) {
            config(['capsule.files.paths' => (array) $policy['paths']]);
        }

        if (isset($policy['exclude_paths'])) {
            config(['capsule.files.exclude_paths' => (array) $policy['exclude_paths']]);
        }

        if (isset($policy['connections'])) {
            config(['capsule.database.connections' => $policy['connections']]);
        }

        if (isset($policy['retention'])) {
            foreach ($policy['retention'] as $key => $value) {
                config(["capsule.retention.{$key}" => $value]);
            }
        }

        if (isset($policy['compression_level'])) {
            config(['capsule.backup.compression_level' => (int) $policy['compression_level']]);
        }

        if (isset($policy['incremental']) && $policy['incremental']) {
            config(['capsule._policy_incremental' => true]);
        }

        return $policy;
    }

    /**
     * List all policies with their key properties for display.
     */
    public static function listPolicies(): array
    {
        $policies = self::getPolicies();
        $list = [];

        foreach ($policies as $name => $policy) {
            $list[] = [
                'name' => $name,
                'database' => !empty($policy['database']) ? 'Yes' : 'No',
                'files' => !empty($policy['files']) ? 'Yes' : 'No',
                'disk' => $policy['disk'] ?? config('capsule.default_disk', 'local'),
                'frequency' => $policy['frequency'] ?? config('capsule.schedule.frequency', 'daily'),
                'incremental' => !empty($policy['incremental']) ? 'Yes' : 'No',
                'retention_days' => $policy['retention']['days'] ?? config('capsule.retention.days', 30),
            ];
        }

        return $list;
    }
}
