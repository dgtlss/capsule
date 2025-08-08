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
        $this->retry(function () use ($filePath, $fileName) {
            $this->disk->putFileAs($this->backupPath, $filePath, $fileName);
        });
        return $remotePath;
    }

    public function storeStream($stream, string $fileName): string
    {
        $remotePath = $this->getPrefixedPath($fileName);
        $this->retry(function () use ($remotePath, $stream) {
            $this->disk->put($remotePath, $stream);
        });
        return $remotePath;
    }

    public function delete(string $fileName): bool
    {
        $remotePath = $this->getPrefixedPath($fileName);
        return (bool) $this->retry(function () use ($remotePath) {
            return $this->disk->delete($remotePath);
        });
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

    /**
     * Attempt to retrieve a remote checksum for a file if supported by the driver.
     * Returns null when not available.
     */
    public function getRemoteChecksum(string $fileName): ?string
    {
        $adapter = method_exists($this->disk, 'getAdapter') ? $this->disk->getAdapter() : null;
        $remotePath = $this->getPrefixedPath($fileName);

        // S3 adapter exposes client & bucket; ETag returned by headObject
        if ($adapter && class_exists('\League\Flysystem\AwsS3V3\AwsS3V3Adapter') && $adapter instanceof \League\Flysystem\AwsS3V3\AwsS3V3Adapter) {
            $client = $adapter->getClient();
            $bucket = $adapter->getBucket();
            try {
                $result = $client->headObject(['Bucket' => $bucket, 'Key' => $remotePath]);
                $etag = $result['ETag'] ?? null;
                if ($etag) {
                    return trim($etag, '"');
                }
            } catch (\Throwable $e) {
                return null;
            }
        }

        // Other drivers: not implemented
        return null;
    }

    public function list(): array
    {
        $files = $this->disk->files($this->backupPath);
        
        return array_map('basename', $files);
    }

    public function listFiles(string $path = null): array
    {
        $targetPath = $path ?? $this->backupPath;
        $files = $this->retry(function () use ($targetPath) {
            return $this->disk->files($targetPath);
        });
        
        return array_map('basename', $files);
    }

    public function size(string $fileName): int
    {
        $remotePath = $this->getPrefixedPath($fileName);
        return (int) ($this->retry(function () use ($remotePath) {
            return $this->disk->size($remotePath);
        }) ?? 0);
    }

    public function collateChunks(array $chunkGroups, string $finalFileName): string
    {
        // Backward-compatible simple concatenation ZIP builder
        $finalPath = $this->getPrefixedPath($finalFileName);
        $tempZipPath = tempnam(sys_get_temp_dir(), 'capsule_zip_');

        $zip = new ZipArchive();
        if ($zip->open($tempZipPath, ZipArchive::CREATE) !== TRUE) {
            throw new Exception("Cannot create temporary zip file");
        }

        foreach ($chunkGroups as $baseName => $chunks) {
            // Ensure chunk order
            usort($chunks, fn($a, $b) => ($a['index'] ?? 0) <=> ($b['index'] ?? 0));

            $combinedData = '';
            foreach ($chunks as $chunk) {
                $chunkPath = $this->getPrefixedPath($chunk['name']);
                if ($this->disk->exists($chunkPath)) {
                    $combinedData .= $this->disk->get($chunkPath);
                }
            }

            if ($combinedData !== '') {
                $zip->addFromString($baseName, $combinedData);
            }
        }

        $zip->close();

        $this->disk->put($finalPath, file_get_contents($tempZipPath));
        @unlink($tempZipPath);
        return $finalPath;
    }

    public function collateChunksAdvanced(array $chunks, string $finalFileName, int $compressionLevel = 1, bool $encrypt = false, string $password = ''): string
    {
        $finalPath = $this->getPrefixedPath($finalFileName);
        $tempZipPath = tempnam(sys_get_temp_dir(), 'capsule_zip_');

        $zip = new ZipArchive();
        if ($zip->open($tempZipPath, ZipArchive::CREATE) !== TRUE) {
            throw new Exception('Cannot create temporary zip file');
        }

        // Optional encryption setup
        if ($encrypt && !empty($password)) {
            @$zip->setPassword($password);
        }

        // Group by base_name, then sort by index
        $groups = [];
        foreach ($chunks as $chunk) {
            $groups[$chunk['base_name']][] = $chunk;
        }
        foreach ($groups as $baseName => $group) {
            usort($group, fn($a, $b) => $a['index'] <=> $b['index']);

            if (str_starts_with($baseName, 'db_')) {
                // Database: concatenate chunks into a single .sql entry path under database/
                $entryName = 'database/' . preg_replace('/^db_/', '', $baseName) . '.sql';
                $data = '';
                foreach ($group as $chunk) {
                    $path = $this->getPrefixedPath($chunk['name']);
                    if ($this->disk->exists($path)) {
                        $data .= $this->disk->get($path);
                    }
                }
                if ($data !== '') {
                    $zip->addFromString($entryName, $data);
                    if (method_exists($zip, 'setCompressionName')) {
                        @$zip->setCompressionName($entryName, ZipArchive::CM_DEFLATE, $compressionLevel);
                    }
                    if ($encrypt && method_exists($zip, 'setEncryptionName') && !empty($password)) {
                        @$zip->setEncryptionName($entryName, ZipArchive::EM_AES_256);
                    }
                }
            } elseif (str_starts_with($baseName, 'files_') || str_starts_with($baseName, 'file_')) {
                // Files: our framing format [N][path][N][content] repeated
                $buffer = '';
                foreach ($group as $chunk) {
                    $path = $this->getPrefixedPath($chunk['name']);
                    if ($this->disk->exists($path)) {
                        $buffer .= $this->disk->get($path);
                    }
                }

                $offset = 0;
                $len = strlen($buffer);
                while ($offset + 4 <= $len) {
                    $pathLen = unpack('N', substr($buffer, $offset, 4))[1] ?? 0;
                    $offset += 4;
                    if ($pathLen <= 0 || $offset + $pathLen > $len) break;
                    $relativePath = substr($buffer, $offset, $pathLen);
                    $offset += $pathLen;
                    if ($offset + 4 > $len) break;
                    $contentLen = unpack('N', substr($buffer, $offset, 4))[1] ?? 0;
                    $offset += 4;
                    if ($contentLen < 0 || $offset + $contentLen > $len) break;
                    $content = substr($buffer, $offset, $contentLen);
                    $offset += $contentLen;

                    // Normalize entry path under files/
                    $entryName = 'files/' . ltrim($relativePath, '/');
                    $zip->addFromString($entryName, $content);
                    if (method_exists($zip, 'setCompressionName')) {
                        @$zip->setCompressionName($entryName, ZipArchive::CM_DEFLATE, $compressionLevel);
                    }
                    if ($encrypt && method_exists($zip, 'setEncryptionName') && !empty($password)) {
                        @$zip->setEncryptionName($entryName, ZipArchive::EM_AES_256);
                    }
                }
            } elseif ($baseName === 'manifest.json') {
                // Manifest provided inline (single chunk expected)
                $buffer = '';
                foreach ($group as $chunk) {
                    $path = $this->getPrefixedPath($chunk['name']);
                    if ($this->disk->exists($path)) {
                        $buffer .= $this->disk->get($path);
                    } else {
                        // Some implementations may send inline data; try reading from memory path is not possible here.
                        // Skip if not found; manifest is optional.
                    }
                }
                if ($buffer !== '') {
                    $zip->addFromString('manifest.json', $buffer);
                    if (method_exists($zip, 'setCompressionName')) {
                        @$zip->setCompressionName('manifest.json', ZipArchive::CM_DEFLATE, $compressionLevel);
                    }
                    if ($encrypt && method_exists($zip, 'setEncryptionName') && !empty($password)) {
                        @$zip->setEncryptionName('manifest.json', ZipArchive::EM_AES_256);
                    }
                }
            }
        }

        $zip->close();

        $this->disk->put($finalPath, file_get_contents($tempZipPath));
        @unlink($tempZipPath);
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

    protected function retry(callable $fn)
    {
        $retries = (int) config('capsule.storage.retries', 3);
        $backoff = (int) config('capsule.storage.backoff_ms', 500);
        $maxBackoff = (int) config('capsule.storage.max_backoff_ms', 5000);

        $attempt = 0;
        beginning:
        try {
            $attempt++;
            return $fn();
        } catch (\Throwable $e) {
            if ($attempt > $retries) {
                throw $e;
            }
            usleep(min($backoff, $maxBackoff) * 1000);
            $backoff = min($backoff * 2, $maxBackoff);
            goto beginning;
        }
    }
}
