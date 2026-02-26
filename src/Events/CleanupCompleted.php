<?php

namespace Dgtlss\Capsule\Events;

use Illuminate\Foundation\Events\Dispatchable;

class CleanupCompleted
{
    use Dispatchable;

    public function __construct(public int $deletedCount, public int $freedBytes, public bool $dryRun = false)
    {
    }
}
