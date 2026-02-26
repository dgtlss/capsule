<?php

namespace Dgtlss\Capsule\Services;

use Dgtlss\Capsule\Models\AuditLog;
use Illuminate\Support\Facades\Log;

class AuditLogger
{
    public static function log(string $action, array $context = []): ?AuditLog
    {
        if (!config('capsule.audit.enabled', true)) {
            return null;
        }

        try {
            return AuditLog::create([
                'action' => $action,
                'trigger' => $context['trigger'] ?? (app()->runningInConsole() ? 'artisan' : 'api'),
                'actor' => $context['actor'] ?? self::resolveActor(),
                'backup_log_id' => $context['backup_log_id'] ?? null,
                'policy' => $context['policy'] ?? null,
                'status' => $context['status'] ?? 'completed',
                'details' => $context['details'] ?? null,
            ]);
        } catch (\Throwable $e) {
            Log::warning('Capsule audit log failed: ' . $e->getMessage());
            return null;
        }
    }

    protected static function resolveActor(): ?string
    {
        if (app()->runningInConsole()) {
            $user = get_current_user();
            return $user ?: 'system';
        }

        try {
            $guard = config('auth.defaults.guard', 'web');
            $user = auth($guard)->user();
            if ($user) {
                return ($user->name ?? $user->email ?? 'user:' . $user->getAuthIdentifier());
            }
        } catch (\Throwable $e) {
            // no auth available
        }

        return 'anonymous';
    }
}
