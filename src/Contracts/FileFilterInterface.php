<?php

namespace Dgtlss\Capsule\Contracts;

use Dgtlss\Capsule\Support\BackupContext;

interface FileFilterInterface
{
    /**
     * Decide whether a file at absolute path should be included in the backup.
     * Return true to include, false to skip.
     */
    public function shouldInclude(string $absolutePath, BackupContext $context): bool;
}
