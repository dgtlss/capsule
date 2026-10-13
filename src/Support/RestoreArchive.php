<?php

namespace Dgtlss\Capsule\Support;

use Dgtlss\Capsule\Security\EncryptedArchive;
use RuntimeException;
use SensitiveParameter;
use Symfony\Component\String\UnicodeString;
use ZipArchive;

/** A fully verified, privately staged archive. Never extracts ZIP paths directly. */
class RestoreArchive
{
    public const MAX_MANIFEST_BYTES = 16 * 1024 * 1024;
    protected ZipArchive $zip;
    protected array $manifest = [];
    protected array $entries = [];
    protected array $deletions = [];
    protected array $warnings = [];
    protected ?string $stagingDirectory = null;
    protected bool $opened = false;
    protected ?string $decryptedArchive = null;
    protected bool $verified = false;
    protected int $maxManifestBytes;

    public function __construct(string $path, #[SensitiveParameter] ?string $password = null, ?string $expectedFormat = null,
        int $maxManifestBytes = self::MAX_MANIFEST_BYTES)
    {
        $this->maxManifestBytes = self::manifestByteLimit($maxManifestBytes);
        if ($password !== null && str_contains($password, "\0")) {
            throw new RuntimeException('Archive passwords cannot contain NUL bytes. For an older ZIP backup, configure the effective legacy password (the part before the first NUL byte) explicitly.');
        }
        try {
            if ($expectedFormat !== null && !in_array($expectedFormat, ['zip', EncryptedArchive::FORMAT], true)) {
                throw new RuntimeException('Unsupported expected backup format.');
            }
            $encrypted = EncryptedArchive::isEncryptedFile($path);
            if (($expectedFormat === EncryptedArchive::FORMAT || str_ends_with(strtolower($path), EncryptedArchive::EXTENSION)) && !$encrypted) {
                throw new RuntimeException('Expected a Capsule encrypted backup; plaintext ZIP substitution is not allowed.');
            }
            if ($encrypted) {
                $this->decryptedArchive = BackupDecryption::decryptToTemporaryFile($path, $password ?? '');
                $path = $this->decryptedArchive;
            }
            $this->zip = new ZipArchive();
            if ($this->zip->open($path, ZipArchive::RDONLY) !== true) {
                throw new RuntimeException('Failed to open backup archive.');
            }
            $this->opened = true;
            if ($password !== null && $password !== '' && !$this->zip->setPassword($password)) {
                throw new RuntimeException('Failed to set the archive password.');
            }
        } catch (\Throwable $error) {
            $this->close();
            throw $error;
        }
    }

    /** Validate explicit resource budgets without float conversion or overflow. */
    public static function manifestByteLimit(mixed $value): int
    {
        if (is_int($value) && $value > 0) {
            return $value;
        }
        if (is_string($value) && preg_match('/^[1-9][0-9]*$/D', $value)) {
            $maximum = (string) PHP_INT_MAX;
            if (strlen($value) < strlen($maximum)
                || (strlen($value) === strlen($maximum) && strcmp($value, $maximum) <= 0)) {
                return (int) $value;
            }
        }
        throw new RuntimeException('Set --max-manifest-bytes or capsule.restore.max_manifest_bytes to a positive integer no greater than PHP_INT_MAX.');
    }

    /** Preserve the trusted format requirement when downloads use temporary names. */
    public static function formatExpectation(string $sourcePath, array $metadata = []): ?string
    {
        if (str_ends_with(strtolower($sourcePath), EncryptedArchive::EXTENSION)
            || ($metadata['requires_capsule'] ?? false) === true
            || ($metadata['archive_encryption'] ?? null) === EncryptedArchive::FORMAT) {
            return EncryptedArchive::FORMAT;
        }
        $format = $metadata['archive_format'] ?? null;
        if ($format !== null && !is_string($format)) {
            throw new RuntimeException('Invalid recorded backup format.');
        }
        return $format;
    }

