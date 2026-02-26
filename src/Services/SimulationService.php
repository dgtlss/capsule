<?php

namespace Dgtlss\Capsule\Services;

use Dgtlss\Capsule\Contracts\FileFilterInterface;
use Dgtlss\Capsule\Database\DatabaseDumper;
use Dgtlss\Capsule\Models\BackupLog;
use Dgtlss\Capsule\Support\Helpers;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SimulationService
{
    protected DatabaseDumper $databaseDumper;

    public function __construct(DatabaseDumper $databaseDumper = null)
    {
        $this->databaseDumper = $databaseDumper ?? new DatabaseDumper();
    }

    public function simulate(): array
    {
        $result = [
            'database' => null,
            'files' => null,
            'totals' => [],
            'estimate' => [],
            'warnings' => [],
        ];

        if (config('capsule.database.enabled', true)) {
            $result['database'] = $this->simulateDatabase();
        }

        if (config('capsule.files.enabled', true)) {
            $result['files'] = $this->simulateFiles();
        }

        $rawSize = ($result['database']['total_size'] ?? 0) + ($result['files']['total_size'] ?? 0);
        $fileCount = ($result['files']['file_count'] ?? 0);
        $dbCount = ($result['database']['connection_count'] ?? 0);

        $compressionRatio = $this->estimateCompressionRatio();
        $estimatedArchiveSize = (int) ($rawSize * $compressionRatio);

        $estimatedDuration = $this->estimateDuration($rawSize, $fileCount);

        $result['totals'] = [
            'raw_size' => $rawSize,
            'raw_size_formatted' => Helpers::formatBytes($rawSize),
            'file_count' => $fileCount,
            'database_count' => $dbCount,
        ];

        $result['estimate'] = [
            'compression_ratio' => round($compressionRatio, 2),
            'archive_size' => $estimatedArchiveSize,
            'archive_size_formatted' => Helpers::formatBytes($estimatedArchiveSize),
            'duration_seconds' => $estimatedDuration,
            'duration_formatted' => $this->formatDuration($estimatedDuration),
        ];

        if ($rawSize > 5 * 1024 * 1024 * 1024) {
            $result['warnings'][] = 'Dataset exceeds 5 GB. Consider using --no-local for chunked streaming.';
        }

        if ($fileCount > 100000) {
            $result['warnings'][] = "Large file count ({$fileCount}). Backup may take a while to scan and compress.";
        }

        $diskName = config('capsule.default_disk', 'local');
        $diskConfig = config("filesystems.disks.{$diskName}", []);
        if (($diskConfig['driver'] ?? 'local') === 'local') {
            $localRoot = $diskConfig['root'] ?? storage_path('app');
            $freeSpace = @disk_free_space($localRoot);
            if ($freeSpace !== false && $freeSpace < $rawSize * 1.5) {
                $result['warnings'][] = 'Low disk space. Free: ' . Helpers::formatBytes((int) $freeSpace) . '. Consider using --no-local.';
            }
        }

        $history = $this->getHistoricalComparison($rawSize);
        if ($history) {
            $result['history'] = $history;
        }

        return $result;
    }

    protected function simulateDatabase(): array
    {
        $connections = $this->databaseDumper->resolveConnections();
        $details = [];
        $totalSize = 0;

        foreach ($connections as $connection) {
            $config = config("database.connections.{$connection}");
            $driver = $config['driver'] ?? 'unknown';
            $estimatedSize = $this->estimateDatabaseSize($connection, $config);

            $details[] = [
                'connection' => $connection,
                'driver' => $driver,
                'database' => $config['database'] ?? 'unknown',
                'estimated_size' => $estimatedSize,
                'estimated_size_formatted' => Helpers::formatBytes($estimatedSize),
            ];

            $totalSize += $estimatedSize;
        }

        return [
            'connection_count' => count($connections),
            'connections' => $details,
            'total_size' => $totalSize,
            'total_size_formatted' => Helpers::formatBytes($totalSize),
        ];
    }

    protected function estimateDatabaseSize(string $connection, array $config): int
    {
        $driver = $config['driver'] ?? 'unknown';

        try {
            if ($driver === 'sqlite') {
                $dbPath = $config['database'] ?? '';
                return file_exists($dbPath) ? (int) filesize($dbPath) : 0;
            }

            if ($driver === 'mysql' || $driver === 'mariadb') {
                $dbName = $config['database'] ?? '';
                $rows = DB::connection($connection)
                    ->select("SELECT SUM(data_length + index_length) as size FROM information_schema.tables WHERE table_schema = ?", [$dbName]);
                return (int) ($rows[0]->size ?? 0);
            }

            if ($driver === 'pgsql') {
                $dbName = $config['database'] ?? '';
                $rows = DB::connection($connection)
                    ->select("SELECT pg_database_size(?) as size", [$dbName]);
                return (int) ($rows[0]->size ?? 0);
            }
        } catch (\Throwable $e) {
            Log::warning("Failed to estimate DB size for {$connection}: {$e->getMessage()}");
        }

        return 0;
    }

    protected function simulateFiles(): array
    {
        $paths = config('capsule.files.paths', []);
        $excludePaths = config('capsule.files.exclude_paths', []);
        $filters = $this->resolveFileFilters();

        $totalSize = 0;
        $fileCount = 0;
        $dirCount = 0;
        $largestFiles = [];
        $extensionBreakdown = [];

        foreach ($paths as $path) {
            if (is_dir($path)) {
                $this->scanDirectory($path, $excludePaths, $filters, $totalSize, $fileCount, $dirCount, $largestFiles, $extensionBreakdown);
            } elseif (is_file($path)) {
                if (!$this->passesFilters($path, $filters)) {
                    continue;
                }
                $size = (int) filesize($path);
                $totalSize += $size;
                $fileCount++;
                $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION)) ?: '(none)';
                $extensionBreakdown[$ext] = ($extensionBreakdown[$ext] ?? 0) + $size;
                $this->trackLargestFile($largestFiles, $path, $size);
            }
        }

        arsort($extensionBreakdown);
        $topExtensions = array_slice($extensionBreakdown, 0, 10, true);
        $topExtensionsFormatted = [];
        foreach ($topExtensions as $ext => $size) {
            $topExtensionsFormatted[] = ['extension' => $ext, 'size' => $size, 'size_formatted' => Helpers::formatBytes($size)];
        }

        usort($largestFiles, fn($a, $b) => $b['size'] <=> $a['size']);
        $largestFiles = array_slice($largestFiles, 0, 10);
        foreach ($largestFiles as &$f) {
            $f['size_formatted'] = Helpers::formatBytes($f['size']);
        }

        return [
            'file_count' => $fileCount,
            'directory_count' => $dirCount,
            'total_size' => $totalSize,
            'total_size_formatted' => Helpers::formatBytes($totalSize),
            'largest_files' => $largestFiles,
            'top_extensions' => $topExtensionsFormatted,
        ];
    }

    protected function scanDirectory(string $dir, array $excludePaths, array $filters, int &$totalSize, int &$fileCount, int &$dirCount, array &$largestFiles, array &$extensionBreakdown): void
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
            $filePath = $file->getRealPath();
            if ($filePath === false || empty($filePath)) {
                continue;
            }

            if ($this->shouldExcludePath($filePath, $excludePaths)) {
                continue;
            }

            if ($file->isDir()) {
                $dirCount++;
                continue;
            }

            if (!$this->passesFilters($filePath, $filters)) {
                continue;
            }

            try {
                $size = $file->getSize();
            } catch (\RuntimeException $e) {
                continue;
            }

            $totalSize += $size;
            $fileCount++;

            $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION)) ?: '(none)';
            $extensionBreakdown[$ext] = ($extensionBreakdown[$ext] ?? 0) + $size;

            $this->trackLargestFile($largestFiles, $filePath, $size);
        }
    }

    protected function trackLargestFile(array &$largestFiles, string $path, int $size): void
    {
        if (count($largestFiles) < 10 || $size > ($largestFiles[array_key_last($largestFiles)]['size'] ?? 0)) {
            $largestFiles[] = ['path' => $path, 'size' => $size];
            usort($largestFiles, fn($a, $b) => $b['size'] <=> $a['size']);
            $largestFiles = array_slice($largestFiles, 0, 10);
        }
    }

    protected function shouldExcludePath(string $path, array $excludePaths): bool
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
        $context = new \Dgtlss\Capsule\Support\BackupContext('simulation');
        foreach ($filters as $filter) {
            if (!$filter->shouldInclude($path, $context)) {
                return false;
            }
        }
        return true;
    }

    protected function estimateCompressionRatio(): float
    {
        $recent = BackupLog::successful()
            ->whereNotNull('file_size')
            ->where('file_size', '>', 0)
            ->orderByDesc('created_at')
            ->limit(5)
            ->get();

        if ($recent->isEmpty()) {
            $level = (int) config('capsule.backup.compression_level', 6);
            return match (true) {
                $level <= 2 => 0.65,
                $level <= 5 => 0.45,
                default => 0.35,
            };
        }

        $ratios = [];
        foreach ($recent as $log) {
            $metadata = $log->metadata ?? [];
            if (!empty($metadata['raw_size']) && $metadata['raw_size'] > 0) {
                $ratios[] = $log->file_size / $metadata['raw_size'];
            }
        }

        if (empty($ratios)) {
            return 0.45;
        }

        return array_sum($ratios) / count($ratios);
    }

    protected function estimateDuration(int $rawSize, int $fileCount): float
    {
        $recent = BackupLog::successful()
            ->whereNotNull('file_size')
            ->where('file_size', '>', 0)
            ->orderByDesc('created_at')
            ->limit(10)
            ->get();

        if ($recent->count() >= 2) {
            $durations = [];
            $sizes = [];
            foreach ($recent as $log) {
                $d = $log->duration;
                if ($d && $d > 0) {
                    $durations[] = $d;
                    $sizes[] = $log->file_size;
                }
            }

            if (count($durations) >= 2) {
                $avgBytesPerSecond = array_sum($sizes) / array_sum($durations);
                if ($avgBytesPerSecond > 0) {
                    return round($rawSize / $avgBytesPerSecond, 1);
                }
            }
        }

        $bytesPerSecond = 50 * 1024 * 1024;
        $baseTime = $rawSize / $bytesPerSecond;
        $scanOverhead = $fileCount * 0.0005;

        return round(max(1, $baseTime + $scanOverhead), 1);
    }

    protected function getHistoricalComparison(int $currentRawSize): ?array
    {
        $recent = BackupLog::successful()
            ->whereNotNull('file_size')
            ->where('file_size', '>', 0)
            ->orderByDesc('created_at')
            ->limit(10)
            ->get();

        if ($recent->isEmpty()) {
            return null;
        }

        $avgSize = (int) $recent->avg('file_size');
        $lastSize = (int) $recent->first()->file_size;
        $avgDuration = $recent->avg(fn($l) => $l->duration ?? 0);

        return [
            'last_backup_size' => $lastSize,
            'last_backup_size_formatted' => Helpers::formatBytes($lastSize),
            'avg_backup_size' => $avgSize,
            'avg_backup_size_formatted' => Helpers::formatBytes($avgSize),
            'avg_duration_seconds' => round($avgDuration, 1),
            'backup_count' => $recent->count(),
        ];
    }

    protected function formatDuration(float $seconds): string
    {
        if ($seconds < 1) {
            return '< 1 second';
        }
        if ($seconds < 60) {
            return round($seconds) . ' second(s)';
        }
        $minutes = floor($seconds / 60);
        $remaining = round($seconds - ($minutes * 60));
        if ($remaining > 0) {
            return "{$minutes} minute(s) {$remaining} second(s)";
        }
        return "{$minutes} minute(s)";
    }
}
