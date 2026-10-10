<?php

namespace Dgtlss\Capsule\Tests\Feature;

use Dgtlss\Capsule\Models\BackupLog;
use Dgtlss\Capsule\Models\BackupSnapshot;
use Dgtlss\Capsule\Services\RetentionService;
use Dgtlss\Capsule\Tests\TestCase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Mockery;

class RetentionSafetyTest extends TestCase
{
    private function backup(string $name, int $age, array $metadata = [], int $size = 1024, string $status = 'success'): BackupLog
    {
        $backup = BackupLog::create([
            'started_at' => now()->subDays($age), 'completed_at' => now()->subDays($age),
            'status' => $status, 'file_path' => 'backups/' . $name, 'file_size' => $size,
            'metadata' => $metadata,
        ]);
        $backup->forceFill(['created_at' => now()->subDays($age)])->save();
        return $backup;
    }

    private function rules(array $overrides = []): array
    {
        return array_replace(['days' => 30, 'count' => 0, 'min_keep' => 0, 'max_storage_mb' => null], $overrides);
    }

    private function metadata(string $policy, string $disk, array $rules = []): array
    {
        return ['policy' => $policy, 'storage' => ['disk' => $disk, 'backup_path' => 'backups'], 'retention' => $this->rules($rules)];
    }

    public function test_scoped_cleanup_uses_saved_disk_and_preserves_other_policies(): void
    {
        Storage::fake('first');
        Storage::fake('second');
        Storage::disk('first')->put('backups/same.zip', 'first');
        Storage::disk('second')->put('backups/same.zip', 'second');
        $first = $this->backup('same.zip', 60, $this->metadata('first', 'first'));
        $second = $this->backup('same.zip', 60, $this->metadata('second', 'second'));

        $result = app(RetentionService::class)->cleanup(['scope' => ['policy' => 'first', 'disk' => 'first', 'backup_path' => 'backups']]);

        $this->assertSame(1, $result['count']);
        $this->assertSame([], $result['errors']);
        $this->assertNull($first->fresh());
        $this->assertNotNull($second->fresh());
        Storage::disk('first')->assertMissing('backups/same.zip');
        Storage::disk('second')->assertExists('backups/same.zip');
    }

    public function test_explicit_cleanup_obeys_each_policys_saved_retention(): void
    {
        Storage::fake('first');
        Storage::fake('second');
        $first = $this->backup('first.zip', 60, $this->metadata('short', 'first'));
        $second = $this->backup('second.zip', 60, $this->metadata('long', 'second', ['days' => 365]));
        $result = app(RetentionService::class)->cleanup();
        $this->assertSame(1, $result['count']);
        $this->assertNull($first->fresh());
        $this->assertNotNull($second->fresh());
    }

    public function test_failed_storage_delete_preserves_record_and_does_not_count_it(): void
    {
        $backup = $this->backup('old.zip', 60, $this->metadata('safe', 'broken'));
        $disk = Mockery::mock();
        $disk->shouldReceive('delete')->once()->with('backups/old.zip')->andReturnFalse();
        Storage::shouldReceive('disk')->with('broken')->andReturn($disk);
        $result = app(RetentionService::class)->cleanup();
        $this->assertSame(0, $result['count']);
        $this->assertCount(1, $result['errors']);
        $this->assertNotNull($backup->fresh());
    }

    public function test_dry_run_plans_days_and_budget_once_and_matches_real_deletion(): void
    {
        Storage::fake('archive');
        $metadata = $this->metadata('budget', 'archive', ['min_keep' => 1, 'max_storage_mb' => 1]);
        $old = $this->backup('old.zip', 60, $metadata, 1024 * 1024);
        $middle = $this->backup('middle.zip', 10, $metadata, 1024 * 1024);
        $new = $this->backup('new.zip', 1, $metadata, 1024 * 1024);
        $retention = app(RetentionService::class);
        $dry = $retention->cleanup(['dry_run' => true]);
        $this->assertSame(2, $dry['count']);
        $this->assertSame(2 * 1024 * 1024, $dry['size']);
        $this->assertDatabaseCount('backup_logs', 3);
        $real = $retention->cleanup();
        $this->assertSame($dry, $real);
        $this->assertNull($old->fresh());
        $this->assertNull($middle->fresh());
        $this->assertNotNull($new->fresh());
    }

    public function test_retained_increment_preserves_base_and_budget_can_prune_other_archives(): void
    {
        Storage::fake('archive');
        $metadata = $this->metadata('budget', 'archive', ['count' => 1, 'min_keep' => 1, 'max_storage_mb' => 2]);
        $base = $this->backup('base.zip', 90, $metadata, 1024 * 1024);
        $other = $this->backup('other.zip', 10, $metadata, 1024 * 1024);
        $increment = $this->backup('increment.zip', 1, $metadata, 1024 * 1024);
        $snapshot = BackupSnapshot::create(['backup_log_id' => $base->id, 'type' => 'full', 'file_index' => '{}']);
        BackupSnapshot::create(['backup_log_id' => $increment->id, 'type' => 'incremental', 'base_snapshot_id' => $snapshot->id, 'file_index' => '{}']);
        $result = app(RetentionService::class)->cleanup();
        $this->assertSame(1, $result['count']);
        $this->assertNotNull($base->fresh());
        $this->assertNotNull($increment->fresh());
        $this->assertNull($other->fresh());
    }

    public function test_failed_child_deletion_preserves_its_base(): void
    {
        $metadata = $this->metadata('safe', 'broken');
        $base = $this->backup('base.zip', 90, $metadata);
        $child = $this->backup('child.zip', 60, $metadata);
        $snapshot = BackupSnapshot::create(['backup_log_id' => $base->id, 'type' => 'full', 'file_index' => '{}']);
        BackupSnapshot::create(['backup_log_id' => $child->id, 'type' => 'incremental', 'base_snapshot_id' => $snapshot->id, 'file_index' => '{}']);
        $disk = Mockery::mock();
        $disk->shouldReceive('delete')->once()->with('backups/child.zip')->andThrow(new \RuntimeException('Unavailable'));
        Storage::shouldReceive('disk')->with('broken')->andReturn($disk);
        $result = app(RetentionService::class)->cleanup();
        $this->assertSame(0, $result['count']);
        $this->assertNotNull($base->fresh());
        $this->assertNotNull($child->fresh());
    }

    public function test_orphan_cleanup_preserves_portable_base_without_a_log(): void
    {
        Storage::fake('archive');
        Storage::disk('archive')->put('backups/base.zip', 'base');
        Storage::disk('archive')->put('backups/orphan.zip', 'orphan');
        $metadata = $this->metadata('safe', 'archive');
        $metadata['snapshot']['base_archive'] = ['disk' => 'archive', 'path' => 'backups/base.zip', 'sha256' => 'checksum'];
        $this->backup('child.zip', 1, $metadata);
        $result = app(RetentionService::class)->cleanup(['storage' => true]);
        $this->assertSame(1, $result['count']);
        Storage::disk('archive')->assertExists('backups/base.zip');
        Storage::disk('archive')->assertMissing('backups/orphan.zip');
    }

    public function test_cleanup_emits_one_valid_json_document_for_detailed_dry_run(): void
    {
        $this->backup('old.zip', 60, $this->metadata('safe', 'capsule-test'));
        $code = Artisan::call('capsule:cleanup', ['--format' => 'json', '--dry-run' => true, '--detailed' => true]);
        $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(0, $code);
        $this->assertSame(1, $payload['deleted_count']);
        $this->assertSame(1024, $payload['deleted_size_bytes']);
    }
}
