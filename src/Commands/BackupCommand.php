<?php

namespace Dgtlss\Capsule\Commands;

use Dgtlss\Capsule\Models\BackupLog;
use Dgtlss\Capsule\Services\BackupService;
use Dgtlss\Capsule\Services\ChunkedBackupService;
use Dgtlss\Capsule\Services\SimulationService;
use Dgtlss\Capsule\Support\BackupReport;
use Dgtlss\Capsule\Support\Helpers;
use Dgtlss\Capsule\Support\Lock;
use Illuminate\Console\Command;

class BackupCommand extends Command
{
    protected $signature = 'capsule:backup 
    {--force : Force backup even if another is running} 
    {--no-local : Stream backup directly to external storage without using local disk}
    {--detailed : Verbose output with progress details}
    {--parallel : Enable parallel processing for multiple databases}
    {--compress=1 : Compression level 1-9 (1=fastest, 9=smallest)}
    {--encrypt : Encrypt backup archive}
    {--verify : Verify backup integrity after creation}
    {--db-only : Only backup database (skip files)}
    {--files-only : Only backup files (skip database)}
    {--incremental : Only backup files changed since last full backup}
    {--simulate : Estimate backup size and duration without running}
    {--tag= : Label this backup (e.g., pre-deploy, nightly)}
    {--format=table : Output format (table|json)}';
    
    protected $description = 'Create a backup of the database and files';

    public function handle(BackupService $backupService, ChunkedBackupService $chunkedBackupService): int
    {
        $useChunkedBackup = $this->option('no-local');
        $verbose = $this->option('detailed');
        $parallel = $this->option('parallel');
        $compress = (int) $this->option('compress');
        $encrypt = $this->option('encrypt');
        $verify = $this->option('verify');
        
        if ($useChunkedBackup) {
            $this->info('Starting chunked backup process (no local storage)...');
            $service = $chunkedBackupService;
        } else {
            $this->info('Starting backup process...');
            $service = $backupService;
        }

        if ($this->option('db-only') && $this->option('files-only')) {
            $this->error('Cannot use --db-only and --files-only together.');
            return self::FAILURE;
        }

        if ($this->option('db-only')) {
            config(['capsule.files.enabled' => false]);
        }

        if ($this->option('files-only')) {
            config(['capsule.database.enabled' => false]);
        }

        if ($this->option('simulate')) {
            return $this->runSimulation();
        }

        $service->setVerbose($verbose);
        $service->setParallel($parallel);
        $service->setTag($this->option('tag'));
        if ($this->option('incremental') && $service instanceof BackupService) {
            $service->setIncremental(true);
        }
        if ($compress >= 1 && $compress <= 9) {
            $service->setCompressionLevel($compress);
        }
        $service->setEncryption($encrypt);
        $service->setVerification($verify);
        
        if ($verbose) {
            $service->setOutputCallback(function ($message) {
                $this->info($message);
            });
        }

        $startTime = microtime(true);
        $lock = null;
        
        try {
            if (!$this->option('force')) {
                $lock = Lock::acquire('capsule:backup');
                if (!$lock) {
                    $this->warn('Another backup is currently running. Use --force to override.');
                    return self::FAILURE;
                }
            }

            $success = $service->run();
            $duration = round(microtime(true) - $startTime, 2);
            $format = $this->option('format');

            if ($success) {
                if ($format === 'json') {
                    $this->line(json_encode(['status' => 'success', 'duration_seconds' => $duration], JSON_PRETTY_PRINT));
                } else {
                    $this->newLine();
                    $latestLog = BackupLog::successful()->orderByDesc('created_at')->first();
                    if ($latestLog) {
                        $latestLog->load('metric');
                        $report = BackupReport::build($latestLog, $duration);
                        $this->line(BackupReport::render($report));
                    } else {
                        $label = $useChunkedBackup ? 'Chunked backup' : 'Backup';
                        $this->info("{$label} completed successfully in {$duration} seconds.");
                    }
                }
                return self::SUCCESS;
            }

            if ($format === 'json') {
                $this->line(json_encode(['status' => 'failed', 'duration_seconds' => $duration, 'error' => $service->getLastError()], JSON_PRETTY_PRINT));
            } else {
                $label = $useChunkedBackup ? 'Chunked backup' : 'Backup';
                $this->error("{$label} failed after {$duration} seconds.");
                $lastError = $service->getLastError();
                if ($verbose && $lastError) {
                    $this->error('Detailed error information:');
                    $this->error($lastError);
                } else {
                    $this->error('Check the logs for more details or run with --detailed for verbose output.');
                }
            }
            return self::FAILURE;
        } catch (\Exception $e) {
            $duration = round(microtime(true) - $startTime, 2);
            
            $this->error("Backup failed after {$duration} seconds with exception:");
            $this->error("Error: {$e->getMessage()}");
            
            if ($verbose) {
                $this->error("File: {$e->getFile()}:{$e->getLine()}");
                $this->error("Stack trace:");
                $this->line($e->getTraceAsString());
            } else {
                $this->error('Run with --detailed flag for more detailed error information.');
            }
            
            return self::FAILURE;
        } finally {
            if ($lock) {
                $lock->release();
            }
        }
    }

    protected function runSimulation(): int
    {
        $this->info('Simulating backup (no data will be written)...');
        $this->newLine();

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
