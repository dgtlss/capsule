<?php

namespace Dgtlss\Capsule\Events;

use Dgtlss\Capsule\Support\BackupContext;
use Illuminate\Foundation\Events\Dispatchable;

class BackupUploaded
{
    use Dispatchable;

    public function __construct(public BackupContext $context, public string $remotePath)
    {
    }
}
