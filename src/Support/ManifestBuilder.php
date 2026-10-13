<?php

namespace Dgtlss\Capsule\Support;

class ManifestBuilder
{
    /** @var array<int, array<string, mixed>> */
    protected array $entries = [];
    protected array $paths = [];

    public function addEntry(string $zipPath, string $sourceFilePath): void
    {
        $this->registerPath($zipPath);
        $this->entries[] = [
            'path' => $zipPath,
            'size' => @filesize($sourceFilePath) ?: 0,
            'sha256' => @hash_file('sha256', $sourceFilePath) ?: '',
        ];
    }

    public function addStreamEntry(string $zipPath, int $size, string $sha256, array $metadata = []): void
    {
        $this->registerPath($zipPath);
        $this->entries[] = array_merge($metadata, ['path' => $zipPath, 'size' => $size, 'sha256' => $sha256]);
    }

    public function addDeferredEntry(string $zipPath, string $sourceFilePath): void
    {
        $this->registerPath($zipPath);
        $this->entries[] = [
            'path' => $zipPath,
            'source_file' => $sourceFilePath,
            'deferred' => true,
        ];
    }

    public function build(bool $isChunked, int $compressionLevel, bool $encryptionEnabled): array
    {
        $dbConns = config('capsule.database.connections');
        $dbConns = $dbConns === null ? [config('database.default')] : (is_string($dbConns) ? [$dbConns] : $dbConns);

        $processedEntries = [];
        foreach ($this->entries as $entry) {
            if (!empty($entry['deferred']) && isset($entry['source_file'])) {
                $processedEntries[] = [
                    'path' => $entry['path'],
                    'size' => @filesize($entry['source_file']) ?: 0,
                    'sha256' => @hash_file('sha256', $entry['source_file']) ?: '',
                ];
            } else {
                $processedEntries[] = array_merge(array_intersect_key($entry, array_flip(['connection', 'driver', 'format'])), [
                    'path' => $entry['path'],
                    'size' => $entry['size'] ?? 0,
                    'sha256' => $entry['sha256'] ?? '',
                ]);
            }
        }

        return [
            'schema_version' => 2,
            'generated_at' => now()->toISOString(),
            'app' => [
                'name' => config('app.name'),
                'env' => app()->environment(),
                'laravel_version' => app()->version(),
                'host' => gethostname() ?: null,
            ],
            'capsule' => [
                'version' => null,
                'chunked' => $isChunked,
                'compression_level' => $compressionLevel,
                'encryption_enabled' => $encryptionEnabled,
            ],
            'storage' => [
                'disk' => config('capsule.default_disk'),
                'backup_path' => config('capsule.backup_path'),
            ],
            'database' => [
                'connections' => $dbConns,
                'include_tables' => (array) (config('capsule.database.include_tables', []) ?? []),
                'exclude_tables' => (array) (config('capsule.database.exclude_tables', []) ?? []),
            ],
            'files' => [
                'paths' => (array) config('capsule.files.paths', []),
                'exclude_paths' => (array) config('capsule.files.exclude_paths', []),
            ],
            'file_roots' => FileSelection::roots(),
            'policy' => config('capsule._policy_name'),
            'retention' => (array) config('capsule.retention', []),
            'entries' => $processedEntries,
        ];
    }

    private function registerPath(string $path): void
    {
        RestoreArchive::assertSafeName($path);
        if (RestoreArchive::portablePathKey($path) === 'manifest.json') {
            throw new \RuntimeException("Duplicate or reserved manifest path: {$path}");
        }
        RestoreArchive::registerPortablePath($path, $this->paths);
    }

    public static function encode(array $manifest): string
    {
        $json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (strlen($json) > RestoreArchive::MAX_MANIFEST_BYTES) {
            throw new \RuntimeException('Backup manifest exceeds the supported 16 MiB restore limit. Split the file roots into smaller backup policies.');
        }
        return $json;
    }

    public function reset(): void
    {
        $this->entries = [];
        $this->paths = [];
    }
}