    public static function assertSafeName(string $name, bool $portable = true): void
    {
        if (preg_match('//u', $name) !== 1) {
            throw new RuntimeException('Archive path is not valid UTF-8.');
        }
        $portable = $portable || PHP_OS_FAMILY === 'Windows';
        // Always reject POSIX traversal, absolute paths and controls. On Unix,
        // backslashes and colons are literal legacy filenames, not separators.
        if ($name === '' || preg_match('/[\x00-\x1f\x7f]/', $name) || str_starts_with($name, '/')
            || ($portable && (str_contains($name, ':') || str_contains($name, '\\')))) {
            throw new RuntimeException("Unsafe archive path: {$name}");
        }
        $parts = explode('/', str_ends_with($name, '/') ? substr($name, 0, -1) : $name);
        foreach ($parts as $part) {
            if ($part === '' || $part === '.' || $part === '..'
                || ($portable && rtrim($part, '. ') !== $part)) {
                throw new RuntimeException("Unsafe archive path: {$name}");
            }
            if ($portable && preg_match('/^(CON|PRN|AUX|NUL|COM[1-9]|LPT[1-9])(?:\.|$)/i', $part)) {
                throw new RuntimeException("Unsafe archive path: {$name}");
            }
        }
    }

    /** Compare Unicode spelling without changing the archived or restored name. */
    public static function portablePathKey(string $path): string
    {
        return preg_match('/[^\x00-\x7f]/', $path)
            ? (new UnicodeString($path))->folded(false)->normalize()->toString()
            : strtolower($path);
    }

    /** Track files and inferred directories using the same rules in every producer/reader. */
    public static function registerPortablePath(string $name, array &$paths): void
    {
        self::assertSafeName($name);
        $directory = str_ends_with($name, '/');
        for ($path = rtrim($name, '/'); $path !== '.'; $path = dirname($path)) {
            $key = self::portablePathKey($path);
            $spelling = $path . ($directory ? '/' : '');
            if (isset($paths[$key]) && (!$directory || $paths[$key] !== $spelling)) {
                throw new RuntimeException("Unsafe or duplicate archive path (conflicting destinations): {$name}");
            }
            $paths[$key] = $spelling;
            $directory = true;
        }
    }

    public function verifyAndStage(bool $stage = true): void
    {
        if (!$this->opened) {
            throw new RuntimeException('The backup archive is closed.');
        }
        if ($this->verified && (!$stage || $this->stagingDirectory !== null)) {
            return;
        }
        try {
            $this->verifyContents($stage);
        } catch (\Throwable $e) {
            // Never leave partially verified metadata or plaintext available to
            // callers after any read, authentication or staging failure.
            $this->close();
            $this->manifest = $this->entries = $this->deletions = $this->warnings = [];
            $this->verified = false;
            throw $e;
        }
    }

