<?php

namespace Dgtlss\Capsule\Contracts;

use Dgtlss\Capsule\Support\BackupContext;

interface StepInterface
{
    /**
     * Execute a custom step in the backup pipeline.
     * Return void or throw an exception on failure.
     */
    public function handle(BackupContext $context): void;
}
