<?php

namespace Dgtlss\Capsule\Support;

use Dgtlss\Capsule\Models\BackupLog;
use Dgtlss\Capsule\Models\BackupMetric;

class BackupReport
{
    public static function build(BackupLog $backupLog, float $duration): array
    {
        $metric = $backupLog->metric;

        $report = [
            'id' => $backupLog->id,
            'status' => $backupLog->status,
            'tag' => $backupLog->tag,
            'duration' => round($duration, 2),
            'duration_formatted' => self::formatDuration($duration),
            'archive' => basename($backupLog->file_path ?? ''),
            'compressed_size' => $backupLog->file_size ?? 0,
            'compressed_size_formatted' => Helpers::formatBytes($backupLog->file_size ?? 0),
            'storage_disk' => config('capsule.default_disk', 'local'),
            'path' => $backupLog->file_path,
        ];

        if ($metric) {
            $report['raw_size'] = $metric->raw_size;
            $report['raw_size_formatted'] = Helpers::formatBytes($metric->raw_size);
            $report['compression_ratio'] = $metric->compression_ratio ? round($metric->compression_ratio, 2) . 'x' : 'N/A';
            $report['file_count'] = $metric->file_count;
            $report['directory_count'] = $metric->directory_count;
            $report['db_dumps'] = $metric->db_dump_count;
            $report['db_size_formatted'] = Helpers::formatBytes($metric->db_raw_size);
            $report['files_size_formatted'] = Helpers::formatBytes($metric->files_raw_size);
            $report['throughput'] = $metric->throughput_bytes_per_sec
                ? Helpers::formatBytes((int) $metric->throughput_bytes_per_sec) . '/s'
                : 'N/A';
        }

        $anomalies = $backupLog->metadata['anomalies'] ?? [];
        if (!empty($anomalies)) {
            $report['anomalies'] = $anomalies;
        }

        $schedule = config('capsule.schedule');
        if ($schedule && ($schedule['enabled'] ?? false)) {
            $freq = $schedule['frequency'] ?? 'daily';
            $time = $schedule['time'] ?? '';
            $report['next_run'] = self::describeNextRun($freq, $time);
        }

        return $report;
    }

    public static function render(array $report): string
    {
        $lines = [];
        $w = 55;
        $border = str_repeat("\u{2500}", $w);

        $lines[] = "\u{250C}{$border}\u{2510}";
        $statusIcon = $report['status'] === 'success' ? "\u{2705}" : "\u{274C}";
        $title = "  {$statusIcon}  Backup #{$report['id']} completed successfully";
        $lines[] = "\u{2502}" . str_pad($title, $w + 2) . "\u{2502}";
        $lines[] = "\u{251C}{$border}\u{2524}";

        $rows = [
            ['Duration', $report['duration_formatted']],
            ['Archive', $report['archive']],
            ['Size', $report['compressed_size_formatted'] . (isset($report['raw_size_formatted']) ? " (from {$report['raw_size_formatted']})" : '')],
        ];

        if (isset($report['compression_ratio'])) {
            $rows[] = ['Compression', $report['compression_ratio']];
        }

        if (isset($report['file_count'])) {
            $fileDesc = number_format($report['file_count']) . ' files';
            if ($report['directory_count'] > 0) {
                $fileDesc .= " in {$report['directory_count']} dirs";
            }
            $rows[] = ['Files', $fileDesc];
        }

        if (isset($report['db_dumps']) && $report['db_dumps'] > 0) {
            $rows[] = ['Database', "{$report['db_dumps']} dump(s) - {$report['db_size_formatted']}"];
        }

        $rows[] = ['Storage', $report['storage_disk']];

        if (isset($report['throughput'])) {
            $rows[] = ['Throughput', $report['throughput']];
        }

        if (!empty($report['tag'])) {
            $rows[] = ['Tag', $report['tag']];
        }

        if (isset($report['next_run'])) {
            $rows[] = ['Next run', $report['next_run']];
        }

        foreach ($rows as $row) {
            $label = str_pad("  {$row[0]}", 18);
            $value = $row[1];
            $line = $label . $value;
            $lines[] = "\u{2502}" . str_pad($line, $w + 2) . "\u{2502}";
        }

        $lines[] = "\u{2514}{$border}\u{2518}";

        if (!empty($report['anomalies'])) {
            $lines[] = '';
            foreach ($report['anomalies'] as $anomaly) {
                $icon = ($anomaly['severity'] ?? 'warning') === 'critical' ? "\u{1F6A8}" : "\u{26A0}\u{FE0F}";
                $lines[] = "  {$icon}  {$anomaly['message']}";
            }
        }

        return implode("\n", $lines);
    }

    protected static function formatDuration(float $seconds): string
    {
        if ($seconds < 1) {
            return '< 1s';
        }
        if ($seconds < 60) {
            return round($seconds, 1) . 's';
        }
        $m = floor($seconds / 60);
        $s = round($seconds - ($m * 60));
        return "{$m}m {$s}s";
    }

    protected static function describeNextRun(string $frequency, string $time): string
    {
        return match ($frequency) {
            'hourly' => 'Next hour',
            'daily' => "Tomorrow at {$time}",
            'twiceDaily' => 'Twice daily',
            'weekly' => "Next week at {$time}",
            'monthly' => "Next month at {$time}",
            default => str_contains($frequency, ' ') ? "Cron: {$frequency}" : "At {$time}",
        };
    }
}