    protected function verifyContents(bool $stage): void
    {
        $this->warnings = [];
        $members = [];
        $names = [];
        for ($i = 0; $i < $this->zip->numFiles; $i++) {
            $stat = $this->zip->statIndex($i);
            if ($stat === false || !isset($stat['name'])) {
                throw new RuntimeException('Unable to read archive directory.');
            }
            $name = $stat['name'];
            // The manifest is not yet available. Critical path safety applies
            // now; schema-2 portability checks follow before any staging.
            self::assertSafeName($name, false);
            $exactName = rtrim($name, '/');
            if (isset($names[$exactName])) {
                throw new RuntimeException("Duplicate archive path: {$name}");
            }
            $names[$exactName] = $name;
            $opsys = $attributes = 0;
            if ($this->zip->getExternalAttributesIndex($i, $opsys, $attributes)) {
                $type = ($attributes >> 16) & 0170000;
                if ($type !== 0 && $type !== 0100000 && $type !== 0040000) {
                    throw new RuntimeException("Archive links and special files are not supported: {$name}");
                }
                if ($type === 0040000 && !str_ends_with($name, '/')) {
                    throw new RuntimeException("Malformed archive directory: {$name}");
                }
            }
            if (!str_ends_with($name, '/')) {
                $members[$name] = $stat;
            }
        }

        if (!isset($members['manifest.json'])) {
            throw new RuntimeException('A checksum manifest is required for restore.');
        }
        if ($members['manifest.json']['size'] > $this->maxManifestBytes) {
            throw new RuntimeException('Archive manifest exceeds the configured byte limit. For a trusted historical backup, explicitly increase --max-manifest-bytes or capsule.restore.max_manifest_bytes.');
        }
        $json = '';
        $this->readMember('manifest.json', $members['manifest.json']['size'], static function (#[SensitiveParameter] string $chunk) use (&$json): void {
            $json .= $chunk;
        });
        try {
            $manifest = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new RuntimeException('Archive manifest is not valid JSON.', 0, $e);
        }
        if (!is_array($manifest) || !isset($manifest['entries']) || !is_array($manifest['entries'])
            || !in_array($manifest['schema_version'] ?? null, [1, 2], true)) {
            throw new RuntimeException('Unsupported or malformed archive manifest.');
        }
        $this->manifest = $manifest;
        $legacyPaths = $manifest['schema_version'] === 1 && PHP_OS_FAMILY !== 'Windows';
        $portableNames = [];
        $requiresUnixPaths = false;
        foreach ($names as $name) {
            self::assertSafeName($name, !$legacyPaths);
            if (!$legacyPaths) {
                self::registerPortablePath($name, $portableNames);
            } else {
                for ($path = rtrim($name, '/'); $path !== '.'; $path = dirname($path)) {
                    $key = self::portablePathKey($path);
                    if (isset($portableNames[$key]) && $portableNames[$key] !== $path) {
                        $requiresUnixPaths = true;
                    }
                    $portableNames[$key] = $path;
                }
            }
            if ($legacyPaths) {
                try {
                    self::assertSafeName($name);
                } catch (RuntimeException) {
                    $requiresUnixPaths = true;
                }
            }
        }
        if ($requiresUnixPaths) {
            $this->warnings[] = 'Legacy archive uses Unix-specific or case-distinct paths; restore requires a compatible target filesystem.';
        }
        $declared = [];
        foreach ($manifest['entries'] as $entry) {
            if (!is_array($entry) || !is_string($entry['path'] ?? null)
                || !is_int($entry['size'] ?? null) || $entry['size'] < 0
                || !is_string($entry['sha256'] ?? null) || !preg_match('/^[a-f0-9]{64}$/iD', $entry['sha256'])) {
                throw new RuntimeException('Manifest entry is missing a valid path, size or SHA-256 checksum.');
            }
            $name = $entry['path'];
            self::assertSafeName($name, !$legacyPaths);
            if ($name === 'manifest.json' || str_ends_with($name, '/') || isset($declared[$name])) {
                throw new RuntimeException("Invalid or duplicate manifest entry: {$name}");
            }
            if (!isset($members[$name]) || $members[$name]['size'] !== $entry['size']) {
                throw new RuntimeException("Archive member is missing or has the wrong size: {$name}");
            }
            // Integrity is a result of this reader, never a claim supplied by a manifest.
            $entry['integrity'] = 'sha256';
            unset($entry['legacy_paths']);
            if ($legacyPaths) {
                $entry['legacy_paths'] = true;
            }
            $declared[$name] = $entry;
        }
        unset($members['manifest.json']);
        $unlisted = array_diff_key($members, $declared);
        $legacyConnections = $this->legacyStreamingConnections($manifest);
        $legacyDatabases = [];
        $legacyDefaultConnection = null;
        foreach ($unlisted as $name => $stat) {
            // The schema-1 streaming writer omitted database checksums and used
            // timestamped names. Only that documented historical shape is eligible.
            if (!preg_match('#^database/([A-Za-z0-9_-]+)_(\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2})\.sql$#D', $name, $match)
                || $stat['size'] <= 0
                || isset($legacyDatabases[$match[1]]) || isset($declared['database/' . $match[1] . '.sql'])
                || isset($declared['database/' . $match[1] . '.sqlite'])) {
                throw new RuntimeException('Archive contains members missing from the checksum manifest.');
            }
            if (!in_array($match[1], $legacyConnections, true)) {
                // The old manifest retained the literal "default" setting, but
                // its writer named the dump after the resolved source connection.
                if (!in_array('default', $legacyConnections, true) || $legacyDefaultConnection !== null) {
                    throw new RuntimeException('Archive contains members missing from the checksum manifest.');
                }
                $legacyDefaultConnection = $match[1];
                $this->warnings[] = "Legacy default database connection was recorded as {$match[1]} in its archive member name; confirm the restore target connection.";
            }
            $timestamp = \DateTimeImmutable::createFromFormat('!Y-m-d_H-i-s', $match[2], new \DateTimeZone('UTC'));
            if ($timestamp === false || $timestamp->format('Y-m-d_H-i-s') !== $match[2]) {
                throw new RuntimeException('Archive contains an invalid legacy database timestamp.');
            }
            $legacyDatabases[$match[1]] = true;
            $declared[$name] = ['path' => $name, 'size' => $stat['size'], 'sha256' => null,
                'connection' => $match[1], 'integrity' => 'legacy-zip'];
            if ($legacyPaths) {
                $declared[$name]['legacy_paths'] = true;
            }
            $this->warnings[] = "Legacy database {$name} has no historical SHA-256 checksum; integrity is limited to ZIP CRC or native ZIP encryption authentication.";
        }
        foreach (array_keys($members) as $name) {
            $parent = dirname($name);
            while ($parent !== '.') {
                if (isset($members[$parent])) {
                    throw new RuntimeException("Archive file/directory conflict: {$name}");
                }
                $parent = dirname($parent);
            }
        }
        $deleted = $manifest['deleted_files'] ?? [];
        if (!is_array($deleted)) {
            throw new RuntimeException('Manifest deletion records must be a list.');
        }
        foreach ($deleted as $name) {
            if (!is_string($name)) {
                throw new RuntimeException('Invalid manifest deletion record.');
            }
            self::assertSafeName($name);
            if (!str_starts_with($name, 'files/') || str_ends_with($name, '/') || isset($declared[$name])) {
                throw new RuntimeException("Invalid or conflicting deletion record: {$name}");
            }
            $this->deletions[$name] = $name;
        }

        if ($stage) {
            $directory = tempnam(sys_get_temp_dir(), 'capsule_stage_');
            if ($directory === false || !unlink($directory) || !mkdir($directory, 0700)) {
                throw new RuntimeException('Unable to create a private restore staging directory.');
            }
            $this->stagingDirectory = $directory;
        }
        $this->entries = [];
        foreach ($declared as $name => $entry) {
            $path = $stage ? $this->stagingDirectory . '/' . count($this->entries) : null;
            $mask = umask(0077);
            try {
                $output = $stage ? fopen($path, 'xb') : null;
            } finally {
                umask($mask);
            }
            if ($output === false) {
                throw new RuntimeException('Unable to stage archive contents.');
            }
            // Never let a manifest supply an internal temporary file location.
            unset($entry['staged_path']);
            $this->entries[$name] = $stage ? $entry + ['staged_path' => $path] : $entry;
            $hash = hash_init('sha256');
            try {
                $this->readMember($name, $entry['size'], static function (#[SensitiveParameter] string $chunk) use ($hash, $stage, $output): void {
                    hash_update($hash, $chunk);
                    $written = 0;
                    while ($stage && $written < strlen($chunk)) {
                        $bytes = fwrite($output, substr($chunk, $written));
                        if ($bytes === false || $bytes === 0) {
                            throw new RuntimeException('Failed writing restore staging data.');
                        }
                        $written += $bytes;
                    }
                });
                $actualHash = hash_final($hash);
                if ($entry['integrity'] === 'sha256' && !hash_equals(strtolower($entry['sha256']), $actualHash)) {
                    throw new RuntimeException("Archive checksum or size mismatch: {$name}");
                }
                if ($stage && !fflush($output)) {
                    throw new RuntimeException('Failed flushing restore staging data.');
                }
            } finally {
                if (is_resource($output)) {
                    fclose($output);
                }
            }
        }
        $this->verified = true;
    }

