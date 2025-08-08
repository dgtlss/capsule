<?php

namespace Dgtlss\Capsule\Commands;

use Dgtlss\Capsule\Models\BackupLog;
use Illuminate\Console\Command;

class ListCommand extends Command
{
    protected $signature = 'capsule:list {--limit=50} {--format=table : table|json}';
    protected $description = 'List recent backups';

    public function handle(): int
    {
        $limit = (int) $this->option('limit');
        $format = $this->option('format');

        $logs = BackupLog::orderByDesc('created_at')->limit($limit)->get();

        if ($format === 'json') {
            $this->line($logs->map(function ($log) {
                return [
                    'id' => $log->id,
                    'status' => $log->status,
                    'size' => $log->file_size,
                    'path' => $log->file_path,
                    'started_at' => optional($log->started_at)->toISOString(),
                    'completed_at' => optional($log->completed_at)->toISOString(),
                ];
            })->values()->toJson(JSON_PRETTY_PRINT));
            return self::SUCCESS;
        }

        $this->table(['ID', 'Status', 'Size', 'Path', 'Created'], $logs->map(function ($log) {
            return [
                $log->id,
                $log->status,
                $log->formattedFileSize,
                $log->file_path,
                $log->created_at,
            ];
        })->toArray());

        return self::SUCCESS;
    }
}
