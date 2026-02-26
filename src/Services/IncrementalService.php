<?php

namespace Dgtlss\Capsule\Services;

use Dgtlss\Capsule\Contracts\FileFilterInterface;
use Dgtlss\Capsule\Models\BackupLog;
use Dgtlss\Capsule\Models\BackupSnapshot;
use Dgtlss\Capsule\Support\BackupContext;
use Illuminate\Support\Facades\Log;

class IncrementalService
{
    /**
     * Build the current file index for the configured backup paths.
     * Returns [relative_path => ['size' => int, 'mtime' => int, 'hash' => string|null], ...]
     *
     * @param bool $computeHashes  Full SHA-256 per file (slower but exact)
     */
    public function buildCurrentIndex(bool $computeHashes = false): array
    {
        $paths = config('capsule.files.paths', []);
        $excludePaths = config('capsule.files.exclude_paths', []);
        $filters = $this->resolveFileFilters();
        $index = [];

        foreach ($paths as $basePath) {
            if (is_dir($basePath)) {
                $this->indexDirectory($basePath, $excludePaths, $filters, $index, $computeHashes);
            } elseif (is_file($basePath)) {
                if (!$this->passesFilters($basePath, $filters)) {
                    continue;
                }
                $index[$basePath] = $this->fileEntry($basePath, $computeHashes);
            }
        }

        return $index;
    }

    /**
     * Compare current index against a base snapshot and return the diff.
     */
    public function diff(array $currentIndex, BackupSnapshot $baseSnapshot): array
    {
        $baseIndex = $baseSnapshot->getFileIndexArray();

        $added = [];
        $modified = [];
        $removed = [];

        foreach ($currentIndex as $path => $current) {
            if (!isset($baseIndex[$path])) {
                $added[$path] = $current;
                continue;
            }

            $base = $baseIndex[$path];
            $changed = false;

            if (($current['size'] ?? 0) !== ($base['size'] ?? 0)) {
                $changed = true;
            } elseif (($current['mtime'] ?? 0) !== ($base['mtime'] ?? 0)) {
                $changed = true;
            }

            if ($changed) {
                $modified[$path] = $current;
            }
        }

        foreach ($baseIndex as $path => $base) {
            if (!isset($currentIndex[$path])) {
                $removed[$path] = $base;
            }
        }

        return [
            'added' => $added,
            'modified' => $modified,
            'removed' => $removed,
            'unchanged_count' => count($currentIndex) - count($added) - count($modified),
            'total_current' => count($currentIndex),
            'total_base' => count($baseIndex),
            'changed_size' => $this->sumSize($added) + $this->sumSize($modified),
        ];
    }

    /**
     * Get the list of absolute file paths that need to be included in an incremental backup.
     */
    public function getChangedFiles(array $diff): array
    {
        return array_merge(
            array_keys($diff['added']),
            array_keys($diff['modified'])
        );
    }

    /**
     * Find the latest full backup snapshot to use as a base.
     */
    public function getLatestFullSnapshot(): ?BackupSnapshot
    {
        return BackupSnapshot::where('type', 'full')
            ->whereHas('backupLog', function ($q) {
                $q->where('status', 'success');
            })
            ->orderByDesc('created_at')
            ->first();
    }

    /**
     * Save a snapshot for a completed backup.
     */
    public function saveSnapshot(BackupLog $backupLog, array $fileIndex, string $type = 'full', ?int $baseSnapshotId = null): BackupSnapshot
    {
        $snapshot = new BackupSnapshot();
        $snapshot->backup_log_id = $backupLog->id;
        $snapshot->type = $type;
        $snapshot->base_snapshot_id = $baseSnapshotId;
        $snapshot->setFileIndexFromArray($fileIndex);
        $snapshot->total_files = count($fileIndex);
        $snapshot->total_size = $this->sumSize($fileIndex);
        $snapshot->save();

        return $snapshot;
    }

    protected function indexDirectory(string $dir, array $excludePaths, array $filters, array &$index, bool $computeHashes): void
    {
        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::SELF_FIRST
            );
        } catch (\Throwable $e) {
            return;
        }

        foreach ($iterator as $file) {
            if ($file->isDir()) {
                continue;
            }

            $filePath = $file->getRealPath();
            if ($filePath === false || empty($filePath)) {
                continue;
            }

            if ($this->shouldExclude($filePath, $excludePaths)) {
                continue;
            }

            if (!$this->passesFilters($filePath, $filters)) {
                continue;
            }

            try {
                $index[$filePath] = $this->fileEntry($filePath, $computeHashes);
            } catch (\RuntimeException $e) {
                continue;
            }
        }
    }

    protected function fileEntry(string $path, bool $computeHash): array
    {
        $entry = [
            'size' => (int) @filesize($path),
            'mtime' => (int) @filemtime($path),
        ];

        if ($computeHash) {
            $entry['hash'] = @hash_file('sha256', $path) ?: null;
        }

        return $entry;
    }

    protected function shouldExclude(string $path, array $excludePaths): bool
    {
        $normalizedPath = rtrim($path, '/');

        foreach ($excludePaths as $excludePath) {
            $normalizedExclude = rtrim($excludePath, '/');
            if ($normalizedPath === $normalizedExclude) {
                return true;
            }
            if (strpos($normalizedPath, $normalizedExclude . '/') === 0) {
                return true;
            }
            if (!str_contains($excludePath, '/') && basename($path) === $excludePath) {
                return true;
            }
        }

        return false;
    }

    protected function resolveFileFilters(): array
    {
        $filters = [];
        foreach ((array) config('capsule.extensibility.file_filters', []) as $class) {
            try {
                $instance = app($class);
                if ($instance instanceof FileFilterInterface) {
                    $filters[] = $instance;
                }
            } catch (\Throwable $e) {
                // skip
            }
        }
        return $filters;
    }

    protected function passesFilters(string $path, array $filters): bool
    {
        $context = new BackupContext('incremental');
        foreach ($filters as $filter) {
            if (!$filter->shouldInclude($path, $context)) {
                return false;
            }
        }
        return true;
    }

    protected function sumSize(array $entries): int
    {
        $total = 0;
        foreach ($entries as $entry) {
            $total += $entry['size'] ?? 0;
        }
        return $total;
    }
}
