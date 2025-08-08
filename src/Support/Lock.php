<?php

namespace Dgtlss\Capsule\Support;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Cache;

class Lock
{
    public static function acquire(string $name)
    {
        $store = config('capsule.lock.store');
        $timeout = (int) config('capsule.lock.timeout_seconds', 900);
        $wait = (int) config('capsule.lock.wait_seconds', 0);

        /** @var CacheRepository $cache */
        $cache = $store ? Cache::store($store) : Cache::store();
        $lock = $cache->lock($name, $timeout);

        if ($wait > 0) {
            return $lock->block($wait);
        }
        return $lock->get() ? $lock : null;
    }
}
