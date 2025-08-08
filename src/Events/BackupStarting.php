<?php

namespace Dgtlss\Capsule\Events;

use Dgtlss\Capsule\Support\BackupContext;
use Illuminate\Foundation\Events\Dispatchable;

class BackupStarting
{
    use Dispatchable;

    public function __construct(public BackupContext $context)
    {
    }
}
