<?php

namespace Dgtlss\Capsule\Storage;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Dgtlss\Capsule\Support\RestoreArchive;
use Exception;

class StorageManager
{
    protected Filesystem $disk;
    protected string $backupPath;

    public function __construct()
    {
        $diskName = config('capsule.default_disk', 'local');
        $this->disk = $this->resolveDisk($diskName);
        $this->backupPath = config('capsule.backup_path', 'backups');
    }

    protected function resolveDisk(string $name): Filesystem
    {
        $configuration = (array) config("filesystems.disks.{$name}", []);
        if (($configuration['driver'] ?? null) === 's3') {
            // Laravel defaults to buffering GET responses; that can spool a whole final
            // archive to php://temp. Build a private streaming adapter without mutating
            // the application's configuration or reusing an already-cached disk.
            return Storage::build(array_replace($configuration, ['stream_reads' => true]));
        }
        return Storage::disk($name);
    }

    public function store(string $filePath): string
    {
        $stream = @fopen($filePath, 'rb');
        if ($stream === false) {
            throw new Exception("Cannot open backup for upload: {$filePath}");
        }
        try {
            $path = $this->storeStream($stream, basename($filePath));
            $this->replicateToAdditionalDisks($filePath, basename($filePath));
            return $path;
        } finally {
            fclose($stream);
        }
    }

    /** The caller retains ownership of the stream. Uploads are replayed from byte zero. */
    public function storeStream($stream, string $fileName): string
    {
        if (!is_resource($stream) || !stream_get_meta_data($stream)['seekable']) {
            throw new Exception('Backup upload requires a replayable, seekable stream.');
        }
        $expected = $this->fingerprint($stream);
        $remotePath = $this->getPrefixedPath($fileName);
        $this->writeVerified($this->disk, $remotePath, $stream, $expected);
        return $remotePath;
    }

    protected function fingerprint($stream): array
    {
        if (!rewind($stream)) {
            throw new Exception('Cannot rewind backup upload stream.');
        }
        $hash = hash_init('sha256');
        $size = 0;
        while (!feof($stream)) {
            $data = fread($stream, 65536);
            if ($data === false || ($data === '' && !feof($stream))) {
                throw new Exception('Cannot read backup upload stream.');
            }
            $size += strlen($data);
            hash_update($hash, $data);
        }
        return ['size' => $size, 'sha256' => hash_final($hash)];
    }

