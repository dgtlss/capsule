<?php

namespace Dgtlss\Capsule\Support;

class ManifestBuilder
{
    /** @var array<int, array<string, mixed>> */
    protected array $entries = [];

    public function addEntry(string $zipPath, string $sourceFilePath): void
    {
        $this->entries[] = [
            'path' => $zipPath,
            'size' => @filesize($sourceFilePath) ?: 0,
            'sha256' => @hash_file('sha256', $sourceFilePath) ?: '',
        ];
    }

    public function addDeferredEntry(string $zipPath, string $sourceFilePath): void
    {
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
                $processedEntries[] = [
                    'path' => $entry['path'],
                    'size' => $entry['size'] ?? 0,
                    'sha256' => $entry['sha256'] ?? '',
                ];
            }
        }

        return [
            'schema_version' => 1,
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
            'entries' => $processedEntries,
        ];
    }

    public function reset(): void
    {
        $this->entries = [];
    }
}
