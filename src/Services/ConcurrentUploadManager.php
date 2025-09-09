<?php

namespace Dgtlss\Capsule\Services;

use Dgtlss\Capsule\Storage\StorageManager;
use Illuminate\Support\Facades\Log;
use Exception;

class ConcurrentUploadManager
{
    protected StorageManager $storageManager;
    protected int $maxConcurrent;
    protected array $activeUploads = [];
    protected array $uploadQueue = [];
    protected array $results = [];

    public function __construct(StorageManager $storageManager, int $maxConcurrent = 3)
    {
        $this->storageManager = $storageManager;
        $this->maxConcurrent = $maxConcurrent;
    }

    public function uploadChunks(array $chunks): array
    {
        $this->uploadQueue = $chunks;
        $this->activeUploads = [];
        $this->results = [];

        // Start initial batch of uploads
        while (count($this->activeUploads) < $this->maxConcurrent && !empty($this->uploadQueue)) {
            $this->startNextUpload();
        }

        // Process uploads until all are complete
        while (!empty($this->activeUploads) || !empty($this->uploadQueue)) {
            $this->processActiveUploads();
            usleep(10000); // 10ms delay to prevent busy waiting
        }

        return $this->results;
    }

    protected function startNextUpload(): void
    {
        if (empty($this->uploadQueue)) {
            return;
        }

        $chunk = array_shift($this->uploadQueue);
        $uploadId = uniqid('upload_');

        // Use multi-curl for true concurrent uploads
        $this->activeUploads[$uploadId] = [
            'chunk' => $chunk,
            'started_at' => microtime(true),
            'process' => $this->createUploadProcess($chunk),
        ];
    }

    protected function createUploadProcess(array $chunk): array
    {
        // For file system uploads, we'll use background processes
        // Create a temporary PHP script to handle the upload
        $tempScript = $this->createTempUploadScript($chunk);
        
        $process = [
            'script' => $tempScript,
            'handle' => null,
            'completed' => false,
            'success' => false,
            'error' => null,
            'pid' => null,
            'error_log' => $tempScript . '.error.log', // Add an error log file
        ];

        // Start the background process and redirect stderr
        $command = sprintf(
            '(php %s > /dev/null 2> %s) & echo $!',
            escapeshellarg($tempScript),
            escapeshellarg($process['error_log'])
        );
        
        $process['handle'] = popen($command, 'r');
        $pid = trim(fgets($process['handle']));
        if (!empty($pid)) {
            $process['pid'] = $pid;
        }
        pclose($process['handle']);

        return $process;
    }

    protected function createTempUploadScript(array $chunk): string
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'capsule_upload_');
        
        // Check if chunk has temp_file (memory-efficient) or data (legacy)
        if (isset($chunk['temp_file']) && file_exists($chunk['temp_file'])) {
            $dataFile = $chunk['temp_file'];
            $useTempFile = true;
        } else {
            $dataFile = $tempFile . '.data';
            file_put_contents($dataFile, $chunk['data']);
            $useTempFile = false;
        }
        
        $script = '<?php
require_once "' . base_path('vendor/autoload.php') . '";

try {
    $app = require_once "' . base_path('bootstrap/app.php') . '";
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    
    $storageManager = app(\Dgtlss\Capsule\Storage\StorageManager::class);
    
    $chunkName = "' . $chunk['name'] . '";
    
    // Use file stream for better memory efficiency
    $fileStream = fopen("' . $dataFile . '", 'r');
    if (!$fileStream) {
        throw new Exception("Cannot open chunk file");
    }
    
    $storageManager->storeStream($fileStream, $chunkName);
    fclose($fileStream);
    
    file_put_contents("' . $tempFile . '.success", "SUCCESS");
} catch (Exception $e) {
    file_put_contents("' . $tempFile . '.error", $e->getMessage());
} finally {
    // Clean up data file only if we created it
    if (!' . ($useTempFile ? 'true' : 'false') . ') {
        @unlink("' . $dataFile . '");
    }
}
';

        file_put_contents($tempFile, $script);
        return $tempFile;
    }

    protected function processActiveUploads(): void
    {
        foreach ($this->activeUploads as $uploadId => $upload) {
            if ($this->checkUploadComplete($upload)) {
                $this->completeUpload($uploadId, $upload);
                unset($this->activeUploads[$uploadId]);
                
                // Start next upload if queue not empty
                if (!empty($this->uploadQueue)) {
                    $this->startNextUpload();
                }
            }
        }
    }

    protected function checkUploadComplete(array &$upload): bool
    {
        $script = $upload['process']['script'];
        $errorLog = $upload['process']['error_log'];
        
        // Check if success file exists
        if (file_exists($script . '.success')) {
            $upload['process']['completed'] = true;
            $upload['process']['success'] = true;
            return true;
        }
        
        // Check if an error was logged by the script
        if (file_exists($script . '.error')) {
            $upload['process']['completed'] = true;
            $upload['process']['success'] = false;
            $upload['process']['error'] = file_get_contents($script . '.error');
            return true;
        }
        
        // Check if the stderr log has content
        if (file_exists($errorLog) && filesize($errorLog) > 0) {
            $upload['process']['completed'] = true;
            $upload['process']['success'] = false;
            $upload['process']['error'] = file_get_contents($errorLog);
            return true;
        }

        // Check if process is still running (timeout after 60 seconds)
        if (microtime(true) - $upload['started_at'] > 60) {
            $upload['process']['completed'] = true;
            $upload['process']['success'] = false;
            $upload['process']['error'] = 'Upload timeout';
            return true;
        }

        return false;
    }

    protected function completeUpload(string $uploadId, array $upload): void
    {
        $chunk = $upload['chunk'];
        $process = $upload['process'];
        
        // Clean up temp files
        @unlink($process['script']);
        @unlink($process['script'] . '.success');
        @unlink($process['script'] . '.error');
        @unlink($process['error_log']);
        
        // Clean up temp file if it was used
        if (isset($chunk['temp_file']) && file_exists($chunk['temp_file'])) {
            @unlink($chunk['temp_file']);
        }
        
        $this->results[$uploadId] = [
            'chunk' => $chunk,
            'success' => $process['success'],
            'error' => $process['error'] ?? null,
            'duration' => microtime(true) - $upload['started_at'],
        ];

        if (!$process['success']) {
            Log::warning("Concurrent chunk upload failed: {$chunk['name']}", [
                'error' => $process['error'],
                'chunk' => $chunk['name'],
            ]);
        }
    }

    public function getSuccessfulUploads(): array
    {
        return array_filter($this->results, fn($result) => $result['success']);
    }

    public function getFailedUploads(): array
    {
        return array_filter($this->results, fn($result) => !$result['success']);
    }

    public function getUploadStats(): array
    {
        $successful = count($this->getSuccessfulUploads());
        $failed = count($this->getFailedUploads());
        $total = count($this->results);

        return [
            'total' => $total,
            'successful' => $successful,
            'failed' => $failed,
            'success_rate' => $total > 0 ? ($successful / $total) * 100 : 0,
        ];
    }
}