<?php

namespace Dgtlss\Capsule\Commands;

use Dgtlss\Capsule\Models\BackupLog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use ZipArchive;

class InspectCommand extends Command
{
    protected $signature = 'capsule:inspect {id} {--format=table : table|json}';
    protected $description = 'Inspect a specific backup and show manifest details';

    public function handle(): int
    {
        $temporary = null;
        $zip = null;
        try {
            $log = BackupLog::find((int) $this->argument('id'));
            if (!$log || $log->status !== 'success' || !$log->file_path) {
                throw new \RuntimeException('Backup not found or not successful.');
            }
            $diskName = $log->metadata['storage']['disk'] ?? config('capsule.default_disk', 'local');
            $disk = Storage::disk($diskName);
            if (!$disk->exists($log->file_path)) {
                throw new \RuntimeException('Backup file not found in storage.');
            }
            $temporary = tempnam(sys_get_temp_dir(), 'capsule_inspect_');
            $input = $disk->readStream($log->file_path);
            $output = $temporary !== false ? fopen($temporary, 'wb') : false;
            if ($input === false || $output === false) {
                if (is_resource($input)) fclose($input);
                if (is_resource($output)) fclose($output);
                throw new \RuntimeException('Cannot read backup archive.');
            }
            try {
                $bytes = stream_copy_to_stream($input, $output);
            } finally {
                fclose($input);
                fclose($output);
            }
            if ($bytes === false || $bytes !== $disk->size($log->file_path)) {
                throw new \RuntimeException('Incomplete archive download.');
            }
            $zip = new ZipArchive();
            if ($zip->open($temporary, ZipArchive::CHECKCONS) !== true) {
                $zip = null;
                throw new \RuntimeException('Failed to open ZIP.');
            }
            $password = config('capsule.security.backup_password') ?? env('CAPSULE_BACKUP_PASSWORD');
            if (is_string($password) && $password !== '') $zip->setPassword($password);
            $raw = $zip->getFromName('manifest.json');
            if ($raw === false) {
                throw new \RuntimeException('Manifest is missing or the archive password is incorrect.');
            }
            $manifest = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            $result = ['id' => $log->id, 'status' => 'success', 'size' => $log->file_size,
                'disk' => $diskName, 'path' => $log->file_path, 'manifest' => $manifest];
            if ($this->option('format') === 'json') {
                $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
            } else {
                $this->info("Backup #{$log->id}");
                $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            }
            return self::SUCCESS;
        } catch (\Throwable $e) {
            if ($this->option('format') === 'json') {
                $this->line(json_encode(['status' => 'failed', 'error' => $e->getMessage()], JSON_THROW_ON_ERROR));
            } else {
                $this->error($e->getMessage());
            }
            return self::FAILURE;
        } finally {
            if ($zip !== null) $zip->close();
            if ($temporary) @unlink($temporary);
        }
    }
}
