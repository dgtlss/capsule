<?php

namespace Dgtlss\Capsule\Tests\Feature;

use Dgtlss\Capsule\Models\BackupLog;
use Dgtlss\Capsule\Models\VerificationLog;
use Dgtlss\Capsule\Services\IntegrityMonitor;
use Dgtlss\Capsule\Tests\TestCase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use ZipArchive;

class VerifyCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('original');
        Storage::fake('new-default');
        config()->set('capsule.default_disk', 'new-default');
    }

    public function test_verify_uses_recorded_disk_and_emits_only_json_even_when_detailed(): void
    {
        $backup = $this->createArchive();
        Storage::disk('new-default')->put($backup->file_path, 'wrong archive');

        $code = Artisan::call('capsule:verify', ['--id' => $backup->id, '--format' => 'json', '--detailed' => true]);
        $result = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(0, $code);
        $this->assertSame('passed', $result[0]['status']);
        $this->assertSame('original', $result[0]['disk']);
        $this->assertSame(2, $result[0]['entries_checked']);
    }

    public function test_missing_backup_is_a_json_failure(): void
    {
        $code = Artisan::call('capsule:verify', ['--id' => 98765, '--format' => 'json']);
        $result = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(1, $code);
        $this->assertSame('failed', $result[0]['status']);
        $this->assertStringContainsString('No successful backups', $result[0]['error']);
    }

    public function test_all_mode_keeps_per_backup_failures_in_one_json_document(): void
    {
        $good = $this->createArchive();
        $bad = $this->createArchive();
        Storage::disk('original')->delete($bad->file_path);

        $code = Artisan::call('capsule:verify', ['--all' => true, '--format' => 'json']);
        $results = collect(json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR))->keyBy('id');
        $this->assertSame(1, $code);
        $this->assertSame('passed', $results[$good->id]['status']);
        $this->assertSame('failed', $results[$bad->id]['status']);
        $this->assertStringContainsString("disk 'original'", $results[$bad->id]['error']);
    }

    public function test_keep_exposes_download_path_in_json(): void
    {
        $backup = $this->createArchive();
        $code = Artisan::call('capsule:verify', ['--id' => $backup->id, '--format' => 'json', '--keep' => true]);
        $result = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR)[0];
        try {
            $this->assertSame(0, $code);
            $this->assertFileExists($result['local_path']);
            $this->assertSame($backup->metadata['archive_sha256'], hash_file('sha256', $result['local_path']));
        } finally {
            if (isset($result['local_path'])) {
                @unlink($result['local_path']);
            }
        }
    }

    public function test_scheduled_monitor_uses_recorded_disk(): void
    {
        $backup = $this->createArchive();
        Storage::disk('new-default')->put($backup->file_path, 'wrong archive');
        $result = app(IntegrityMonitor::class)->verify($backup, 'scheduled');
        $this->assertSame('passed', $result->status);
        $this->assertSame(2, $result->entries_checked);
    }

    public function test_legacy_metadata_disk_is_used_when_storage_metadata_is_absent(): void
    {
        $backup = $this->createArchive();
        $backup->metadata = ['disk' => 'original'];
        $backup->save();
        $result = app(IntegrityMonitor::class)->inspect($backup);
        $this->assertSame('original', $result['disk']);
        $this->assertSame(0, $result['failures']);
    }

    public function test_archive_checksum_mismatch_is_json_failure(): void
    {
        $backup = $this->createArchive();
        $backup->metadata = array_merge($backup->metadata, ['archive_sha256' => str_repeat('0', 64)]);
        $backup->save();
        $code = Artisan::call('capsule:verify', ['--id' => $backup->id, '--format' => 'json']);
        $result = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR)[0];
        $this->assertSame(1, $code);
        $this->assertStringContainsString('recorded checksum', $result['error']);
    }

    public function test_zero_size_manifest_entry_is_not_allowed_to_skip_size_validation(): void
    {
        $backup = $this->createArchive(true);
        $code = Artisan::call('capsule:verify', ['--id' => $backup->id, '--format' => 'json']);
        $result = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR)[0];
        $this->assertSame(1, $code);
        $this->assertSame('failed', $result['status']);
        $this->assertStringContainsString('wrong size', $result['error']);
    }

    public function test_scheduled_exception_is_a_json_error(): void
    {
        $this->app->instance(IntegrityMonitor::class, new class extends IntegrityMonitor {
            public function runScheduledVerification(): ?VerificationLog
            {
                throw new \RuntimeException('fixture storage failure');
            }
        });
        $code = Artisan::call('capsule:verify-scheduled', ['--format' => 'json']);
        $result = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(1, $code);
        $this->assertSame('error', $result['status']);
        $this->assertSame('fixture storage failure', $result['error']);
    }

    public function test_scheduled_empty_result_is_json(): void
    {
        $code = Artisan::call('capsule:verify-scheduled', ['--format' => 'json']);
        $result = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(0, $code);
        $this->assertSame('skipped', $result['status']);
    }

    private function createArchive(bool $wrongZeroSize = false): BackupLog
    {
        $local = tempnam(sys_get_temp_dir(), 'capsule-verify-fixture-');
        try {
            $zip = new ZipArchive();
            $this->assertTrue($zip->open($local, ZipArchive::OVERWRITE));
            $entries = [];
            foreach (['files/example.txt' => 'persisted value', 'files/empty.txt' => ''] as $path => $content) {
                $zip->addFromString($path, $content);
                $entries[] = [
                    'path' => $path,
                    'size' => $wrongZeroSize ? 0 : strlen($content),
                    'sha256' => hash('sha256', $content),
                ];
            }
            $zip->addFromString('manifest.json', json_encode(['schema_version' => 1, 'entries' => $entries]));
            $this->assertTrue($zip->close());
            $path = 'old-prefix/' . bin2hex(random_bytes(8)) . '.zip';
            Storage::disk('original')->put($path, file_get_contents($local));
            return BackupLog::create([
                'status' => 'success', 'started_at' => now(), 'completed_at' => now(),
                'file_path' => $path, 'file_size' => filesize($local),
                'metadata' => ['storage' => ['disk' => 'original', 'backup_path' => 'old-prefix'], 'archive_sha256' => hash_file('sha256', $local)],
            ]);
        } finally {
            unlink($local);
        }
    }
}