    /** Recognize the old writer's manifest, without trusting it as proof of origin. */
    protected function legacyStreamingConnections(array $manifest): array
    {
        if ($manifest['schema_version'] !== 1 || ($manifest['backup_type'] ?? 'full') !== 'full'
            || isset($manifest['base_archive']) || isset($manifest['base_snapshot_id'])
            || !empty($manifest['deleted_files']) || !is_string($manifest['generated_at'] ?? null)
            || !is_array($manifest['app'] ?? null) || !is_array($manifest['capsule'] ?? null)
            || !is_array($manifest['storage'] ?? null) || !is_array($manifest['database'] ?? null)
            || !is_array($manifest['files'] ?? null)) {
            return [];
        }
        foreach (['app' => ['name', 'env', 'laravel_version', 'host'],
            'capsule' => ['version', 'chunked', 'compression_level', 'encryption_enabled'],
            'storage' => ['disk', 'backup_path'], 'database' => ['connections', 'include_tables', 'exclude_tables'],
            'files' => ['paths', 'exclude_paths']] as $section => $keys) {
            foreach ($keys as $key) {
                if (!array_key_exists($key, $manifest[$section])) {
                    return [];
                }
            }
        }
        $connections = $manifest['database']['connections'];
        if ($manifest['capsule']['chunked'] !== true
            || !is_bool($manifest['capsule']['encryption_enabled'])
            || !is_int($manifest['capsule']['compression_level'])
            || !is_array($connections) || !array_is_list($connections) || !array_is_list($manifest['entries'])) {
            return [];
        }
        foreach ($connections as $connection) {
            if (!is_string($connection) || !preg_match('/^[A-Za-z0-9_-]+$/D', $connection)) {
                return [];
            }
        }
        foreach ($manifest['entries'] as $entry) {
            if (!str_starts_with($entry['path'], 'files/')) {
                return [];
            }
        }
        return $connections;
    }

