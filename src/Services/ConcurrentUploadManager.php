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
        ];

        // Start the background process
        $command = sprintf(
            'php %s > /dev/null 2>&1 & echo $!',
            escapeshellarg($tempScript)
        );
        
        $process['handle'] = popen($command, 'r');
        $process['pid'] = trim(fgets($process['handle']));
        pclose($process['handle']);

        return $process;
    }

    protected function createTempUploadScript(array $chunk): string
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'capsule_upload_');
        
        $script = '<?php
require_once "' . base_path('vendor/autoload.php') . '";

try {
    $app = require_once "' . base_path('bootstrap/app.php') . '";
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    
    $storageManager = app(\Dgtlss\Capsule\Storage\StorageManager::class);
    
    $chunkData = base64_decode("' . base64_encode($chunk['data']) . '");
    $chunkName = "' . $chunk['name'] . '";
    
    $tempStream = fopen("php://temp", "r+");
    fwrite($tempStream, $chunkData);
    rewind($tempStream);
    
    $storageManager->storeStream($tempStream, $chunkName);
    fclose($tempStream);
    
    file_put_contents("' . $tempFile . '.success", "SUCCESS");
} catch (Exception $e) {
    file_put_contents("' . $tempFile . '.error", $e->getMessage());
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

    protected function checkUploadComplete(array $upload): bool
    {
        $script = $upload['process']['script'];
        
        // Check if success or error file exists
        if (file_exists($script . '.success')) {
            $upload['process']['completed'] = true;
            $upload['process']['success'] = true;
            return true;
        }
        
        if (file_exists($script . '.error')) {
            $upload['process']['completed'] = true;
            $upload['process']['success'] = false;
            $upload['process']['error'] = file_get_contents($script . '.error');
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