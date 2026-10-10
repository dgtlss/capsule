<?php

namespace Dgtlss\Capsule\Tests\Unit;

use Dgtlss\Capsule\Storage\StorageManager;
use Dgtlss\Capsule\Tests\TestCase;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Mockery;
use ZipArchive;

class StreamingStorageTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['capsule.storage.retries' => 1, 'capsule.storage.backoff_ms' => 0]);
    }

    protected function manager(Filesystem $disk): StorageManager
    {
        return new class($disk) extends StorageManager {
            public function __construct(Filesystem $disk)
            {
                $this->disk = $disk;
                $this->backupPath = 'backups';
            }
        };
    }

    public function test_false_write_is_retried_from_start_and_verified_by_readback(): void
    {
        $payload = 'complete backup contents';
        $attempts = [];
        $disk = Mockery::mock(Filesystem::class);
        $disk->shouldReceive('put')->twice()->andReturnUsing(function ($path, $stream) use (&$attempts) {
            $attempts[] = stream_get_contents($stream);
            return count($attempts) > 1;
        });
        $disk->shouldReceive('exists')->once()->with('backups/test.zip')->andReturn(true);
        $disk->shouldReceive('size')->once()->with('backups/test.zip')->andReturn(strlen($payload));
        $disk->shouldReceive('readStream')->once()->andReturnUsing(fn () => $this->stream($payload));
        $stream = $this->stream($payload);
        try {
            $this->assertSame('backups/test.zip', $this->manager($disk)->storeStream($stream, 'test.zip'));
            $this->assertSame([$payload, $payload], $attempts);
        } finally {
            fclose($stream);
        }
    }

    public function test_same_length_corruption_fails_and_does_not_delete_local_source(): void
    {
        $disk = Mockery::mock(Filesystem::class);
        $disk->shouldReceive('put')->twice()->andReturn(true);
        $disk->shouldReceive('exists')->twice()->andReturn(true);
        $disk->shouldReceive('size')->twice()->andReturn(4);
        $disk->shouldReceive('readStream')->twice()->andReturnUsing(fn () => $this->stream('FAIL'));
        $file = tempnam(sys_get_temp_dir(), 'capsule-source-');
        file_put_contents($file, 'GOOD');
        try {
            $this->manager($disk)->store($file);
            $this->fail('Corrupt uploads must fail.');
        } catch (\Exception $e) {
            $this->assertStringContainsString('checksum', $e->getMessage());
            $this->assertSame('GOOD', file_get_contents($file));
        } finally {
            unlink($file);
        }
    }

    public function test_missing_remote_object_cannot_pass_verification(): void
    {
        $disk = Mockery::mock(Filesystem::class);
        $disk->shouldReceive('put')->twice()->andReturn(true);
        $disk->shouldReceive('exists')->twice()->andReturn(false);
        $stream = $this->stream('backup');
        try {
            $this->expectExceptionMessage('Stored backup size does not match');
            $this->manager($disk)->storeStream($stream, 'backup.zip');
        } finally {
            fclose($stream);
        }
    }

    public function test_legacy_fragments_across_chunk_boundaries_form_one_complete_zip_entry(): void
    {
        $payload = '';
        $wire = '';
        for ($i = 0; $i < 18; $i++) {
            $fragment = str_repeat(chr(65 + $i), 29);
            $payload .= $fragment;
            $wire .= pack('N', 9) . 'large.txt' . pack('N', strlen($fragment)) . $fragment;
        }
        $wire .= pack('N', 9) . 'empty.txt' . pack('N', 0);
        $chunks = $this->writeChunks($wire, 13, 'files_legacy');
        $path = app(StorageManager::class)->collateChunksAdvanced($chunks, 'legacy.zip');
        $zip = $this->openZip($path);
        try {
            $this->assertSame(2, $zip->numFiles);
            $this->assertSame($payload, $zip->getFromName('files/large.txt'));
            $this->assertSame('', $zip->getFromName('files/empty.txt'));
        } finally {
            $zip->close();
        }
    }

    public function test_v2_framing_handles_split_header_empty_files_and_large_files(): void
    {
        $payload = random_bytes(13017);
        // Path lengths are deliberately computed from bytes, including UTF-8 paths.
        $name = 'root-0/é.txt';
        $wire = pack('N', strlen('root-0/file.bin')) . 'root-0/file.bin' . pack('P', strlen($payload)) . $payload
            . pack('N', strlen('root-0/empty.txt')) . 'root-0/empty.txt' . pack('P', 0)
            . pack('N', strlen($name)) . $name . pack('P', 3) . 'abc';
        $chunks = $this->writeChunks($wire, 127, 'files_v2', 'framed-v2');
        $path = app(StorageManager::class)->collateChunksAdvanced($chunks, 'v2.zip', 6);
        $zip = $this->openZip($path);
        try {
            $this->assertSame(3, $zip->numFiles);
            $this->assertSame($payload, $zip->getFromName('files/root-0/file.bin'));
            $this->assertSame('', $zip->getFromName('files/root-0/empty.txt'));
            $this->assertSame('abc', $zip->getFromName('files/' . $name));
        } finally {
            $zip->close();
        }
    }

    public function test_truncated_frame_fails_without_deleting_chunks(): void
    {
        $wire = pack('N', 5) . 'a.txt' . pack('P', 100) . 'short';
        $chunks = $this->writeChunks($wire, 7, 'files_broken', 'framed-v2');
        try {
            app(StorageManager::class)->collateChunksAdvanced($chunks, 'broken.zip');
            $this->fail('Truncated framing must fail.');
        } catch (\Exception $e) {
            $this->assertStringContainsString('Truncated', $e->getMessage());
            foreach ($chunks as $chunk) {
                $this->assertTrue(Storage::disk('capsule-test')->exists('backups/' . $chunk['name']));
            }
            $this->assertFalse(Storage::disk('capsule-test')->exists('backups/broken.zip'));
        }
    }

    public function test_chunk_checksum_fails_closed(): void
    {
        $chunks = $this->writeChunks('database payload', 7, 'db_test');
        $chunks[0]['sha256'] = str_repeat('0', 64);
        $this->expectExceptionMessage('integrity check failed');
        app(StorageManager::class)->collateChunksAdvanced($chunks, 'bad.zip');
    }

    public function test_missing_chunk_index_fails_closed(): void
    {
        $chunks = $this->writeChunks('database payload', 7, 'db_test');
        unset($chunks[1]);
        $this->expectExceptionMessage('Missing or duplicate backup chunk index');
        app(StorageManager::class)->collateChunksAdvanced(array_values($chunks), 'missing.zip');
    }

    public function test_large_database_collation_keeps_payload_memory_bounded(): void
    {
        // This payload is much larger than the permitted memory increase. It is deliberately
        // incompressible over deflate's 32 KiB window, so both SQL and ZIP paths are stressed.
        $block = random_bytes(1048576);
        $chunks = [];
        $expected = hash_init('sha256');
        for ($index = 0; $index < 96; $index++) {
            $name = 'large.part' . $index;
            Storage::disk('capsule-test')->put('backups/' . $name, $block);
            hash_update($expected, $block);
            $chunks[] = ['name' => $name, 'index' => $index, 'base_name' => 'db_large',
                'size' => strlen($block), 'sha256' => hash('sha256', $block), 'entry_name' => 'database/large.sql'];
        }
        unset($block);
        if (function_exists('memory_reset_peak_usage')) {
            memory_reset_peak_usage();
        }
        $baseline = memory_get_usage(true);
        $path = app(StorageManager::class)->collateChunksAdvanced($chunks, 'large.zip');
        $this->assertLessThan(24 * 1048576, memory_get_peak_usage(true) - $baseline);
        $zip = $this->openZip($path);
        try {
            $stream = $zip->getStream('database/large.sql');
            $this->assertIsResource($stream);
            $actual = hash_init('sha256');
            $this->assertSame(96 * 1048576, hash_update_stream($actual, $stream));
            fclose($stream);
            $this->assertSame(hash_final($expected), hash_final($actual));
        } finally {
            $zip->close();
        }
    }

    public function test_encrypted_streaming_rejects_before_any_storage_access(): void
    {
        $disk = Mockery::mock(Filesystem::class);
        $this->expectExceptionMessage('Encrypted streaming backups are not supported');
        $this->manager($disk)->collateChunksAdvanced([], 'encrypted.zip', 1, true, 'secret');
    }

    public function test_s3_adapter_reads_are_streamed_without_changing_user_configuration(): void
    {
        config(['capsule.default_disk' => 'remote', 'filesystems.disks.remote' => [
            'driver' => 's3', 'bucket' => 'backups', 'region' => 'us-east-1', 'stream_reads' => false,
        ]]);
        $disk = Mockery::mock(Filesystem::class);
        Storage::shouldReceive('build')->once()->with(Mockery::on(fn ($config) =>
            $config['stream_reads'] === true && $config['bucket'] === 'backups'))->andReturn($disk);
        $this->assertSame($disk, (new StorageManager())->getDisk());
        $this->assertFalse(config('filesystems.disks.remote.stream_reads'));
    }

    public function test_unrestorable_entry_names_are_rejected_before_upload(): void
    {
        $wire = pack('N', strlen('root/a:invalid.txt')) . 'root/a:invalid.txt' . pack('P', 1) . 'x';
        $chunks = $this->writeChunks($wire, 127, 'files_unsafe', 'framed-v2');
        $this->expectExceptionMessage('Unsafe archive path');
        app(StorageManager::class)->collateChunksAdvanced($chunks, 'unsafe.zip');
    }

    public function test_backup_prefix_uses_directory_boundary(): void
    {
        $manager = app(StorageManager::class);
        $stream = $this->stream('test');
        try {
            $this->assertSame('backups/backups-old.zip', $manager->storeStream($stream, 'backups-old.zip'));
            $this->assertTrue($manager->exists('backups-old.zip'));
        } finally {
            fclose($stream);
        }
    }

    public function test_every_header_and_payload_truncation_fails_closed(): void
    {
        $wire = pack('N', 5) . 'a.txt' . pack('P', 3) . 'abc';
        for ($cut = 1; $cut < strlen($wire); $cut++) {
            $chunks = $this->writeChunks(substr($wire, 0, $cut), 3, 'files_truncated', 'framed-v2');
            try {
                app(StorageManager::class)->collateChunksAdvanced($chunks, 'truncated.zip');
                $this->fail('Accepted truncated byte ' . $cut);
            } catch (\Exception $e) {
                $this->assertStringContainsString('Truncated', $e->getMessage());
            }
            $this->assertFalse(Storage::disk('capsule-test')->exists('backups/truncated.zip'));
        }
    }

    public function test_exact_and_case_conflicting_entries_fail_closed(): void
    {
        foreach (['a.txt', 'A.txt'] as $second) {
            $wire = pack('N', 5) . 'a.txt' . pack('P', 1) . 'A'
                . pack('N', 5) . $second . pack('P', 1) . 'B';
            try {
                app(StorageManager::class)->collateChunksAdvanced($this->writeChunks($wire, 3, 'files_duplicate', 'framed-v2'), 'duplicate.zip');
                $this->fail('Accepted conflicting path.');
            } catch (\Exception $e) {
                $this->assertStringContainsString('duplicate', $e->getMessage());
            }
            $this->assertFalse(Storage::disk('capsule-test')->exists('backups/duplicate.zip'));
        }
    }

    public function test_nonseekable_input_is_rejected_without_closing_the_callers_resource(): void
    {
        $stream = popen('printf abc', 'r');
        try {
            try {
                app(StorageManager::class)->storeStream($stream, 'nonseekable.zip');
                $this->fail('Accepted nonseekable stream.');
            } catch (\Exception $e) {
                $this->assertStringContainsString('seekable', $e->getMessage());
            }
            $this->assertIsResource($stream);
            $this->assertSame('abc', stream_get_contents($stream));
        } finally {
            pclose($stream);
        }
        $this->assertFalse(Storage::disk('capsule-test')->exists('backups/nonseekable.zip'));
    }

    protected function stream(string $data)
    {
        $stream = fopen('php://memory', 'w+b');
        fwrite($stream, $data);
        rewind($stream);
        return $stream;
    }

    protected function writeChunks(string $wire, int $size, string $base, ?string $format = null): array
    {
        $chunks = [];
        foreach (str_split($wire, $size) as $index => $bytes) {
            $name = $base . '.part' . $index;
            Storage::disk('capsule-test')->put('backups/' . $name, $bytes);
            $chunks[] = ['name' => $name, 'index' => $index, 'base_name' => $base,
                'size' => strlen($bytes), 'sha256' => hash('sha256', $bytes), 'format' => $format];
        }
        return $chunks;
    }

    protected function openZip(string $path): ZipArchive
    {
        $zip = new ZipArchive();
        $this->assertTrue($zip->open(Storage::disk('capsule-test')->path($path), ZipArchive::CHECKCONS));
        return $zip;
    }
}