    /** Read through EOF: returning all plaintext does not prove ZIP AES authentication. */
    protected function readMember(string $name, int $expectedSize, callable $consume): void
    {
        $stat = $this->zip->statName($name);
        if ($stat === false || !is_int($stat['crc'] ?? null)) {
            throw new RuntimeException("Cannot read archive checksum: {$name}");
        }
        $aes = in_array($stat['encryption_method'] ?? ZipArchive::EM_NONE,
            [ZipArchive::EM_AES_128, ZipArchive::EM_AES_192, ZipArchive::EM_AES_256], true);
        if ($aes) {
            $this->authenticateNativeAesMember($name, $stat);
        }
        $crc = hash_init('crc32b');
        $input = $this->readZipStream($name, fn () => $this->zip->getStream($name));
        if ($input === false) {
            throw new RuntimeException("Cannot read {$name}; the archive is corrupt or its password is incorrect.");
        }
        try {
            $size = 0;
            while (true) {
                // libzip may emit a warning only on the read after the last
                // plaintext byte, including for empty encrypted members.
                $chunk = $this->readZipStream($name, static fn () => fread($input, 1024 * 1024));
                if ($chunk === false || ($chunk === '' && !feof($input))) {
                    throw new RuntimeException("Failed reading archive member: {$name}");
                }
                if ($chunk === '') {
                    break;
                }
                $size += strlen($chunk);
                if ($size > $expectedSize) {
                    throw new RuntimeException("Archive size mismatch: {$name}");
                }
                hash_update($crc, $chunk);
                $consume($chunk);
            }
            if ($size !== $expectedSize) {
                throw new RuntimeException("Archive size mismatch: {$name}");
            }
            // WinZip AES AE-2 deliberately stores CRC=0 and is authenticated by
            // libzip at EOF instead. Plain ZIP and ZipCrypto still require CRC.
            $actualCrc = hash_final($crc);
            if ((!$aes || $stat['crc'] !== 0) && !hash_equals(sprintf('%08x', $stat['crc'] & 0xffffffff), $actualCrc)) {
                throw new RuntimeException("Cannot read {$name}; the archive is corrupt or its password is incorrect.");
            }
        } finally {
            fclose($input);
        }
    }

