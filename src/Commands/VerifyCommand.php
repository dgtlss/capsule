<?php

namespace Dgtlss\Capsule\Commands;

use Dgtlss\Capsule\Models\BackupLog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use ZipArchive;

class VerifyCommand extends Command
{
    protected $signature = 'capsule:verify 
        {--id= : Verify a specific backup log id} 
        {--keep : Keep the downloaded file instead of deleting it} 
        {--detailed : Verbose output}
        {--all : Verify all successful backups}
        {--format=table : Output format (table|json)}';

    protected $description = 'Re-download latest backup, verify ZIP integrity and manifest checksums';

    public function handle(): int
    {
        $verbose = (bool) $this->option('detailed');
        $format = $this->option('format');
        $results = [];

        if ($this->option('all')) {
            $backups = BackupLog::where('status', 'success')->orderByDesc('created_at')->get();
            if ($backups->isEmpty()) {
                $this->error('No successful backups found to verify.');
                return self::FAILURE;
            }
            foreach ($backups as $backup) {
                $results[] = $this->verifyOne($backup, $verbose);
            }
        } else {
            $backup = $this->resolveBackupToVerify();
            if (!$backup) {
                $this->error('No successful backups found to verify.');
                return self::FAILURE;
            }
            $results[] = $this->verifyOne($backup, $verbose);
        }

        if ($format === 'json') {
            $this->line(json_encode($results, JSON_PRETTY_PRINT));
            $failed = collect($results)->contains(fn($r) => $r['status'] !== 'passed');
            return $failed ? self::FAILURE : self::SUCCESS;
        }

        // Return success/failure based on results when using table output as well
        $failed = collect($results)->contains(fn($r) => $r['status'] !== 'passed');
        return $failed ? self::FAILURE : self::SUCCESS;
    }

    protected function verifyOne(BackupLog $backup, bool $verbose): array
    {
        $diskName = config('capsule.default_disk', 'local');
        $disk = Storage::disk($diskName);

        $remotePath = $backup->file_path; // already prefixed path
        if (!$remotePath || !$disk->exists($remotePath)) {
            $this->error("Backup file not found on disk '{$diskName}': {$remotePath}");
            return [
                'id' => $backup->id,
                'status' => 'failed',
                'error' => "Backup not found on disk '{$diskName}': {$remotePath}",
            ];
        }

        // Download to temporary file
        $tmpPath = tempnam(sys_get_temp_dir(), 'capsule_verify_');
        $this->info('Downloading backup to temporary file...');

        $stream = $disk->readStream($remotePath);
        if ($stream === false) {
            $this->error('Failed to open remote file stream.');
            return [ 'id' => $backup->id, 'status' => 'failed', 'error' => 'Failed to open remote file stream.' ];
        }

        $out = fopen($tmpPath, 'wb');
        stream_copy_to_stream($stream, $out);
        fclose($out);
        if (is_resource($stream)) {
            fclose($stream);
        }

        $result = $this->verifyZipAndManifest($tmpPath, $verbose);

        // Cleanup local temp file (unless --keep)
        if (!$this->option('keep')) {
            @unlink($tmpPath);
        } else {
            if ($verbose) $this->line("Kept downloaded file at: {$tmpPath}");
        }

        return [
            'id' => $backup->id,
            'status' => $result ? 'passed' : 'failed',
        ];
    }

    protected function resolveBackupToVerify(): ?BackupLog
    {
        $id = $this->option('id');
        if ($id) {
            return BackupLog::where('id', (int) $id)
                ->where('status', 'success')
                ->first();
        }

        return BackupLog::where('status', 'success')
            ->orderByDesc('created_at')
            ->first();
    }

