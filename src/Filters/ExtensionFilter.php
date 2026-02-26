<?php

namespace Dgtlss\Capsule\Filters;

use Dgtlss\Capsule\Contracts\FileFilterInterface;
use Dgtlss\Capsule\Support\BackupContext;

class ExtensionFilter implements FileFilterInterface
{
    protected array $excludeExtensions;
    protected array $includeExtensions;

    public function __construct()
    {
        $this->excludeExtensions = array_map(
            fn($ext) => ltrim(strtolower($ext), '.'),
            (array) config('capsule.filters.exclude_extensions', [])
        );
        $this->includeExtensions = array_map(
            fn($ext) => ltrim(strtolower($ext), '.'),
            (array) config('capsule.filters.include_extensions', [])
        );
    }

    public function shouldInclude(string $absolutePath, BackupContext $context): bool
    {
        $ext = strtolower(pathinfo($absolutePath, PATHINFO_EXTENSION));

        if (!empty($this->includeExtensions)) {
            return in_array($ext, $this->includeExtensions, true);
        }

        if (!empty($this->excludeExtensions)) {
            return !in_array($ext, $this->excludeExtensions, true);
        }

        return true;
    }
}