    protected function authenticateNativeAesMember(string $name, array $stat): void
    {
        if (!is_int($stat['comp_size'] ?? null) || $stat['comp_size'] < 0) {
            throw new RuntimeException("Cannot read archive compressed size: {$name}");
        }
        // Some libzip decompressors finish at the end of the compressed payload
        // without asking the AES layer to authenticate its footer. FL_COMPRESSED
        // bypasses decompression, but still decrypts/authenticates natively; do
        // not use FL_ENCRYPTED, which would bypass that authentication entirely.
        // getStreamName's flags argument is available from our PHP 8.2 minimum.
        $input = $this->readZipStream($name, fn () => $this->zip->getStreamName($name, ZipArchive::FL_COMPRESSED));
        if ($input === false) {
            throw new RuntimeException("Cannot read {$name}; the archive is corrupt or its password is incorrect.");
        }
        try {
            $size = 0;
            while (true) {
                // Read again after the final bytes, even when feof() is already
                // true: native AES can report a bad tag on that final read.
                $chunk = $this->readZipStream($name, static fn () => fread($input, 1024 * 1024));
                if ($chunk === false || ($chunk === '' && !feof($input))) {
                    throw new RuntimeException("Failed authenticating archive member: {$name}");
                }
                if ($chunk === '') {
                    break;
                }
                $size += strlen($chunk);
                if ($size > $stat['comp_size']) {
                    throw new RuntimeException("Archive compressed size mismatch: {$name}");
                }
            }
        } finally {
            $this->readZipStream($name, static fn () => fclose($input));
        }
    }

    protected function readZipStream(string $name, callable $read): mixed
    {
        // Convert native authentication/CRC warnings into a controlled failure,
        // even if libzip has already returned the declared plaintext size.
        set_error_handler(static function () use ($name): never {
            throw new RuntimeException("Cannot read {$name}; the archive is corrupt or its password is incorrect.");
        }, E_WARNING);
        try {
            return $read();
        } finally {
            restore_error_handler();
        }
    }

    public function verifyOnly(): void
    {
        $this->verifyAndStage(false);
    }

    public function manifest(): array
    {
        return $this->manifest;
    }

    public function entries(): array
    {
        return $this->entries;
    }

    public function deletions(): array
    {
        return array_values($this->deletions);
    }

    public function warnings(): array
    {
        return $this->warnings;
    }

    public function close(): void
    {
        if ($this->opened) {
            $this->zip->close();
            $this->opened = false;
        }
        if ($this->decryptedArchive !== null) {
            @unlink($this->decryptedArchive);
            $this->decryptedArchive = null;
        }
        if ($this->stagingDirectory !== null) {
            foreach ($this->entries as $entry) {
                if (isset($entry['staged_path'])) {
                    @unlink($entry['staged_path']);
                }
            }
            @rmdir($this->stagingDirectory);
            $this->stagingDirectory = null;
        }
    }

    public function __destruct()
    {
        $this->close();
    }
}
