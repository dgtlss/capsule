<?php

namespace Dgtlss\Capsule\Tests\Feature;

use Dgtlss\Capsule\Models\BackupLog;
use Dgtlss\Capsule\Services\BackupService;
use Dgtlss\Capsule\Services\IncrementalService;
use Dgtlss\Capsule\Support\FileSelection;
use Dgtlss\Capsule\Tests\TestCase;
use Illuminate\Support\Facades\Storage;
use ZipArchive;

class BackupReliabilityTest extends TestCase
{
    protected string $fixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixture = sys_get_temp_dir() . '/capsule-reliability-' . bin2hex(random_bytes(8));
        mkdir($this->fixture . '/source', 0700, true);
        file_put_contents($this->fixture . '/source/hello.txt', 'hello');
        config([
            'capsule.files.enabled' => true,
            'capsule.files.paths' => [$this->fixture . '/source'],
            'capsule.files.exclude_paths' => [],
            'capsule.retention.cleanup_enabled' => false,
            'capsule.audit.enabled' => false,
            'capsule.performance.memory_limit' => null,
        ]);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->fixture)) {
            $this->removeDirectory($this->fixture);
        }
        parent::tearDown();
    }

    public function test_local_disk_backups_are_nonempty_unique_and_never_capture_output(): void
    {
        config([
            'filesystems.disks.local' => ['driver' => 'local', 'root' => $this->fixture . '/source'],
            'capsule.default_disk' => 'local',
        ]);
        $service = app(BackupService::class);
        $this->assertTrue($service->run(), $service->getLastError() ?? '');
        $this->assertTrue($service->run(), $service->getLastError() ?? '');
        $logs = BackupLog::successful()->get();
        $this->assertCount(2, $logs);
        $this->assertNotSame($logs[0]->file_path, $logs[1]->file_path);
        foreach ($logs as $log) {
            $this->assertGreaterThan(0, Storage::disk('local')->size($log->file_path));
            $zip = new ZipArchive();
            $this->assertTrue($zip->open(Storage::disk('local')->path($log->file_path)));
            $this->assertSame('hello', $zip->getFromName('files/root-0/hello.txt'));
            $manifest = json_decode($zip->getFromName('manifest.json'), true);
            $this->assertCount(1, $manifest['entries']);
            $zip->close();
        }
    }

    public function test_requested_encryption_requires_a_password_and_stores_nothing(): void
    {
        config(['capsule.security.backup_password' => '', 'capsule.security.encrypt_backups' => true]);
        $service = app(BackupService::class);
        $this->assertFalse($service->run());
        $this->assertStringContainsString('password', $service->getLastError());
        $this->assertSame([], Storage::disk(config('capsule.default_disk'))->allFiles());
    }

    public function test_encrypted_archive_really_requires_password(): void
    {
        if (!ZipArchive::isEncryptionMethodSupported(ZipArchive::EM_AES_256, true)) {
            $this->markTestSkipped('libzip AES encryption not available.');
        }
        config(['capsule.security.backup_password' => 'test-only-password', 'capsule.security.encrypt_backups' => true]);
        $service = app(BackupService::class);
        $this->assertTrue($service->run(), $service->getLastError() ?? '');
        $log = BackupLog::successful()->firstOrFail();
        $zip = new ZipArchive();
        $this->assertTrue($zip->open(Storage::disk(config('capsule.default_disk'))->path($log->file_path)));
        $this->assertFalse(@$zip->getFromName('files/root-0/hello.txt'));
        $zip->setPassword('test-only-password');
        $this->assertSame('hello', $zip->getFromName('files/root-0/hello.txt'));
        $zip->close();
    }

    public function test_roots_preserve_identical_relative_names_and_private_uploads(): void
    {
        mkdir($this->fixture . '/other');
        mkdir($this->fixture . '/source/private');
        file_put_contents($this->fixture . '/other/hello.txt', 'other');
        file_put_contents($this->fixture . '/source/private/upload.txt', 'private');
        config(['capsule.files.paths' => [$this->fixture . '/source', $this->fixture . '/other']]);
        $files = FileSelection::files();
        $this->assertCount(3, $files);
        $this->assertArrayHasKey('files/root-0/hello.txt', $files);
        $this->assertArrayHasKey('files/root-1/hello.txt', $files);
        $this->assertArrayHasKey('files/root-0/private/upload.txt', $files);
    }

    public function test_incremental_manifest_binds_base_and_tracks_deleted_and_same_size_changes(): void
    {
        file_put_contents($this->fixture . '/source/deleted.txt', 'delete');
        $service = app(BackupService::class);
        $this->assertTrue($service->run(), $service->getLastError() ?? '');
        $base = BackupLog::successful()->firstOrFail();
        $mtime = filemtime($this->fixture . '/source/hello.txt');
        file_put_contents($this->fixture . '/source/hello.txt', 'world');
        touch($this->fixture . '/source/hello.txt', $mtime);
        unlink($this->fixture . '/source/deleted.txt');
        clearstatcache();
        $service->setIncremental(true);
        $this->assertTrue($service->run(), $service->getLastError() ?? '');
        $log = BackupLog::successful()->orderByDesc('id')->firstOrFail();
        $zip = new ZipArchive();
        $this->assertTrue($zip->open(Storage::disk(config('capsule.default_disk'))->path($log->file_path)));
        $manifest = json_decode($zip->getFromName('manifest.json'), true);
        $this->assertSame('incremental', $manifest['backup_type']);
        $this->assertSame($base->file_path, $manifest['base_archive']['path']);
        $this->assertSame($base->metadata['archive_sha256'], $manifest['base_archive']['sha256']);
        $this->assertSame(['files/root-0/deleted.txt'], $manifest['deleted_files']);
        $this->assertSame('world', $zip->getFromName('files/root-0/hello.txt'));
        $zip->close();
    }
    public function test_failure_listener_cannot_mask_original_storage_error(): void
    {
        $storage = \Mockery::mock(\Dgtlss\Capsule\Storage\StorageManager::class);
        $storage->shouldReceive('store')->once()->andThrow(new \RuntimeException('original storage failure'));
        $this->app->instance(\Dgtlss\Capsule\Storage\StorageManager::class, $storage);
        \Illuminate\Support\Facades\Event::listen(\Dgtlss\Capsule\Events\BackupFailed::class, function () {
            throw new \RuntimeException('observer failure');
        });
        $service = app(BackupService::class);
        $this->assertFalse($service->run());
        $this->assertSame('original storage failure', $service->getLastError());
    }

}