    protected function verifyZipAndManifest(string $localPath, bool $verbose): bool
    {
        $this->info('Verifying ZIP integrity...');

        $zip = new ZipArchive();
        $openResult = $zip->open($localPath, ZipArchive::CHECKCONS);
        if ($openResult !== true) {
            $this->error('ZIP integrity check failed: ' . $this->zipErrorToString($openResult));
            return false;
        }

        // If archive is encrypted, set password if available
        $password = config('capsule.security.backup_password') ?? env('CAPSULE_BACKUP_PASSWORD');
        if (!empty($password)) {
            @$zip->setPassword($password);
        }

        // Read manifest.json
        $manifestStream = $zip->getStream('manifest.json');
        if ($manifestStream === false) {
            $this->error('manifest.json not found in archive.');
            $zip->close();
            return false;
        }

        $manifestContent = stream_get_contents($manifestStream);
        fclose($manifestStream);
        $manifest = json_decode($manifestContent, true);
        if (!is_array($manifest)) {
            $this->error('manifest.json is not valid JSON.');
            $zip->close();
            return false;
        }

        if (!isset($manifest['entries']) || !is_array($manifest['entries'])) {
            $this->error('manifest.json missing entries section.');
            $zip->close();
            return false;
        }

        $this->info('Validating manifest entries (sizes and sha256)...');
        $failures = 0;
        $checked = 0;

        foreach ($manifest['entries'] as $entry) {
            $path = $entry['path'] ?? null;
            $expectedSize = (int) ($entry['size'] ?? -1);
            $expectedHash = $entry['sha256'] ?? '';

            if (!$path) {
                $failures++;
                continue;
            }

            $stat = $zip->statName($path);
            if ($stat === false) {
                $this->error("Missing entry in ZIP: {$path}");
                $failures++;
                continue;
            }

            // Size check
            $actualSize = (int) ($stat['size'] ?? 0);
            if ($expectedSize !== 0 && $expectedSize !== $actualSize) {
                $this->error("Size mismatch for {$path}: expected {$expectedSize}, got {$actualSize}");
                $failures++;
            }

            // Hash check
            if (!empty($expectedHash)) {
                $stream = $zip->getStream($path);
                if ($stream === false) {
                    $this->error("Unable to open stream for {$path}");
                    $failures++;
                    continue;
                }
                $ctx = hash_init('sha256');
                while (!feof($stream)) {
                    $buf = fread($stream, 8192);
                    if ($buf === false) break;
                    hash_update($ctx, $buf);
                }
                fclose($stream);
                $actualHash = hash_final($ctx);
                if (!hash_equals($expectedHash, $actualHash)) {
                    $this->error("Hash mismatch for {$path}");
                    if ($verbose) {
                        $this->line("  expected: {$expectedHash}");
                        $this->line("  actual:   {$actualHash}");
                    }
                    $failures++;
                }
            }

            $checked++;
        }

        $zip->close();

        // Remote checksum verification for S3 (best-effort): compare ETag to local md5 if single-part
        try {
            $diskName = config('capsule.default_disk', 'local');
            $disk = Storage::disk($diskName);
            // If we verified a single backup (not --all), try ETag check
            // Not implementing multi-part MD5 reconciliation here; best effort only.
            if (!$this->option('all')) {
                $backup = $this->resolveBackupToVerify();
                if ($backup && $backup->file_path) {
                    $storageManager = app(\Dgtlss\Capsule\Storage\StorageManager::class);
                    $etag = $storageManager->getRemoteChecksum(basename($backup->file_path));
                    if ($etag) {
                        // Compute md5 of whole archive file only if likely single-part
                        if (filesize($localPath) < 5 * 1024 * 1024 * 1024) { // < 5GB heuristic
                            $md5 = md5_file($localPath);
                            if ($md5 && strtolower($md5) !== strtolower($etag)) {
                                $this->error('Remote checksum (ETag) mismatch with local MD5');
                                return false;
                            }
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            // Ignore remote checksum errors silently or add verbose log
            if ($verbose) {
                $this->line('Remote checksum verification skipped: ' . $e->getMessage());
            }
        }

        if ($failures > 0) {
            $this->error("Verification failed: {$failures} of {$checked} entries mismatched.");
            return false;
        }

        $this->info("Verification passed for {$checked} entries.");
        return true;
    }

    protected function zipErrorToString(int $code): string
    {
        return match ($code) {
            ZipArchive::ER_NOZIP => 'Not a valid ZIP archive',
            ZipArchive::ER_INCONS => 'ZIP archive is inconsistent',
            ZipArchive::ER_CRC => 'CRC error in ZIP archive',
            ZipArchive::ER_MEMORY => 'Memory allocation failure',
            ZipArchive::ER_READ => 'Read error',
            default => 'ZIP open failed with code ' . $code,
        };
    }
}
