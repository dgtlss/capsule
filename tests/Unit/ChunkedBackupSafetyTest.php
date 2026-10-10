<?php

namespace Dgtlss\Capsule\Tests\Unit;

use Dgtlss\Capsule\Models\BackupLog;
use Dgtlss\Capsule\Services\ChunkedBackupService;
use Dgtlss\Capsule\Storage\StorageManager;
use Dgtlss\Capsule\Tests\TestCase;
use Illuminate\Support\Facades\Storage;
use ZipArchive;

class ChunkedBackupSafetyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'capsule.storage.retries' => 0,
            'capsule.storage.backoff_ms' => 0,
            'capsule.files.enabled' => true,
            'capsule.retention.cleanup_enabled' => false,
            'capsule.chunked_backup.chunk_size' => 127,
            'capsule.files.exclude_paths' => [],
        ]);
    }

    public function test_direct_file_and_empty_file_have_complete_entries_and_captured_checksums(): void
    {
        $root = $this->sourceDirectory();
        $bytes = random_bytes(13017);
        file_put_contents($root . '/direct.bin', $bytes);
        file_put_contents($root . '/empty.txt', '');
        config(['capsule.files.paths' => [$root . '/direct.bin', $root . '/empty.txt']]);
        $service = app(ChunkedBackupService::class);
        $this->assertTrue($service->run(), $service->getLastError() ?? 'Backup failed.');
        $backup = BackupLog::where('status', 'success')->latest('id')->firstOrFail();
        $zip = new ZipArchive();
        $this->assertTrue($zip->open(Storage::disk('capsule-test')->path($backup->file_path), ZipArchive::CHECKCONS));
        try {
            $this->assertSame($bytes, $zip->getFromName('files/root-0/direct.bin'));
            $this->assertSame('', $zip->getFromName('files/root-1/empty.txt'));
            $manifest = json_decode($zip->getFromName('manifest.json'), true, 512, JSON_THROW_ON_ERROR);
            $entries = array_column($manifest['entries'], null, 'path');
            $this->assertSame(hash('sha256', $bytes), $entries['files/root-0/direct.bin']['sha256']);
            $this->assertSame(hash('sha256', ''), $entries['files/root-1/empty.txt']['sha256']);
            $this->assertCount(2, $entries);
        } finally {
            $zip->close();
        }
        $this->assertSame([$backup->file_path], Storage::disk('capsule-test')->files('backups'));
    }

    public function test_encryption_fails_before_chunks_and_same_instance_resets_for_later_runs(): void
    {
        $root = $this->sourceDirectory();
        file_put_contents($root . '/first.txt', 'first');
        file_put_contents($root . '/second.txt', 'second');
        config(['capsule.files.paths' => [$root . '/first.txt']]);
        $service = app(ChunkedBackupService::class);
        $service->setEncryption(true);
        $this->assertFalse($service->run());
        $this->assertStringContainsString('Encrypted streaming backups are not supported', $service->getLastError());
        $this->assertSame([], Storage::disk('capsule-test')->allFiles('backups'));
        $service->setEncryption(false);
        $this->assertTrue($service->run(), $service->getLastError() ?? 'Backup failed.');
        $this->assertNull($service->getLastError());
        config(['capsule.files.paths' => [$root . '/second.txt']]);
        $this->assertTrue($service->run(), $service->getLastError() ?? 'Backup failed.');
        $files = Storage::disk('capsule-test')->files('backups');
        $this->assertCount(2, $files);
        $this->assertNotSame($files[0], $files[1]);
        $backup = BackupLog::where('status', 'success')->latest('id')->firstOrFail();
        $zip = new ZipArchive();
        $this->assertTrue($zip->open(Storage::disk('capsule-test')->path($backup->file_path)));
        try {
            $manifest = json_decode($zip->getFromName('manifest.json'), true, 512, JSON_THROW_ON_ERROR);
            $this->assertSame(['files/root-0/second.txt'], array_column($manifest['entries'], 'path'));
            $this->assertFalse($zip->locateName('files/root-0/first.txt'));
        } finally {
            $zip->close();
        }
    }

    public function test_nonzero_dump_exit_is_not_hidden_by_output_and_stderr_is_not_archived(): void
    {
        $service = app(ChunkedBackupService::class);
        $method = new \ReflectionMethod($service, 'streamCommandToChunks');
        $method->setAccessible(true);
        try {
            $method->invoke($service, "printf 'SQL PAYLOAD'; printf 'dump failure' >&2; exit 7", 'db_test', 3, 'capsule_');
            $this->fail('A failing dump must fail even when it writes bytes.');
        } catch (\Exception $e) {
            $this->assertStringContainsString('exit code 7', $e->getMessage());
            $this->assertStringContainsString('dump failure', $e->getMessage());
        }
        foreach (Storage::disk('capsule-test')->files('backups') as $chunk) {
            $this->assertStringNotContainsString('dump failure', Storage::disk('capsule-test')->get($chunk));
        }
    }

    public function test_failed_final_upload_preserves_source_chunks_for_recovery(): void
    {
        $root = $this->sourceDirectory();
        file_put_contents($root . '/document.txt', str_repeat('contents', 500));
        config(['capsule.files.paths' => [$root]]);
        $storage = new class extends StorageManager {
            public function collateChunksAdvanced(array $chunks, string $finalFileName, int $compressionLevel = 1, bool $encrypt = false, string $password = ''): string
            {
                throw new \RuntimeException('Final upload failed.');
            }
        };
        $service = new ChunkedBackupService($this->app, $storage);
        $this->assertFalse($service->run());
        $this->assertSame('Final upload failed.', $service->getLastError());
        $files = Storage::disk('capsule-test')->files('backups');
        $this->assertNotEmpty($files);
        $failed = BackupLog::where('status', 'failed')->latest('id')->firstOrFail();
        $this->assertNotEmpty($failed->metadata['recovery_chunks']);
        foreach ($files as $file) {
            $this->assertStringContainsString('capsule_chunk_', $file);
            $this->assertStringNotContainsString('document', $file);
        }
    }

    public function test_failure_listener_cannot_overwrite_the_original_backup_error(): void
    {
        \Illuminate\Support\Facades\Event::listen(\Dgtlss\Capsule\Events\BackupFailed::class, function () {
            throw new \RuntimeException('Observer failure.');
        });
        $service = app(ChunkedBackupService::class);
        $service->setEncryption(true);
        $this->assertFalse($service->run());
        $this->assertStringContainsString('Encrypted streaming backups are not supported', $service->getLastError());
    }

    public function test_streaming_replicas_are_complete_and_recorded_in_metadata(): void
    {
        $root = $this->sourceDirectory();
        file_put_contents($root . '/file.txt', 'test file payload');
        config([
            'capsule.files.paths' => [$root],
            'capsule.additional_disks' => ['secondary'],
            'filesystems.disks.secondary' => ['driver' => 'local', 'root' => $this->testPath('replica'), 'throw' => true],
        ]);
        $service = app(ChunkedBackupService::class);
        $this->assertTrue($service->run(), $service->getLastError() ?? 'Backup failed.');
        $backup = BackupLog::where('status', 'success')->firstOrFail();
        $primary = Storage::disk('capsule-test')->path($backup->file_path);
        $replica = Storage::disk('secondary')->path($backup->file_path);
        $this->assertSame(hash_file('sha256', $primary), hash_file('sha256', $replica));
        $this->assertSame(['secondary'], $backup->metadata['storage']['additional_disks']);
    }

    protected function sourceDirectory(): string
    {
        $root = $this->testPath('sources');
        if (!is_dir($root)) {
            mkdir($root, 0700, true);
        }
        return $root;
    }
}
