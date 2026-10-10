<?php

namespace Dgtlss\Capsule\Health\Checks;

use Dgtlss\Capsule\Models\BackupLog;

class BackupHealthCheck
{
    public static function lastSuccessAgeDays(): ?int
    {
        $last = BackupLog::successful()->orderByDesc('created_at')->first();
        if (!$last) return null;
        return max(0, (int) $last->created_at->diffInDays(now(), false));
    }

    public static function recentFailuresCount(int $days = 7): int
    {
        return BackupLog::failed()->where('created_at', '>=', now()->subDays($days))->count();
    }

    public static function storageUsageBytes(): int
    {
        return (int) BackupLog::successful()->sum('file_size');
    }
}
