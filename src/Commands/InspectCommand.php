<?php

namespace Dgtlss\Capsule\Commands;

use Dgtlss\Capsule\Models\BackupLog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Dgtlss\Capsule\Support\RestoreArchive;
use Dgtlss\Capsule\Security\EncryptedArchive;

class InspectCommand extends Command
{
    protected $signature = 'capsule:inspect {id} {--format=table : table|json}';
    protected $description = 'Inspect a specific backup and show manifest details';

    public function handle(): int
    {
        $temporary = null;
        $archive = null;
        try {
            $log = BackupLog::find((int) $this->argument('id'));
            if (!$log || $log->status !== 'success' || !$log->file_path) {
                throw new \RuntimeException('Backup not found or not successful.');
            }
            $diskName = $log->metadata['storage']['disk'] ?? $log->metadata['disk'] ?? config('capsule.default_disk', 'local');
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
            $expectedHash = $log->metadata['archive_sha256'] ?? null;
            if ($expectedHash !== null && (!is_string($expectedHash) || !preg_match('/^[a-f0-9]{64}$/iD', $expectedHash)
                || !hash_equals(strtolower($expectedHash), hash_file('sha256', $temporary)))) {
                throw new \RuntimeException('Backup checksum differs from its recorded checksum.');
            }
            $password = config('capsule.security.backup_password') ?? env('CAPSULE_BACKUP_PASSWORD');
            $requiresCapsule = EncryptedArchive::isEncryptedFile($temporary);
            $archive = new RestoreArchive($temporary, $password, RestoreArchive::formatExpectation($log->file_path, $log->metadata ?? []),
                RestoreArchive::manifestByteLimit(config('capsule.restore.max_manifest_bytes', RestoreArchive::MAX_MANIFEST_BYTES)));
            $archive->verifyOnly();
            $manifest = $archive->manifest();
            $result = ['id' => $log->id, 'status' => 'success', 'size' => $log->file_size,
                'disk' => $diskName, 'path' => $log->file_path, 'manifest' => $manifest,
                'archive_format' => $requiresCapsule ? EncryptedArchive::FORMAT : 'zip',
                'requires_capsule' => $requiresCapsule,
                'warnings' => $archive->warnings(),
                'checksums_complete' => count(array_filter($archive->entries(), static fn ($entry) => ($entry['integrity'] ?? 'sha256') !== 'sha256')) === 0];
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
            if ($archive !== null) $archive->close();
            if ($temporary) @unlink($temporary);
        }
    }
}
