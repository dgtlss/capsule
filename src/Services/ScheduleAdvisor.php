<?php

namespace Dgtlss\Capsule\Services;

use Dgtlss\Capsule\Models\BackupLog;
use Dgtlss\Capsule\Models\BackupMetric;
use Dgtlss\Capsule\Support\Helpers;

class ScheduleAdvisor
{
    public function analyze(): array
    {
        $metrics = BackupMetric::orderByDesc('created_at')->limit(30)->get();

        if ($metrics->count() < 3) {
            return [
                'status' => 'insufficient_data',
                'message' => 'Need at least 3 backups to generate recommendations.',
                'recommendations' => [],
                'trends' => [],
            ];
        }

        $recommendations = [];
        $trends = $this->computeTrends($metrics);

        if ($trends['size_growth_percent'] > 20) {
            $recommendations[] = [
                'type' => 'warning',
                'category' => 'growth',
                'message' => "Backup size has grown {$trends['size_growth_percent']}% over the last {$metrics->count()} backups. Consider reviewing what's being backed up or increasing storage budget.",
            ];
        }

        if ($trends['duration_growth_percent'] > 50) {
            $recommendations[] = [
                'type' => 'warning',
                'category' => 'performance',
                'message' => "Backup duration has increased {$trends['duration_growth_percent']}%. Consider using --no-local for chunked streaming, or reducing the dataset.",
            ];
        }

        if ($trends['avg_duration'] > 300) {
            $currentFreq = config('capsule.schedule.frequency', 'daily');
            if ($currentFreq === 'hourly') {
                $recommendations[] = [
                    'type' => 'critical',
                    'category' => 'schedule',
                    'message' => "Backups average " . round($trends['avg_duration'] / 60, 1) . " minutes but run hourly. Consider switching to a less frequent schedule.",
                ];
            }
        }

        if ($trends['avg_duration'] < 10 && $trends['avg_compressed_size'] < 50 * 1024 * 1024) {
            $currentFreq = config('capsule.schedule.frequency', 'daily');
            if (in_array($currentFreq, ['daily', 'weekly', 'monthly'])) {
                $recommendations[] = [
                    'type' => 'suggestion',
                    'category' => 'schedule',
                    'message' => "Backups are fast (" . round($trends['avg_duration'], 1) . "s) and small (" . Helpers::formatBytes((int) $trends['avg_compressed_size']) . "). You could safely increase frequency to hourly for better RPO.",
                ];
            }
        }

        if ($trends['failure_rate'] > 0.1) {
            $pct = round($trends['failure_rate'] * 100);
            $recommendations[] = [
                'type' => 'critical',
                'category' => 'reliability',
                'message' => "{$pct}% of recent backups have failed. Run capsule:diagnose to investigate.",
            ];
        }

        if ($trends['avg_compression_ratio'] !== null && $trends['avg_compression_ratio'] > 0.8) {
            $recommendations[] = [
                'type' => 'suggestion',
                'category' => 'optimization',
                'message' => "Compression ratio is low ({$trends['avg_compression_ratio']}x). Data may already be compressed. Consider lowering compression level for faster backups.",
            ];
        }

        if ($trends['size_volatility'] > 0.5) {
            $recommendations[] = [
                'type' => 'warning',
                'category' => 'anomaly',
                'message' => "Backup sizes are highly variable (coefficient of variation: " . round($trends['size_volatility'], 2) . "). This may indicate inconsistent data or transient large files.",
            ];
        }

        return [
            'status' => 'ok',
            'message' => count($recommendations) . ' recommendation(s) based on ' . $metrics->count() . ' recent backups.',
            'recommendations' => $recommendations,
            'trends' => $trends,
        ];
    }

    protected function computeTrends($metrics): array
    {
        $sizes = $metrics->pluck('compressed_size')->filter(fn($s) => $s > 0)->values();
        $durations = $metrics->pluck('duration_seconds')->filter(fn($d) => $d > 0)->values();
        $compressionRatios = $metrics->pluck('compression_ratio')->filter(fn($r) => $r !== null && $r > 0)->values();

        $sizeGrowth = 0;
        if ($sizes->count() >= 3) {
            $oldest = $sizes->slice(-3)->avg();
            $newest = $sizes->take(3)->avg();
            if ($oldest > 0) {
                $sizeGrowth = round((($newest - $oldest) / $oldest) * 100, 1);
            }
        }

        $durationGrowth = 0;
        if ($durations->count() >= 3) {
            $oldest = $durations->slice(-3)->avg();
            $newest = $durations->take(3)->avg();
            if ($oldest > 0) {
                $durationGrowth = round((($newest - $oldest) / $oldest) * 100, 1);
            }
        }

        $sizeVolatility = 0;
        if ($sizes->count() >= 3) {
            $mean = $sizes->avg();
            if ($mean > 0) {
                $variance = $sizes->map(fn($s) => pow($s - $mean, 2))->avg();
                $sizeVolatility = sqrt($variance) / $mean;
            }
        }

        $recentLogs = BackupLog::where('created_at', '>=', now()->subDays(30))->get();
        $failureRate = $recentLogs->count() > 0
            ? $recentLogs->where('status', 'failed')->count() / $recentLogs->count()
            : 0;

        return [
            'avg_compressed_size' => $sizes->avg() ?: 0,
            'avg_compressed_size_formatted' => Helpers::formatBytes((int) ($sizes->avg() ?: 0)),
            'avg_raw_size' => $metrics->avg('raw_size') ?: 0,
            'avg_raw_size_formatted' => Helpers::formatBytes((int) ($metrics->avg('raw_size') ?: 0)),
            'avg_duration' => round($durations->avg() ?: 0, 1),
            'avg_compression_ratio' => $compressionRatios->isNotEmpty() ? round($compressionRatios->avg(), 3) : null,
            'avg_file_count' => round($metrics->avg('file_count') ?: 0),
            'avg_throughput_formatted' => Helpers::formatBytes((int) ($metrics->avg('throughput_bytes_per_sec') ?: 0)) . '/s',
            'size_growth_percent' => $sizeGrowth,
            'duration_growth_percent' => $durationGrowth,
            'size_volatility' => round($sizeVolatility, 3),
            'failure_rate' => round($failureRate, 3),
            'sample_count' => $metrics->count(),
        ];
    }
}
