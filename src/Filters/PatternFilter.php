<?php

namespace Dgtlss\Capsule\Filters;

use Dgtlss\Capsule\Contracts\FileFilterInterface;
use Dgtlss\Capsule\Support\BackupContext;

class PatternFilter implements FileFilterInterface
{
    protected array $excludePatterns;
    protected array $includePatterns;

    public function __construct()
    {
        $this->excludePatterns = (array) config('capsule.filters.exclude_patterns', []);
        $this->includePatterns = (array) config('capsule.filters.include_patterns', []);
    }

    public function shouldInclude(string $absolutePath, BackupContext $context): bool
    {
        if (!empty($this->includePatterns)) {
            foreach ($this->includePatterns as $pattern) {
                if (fnmatch($pattern, $absolutePath) || fnmatch($pattern, basename($absolutePath))) {
                    return true;
                }
            }
            return false;
        }

        if (!empty($this->excludePatterns)) {
            foreach ($this->excludePatterns as $pattern) {
                if (fnmatch($pattern, $absolutePath) || fnmatch($pattern, basename($absolutePath))) {
                    return false;
                }
            }
        }

        return true;
    }
}
