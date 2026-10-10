<?php

namespace Dgtlss\Capsule\Commands;

use Dgtlss\Capsule\Models\BackupLog;
use Dgtlss\Capsule\Services\BackupService;
use Dgtlss\Capsule\Services\ChunkedBackupService;
use Dgtlss\Capsule\Services\PolicyRunner;
use Dgtlss\Capsule\Services\SimulationService;
use Dgtlss\Capsule\Support\BackupReport;
use Illuminate\Console\Command;

class BackupCommand extends Command
{
    protected $signature = 'capsule:backup 
    {--force : Force backup even if another is running} 
    {--no-local : Stream backup directly to external storage without using local disk}
    {--detailed : Verbose output with progress details}
    {--parallel : Enable parallel processing for multiple databases}
    {--compress= : Compression level 1-9 (defaults to config)}
    {--encrypt : Encrypt backup archive}
    {--verify : Verify backup integrity after creation}
    {--db-only : Only backup database (skip files)}
    {--files-only : Only backup files (skip database)}
    {--incremental : Only backup files changed since last full backup}
    {--simulate : Estimate backup size and duration without running}
    {--policy= : Run a named backup policy from config}
    {--tag= : Label this backup (e.g., pre-deploy, nightly)}
    {--format=table : Output format (table|json)}';
    
    protected $description = 'Create a backup of the database and files';

    public function handle(): int
    {
        $startTime = microtime(true);
        $json = $this->option('format') === 'json';

        try {
            if (!in_array($this->option('format'), ['table', 'json'], true)) {
                throw new \InvalidArgumentException('Output format must be table or json.');
            }
            if ($this->option('db-only') && $this->option('files-only')) {
                throw new \InvalidArgumentException('Cannot use --db-only and --files-only together.');
            }
            $compress = $this->option('compress');
            if ($compress !== null && (!ctype_digit((string) $compress) || (int) $compress < 1 || (int) $compress > 9)) {
                throw new \InvalidArgumentException('Compression level must be between 1 and 9.');
            }

            return PolicyRunner::withPolicy($this->option('policy'), function (array $policy) use ($startTime, $json, $compress): int {
                if ($this->option('db-only')) {
                    config(['capsule.files.enabled' => false]);
                }
                if ($this->option('files-only')) {
                    config(['capsule.database.enabled' => false]);
                }
                if ($this->option('simulate')) {
                    return $this->runSimulation();
                }

                // Resolve dependencies after applying overrides: storage captures its disk at construction.
                $useChunkedBackup = (bool) $this->option('no-local');
                $incremental = (bool) ($this->option('incremental') || config('capsule._policy_incremental', false));
                if ($useChunkedBackup && $incremental) {
                    throw new \InvalidArgumentException('Incremental backups require local archive creation; remove --no-local.');
                }
                $service = app($useChunkedBackup ? ChunkedBackupService::class : BackupService::class);
                $verbose = (bool) $this->option('detailed');
                $service->setVerbose($verbose && !$json);
                $service->setParallel((bool) $this->option('parallel'));
                $service->setTag($this->option('tag') ?? ($policy['tag'] ?? $this->option('policy')));
                $service->setCompressionLevel((int) ($compress ?? config('capsule.backup.compression_level', 1)));
                $service->setEncryption((bool) $this->option('encrypt'));
                $service->setVerification((bool) $this->option('verify'));
                if ($service instanceof BackupService) {
                    $service->setIncremental($incremental);
                }
                if ($verbose && !$json) {
                    $service->setOutputCallback(fn ($message) => $this->info($message));
                }
                if (!$json) {
                    $this->info($useChunkedBackup ? 'Starting chunked backup process (no local storage)...' : 'Starting backup process...');
                    if ($this->option('policy')) {
                        $this->info('Using policy: ' . $this->option('policy'));
                    }
                }

                $success = $service->run((bool) $this->option('force'));
                $duration = round(microtime(true) - $startTime, 2);
                if ($json) {
                    $payload = ['status' => $success ? 'success' : 'failed', 'duration_seconds' => $duration];
                    if (!$success) {
                        $payload['error'] = $service->getLastError();
                    }
                    $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                } elseif ($success) {
                    $latestLog = BackupLog::successful()->orderByDesc('created_at')->orderByDesc('id')->first();
                    if ($latestLog) {
                        $latestLog->load('metric');
                        $this->line(BackupReport::render(BackupReport::build($latestLog, $duration)));
                    } else {
                        $this->info("Backup completed successfully in {$duration} seconds.");
                    }
                } else {
                    $this->error($service->getLastError() ?: "Backup failed after {$duration} seconds.");
                }
                return $success ? self::SUCCESS : self::FAILURE;
            });
        } catch (\Throwable $e) {
            if ($json) {
                $this->line(json_encode([
                    'status' => 'failed',
                    'duration_seconds' => round(microtime(true) - $startTime, 2),
                    'error' => $e->getMessage(),
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            } else {
                $this->error($e->getMessage());
                if ($this->option('detailed')) {
                    $this->line($e->getTraceAsString());
                }
            }
            return self::FAILURE;
        }
    }

    protected function runSimulation(): int
    {
        if ($this->option('format') !== 'json') {
            $this->info('Simulating backup (no data will be written)...');
            $this->newLine();
        }

        $sim = app(SimulationService::class);
        $result = $sim->simulate();
        $format = $this->option('format');

        if ($format === 'json') {
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return self::SUCCESS;
        }

        if ($db = $result['database'] ?? null) {
            $this->info('Database');
            foreach ($db['connections'] as $conn) {
                $this->line("  {$conn['connection']} ({$conn['driver']}) - {$conn['estimated_size_formatted']}");
            }
            $this->line("  Total: {$db['total_size_formatted']}");
            $this->newLine();
        }

        if ($files = $result['files'] ?? null) {
            $this->info('Files');
            $this->line("  {$files['file_count']} files in {$files['directory_count']} directories");
            $this->line("  Total: {$files['total_size_formatted']}");

            if (!empty($files['top_extensions'])) {
                $this->newLine();
                $this->info('Top extensions by size');
                foreach (array_slice($files['top_extensions'], 0, 5) as $ext) {
                    $this->line("  .{$ext['extension']}  {$ext['size_formatted']}");
                }
            }

            if (!empty($files['largest_files'])) {
                $this->newLine();
                $this->info('Largest files');
                foreach (array_slice($files['largest_files'], 0, 5) as $f) {
                    $this->line("  {$f['size_formatted']}  {$f['path']}");
                }
            }
            $this->newLine();
        }

        $totals = $result['totals'];
        $est = $result['estimate'];

        $this->table(
            ['Metric', 'Value'],
            [
                ['Raw data size', $totals['raw_size_formatted']],
                ['Estimated archive size', $est['archive_size_formatted']],
                ['Compression ratio', $est['compression_ratio'] . 'x'],
                ['Estimated duration', $est['duration_formatted']],
                ['Files', number_format($totals['file_count'])],
                ['Databases', $totals['database_count']],
            ]
        );

        if (!empty($result['history'])) {
            $this->newLine();
            $this->info('Historical comparison');
            $h = $result['history'];
            $this->line("  Last backup:     {$h['last_backup_size_formatted']}");
            $this->line("  Average backup:  {$h['avg_backup_size_formatted']} (over {$h['backup_count']} backups)");
            $this->line("  Average duration: {$h['avg_duration_seconds']}s");
        }

        if (!empty($result['warnings'])) {
            $this->newLine();
            foreach ($result['warnings'] as $warning) {
                $this->warn("  Warning: {$warning}");
            }
        }

        return self::SUCCESS;
    }
}
