<?php

namespace Dgtlss\Capsule\Tests\Feature;

use Dgtlss\Capsule\Models\BackupLog;
use Dgtlss\Capsule\Services\RetentionService;
use Dgtlss\Capsule\Tests\TestCase;
use Illuminate\Support\Facades\Storage;

class RetentionReplicaTest extends TestCase
{
    protected function backup(): BackupLog
    {
        Storage::fake('replica');
        foreach (['capsule-test', 'replica'] as $disk) {
            Storage::disk($disk)->put('backups/old.zip', 'archive');
        }
        $log = BackupLog::create([
            'started_at' => now()->subDays(20), 'completed_at' => now()->subDays(20),
            'status' => 'success', 'file_path' => 'backups/old.zip', 'file_size' => 7,
            'metadata' => [
                'storage' => ['disk' => 'capsule-test', 'backup_path' => 'backups', 'additional_disks' => ['replica']],
                'retention' => ['days' => 1, 'count' => 0, 'min_keep' => 0],
            ],
        ]);
        $log->created_at = now()->subDays(20);
        $log->save();
        return $log;
    }

    public function test_retention_deletes_recorded_replicas_even_after_configuration_changes(): void
    {
        $log = $this->backup();
        config(['capsule.additional_disks' => []]);
        $result = app(RetentionService::class)->cleanup();
        $this->assertSame([], $result['errors']);
        $this->assertSame(1, $result['count']);
        $this->assertNull($log->fresh());
        Storage::disk('capsule-test')->assertMissing('backups/old.zip');
        Storage::disk('replica')->assertMissing('backups/old.zip');
    }

    public function test_orphan_cleanup_preserves_a_retained_replica(): void
    {
        $log = $this->backup();
        $log->created_at = now();
        $log->save();
        $result = app(RetentionService::class)->cleanup(['storage' => true]);
        $this->assertSame([], $result['errors']);
        $this->assertSame(0, $result['count']);
        Storage::disk('replica')->assertExists('backups/old.zip');
    }

    public function test_replica_used_by_another_backup_is_preserved(): void
    {
        $old = $this->backup();
        $other = BackupLog::create([
            'started_at' => now(), 'status' => 'success', 'file_path' => 'backups/old.zip',
            'metadata' => ['storage' => ['disk' => 'replica', 'backup_path' => 'backups'], 'retention' => ['count' => 10]],
        ]);
        app(RetentionService::class)->cleanup();
        $this->assertNull($old->fresh());
        $this->assertNotNull($other->fresh());
        Storage::disk('replica')->assertExists('backups/old.zip');
    }
}
