<?php

namespace Dgtlss\Capsule\Support;

use RuntimeException;
use ZipArchive;

/** A fully verified, privately staged archive. Never extracts ZIP paths directly. */
class RestoreArchive
{
    public const MAX_MANIFEST_BYTES = 16 * 1024 * 1024;
    protected ZipArchive $zip;
    protected array $manifest = [];
    protected array $entries = [];
    protected array $deletions = [];
    protected ?string $stagingDirectory = null;
    protected bool $opened = false;
    protected bool $verified = false;

    public function __construct(string $path, ?string $password = null)
    {
        $this->zip = new ZipArchive();
        if ($this->zip->open($path, ZipArchive::RDONLY) !== true) {
            throw new RuntimeException('Failed to open backup archive.');
        }
        $this->opened = true;
        if ($password !== null && $password !== '' && !$this->zip->setPassword($password)) {
            $this->close();
            throw new RuntimeException('Failed to set the archive password.');
        }
    }

    public static function assertSafeName(string $name): void
    {
        if (preg_match('//u', $name) !== 1) {
            throw new RuntimeException('Archive path is not valid UTF-8.');
        }
        // Backslashes, drive prefixes, control characters and empty components are
        // rejected, rather than normalized into a potentially different destination.
        if ($name === '' || preg_match('/[\\\\:\x00-\x1f\x7f]/', $name) || str_starts_with($name, '/')) {
            throw new RuntimeException("Unsafe archive path: {$name}");
        }
        $parts = explode('/', str_ends_with($name, '/') ? substr($name, 0, -1) : $name);
        foreach ($parts as $part) {
            if ($part === '' || $part === '.' || $part === '..' || rtrim($part, '. ') !== $part) {
                throw new RuntimeException("Unsafe archive path: {$name}");
            }
            if (preg_match('/^(CON|PRN|AUX|NUL|COM[1-9]|LPT[1-9])(?:\.|$)/i', $part)) {
                throw new RuntimeException("Unsafe archive path: {$name}");
            }
        }
    }

    public function verifyAndStage(bool $stage = true): void
    {
        if ($this->verified && (!$stage || $this->stagingDirectory !== null)) {
            return;
        }
        $members = [];
        $portableNames = [];
        for ($i = 0; $i < $this->zip->numFiles; $i++) {
            $stat = $this->zip->statIndex($i);
            if ($stat === false || !isset($stat['name'])) {
                throw new RuntimeException('Unable to read archive directory.');
            }
            $name = $stat['name'];
            self::assertSafeName($name);
            $portableName = strtolower(rtrim($name, '/'));
            if (isset($portableNames[$portableName])) {
                throw new RuntimeException("Duplicate archive path: {$name}");
            }
            $portableNames[$portableName] = true;
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
        if ($members['manifest.json']['size'] > self::MAX_MANIFEST_BYTES) {
            throw new RuntimeException('Archive manifest exceeds the supported size.');
        }
        $json = $this->zip->getFromName('manifest.json');
        if ($json === false) {
            throw new RuntimeException('Cannot read manifest; the archive is corrupt or its password is incorrect.');
        }
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
        $declared = [];
        foreach ($manifest['entries'] as $entry) {
            if (!is_array($entry) || !is_string($entry['path'] ?? null)
                || !is_int($entry['size'] ?? null) || $entry['size'] < 0
                || !is_string($entry['sha256'] ?? null) || !preg_match('/^[a-f0-9]{64}$/iD', $entry['sha256'])) {
                throw new RuntimeException('Manifest entry is missing a valid path, size or SHA-256 checksum.');
            }
            $name = $entry['path'];
            self::assertSafeName($name);
            if ($name === 'manifest.json' || str_ends_with($name, '/') || isset($declared[$name])) {
                throw new RuntimeException("Invalid or duplicate manifest entry: {$name}");
            }
            if (!isset($members[$name]) || $members[$name]['size'] !== $entry['size']) {
                throw new RuntimeException("Archive member is missing or has the wrong size: {$name}");
            }
            $declared[$name] = $entry;
        }
        unset($members['manifest.json']);
        if (array_diff_key($members, $declared)) {
            throw new RuntimeException('Archive contains members missing from the checksum manifest.');
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
            $input = $this->zip->getStream($name);
            if ($input === false) {
                throw new RuntimeException("Cannot read {$name}; the archive is corrupt or its password is incorrect.");
            }
            $output = $stage ? fopen($path, 'xb') : null;
            if ($output === false) {
                fclose($input);
                throw new RuntimeException('Unable to stage archive contents.');
            }
            // Never let a manifest supply an internal temporary file location.
            unset($entry['staged_path']);
            $this->entries[$name] = $stage ? $entry + ['staged_path' => $path] : $entry;
            $hash = hash_init('sha256');
            $size = 0;
            try {
                while (!feof($input)) {
                    $chunk = fread($input, 1024 * 1024);
                    if ($chunk === false || ($chunk === '' && !feof($input))) {
                        throw new RuntimeException("Failed reading archive member: {$name}");
                    }
                    $size += strlen($chunk);
                    if ($size > $entry['size']) {
                        throw new RuntimeException("Archive size mismatch: {$name}");
                    }
                    hash_update($hash, $chunk);
                    $written = 0;
                    while ($stage && $written < strlen($chunk)) {
                        $bytes = fwrite($output, substr($chunk, $written));
                        if ($bytes === false || $bytes === 0) {
                            throw new RuntimeException('Failed writing restore staging data.');
                        }
                        $written += $bytes;
                    }
                }
                if ($size !== $entry['size'] || !hash_equals(strtolower($entry['sha256']), hash_final($hash))) {
                    throw new RuntimeException("Archive checksum or size mismatch: {$name}");
                }
                if ($stage && !fflush($output)) {
                    throw new RuntimeException('Failed flushing restore staging data.');
                }
            } finally {
                fclose($input);
                if (is_resource($output)) {
                    fclose($output);
                }
            }
        }
        $this->verified = true;
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

    public function close(): void
    {
        if ($this->opened) {
            $this->zip->close();
            $this->opened = false;
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
