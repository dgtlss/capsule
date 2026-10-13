<?php

namespace Dgtlss\Capsule\Tests\Feature;

use Dgtlss\Capsule\Events\BackupStarting;
use Dgtlss\Capsule\Facades\Capsule;
use Dgtlss\Capsule\Services\BackupService;
use Dgtlss\Capsule\Services\ChunkedBackupService;
use Dgtlss\Capsule\Support\Lock;
use Dgtlss\Capsule\Tests\TestCase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;

class BackupCoordinationTest extends TestCase
{
    public function test_direct_services_and_facade_refuse_while_another_operation_holds_lock(): void
    {
        config(['capsule.lock.wait_seconds' => 0]);
        Event::fake([BackupStarting::class]);
        $held = Lock::acquire('capsule:backup');
        $this->assertNotNull($held);
        try {
            foreach ([BackupService::class, ChunkedBackupService::class] as $class) {
                $service = app($class);
                $this->assertFalse($service->run());
                $this->assertStringContainsString('running', $service->getLastError());
            }
            $this->assertFalse(Capsule::backup());
            $this->assertSame(1, Artisan::call('capsule:cleanup', ['--format' => 'json']));
            $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
            $this->assertSame('failed', $payload['status']);
            $this->assertSame(0, $payload['deleted_count']);
            $this->assertDatabaseCount('backup_logs', 0);
            Event::assertNotDispatched(BackupStarting::class);
            // Failed contenders must not release the current owner's lock.
            $this->assertNull(Lock::acquire('capsule:backup'));
        } finally {
            $held->release();
        }
        $next = Lock::acquire('capsule:backup');
        $this->assertNotNull($next);
        $next->release();
    }

    public function test_service_releases_lock_after_preflight_failure(): void
    {
        foreach ([BackupService::class, ChunkedBackupService::class] as $class) {
            $service = app($class);
            config(['capsule.default_disk' => 'missing-disk']);
            $this->assertFalse($service->run());
            config(['capsule.default_disk' => 'capsule-test']);
            $next = Lock::acquire('capsule:backup');
            $this->assertNotNull($next, $class . ' leaked its lock after failure.');
            $next->release();
        }
    }
}