    protected function writeVerified(Filesystem $disk, string $path, $stream, array $expected): void
    {
        $this->retry(function () use ($disk, $path, $stream, $expected) {
            if (!rewind($stream) || $disk->put($path, $stream) === false) {
                throw new Exception("Storage write failed for: {$path}");
            }
            if (!$disk->exists($path) || $disk->size($path) !== $expected['size']) {
                throw new Exception("Stored backup size does not match: {$path}");
            }
            $stored = $disk->readStream($path);
            if (!is_resource($stored)) {
                throw new Exception("Cannot verify stored backup: {$path}");
            }
            try {
                $hash = hash_init('sha256');
                $bytes = hash_update_stream($hash, $stored);
                if ($bytes !== $expected['size'] || !hash_equals($expected['sha256'], hash_final($hash))) {
                    throw new Exception("Stored backup checksum does not match: {$path}");
                }
            } finally {
                fclose($stored);
            }
        });
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
        if ($adapter && method_exists($adapter, 'getClient') && method_exists($adapter, 'getBucket') && class_exists('\League\Flysystem\AwsS3V3\AwsS3V3Adapter') && $adapter instanceof \League\Flysystem\AwsS3V3\AwsS3V3Adapter) {
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

    public function listFiles(?string $path = null): array
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
        $entries = function () use ($chunkGroups) {
            foreach ($chunkGroups as $name => $chunks) {
                usort($chunks, fn ($a, $b) => ($a['index'] ?? 0) <=> ($b['index'] ?? 0));
                yield ['name' => $name, 'size' => $this->chunksSize($chunks), 'data' => fn () => $this->readChunks($chunks)];
            }
        };
        return $this->storeArchive($entries, $finalFileName, 1);
    }

    public function collateChunksAdvanced(array $chunks, string $finalFileName, int $compressionLevel = 1, bool $encrypt = false, string $password = ''): string
    {
        if ($encrypt) {
            throw new Exception('Encrypted streaming backups are not supported. Use regular backup mode without --no-local to create an encrypted archive.');
        }
        $groups = [];
        foreach ($chunks as $chunk) {
            $groups[$chunk['base_name']][] = $chunk;
        }
        foreach ($groups as &$group) {
            usort($group, fn ($a, $b) => $a['index'] <=> $b['index']);
            foreach ($group as $index => $chunk) {
                if ($chunk['index'] !== $index) {
                    throw new Exception('Missing or duplicate backup chunk index.');
                }
            }
        }
        unset($group);

        $entries = function () use ($groups) {
            foreach ($groups as $baseName => $group) {
                if (str_starts_with($baseName, 'db_')) {
                    yield ['name' => $group[0]['entry_name'] ?? ('database/' . substr($baseName, 3) . '.sql'),
                        'size' => $this->chunksSize($group), 'data' => fn () => $this->readChunks($group)];
                } elseif (str_starts_with($baseName, 'files_') || str_starts_with($baseName, 'file_')) {
                    yield from $this->fileEntries($group);
                } elseif ($baseName === 'manifest.json') {
                    yield ['name' => 'manifest.json', 'size' => $this->chunksSize($group), 'data' => fn () => $this->readChunks($group)];
                } else {
                    throw new Exception('Unrecognized backup chunk group.');
                }
            }
        };
        return $this->storeArchive($entries, $finalFileName, $compressionLevel);
    }

    /** All payloads stay bounded; only ZIP directory and chunk metadata scale with entry count. */
    protected function storeArchive(callable $entries, string $fileName, int $compressionLevel): string
    {
        $zip64Entries = [];
        $factory = function () use ($entries, $compressionLevel, &$zip64Entries) {
            return $this->zipBytes($entries, max(1, min(9, $compressionLevel)), $zip64Entries);
        };
        $stream = ReplayableArchiveStream::open($factory);
        try {
            // The first pass calculates size and hash. The wrapper then exposes its exact size
            // to adapters such as S3 and regenerates the same bytes for each upload retry.
            $expected = $this->fingerprint($stream);
            if ($zip64Entries !== []) {
                // Very large incompressible entries can grow past 4 GiB in deflate.
                // The prepass discovers those before anything is uploaded; replay once
                // with their ZIP64 local headers to calculate the final fingerprint.
                $expected = $this->fingerprint($stream);
            }
            $remotePath = $this->getPrefixedPath($fileName);
            $this->writeVerified($this->disk, $remotePath, $stream, $expected);
            foreach ((array) config('capsule.additional_disks', []) as $diskName) {
                $this->writeVerified($this->resolveDisk($diskName), $remotePath, $stream, $expected);
            }
            return $remotePath;
        } finally {
            fclose($stream);
        }
    }

    protected function chunksSize(array $chunks): int
    {
        $size = 0;
        foreach ($chunks as $chunk) {
            $size += $chunk['size'] ?? $this->disk->size($this->getPrefixedPath($chunk['name']));
        }
        return $size;
    }

    protected function readChunks(array $chunks): \Generator
    {
        foreach ($chunks as $chunk) {
            $path = $this->getPrefixedPath($chunk['name']);
            $stream = $this->disk->readStream($path);
            if (!is_resource($stream)) {
                throw new Exception("Cannot read backup chunk: {$path}");
            }
            $size = 0;
            $hash = hash_init('sha256');
            try {
                while (!feof($stream)) {
                    $data = fread($stream, 65536);
                    if ($data === false || ($data === '' && !feof($stream))) {
                        throw new Exception("Cannot read backup chunk: {$path}");
                    }
                    $size += strlen($data);
                    hash_update($hash, $data);
                    if ($data !== '') {
                        yield $data;
                    }
                }
            } finally {
                fclose($stream);
            }
            $checksum = hash_final($hash);
            if ((isset($chunk['size']) && $size !== $chunk['size']) ||
                (isset($chunk['sha256']) && !hash_equals($chunk['sha256'], $checksum))) {
                throw new Exception("Backup chunk integrity check failed: {$path}");
            }
        }
    }

    protected function fileEntries(array $chunks): \Generator
    {
        $reader = new ArchiveByteReader($this->readChunks($chunks));
        $wideLengths = ($chunks[0]['format'] ?? null) === 'framed-v2';
        $readHeader = function () use (&$reader, $wideLengths) {
            $length = $reader->read(4, true);
            if ($length === null) {
                return null;
            }
            $pathLength = unpack('N', $length)[1];
            if ($pathLength < 1 || $pathLength > 65529) {
                throw new Exception('Invalid backup file frame path length.');
            }
            $path = $reader->read($pathLength);
            $size = unpack($wideLengths ? 'P' : 'N', $reader->read($wideLengths ? 8 : 4))[1];
            if ($size < 0) {
                throw new Exception('Invalid backup file frame size.');
            }
            return ['name' => 'files/' . $path, 'size' => $size];
        };
        $legacySizes = [];
        if (!$wideLengths) {
            while (($header = $readHeader()) !== null) {
                $legacySizes[$header['name']] = ($legacySizes[$header['name']] ?? 0) + $header['size'];
                for ($remaining = $header['size']; $remaining > 0;) {
                    $remaining -= strlen($reader->read(min(65536, $remaining)));
                }
            }
            $reader = new ArchiveByteReader($this->readChunks($chunks));
        }
        $pending = $readHeader();
        while ($pending !== null) {
            $entry = $pending;
            yield ['name' => $entry['name'], 'size' => $wideLengths ? $entry['size'] : $legacySizes[$entry['name']],
                'data' => function () use ($reader, $readHeader, $entry, $wideLengths, &$pending) {
                do {
                    $remaining = $pending['size'];
                    while ($remaining > 0) {
                        $data = $reader->read(min(65536, $remaining));
                        $remaining -= strlen($data);
                        yield $data;
                    }
                    $pending = $readHeader();
                    // Old framing repeated the same path for each fragment. Combine those
                    // records into ONE ZIP entry, including across arbitrary chunk boundaries.
                } while (!$wideLengths && $pending !== null && $pending['name'] === $entry['name']);
            }];
        }
    }

    /** Generate interoperable ZIP64 + raw-deflate records without a local ZIP or payload spool. */
    protected function zipBytes(callable $entries, int $compressionLevel, array &$zip64Entries = []): \Generator
    {
        if (PHP_INT_SIZE < 8 || !function_exists('deflate_init')) {
            throw new Exception('Streaming backups require 64-bit PHP and the zlib extension.');
        }
        $offset = 0;
        $directory = [];
        $portableNames = [];
        $portableDirectories = [];
        foreach ($entries() as $entry) {
            $name = $entry['name'];
            RestoreArchive::assertSafeName($name);
            $portableName = strtolower(rtrim($name, '/'));
            if (strlen($name) > 65535 || str_ends_with($name, '/') || isset($portableNames[$portableName]) || isset($portableDirectories[$portableName])) {
                throw new Exception('Unsafe or duplicate ZIP entry: ' . $name);
            }
            for ($parent = dirname($portableName); $parent !== '.'; $parent = dirname($parent)) {
                if (isset($portableNames[$parent])) {
                    throw new Exception('ZIP file/directory conflict: ' . $name);
                }
                $portableDirectories[$parent] = true;
            }
            $portableNames[$portableName] = true;
            if (!isset($entry['size']) || !is_int($entry['size']) || $entry['size'] < 0) {
                throw new Exception('Streaming ZIP entries require a known non-negative size.');
            }
            $expectedSize = $entry['size'];
            $zip64 = $expectedSize >= 0xffffffff || isset($zip64Entries[$name]);
            $method = 8;
            $start = $offset;
            $unicode = preg_match('/[^\x20-\x7e]/', $name) && strlen($name) <= 65000
                ? pack('vvCV', 0x7075, strlen($name) + 5, 1, crc32($name)) . $name : '';
            $extra = ($zip64 ? pack('vvPP', 1, 16, $expectedSize, 0) : '') . $unicode;
            $header = pack('VvvvvvVVVvv', 0x04034b50, $zip64 ? 45 : 20, 0x0808, $method, 0, 33, 0,
                $zip64 ? 0xffffffff : 0, $zip64 ? 0xffffffff : 0, strlen($name), strlen($extra)) . $name . $extra;
            $offset += strlen($header);
            yield $header;
            $compressor = deflate_init(ZLIB_ENCODING_RAW, ['level' => $compressionLevel]);
            if ($compressor === false) {
                throw new Exception('Cannot initialize ZIP compression.');
            }
            $hash = hash_init('crc32b');
            $size = 0;
            $compressedSize = 0;
            foreach (($entry['data'])() as $data) {
                $size += strlen($data);
                hash_update($hash, $data);
                $compressed = deflate_add($compressor, $data, ZLIB_NO_FLUSH);
                if ($compressed === false) {
                    throw new Exception('ZIP compression failed.');
                }
                $compressedSize += strlen($compressed);
                $offset += strlen($compressed);
                if ($compressed !== '') {
                    yield $compressed;
                }
            }
            $compressed = deflate_add($compressor, '', ZLIB_FINISH);
            if ($compressed === false) {
                throw new Exception('ZIP compression failed.');
            }
            $compressedSize += strlen($compressed);
            $offset += strlen($compressed);
            yield $compressed;
            $crc = hexdec(hash_final($hash));
            if ($size !== $expectedSize) {
                throw new Exception('ZIP entry size changed during generation.');
            }
            if (!$zip64 && $compressedSize >= 0xffffffff) {
                $zip64Entries[$name] = true;
                $zip64 = true;
            }
            $descriptor = $zip64 ? pack('VVPP', 0x08074b50, $crc, $compressedSize, $size)
                : pack('VVVV', 0x08074b50, $crc, $compressedSize, $size);
            $offset += strlen($descriptor);
            yield $descriptor;
            $directory[$name] = compact('name', 'start', 'size', 'compressedSize', 'crc', 'method', 'unicode');
        }
        $centralStart = $offset;
        foreach ($directory as $entry) {
            extract($entry, EXTR_OVERWRITE);
            $wide = ($size >= 0xffffffff ? pack('P', $size) : '')
                . ($compressedSize >= 0xffffffff ? pack('P', $compressedSize) : '')
                . ($start >= 0xffffffff ? pack('P', $start) : '');
            $extra = ($wide !== '' ? pack('vv', 1, strlen($wide)) . $wide : '') . $unicode;
            $version = $wide !== '' ? 45 : 20;
            $record = pack('VvvvvvvVVVvvvvvVV', 0x02014b50, $version, $version, 0x0808, $method, 0, 33, $crc,
                min($compressedSize, 0xffffffff), min($size, 0xffffffff),
                strlen($name), strlen($extra), 0, 0, 0, 0, min($start, 0xffffffff)) . $name . $extra;
            $offset += strlen($record);
            yield $record;
        }
        $centralSize = $offset - $centralStart;
        $count = count($directory);
        if ($count >= 0xffff || $centralSize >= 0xffffffff || $centralStart >= 0xffffffff) {
            yield pack('VPvvVVPPPP', 0x06064b50, 44, 45, 45, 0, 0, $count, $count, $centralSize, $centralStart);
            yield pack('VVPV', 0x07064b50, 0, $offset, 1);
        }
        yield pack('VvvvvVVv', 0x06054b50, 0, 0, min($count, 0xffff), min($count, 0xffff),
            min($centralSize, 0xffffffff), min($centralStart, 0xffffffff), 0);
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
        $backupPath = trim($this->backupPath, '/');
        $fileName = ltrim($fileName, '/');
        if ($backupPath === '' || str_starts_with($fileName, $backupPath . '/')) {
            return $fileName;
        }
        return $backupPath . '/' . $fileName;
    }

    protected function replicateToAdditionalDisks(string $filePath, string $fileName): void
    {
        foreach ((array) config('capsule.additional_disks', []) as $diskName) {
            $stream = @fopen($filePath, 'rb');
            if ($stream === false) {
                throw new Exception('Cannot read backup for replication.');
            }
            try {
                $this->writeVerified($this->resolveDisk($diskName), $this->getPrefixedPath($fileName), $stream, $this->fingerprint($stream));
                Log::info("Backup replicated to additional disk: {$diskName}");
            } finally {
                fclose($stream);
            }
        }
    }

    protected function retry(callable $fn)
    {
        $retries = max(0, (int) config('capsule.storage.retries', 3));
        $backoff = max(0, (int) config('capsule.storage.backoff_ms', 500));
        $maxBackoff = max(0, (int) config('capsule.storage.max_backoff_ms', 5000));

        $attempt = 0;
        beginning:
        try {
            $attempt++;
            $result = $fn();
            if ($result === false) {
                throw new Exception('Storage operation returned false.');
            }
            return $result;
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

/** @internal An exact-read cursor over bounded generator blocks, independent of remote chunk boundaries. */
class ArchiveByteReader
{
    protected string $buffer = '';

    public function __construct(protected \Generator $bytes)
    {
    }

    public function read(int $length, bool $allowEof = false): ?string
    {
        while (strlen($this->buffer) < $length && $this->bytes->valid()) {
            $this->buffer .= $this->bytes->current();
            $this->bytes->next();
        }
        if ($allowEof && $this->buffer === '' && !$this->bytes->valid()) {
            return null;
        }
        if (strlen($this->buffer) < $length) {
            throw new Exception('Truncated backup file frame.');
        }
        $result = substr($this->buffer, 0, $length);
        $this->buffer = substr($this->buffer, $length);
        return $result;
    }
}

/** @internal Pull-based, replayable resource for Filesystem::put; no local payload spool. */
class ReplayableArchiveStream
{
    public $context;
    protected static array $factories = [];
    protected $factory;
    protected ?\Generator $bytes = null;
    protected string $buffer = '';
    protected int $position = 0;
    protected int $size = 0;

    public static function open(callable $factory)
    {
        if (!in_array('capsule-archive', stream_get_wrappers(), true) &&
            !stream_wrapper_register('capsule-archive', self::class)) {
            throw new Exception('Cannot register streaming archive reader.');
        }
        $id = bin2hex(random_bytes(16));
        self::$factories[$id] = $factory;
        try {
            $stream = fopen('capsule-archive://' . $id, 'rb');
            if ($stream === false) {
                throw new Exception('Cannot open streaming archive.');
            }
            return $stream;
        } finally {
            unset(self::$factories[$id]);
        }
    }

    public function stream_open($path, $mode, $options, &$openedPath): bool
    {
        $id = substr($path, strlen('capsule-archive://'));
        if (!isset(self::$factories[$id])) {
            return false;
        }
        $this->factory = self::$factories[$id];
        $this->bytes = ($this->factory)();
        return true;
    }

    public function stream_read($count): string
    {
        while (strlen($this->buffer) < $count && $this->bytes->valid()) {
            $this->buffer .= $this->bytes->current();
            $this->bytes->next();
        }
        $data = substr($this->buffer, 0, $count);
        $this->buffer = substr($this->buffer, strlen($data));
        $this->position += strlen($data);
        $this->size = max($this->size, $this->position);
        return $data;
    }

    public function stream_eof(): bool
    {
        return $this->buffer === '' && !$this->bytes->valid();
    }

    public function stream_tell(): int
    {
        return $this->position;
    }

    public function stream_seek($offset, $whence): bool
    {
        $target = match ($whence) {
            SEEK_SET => $offset,
            SEEK_CUR => $this->position + $offset,
            SEEK_END => $this->size + $offset,
            default => -1,
        };
        if ($target < 0 || $target > $this->size) {
            return false;
        }
        if ($target < $this->position || $target === 0) {
            $this->bytes = ($this->factory)();
            $this->buffer = '';
            $this->position = 0;
        }
        while ($this->position < $target) {
            if ($this->stream_read(min(65536, $target - $this->position)) === '') {
                return false;
            }
        }
        return true;
    }

    public function stream_stat(): array
    {
        return ['size' => $this->size, 'mode' => 0100444, 'mtime' => 0];
    }

    public function stream_set_option($option, $arg1, $arg2): bool
    {
        return false;
    }

    public function stream_close(): void
    {
        $this->bytes = null;
        $this->buffer = '';
    }
}
