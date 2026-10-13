<?php

namespace Dgtlss\Capsule\Tests\Unit;

use Dgtlss\Capsule\Support\Lock;
use Dgtlss\Capsule\Tests\TestCase;
use Illuminate\Contracts\Cache\Lock as CacheLock;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Mockery;

class LockTest extends TestCase
{
    public function test_waiting_acquisition_returns_releasable_lock_object(): void
    {
        config(['capsule.lock.wait_seconds' => 1, 'capsule.lock.store' => 'array']);
        $lock = Lock::acquire('capsule-test-wait');
        $this->assertInstanceOf(CacheLock::class, $lock);
        $this->assertTrue($lock->release());
        $next = Lock::acquire('capsule-test-wait');
        $this->assertNotNull($next);
        $next->release();
    }

    public function test_lock_timeout_returns_null_without_claiming_ownership(): void
    {
        config(['capsule.lock.wait_seconds' => 1, 'capsule.lock.timeout_seconds' => 900, 'capsule.lock.store' => 'array']);
        $lock = Mockery::mock(CacheLock::class);
        $lock->shouldReceive('block')->once()->with(1)->andThrow(new LockTimeoutException());
        $store = Mockery::mock();
        $store->shouldReceive('lock')->once()->with('busy', 900)->andReturn($lock);
        Cache::shouldReceive('store')->once()->with('array')->andReturn($store);
        $this->assertNull(Lock::acquire('busy'));
    }
}
