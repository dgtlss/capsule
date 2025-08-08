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
        $id = (int) $this->argument('id');
        $format = $this->option('format');

        $log = BackupLog::find($id);
        if (!$log || $log->status !== 'success' || empty($log->file_path)) {
            $this->error('Backup not found or not successful.');
            return self::FAILURE;
        }

        $disk = Storage::disk(config('capsule.default_disk', 'local'));
        if (!$disk->exists($log->file_path)) {
            $this->error('Backup file not found in storage.');
            return self::FAILURE;
        }

        $tmp = tempnam(sys_get_temp_dir(), 'capsule_inspect_');
        $stream = $disk->readStream($log->file_path);
        $out = fopen($tmp, 'wb');
        stream_copy_to_stream($stream, $out);
        fclose($out);
        if (is_resource($stream)) fclose($stream);

        $zip = new ZipArchive();
        $open = $zip->open($tmp);
        if ($open !== true) {
            @unlink($tmp);
            $this->error('Failed to open ZIP.');
            return self::FAILURE;
        }

        $manifestJson = '';
        $s = $zip->getStream('manifest.json');
        if ($s !== false) {
            $manifestJson = stream_get_contents($s);
            fclose($s);
        }
        $zip->close();
        @unlink($tmp);

        $manifest = $manifestJson ? json_decode($manifestJson, true) : null;

        if ($format === 'json') {
            $data = [
                'id' => $log->id,
                'status' => $log->status,
                'size' => $log->file_size,
                'path' => $log->file_path,
                'manifest' => $manifest,
            ];
            $this->line(json_encode($data, JSON_PRETTY_PRINT));
            return self::SUCCESS;
        }

        $this->info("Backup #{$log->id}");
        $this->line('Path: ' . $log->file_path);
        $this->line('Size: ' . $log->formattedFileSize);
        if (is_array($manifest)) {
            $this->newLine();
            $this->info('Manifest:');
            $this->line(json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->warn('No manifest found');
        }

        return self::SUCCESS;
    }
}
