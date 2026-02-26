<?php

namespace Dgtlss\Capsule\Filters;

use Dgtlss\Capsule\Contracts\FileFilterInterface;
use Dgtlss\Capsule\Support\BackupContext;

class MaxFileSizeFilter implements FileFilterInterface
{
    protected int $maxBytes;

    public function __construct(int $maxBytes = 104857600) // 100MB default
    {
        $this->maxBytes = config('capsule.filters.max_file_size_bytes', $maxBytes);
    }

    public function shouldInclude(string $absolutePath, BackupContext $context): bool
    {
        $size = @filesize($absolutePath);
        if ($size === false) {
            return true;
        }

        return $size <= $this->maxBytes;
    }
}
