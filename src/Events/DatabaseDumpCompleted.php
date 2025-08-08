<?php

namespace Dgtlss\Capsule\Events;

use Dgtlss\Capsule\Support\BackupContext;
use Illuminate\Foundation\Events\Dispatchable;

class DatabaseDumpCompleted
{
    use Dispatchable;

    public function __construct(public BackupContext $context)
    {
    }
}
