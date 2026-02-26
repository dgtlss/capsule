<?php

namespace Dgtlss\Capsule\Events;

use Illuminate\Foundation\Events\Dispatchable;

class CleanupStarting
{
    use Dispatchable;

    public function __construct(public bool $dryRun = false)
    {
    }
}
