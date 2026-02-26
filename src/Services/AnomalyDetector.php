<?php

namespace Dgtlss\Capsule\Services;

use Dgtlss\Capsule\Models\BackupLog;
use Dgtlss\Capsule\Models\BackupMetric;
use Dgtlss\Capsule\Support\Helpers;
use Illuminate\Support\Facades\Log;

class AnomalyDetector
{
    protected float $sizeThreshold;
    protected float $durationThreshold;

    public function __construct()
    {
        $this->sizeThreshold = (float) config('capsule.anomaly.size_deviation_percent', 200);
        $this->durationThreshold = (float) config('capsule.anomaly.duration_deviation_percent', 300);
    }

    /**
     * Analyze a completed backup for anomalies by comparing against recent history.
     * Returns an array of anomaly descriptions, empty if everything looks normal.
     */
    public function analyze(BackupLog $backupLog): array
    {
        $recent = BackupMetric::where('backup_log_id', '!=', $backupLog->id)
            ->orderByDesc('created_at')
            ->limit(10)
            ->get();

        if ($recent->count() < 3) {
            return [];
        }

        $anomalies = [];
        $currentMetric = $backupLog->metric;

        if (!$currentMetric) {
            return [];
        }

        $avgSize = $recent->avg('compressed_size');
        $avgRawSize = $recent->avg('raw_size');
        $avgDuration = $recent->avg('duration_seconds');
        $avgFileCount = $recent->avg('file_count');

        if ($avgSize > 0 && $currentMetric->compressed_size > 0) {
            $sizeDeviation = (($currentMetric->compressed_size - $avgSize) / $avgSize) * 100;

            if (abs($sizeDeviation) > $this->sizeThreshold) {
                $direction = $sizeDeviation > 0 ? 'larger' : 'smaller';
                $anomalies[] = [
                    'type' => 'size',
                    'severity' => abs($sizeDeviation) > $this->sizeThreshold * 2 ? 'critical' : 'warning',
                    'message' => sprintf(
                        'Backup is %s%% %s than the 10-backup average (%s vs avg %s)',
                        round(abs($sizeDeviation)),
                        $direction,
                        Helpers::formatBytes($currentMetric->compressed_size),
                        Helpers::formatBytes((int) $avgSize)
                    ),
                    'current' => $currentMetric->compressed_size,
                    'average' => (int) $avgSize,
                    'deviation_percent' => round($sizeDeviation, 1),
                ];
            }
        }

        if ($avgDuration > 0.5 && $currentMetric->duration_seconds > 0) {
            $durationDeviation = (($currentMetric->duration_seconds - $avgDuration) / $avgDuration) * 100;

            if ($durationDeviation > $this->durationThreshold) {
                $anomalies[] = [
                    'type' => 'duration',
                    'severity' => $durationDeviation > $this->durationThreshold * 2 ? 'critical' : 'warning',
                    'message' => sprintf(
                        'Backup took %s%% longer than average (%ss vs avg %ss)',
                        round($durationDeviation),
                        round($currentMetric->duration_seconds, 1),
                        round($avgDuration, 1)
                    ),
                    'current' => $currentMetric->duration_seconds,
                    'average' => round($avgDuration, 1),
                    'deviation_percent' => round($durationDeviation, 1),
                ];
            }
        }

        if ($avgFileCount > 0 && $currentMetric->file_count > 0) {
            $fileDeviation = (($currentMetric->file_count - $avgFileCount) / $avgFileCount) * 100;

            if (abs($fileDeviation) > $this->sizeThreshold) {
                $direction = $fileDeviation > 0 ? 'more' : 'fewer';
                $anomalies[] = [
                    'type' => 'file_count',
                    'severity' => 'warning',
                    'message' => sprintf(
                        '%s%% %s files than average (%s vs avg %s)',
                        round(abs($fileDeviation)),
                        $direction,
                        number_format($currentMetric->file_count),
                        number_format((int) $avgFileCount)
                    ),
                    'current' => $currentMetric->file_count,
                    'average' => (int) $avgFileCount,
                    'deviation_percent' => round($fileDeviation, 1),
                ];
            }
        }

        if ($currentMetric->compression_ratio !== null && $currentMetric->compression_ratio > 0.9) {
            $avgRatio = $recent->avg('compression_ratio');
            if ($avgRatio && $avgRatio < 0.7 && $currentMetric->compression_ratio > $avgRatio * 1.5) {
                $anomalies[] = [
                    'type' => 'compression',
                    'severity' => 'warning',
                    'message' => sprintf(
                        'Compression efficiency dropped significantly (%.2fx vs avg %.2fx). Data may have changed character.',
                        $currentMetric->compression_ratio,
                        $avgRatio
                    ),
                    'current' => $currentMetric->compression_ratio,
                    'average' => round($avgRatio, 3),
                ];
            }
        }

        if (!empty($anomalies)) {
            Log::warning('Capsule anomaly detected for backup #' . $backupLog->id, [
                'anomalies' => $anomalies,
            ]);
        }

        return $anomalies;
    }
}
