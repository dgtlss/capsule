<?php

namespace Dgtlss\Capsule\Storage;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Exception;
use ZipArchive;

class StorageManager
{
    protected Filesystem $disk;
    protected string $backupPath;

    public function __construct()
    {
        $diskName = config('capsule.default_disk', 'local');
        $this->disk = Storage::disk($diskName);
        $this->backupPath = config('capsule.backup_path', 'backups');
    }

    public function store(string $filePath): string
    {
        $fileName = basename($filePath);
        $remotePath = $this->getPrefixedPath($fileName);
        
        $this->disk->putFileAs($this->backupPath, $filePath, $fileName);
        
        return $remotePath;
    }

    public function storeStream($stream, string $fileName): string
    {
        $remotePath = $this->getPrefixedPath($fileName);
        
        $this->disk->put($remotePath, $stream);
        
        return $remotePath;
    }

    public function delete(string $fileName): bool
    {
        $remotePath = $this->getPrefixedPath($fileName);
        
        return $this->disk->delete($remotePath);
    }

    public function exists(string $fileName): bool
    {
        $remotePath = $this->getPrefixedPath($fileName);
        
        return $this->disk->exists($remotePath);
    }

    public function getFileSize(string $fileName): int
    {
        $remotePath = $this->getPrefixedPath($fileName);

        if (!$this->disk->exists($remotePath)) {
            throw new Exception("Unable to retrieve the file_size for file at location: {$remotePath}.");
        }
        
        return $this->disk->size($remotePath) ?? 0;
    }

    public function list(): array
    {
        $files = $this->disk->files($this->backupPath);
        
        return array_map('basename', $files);
    }

    public function listFiles(string $path = null): array
    {
        $targetPath = $path ?? $this->backupPath;
        $files = $this->disk->files($targetPath);
        
        return array_map('basename', $files);
    }

    public function size(string $fileName): int
    {
        $remotePath = $this->getPrefixedPath($fileName);
        
        return $this->disk->size($remotePath) ?? 0;
    }

    public function collateChunks(array $chunkGroups, string $finalFileName): string
    {
        $finalPath = $this->getPrefixedPath($finalFileName);
        
        // Create a temporary zip file
        $tempZip = tmpfile();
        $tempZipPath = stream_get_meta_data($tempZip)['uri'];
        fclose($tempZip);
        
        $zip = new ZipArchive();
        if ($zip->open($tempZipPath, ZipArchive::CREATE) !== TRUE) {
            throw new Exception("Cannot create temporary zip file");
        }

        foreach ($chunkGroups as $baseName => $chunks) {
            $combinedData = '';
            
            foreach ($chunks as $chunk) {
                $chunkPath = $this->getPrefixedPath($chunk['name']);
                
                if ($this->disk->exists($chunkPath)) {
                    $combinedData .= $this->disk->get($chunkPath);
                }
            }
            
            if ($combinedData) {
                $zip->addFromString($baseName, $combinedData);
            }
        }

        $zip->close();

        // Upload the final zip file
        $this->disk->put($finalPath, file_get_contents($tempZipPath));
        unlink($tempZipPath);
        
        return $finalPath;
    }

    public function getDisk(): Filesystem
    {
        return $this->disk;
    }

    public function getBackupPath(): string
    {
        return $this->backupPath;
    }

    protected function getPrefixedPath(string $fileName): string
    {
        // Normalize slashes and remove leading/trailing slashes
        $backupPath = trim($this->backupPath, '/');
        $fileName = trim($fileName, '/');
        
        // Check if the filename already starts with the backup path
        if (strpos($fileName, $backupPath) === 0) {
            return $fileName;
        }

        return $backupPath . '/' . $fileName;
    }
}
