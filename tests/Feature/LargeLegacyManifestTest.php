<?php

namespace Dgtlss\Capsule\Tests\Feature;

use Dgtlss\Capsule\Models\BackupLog;
use Dgtlss\Capsule\Support\RestoreArchive;
use Dgtlss\Capsule\Tests\TestCase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use ZipArchive;

class LargeLegacyManifestTest extends TestCase
{
    public function test_explicit_reader_budget_recovers_valid_legacy_manifest_larger_than_default(): void
    {
        $path = $this->largeArchive();
        $arguments = ['--archive' => $path, '--target' => $this->testPath('restored'),
            '--files-only' => true, '--force' => true, '--format' => 'json'];
        $this->assertSame(1, Artisan::call('capsule:restore', $arguments));
        $this->assertStringContainsString('--max-manifest-bytes', Artisan::output());
        $this->assertDirectoryDoesNotExist($this->testPath('restored'));

        $this->assertSame(0, Artisan::call('capsule:restore', $arguments + ['--max-manifest-bytes' => '33554432']), Artisan::output());
        $this->assertSame('legacy payload', file_get_contents($this->testPath('restored/original.txt')));

        // The archive is unchanged, and the default stays conservative afterward.
        $reader = new RestoreArchive($path);
        try {
            $this->expectExceptionMessage('manifest exceeds');
            $reader->verifyOnly();
        } finally {
            $reader->close();
        }
    }

    public function test_verify_and_inspect_use_explicit_configuration_budget(): void
    {
        $path = $this->largeArchive();
        $stream = fopen($path, 'rb');
        try {
            Storage::disk('capsule-test')->put('backups/large-legacy.zip', $stream);
        } finally {
            fclose($stream);
        }
        $backup = BackupLog::create(['status' => 'success', 'started_at' => now(),
            'file_path' => 'backups/large-legacy.zip', 'file_size' => filesize($path)]);
        $this->assertSame(1, Artisan::call('capsule:verify', ['--id' => $backup->id, '--format' => 'json']));
        $this->assertSame(1, Artisan::call('capsule:inspect', ['id' => $backup->id, '--format' => 'json']));
        config(['capsule.restore.max_manifest_bytes' => 33554432]);
        $this->assertSame(0, Artisan::call('capsule:verify', ['--id' => $backup->id, '--format' => 'json']), Artisan::output());
        $code = Artisan::call('capsule:inspect', ['id' => $backup->id, '--format' => 'json']);
        $output = Artisan::output();
        $this->assertSame(0, $code);
        $this->assertSame('success', json_decode($output, true, 512, JSON_THROW_ON_ERROR)['status']);
    }

    public function test_larger_budget_does_not_relax_historical_payload_checksums(): void
    {
        $path = $this->largeArchive(true);
        $code = Artisan::call('capsule:restore', ['--archive' => $path,
            '--target' => $this->testPath('must-not-exist'), '--force' => true,
            '--max-manifest-bytes' => '33554432', '--format' => 'json']);
        $this->assertSame(1, $code);
        $this->assertStringContainsString('checksum', Artisan::output());
        $this->assertDirectoryDoesNotExist($this->testPath('must-not-exist'));
    }

    private function largeArchive(bool $badChecksum = false): string
    {
        $payload = 'legacy payload';
        // Schema 1 allowed arbitrary app metadata; this is a small compressed
        // fixture with a valid JSON manifest just over the normal 16 MiB budget.
        $manifest = ['schema_version' => 1, 'backup_type' => 'full',
            'app' => ['name' => str_repeat('x', RestoreArchive::MAX_MANIFEST_BYTES)],
            'entries' => [['path' => 'files/original.txt', 'size' => strlen($payload),
                'sha256' => $badChecksum ? str_repeat('0', 64) : hash('sha256', $payload)]]];
        $path = $this->testPath('large-legacy.zip');
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE));
        $this->assertTrue($zip->addFromString('files/original.txt', $payload));
        $this->assertTrue($zip->addFromString('manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR)));
        $this->assertTrue($zip->close());
        return $path;
    }
}
