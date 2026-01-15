<?php

namespace Dgtlss\Capsule\Facades;

use Illuminate\Support\Facades\Facade;

class Capsule extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Dgtlss\Capsule\Services\BackupService::class;
    }

    public static function restore(string|int|null $id = null, array $options = []): bool
    {
        $service = app(\Dgtlss\Capsule\Services\RestoreService::class);
        
        return $id 
            ? $service->restoreById($id, $options)
            : $service->restoreLatest($options);
    }

    public static function verify(string|int $backupId): bool
    {
        $service = app(\Dgtlss\Capsule\Services\VerificationService::class);
        return $service->verify($backupId);
    }
}
