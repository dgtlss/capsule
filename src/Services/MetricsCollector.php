<?php

namespace Dgtlss\Capsule\Services;

use Dgtlss\Capsule\Models\BackupLog;
use Dgtlss\Capsule\Models\BackupMetric;
use Illuminate\Support\Facades\Log;

class MetricsCollector
{
    protected int $rawSize = 0;
    protected int $fileCount = 0;
    protected int $directoryCount = 0;
    protected int $dbDumpCount = 0;
    protected int $dbRawSize = 0;
    protected int $filesRawSize = 0;
    protected array $extensionBreakdown = [];
    protected float $startTime = 0;

    public function start(): void
    {
        $this->startTime = microtime(true);
    }

    public function addFile(string $path, int $size): void
    {
        $this->fileCount++;
        $this->filesRawSize += $size;
        $this->rawSize += $size;

        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION)) ?: '(none)';
        $this->extensionBreakdown[$ext] = ($this->extensionBreakdown[$ext] ?? 0) + $size;
    }

    public function addDirectory(): void
    {
        $this->directoryCount++;
    }

    public function addDatabaseDump(int $size): void
    {
        $this->dbDumpCount++;
        $this->dbRawSize += $size;
        $this->rawSize += $size;
    }

    public function persist(BackupLog $backupLog): ?BackupMetric
    {
        $duration = microtime(true) - $this->startTime;
        $compressedSize = $backupLog->file_size ?? 0;
        $compressionRatio = $this->rawSize > 0 ? $compressedSize / $this->rawSize : null;
        $throughput = $duration > 0 ? $this->rawSize / $duration : null;

        arsort($this->extensionBreakdown);
        $topExtensions = array_slice($this->extensionBreakdown, 0, 20, true);

        try {
            return BackupMetric::create([
                'backup_log_id' => $backupLog->id,
                'raw_size' => $this->rawSize,
                'compressed_size' => $compressedSize,
                'file_count' => $this->fileCount,
                'directory_count' => $this->directoryCount,
                'db_dump_count' => $this->dbDumpCount,
                'db_raw_size' => $this->dbRawSize,
                'files_raw_size' => $this->filesRawSize,
                'duration_seconds' => round($duration, 2),
                'compression_ratio' => $compressionRatio ? round($compressionRatio, 4) : null,
                'throughput_bytes_per_sec' => $throughput ? round($throughput, 0) : null,
                'extension_breakdown' => $topExtensions,
            ]);
        } catch (\Throwable $e) {
            Log::warning('Failed to persist backup metrics', ['exception' => $e]);
            return null;
        }
    }

    public function getRawSize(): int
    {
        return $this->rawSize;
    }

    public function getFileCount(): int
    {
        return $this->fileCount;
    }

    public function getDirectoryCount(): int
    {
        return $this->directoryCount;
    }

    public function getDuration(): float
    {
        return microtime(true) - $this->startTime;
    }
}
