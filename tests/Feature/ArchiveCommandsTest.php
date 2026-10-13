<?php

namespace Dgtlss\Capsule\Tests\Feature;

use Dgtlss\Capsule\Models\BackupLog;
use Dgtlss\Capsule\Tests\TestCase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use ZipArchive;

class ArchiveCommandsTest extends TestCase
{
    public function test_inspection_uses_saved_disk_and_outputs_one_json_object(): void
    {
        $backup = $this->archive();
        config(['capsule.default_disk' => 'missing-global-disk']);
        $this->assertSame(0, Artisan::call('capsule:inspect', ['id' => $backup->id, '--format' => 'json']));
        $result = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('capsule-test', $result['disk']);
        $this->assertSame(1, $result['manifest']['schema_version']);
    }

    public function test_inspection_failure_is_json_and_nonzero(): void
    {
        $this->assertSame(1, Artisan::call('capsule:inspect', ['id' => 999, '--format' => 'json']));
        $result = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('failed', $result['status']);
    }

    public function test_downloading_to_original_local_destination_does_not_truncate_archive(): void
    {
        $backup = $this->archive();
        $disk = Storage::disk('capsule-test');
        $before = hash_file('sha256', $disk->path($backup->file_path));
        config(['capsule.default_disk' => 'missing-global-disk']);
        $this->assertSame(0, Artisan::call('capsule:download', [
            'id' => $backup->id, '--path' => dirname($disk->path($backup->file_path)),
        ]));
        $this->assertSame($before, hash_file('sha256', $disk->path($backup->file_path)));
    }

    private function archive(): BackupLog
    {
        $disk = Storage::disk('capsule-test');
        $disk->makeDirectory('backups');
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($disk->path('backups/test.zip'), ZipArchive::CREATE));
        $zip->addFromString('manifest.json', json_encode(['schema_version' => 1, 'entries' => []]));
        $zip->close();
        return BackupLog::create([
            'status' => 'success', 'started_at' => now(), 'completed_at' => now(),
            'file_path' => 'backups/test.zip', 'file_size' => $disk->size('backups/test.zip'),
            'metadata' => ['storage' => ['disk' => 'capsule-test', 'backup_path' => 'backups']],
        ]);
    }
}
