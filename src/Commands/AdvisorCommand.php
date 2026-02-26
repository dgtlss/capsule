<?php

namespace Dgtlss\Capsule\Commands;

use Dgtlss\Capsule\Services\ScheduleAdvisor;
use Dgtlss\Capsule\Support\Helpers;
use Illuminate\Console\Command;

class AdvisorCommand extends Command
{
    protected $signature = 'capsule:advisor {--format=table : Output format (table|json)}';
    protected $description = 'Analyze backup trends and get scheduling recommendations';

    public function handle(): int
    {
        $advisor = new ScheduleAdvisor();
        $result = $advisor->analyze();
        $format = $this->option('format');

        if ($format === 'json') {
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return self::SUCCESS;
        }

        if ($result['status'] === 'insufficient_data') {
            $this->warn($result['message']);
            return self::SUCCESS;
        }

        $trends = $result['trends'];
        $this->info('Backup Trends (' . $trends['sample_count'] . ' samples)');
        $this->newLine();

        $this->table(['Metric', 'Value'], [
            ['Average backup size', $trends['avg_compressed_size_formatted']],
            ['Average raw data', $trends['avg_raw_size_formatted']],
            ['Average duration', $trends['avg_duration'] . 's'],
            ['Average compression', $trends['avg_compression_ratio'] ? $trends['avg_compression_ratio'] . 'x' : 'N/A'],
            ['Average throughput', $trends['avg_throughput_formatted']],
            ['Average file count', number_format($trends['avg_file_count'])],
            ['Size growth', $trends['size_growth_percent'] . '%'],
            ['Duration growth', $trends['duration_growth_percent'] . '%'],
            ['Size volatility', $trends['size_volatility']],
            ['Failure rate (30d)', round($trends['failure_rate'] * 100, 1) . '%'],
        ]);

        if (empty($result['recommendations'])) {
            $this->newLine();
            $this->info('No recommendations -- your backup configuration looks good.');
            return self::SUCCESS;
        }

        $this->newLine();
        $this->info('Recommendations');
        $this->newLine();

        foreach ($result['recommendations'] as $rec) {
            $icon = match ($rec['type']) {
                'critical' => '<fg=red>[CRITICAL]</>',
                'warning' => '<fg=yellow>[WARNING]</>',
                'suggestion' => '<fg=cyan>[SUGGESTION]</>',
                default => '[INFO]',
            };
            $this->line("  {$icon} <comment>[{$rec['category']}]</comment> {$rec['message']}");
        }

        return self::SUCCESS;
    }
}
