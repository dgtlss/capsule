<?php

namespace Dgtlss\Capsule\Commands;

use Dgtlss\Capsule\Models\BackupLog;
use Dgtlss\Capsule\Support\Helpers;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class DownloadCommand extends Command
{
    protected $signature = 'capsule:download
        {id? : Backup log ID (defaults to latest successful)}
        {--path= : Local destination path (defaults to storage/app/backups)}';

    protected $description = 'Download a backup from remote storage to a local path';

    public function handle(): int
    {
        $backup = $this->resolveBackup();
        if (!$backup) {
            $this->error('No successful backup found.');
            return self::FAILURE;
        }

        $diskName = $backup->metadata['storage']['disk'] ?? config('capsule.default_disk', 'local');
        $disk = Storage::disk($diskName);

        if (!$backup->file_path || !$disk->exists($backup->file_path)) {
            $this->error("Backup file not found on disk '{$diskName}': {$backup->file_path}");
            return self::FAILURE;
        }

        $destinationDir = $this->option('path') ?? storage_path('app/backups');

        if (!is_dir($destinationDir)) {
            mkdir($destinationDir, 0755, true);
        }

        $fileName = basename($backup->file_path);
        $localPath = rtrim($destinationDir, '/') . '/' . $fileName;

        $this->info("Downloading backup #{$backup->id} (" . Helpers::formatBytes($backup->file_size ?? 0) . ")...");

        $stream = $disk->readStream($backup->file_path);
        if ($stream === false) {
            $this->error('Failed to open remote file stream.');
            return self::FAILURE;
        }

        $temporary = tempnam($destinationDir, '.capsule-download-');
        $out = $temporary !== false ? fopen($temporary, 'wb') : false;
        if ($out === false) {
            fclose($stream);
            $this->error('Cannot create temporary download file.');
            return self::FAILURE;
        }
        try {
            $bytes = stream_copy_to_stream($stream, $out);
        } finally {
            fclose($out);
            fclose($stream);
        }
        if ($bytes === false || $bytes !== $disk->size($backup->file_path) || is_link($localPath) || !rename($temporary, $localPath)) {
            @unlink($temporary);
            $this->error('Download could not be safely completed.');
            return self::FAILURE;
        }

        $this->info("Downloaded to: {$localPath} (" . Helpers::formatBytes($bytes ?: 0) . ")");
        return self::SUCCESS;
    }

    protected function resolveBackup(): ?BackupLog
    {
        $id = $this->argument('id');
        if ($id) {
            return BackupLog::where('id', (int) $id)->where('status', 'success')->first();
        }

        return BackupLog::where('status', 'success')->orderByDesc('created_at')->first();
    }
}
