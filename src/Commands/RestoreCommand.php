<?php

namespace Dgtlss\Capsule\Commands;

use Dgtlss\Capsule\Services\RestoreService;
use Dgtlss\Capsule\Models\BackupLog;
use Dgtlss\Capsule\Support\Formatters;
use Illuminate\Console\Command;
use Throwable;

class RestoreCommand extends Command
{
    protected $signature = 'capsule:restore 
        {--id= : Restore specific backup by ID}
        {--latest : Restore the most recent backup}
        {--db-only : Restore only database}
        {--files-only : Restore only files}
        {--target-connection= : Restore database to a different connection}
        {--dry-run : Preview what would be restored without making changes}
        {--v : Verbose output}
        {--force : Skip confirmation prompt}';

    protected $description = 'Restore a backup from storage';

    public function handle(RestoreService $restoreService): int
    {
        $id = $this->option('id');
        $latest = $this->option('latest');
        $dbOnly = $this->option('db-only');
        $filesOnly = $this->option('files-only');
        $targetConnection = $this->option('target-connection');
        $dryRun = $this->option('dry-run');
        $verbose = $this->option('v');
        $force = $this->option('force');

        if (!$id && !$latest) {
            $this->error('You must specify either --id or --latest');
            return self::FAILURE;
        }

        if ($id && $latest) {
            $this->error('Cannot specify both --id and --latest');
            return self::FAILURE;
        }

        if ($dbOnly && $filesOnly) {
            $this->error('Cannot specify both --db-only and --files-only');
            return self::FAILURE;
        }

        $backup = $id 
            ? BackupLog::find($id)
            : BackupLog::where('status', 'success')->orderBy('created_at', 'desc')->first();

        if (!$backup) {
            $this->error('Backup not found');
            return self::FAILURE;
        }

        if ($backup->status !== 'success') {
            $this->error('Cannot restore backup with status: ' . $backup->status);
            return self::FAILURE;
        }

        $this->displayBackupInfo($backup, $dryRun, $dbOnly, $filesOnly, $targetConnection);

        if (!$dryRun && !$force) {
            if (!$this->confirm('Are you sure you want to restore this backup? This will overwrite existing data.', false)) {
                $this->warn('Restore cancelled');
                return self::FAILURE;
            }
        }

        $restoreService->setVerbose($verbose);
        $restoreService->setDryRun($dryRun);
        $restoreService->setDbOnly($dbOnly);
        $restoreService->setFilesOnly($filesOnly);
        $restoreService->setTargetConnection($targetConnection);
        $restoreService->setOutputCallback(function($message) {
            $this->info($message);
        });

        $startTime = microtime(true);

        try {
            if ($id) {
                $success = $restoreService->restoreById($id);
            } else {
                $success = $restoreService->restoreLatest();
            }

            $endTime = microtime(true);
            $duration = round($endTime - $startTime, 2);

            if ($success) {
                $message = $dryRun 
                    ? "Restore preview completed in {$duration} seconds."
                    : "Restore completed successfully in {$duration} seconds.";
                $this->info($message);
                return self::SUCCESS;
            } else {
                $message = $dryRun
                    ? "Restore preview failed after {$duration} seconds."
                    : "Restore failed after {$duration} seconds.";
                $this->error($message);
                
                $lastError = $restoreService->getLastError();
                if ($verbose && $lastError) {
                    $this->error('Error: ' . $lastError);
                }
                
                return self::FAILURE;
            }
        } catch (Throwable $e) {
            $endTime = microtime(true);
            $duration = round($endTime - $startTime, 2);

            $this->error("Restore failed after {$duration} seconds with exception:");
            $this->error("Error: {$e->getMessage()}");
            
            if ($verbose) {
                $this->error("File: {$e->getFile()}:{$e->getLine()}");
                $this->error("Stack trace:");
                $this->line($e->getTraceAsString());
            }
            
            return self::FAILURE;
        }
    }

    protected function displayBackupInfo(BackupLog $backup, bool $dryRun, bool $dbOnly, bool $filesOnly, ?string $targetConnection): void
    {
        $this->newLine();
        $this->info('📋 Backup Information:');
        $this->table(
            ['Property', 'Value'],
            [
                ['ID', $backup->id],
                ['Created At', $backup->created_at->format('Y-m-d H:i:s')],
                ['File Size', $backup->formatted_file_size],
                ['Duration', Formatters::duration($backup->duration ?? 0)],
                ['Status', $backup->status],
            ]
        );

        $this->newLine();
        $this->info('⚙️  Restore Options:');
        $options = [];
        
        if ($dryRun) {
            $options[] = 'Dry Run (preview only)';
        }
        
        if ($dbOnly) {
            $options[] = 'Database Only';
        } elseif ($filesOnly) {
            $options[] = 'Files Only';
        } else {
            $options[] = 'Full Restore (Database + Files)';
        }
        
        if ($targetConnection) {
            $options[] = "Target Connection: {$targetConnection}";
        }

        foreach ($options as $option) {
            $this->line("   • {$option}");
        }

        $this->newLine();
    }
}
