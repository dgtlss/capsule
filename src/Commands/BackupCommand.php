<?php

namespace Dgtlss\Capsule\Commands;

use Dgtlss\Capsule\Services\BackupService;
use Dgtlss\Capsule\Services\ChunkedBackupService;
use Illuminate\Console\Command;

class BackupCommand extends Command
{
    protected $signature = 'capsule:backup 
    {--force : Force backup even if another is running} 
    {--no-local : Stream backup directly to external storage without using local disk}
    {--v : Verbose output with progress details}
    {--parallel : Enable parallel processing for multiple databases}
    {--compress=1 : Compression level 1-9 (1=fastest, 9=smallest)}
    {--encrypt : Encrypt backup archive}
    {--verify : Verify backup integrity after creation}
    {--format=table : Output format (table|json)}';
    
    protected $description = 'Create a backup of the database and files';

    public function handle(BackupService $backupService, ChunkedBackupService $chunkedBackupService): int
    {
        $useChunkedBackup = $this->option('no-local');
        $verbose = $this->option('v');
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

        // Configure service options
        $service->setVerbose($verbose);
        $service->setParallel($parallel);
        if ($compress >= 1 && $compress <= 9) {
            $service->setCompressionLevel($compress);
        }
        $service->setEncryption($encrypt);
        $service->setVerification($verify);
        
        // Set output callback for real-time logging
        if ($verbose) {
            $service->setOutputCallback(function($message) {
                $this->info($message);
            });
        }

        $startTime = microtime(true);
        
        try {
            // Lock to prevent overlaps unless --force
            if (!$this->option('force')) {
                $lockName = 'capsule:backup';
                $lock = \Dgtlss\Capsule\Support\Lock::acquire($lockName);
                if (!$lock) {
                    $this->warn('Another backup is currently running. Use --force to override.');
                    return self::FAILURE;
                }
            }
            $success = $service->run();
            $endTime = microtime(true);
            $duration = round($endTime - $startTime, 2);

            $format = $this->option('format');
            if ($success) {
                if ($format === 'json') {
                    $payload = [
                        'status' => 'success',
                        'duration_seconds' => $duration,
                    ];
                    $this->line(json_encode($payload, JSON_PRETTY_PRINT));
                } else {
                    $message = $useChunkedBackup 
                        ? "Chunked backup completed successfully in {$duration} seconds."
                        : "Backup completed successfully in {$duration} seconds.";
                    $this->info($message);
                }
                return self::SUCCESS;
            } else {
                if ($format === 'json') {
                    $payload = [
                        'status' => 'failed',
                        'duration_seconds' => $duration,
                        'error' => $service->getLastError(),
                    ];
                    $this->line(json_encode($payload, JSON_PRETTY_PRINT));
                } else {
                    $message = $useChunkedBackup 
                        ? "Chunked backup failed after {$duration} seconds."
                        : "Backup failed after {$duration} seconds.";
                    $this->error($message);
                    // Get the last error from the service for verbose output
                    $lastError = $service->getLastError();
                    if ($verbose && $lastError) {
                        $this->error('Detailed error information:');
                        $this->error($lastError);
                    } else {
                        $this->error('Check the logs for more details or run with --v for verbose output.');
                    }
                }
                return self::FAILURE;
            }
        } catch (\Exception $e) {
            $endTime = microtime(true);
            $duration = round($endTime - $startTime, 2);
            
            $this->error("Backup failed after {$duration} seconds with exception:");
            $this->error("Error: {$e->getMessage()}");
            
            if ($verbose) {
                $this->error("File: {$e->getFile()}:{$e->getLine()}");
                $this->error("Stack trace:");
                $this->line($e->getTraceAsString());
            } else {
                $this->error('Run with --v flag for more detailed error information.');
            }
            
            return self::FAILURE;
        }
    }
}