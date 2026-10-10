<?php

namespace Dgtlss\Capsule\Commands;

use Dgtlss\Capsule\Models\BackupLog;
use Dgtlss\Capsule\Services\IntegrityMonitor;
use Illuminate\Console\Command;

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
        $json = $this->option('format') === 'json';
        $results = [];
        try {
            if ($this->option('all')) {
                $backups = BackupLog::where('status', 'success')->orderByDesc('created_at')->cursor();
            } else {
                $backup = $this->resolveBackupToVerify();
                $backups = $backup ? [$backup] : [];
            }
            foreach ($backups as $backup) {
                $results[] = $this->verifyOne($backup, (bool) $this->option('detailed'));
            }
            if ($results === []) {
                $results[] = ['id' => null, 'status' => 'failed', 'error' => 'No successful backups found to verify.'];
            }
        } catch (\Throwable $e) {
            $results[] = ['id' => null, 'status' => 'failed', 'error' => $e->getMessage()];
        }

        if ($json) {
            // One JSON document on stdout, including missing-backup and failure
            // paths. Progress/errors are represented by the result records.
            $this->line(json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE));
        } else {
            foreach ($results as $result) {
                if ($result['status'] === 'passed') {
                    $this->info("Backup #{$result['id']}: verification passed ({$result['entries_checked']} entries).");
                    if (isset($result['local_path'])) {
                        $this->line('Kept downloaded file at: ' . $result['local_path']);
                    }
                } else {
                    $this->error(($result['id'] !== null ? "Backup #{$result['id']}: " : '') . $result['error']);
                }
            }
        }

        foreach ($results as $result) {
            if ($result['status'] !== 'passed') {
                return self::FAILURE;
            }
        }
        return self::SUCCESS;
    }

    protected function verifyOne(BackupLog $backup, bool $verbose): array
    {
        try {
            $result = app(IntegrityMonitor::class)->inspect($backup, (bool) $this->option('keep'));
            $record = [
                'id' => $backup->id,
                'status' => $result['failures'] === 0 ? 'passed' : 'failed',
                'entries_checked' => $result['checked'],
                'disk' => $result['disk'],
            ];
            if ($result['failures'] !== 0) {
                $record['error'] = $result['error_summary'];
            }
            if (isset($result['local_path'])) {
                $record['local_path'] = $result['local_path'];
            }
            return $record;
        } catch (\Throwable $e) {
            return ['id' => $backup->id, 'status' => 'failed', 'error' => $e->getMessage()];
        }
    }

    protected function resolveBackupToVerify(): ?BackupLog
    {
        $id = $this->option('id');
        if ($id) {
            return BackupLog::where('id', (int) $id)->where('status', 'success')->first();
        }
        return BackupLog::where('status', 'success')->orderByDesc('created_at')->first();
    }
}
