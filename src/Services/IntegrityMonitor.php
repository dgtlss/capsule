<?php

namespace Dgtlss\Capsule\Services;

use Dgtlss\Capsule\Models\BackupLog;
use Dgtlss\Capsule\Models\VerificationLog;
use Dgtlss\Capsule\Notifications\NotificationManager;
use Dgtlss\Capsule\Support\Helpers;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use ZipArchive;

class IntegrityMonitor
{
    protected NotificationManager $notificationManager;

    public function __construct(NotificationManager $notificationManager = null)
    {
        $this->notificationManager = $notificationManager ?? new NotificationManager();
    }

    /**
     * Verify a specific backup and log the result.
     */
    public function verify(BackupLog $backup, string $trigger = 'manual'): VerificationLog
    {
        $startTime = microtime(true);

        try {
            $result = $this->performVerification($backup);

            $log = VerificationLog::create([
                'backup_log_id' => $backup->id,
                'status' => $result['failures'] === 0 ? 'passed' : 'failed',
                'entries_checked' => $result['checked'],
                'entries_failed' => $result['failures'],
                'error_message' => $result['failures'] > 0 ? $result['error_summary'] : null,
                'duration_seconds' => round(microtime(true) - $startTime, 2),
                'trigger' => $trigger,
            ]);

            if ($result['failures'] > 0) {
                $this->alertVerificationFailure($backup, $log);
            }

            return $log;
        } catch (\Throwable $e) {
            $log = VerificationLog::create([
                'backup_log_id' => $backup->id,
                'status' => 'error',
                'entries_checked' => 0,
                'entries_failed' => 0,
                'error_message' => $e->getMessage(),
                'duration_seconds' => round(microtime(true) - $startTime, 2),
                'trigger' => $trigger,
            ]);

            $this->alertVerificationFailure($backup, $log);
            return $log;
        }
    }

    /**
     * Run scheduled verification: pick a recent unverified backup and check it.
     */
    public function runScheduledVerification(): ?VerificationLog
    {
        $backup = $this->pickBackupToVerify();
        if (!$backup) {
            Log::info('IntegrityMonitor: No unverified backups to check.');
            return null;
        }

        Log::info("IntegrityMonitor: Verifying backup #{$backup->id}");
        return $this->verify($backup, 'scheduled');
    }

    /**
     * Pick a backup that hasn't been verified recently.
     * Prioritizes: never verified > oldest verification > random recent.
     */
    protected function pickBackupToVerify(): ?BackupLog
    {
        $neverVerified = BackupLog::where('status', 'success')
            ->whereDoesntHave('verificationLogs')
            ->orderByDesc('created_at')
            ->first();

        if ($neverVerified) {
            return $neverVerified;
        }

        $maxAge = (int) config('capsule.verification.recheck_days', 7);

        return BackupLog::where('status', 'success')
            ->whereHas('verificationLogs', function ($q) use ($maxAge) {
                $q->where('created_at', '<', now()->subDays($maxAge));
            })
            ->orderBy('created_at', 'asc')
            ->first();
    }

    protected function performVerification(BackupLog $backup): array
    {
        $diskName = config('capsule.default_disk', 'local');
        $disk = Storage::disk($diskName);

        if (!$backup->file_path || !$disk->exists($backup->file_path)) {
            throw new \Exception("Backup file not found: {$backup->file_path}");
        }

        $tmpPath = tempnam(sys_get_temp_dir(), 'capsule_intmon_');
        $stream = $disk->readStream($backup->file_path);
        if ($stream === false) {
            throw new \Exception('Failed to open remote file stream.');
        }

        $out = fopen($tmpPath, 'wb');
        stream_copy_to_stream($stream, $out);
        fclose($out);
        if (is_resource($stream)) {
            fclose($stream);
        }

        try {
            return $this->verifyArchive($tmpPath);
        } finally {
            @unlink($tmpPath);
        }
    }

    protected function verifyArchive(string $localPath): array
    {
        $zip = new ZipArchive();
        $openResult = $zip->open($localPath, ZipArchive::CHECKCONS);
        if ($openResult !== true) {
            throw new \Exception('ZIP integrity check failed (corrupt archive).');
        }

        $password = config('capsule.security.backup_password') ?? env('CAPSULE_BACKUP_PASSWORD');
        if (!empty($password)) {
            @$zip->setPassword($password);
        }

        $manifestStream = $zip->getStream('manifest.json');
        if ($manifestStream === false) {
            $zip->close();
            throw new \Exception('manifest.json not found in archive.');
        }

        $manifestContent = stream_get_contents($manifestStream);
        fclose($manifestStream);
        $manifest = json_decode($manifestContent, true);

        if (!is_array($manifest) || !isset($manifest['entries'])) {
            $zip->close();
            throw new \Exception('manifest.json is invalid or missing entries.');
        }

        $failures = 0;
        $checked = 0;
        $errors = [];

        foreach ($manifest['entries'] as $entry) {
            $path = $entry['path'] ?? null;
            if (!$path) {
                $failures++;
                continue;
            }

            $stat = $zip->statName($path);
            if ($stat === false) {
                $errors[] = "Missing: {$path}";
                $failures++;
                continue;
            }

            $expectedSize = (int) ($entry['size'] ?? -1);
            $actualSize = (int) ($stat['size'] ?? 0);
            if ($expectedSize !== 0 && $expectedSize !== $actualSize) {
                $errors[] = "Size mismatch: {$path} (expected {$expectedSize}, got {$actualSize})";
                $failures++;
                continue;
            }

            $expectedHash = $entry['sha256'] ?? '';
            if (!empty($expectedHash)) {
                $entryStream = $zip->getStream($path);
                if ($entryStream === false) {
                    $errors[] = "Cannot read: {$path}";
                    $failures++;
                    continue;
                }

                $ctx = hash_init('sha256');
                while (!feof($entryStream)) {
                    $buf = fread($entryStream, 8192);
                    if ($buf === false) {
                        break;
                    }
                    hash_update($ctx, $buf);
                }
                fclose($entryStream);
                $actualHash = hash_final($ctx);

                if (!hash_equals($expectedHash, $actualHash)) {
                    $errors[] = "Hash mismatch: {$path}";
                    $failures++;
                    continue;
                }
            }

            $checked++;
        }

        $zip->close();

        return [
            'checked' => $checked,
            'failures' => $failures,
            'error_summary' => !empty($errors) ? implode('; ', array_slice($errors, 0, 5)) : null,
        ];
    }

    protected function alertVerificationFailure(BackupLog $backup, VerificationLog $verificationLog): void
    {
        try {
            $message = "Backup #{$backup->id} verification {$verificationLog->status}";
            if ($verificationLog->error_message) {
                $message .= ": {$verificationLog->error_message}";
            }
            Log::error("IntegrityMonitor: {$message}");

            $this->notificationManager->sendFailureNotification(
                $backup,
                new \Exception("Backup verification {$verificationLog->status}: " . ($verificationLog->error_message ?? 'unknown error'))
            );
        } catch (\Throwable $e) {
            Log::error('IntegrityMonitor: Failed to send alert: ' . $e->getMessage());
        }
    }
}
