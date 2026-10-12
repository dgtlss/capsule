<?php

namespace Dgtlss\Capsule\Services;

use Dgtlss\Capsule\Models\BackupLog;
use Dgtlss\Capsule\Models\VerificationLog;
use Dgtlss\Capsule\Notifications\NotificationManager;
use Dgtlss\Capsule\Support\RestoreArchive;
use Dgtlss\Capsule\Security\EncryptedArchive;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class IntegrityMonitor
{
    protected NotificationManager $notificationManager;

    public function __construct(?NotificationManager $notificationManager = null)
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
                'error_message' => $result['failures'] > 0 ? $result['error_summary'] : (($result['warnings'] ?? []) !== [] ? implode(' ', $result['warnings']) : null),
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
        return $this->inspect($backup);
    }

    /** Verify without writing a verification log, for the manual CLI command. */
    public function inspect(BackupLog $backup, bool $keep = false): array
    {
        $diskName = $backup->metadata['storage']['disk'] ?? $backup->metadata['disk'] ?? config('capsule.default_disk', 'local');
        if (!is_string($diskName) || $diskName === '') {
            throw new \RuntimeException('Backup storage metadata has an invalid disk.');
        }
        $disk = Storage::disk($diskName);
        if (!$backup->file_path || !$disk->exists($backup->file_path)) {
            throw new \RuntimeException("Backup file not found on disk '{$diskName}': {$backup->file_path}");
        }

        $tmpPath = tempnam(sys_get_temp_dir(), 'capsule_verify_');
        if ($tmpPath === false) {
            throw new \RuntimeException('Unable to create verification download file.');
        }
        $input = $output = null;
        $downloaded = false;
        try {
            $input = $disk->readStream($backup->file_path);
            $output = fopen($tmpPath, 'wb');
            if (!is_resource($input) || !is_resource($output)) {
                throw new \RuntimeException('Failed to open backup download stream.');
            }
            $bytes = stream_copy_to_stream($input, $output);
            if ($bytes === false || !fflush($output) || $bytes !== $disk->size($backup->file_path)) {
                throw new \RuntimeException('Backup download was incomplete.');
            }
            if ($backup->file_size !== null && $backup->file_size > 0 && $bytes !== $backup->file_size) {
                throw new \RuntimeException('Backup size differs from its recorded size.');
            }
            fclose($input);
            fclose($output);
            $input = $output = null;
            $downloaded = true;
            $expectedHash = $backup->metadata['archive_sha256'] ?? null;
            if ($expectedHash !== null && (!is_string($expectedHash)
                || !preg_match('/^[a-f0-9]{64}$/iD', $expectedHash)
                || !hash_equals(strtolower($expectedHash), hash_file('sha256', $tmpPath)))) {
                throw new \RuntimeException('Backup checksum differs from its recorded checksum.');
            }
            $result = $this->verifyArchive($tmpPath, RestoreArchive::formatExpectation($backup->file_path, $backup->metadata ?? []));
            $result['disk'] = $diskName;
            if ($keep) {
                $result['local_path'] = $tmpPath;
            }
            return $result;
        } catch (\Throwable $e) {
            if ($keep && $downloaded) {
                throw new \RuntimeException($e->getMessage() . " Download kept at: {$tmpPath}", 0, $e);
            }
            throw $e;
        } finally {
            if (is_resource($input)) {
                fclose($input);
            }
            if (is_resource($output)) {
                fclose($output);
            }
            if (!$keep || !$downloaded) {
                @unlink($tmpPath);
            }
        }
    }

    protected function verifyArchive(string $localPath, ?string $expectedFormat = null): array
    {
        $password = config('capsule.security.backup_password') ?? env('CAPSULE_BACKUP_PASSWORD');
        $archive = new RestoreArchive($localPath, $password, $expectedFormat,
            RestoreArchive::manifestByteLimit(config('capsule.restore.max_manifest_bytes', RestoreArchive::MAX_MANIFEST_BYTES)));
        try {
            $archive->verifyOnly();
            return [
                'checked' => count($archive->entries()),
                'failures' => 0,
                'error_summary' => null,
                'archive_format' => EncryptedArchive::isEncryptedFile($localPath) ? EncryptedArchive::FORMAT : 'zip',
                'requires_capsule' => EncryptedArchive::isEncryptedFile($localPath),
                'warnings' => $archive->warnings(),
                'checksums_complete' => count(array_filter($archive->entries(), static fn ($entry) => ($entry['integrity'] ?? 'sha256') !== 'sha256')) === 0,
            ];
        } finally {
            $archive->close();
        }
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
