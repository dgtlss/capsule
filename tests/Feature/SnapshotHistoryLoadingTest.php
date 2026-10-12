<?php

namespace Dgtlss\Capsule\Tests\Feature;

use Dgtlss\Capsule\Models\BackupLog;
use Dgtlss\Capsule\Models\BackupSnapshot;
use Dgtlss\Capsule\Services\IncrementalService;
use Dgtlss\Capsule\Services\RetentionService;
use Dgtlss\Capsule\Tests\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Mockery;

class SnapshotHistoryLoadingTest extends TestCase
{
    public function test_retention_reads_only_dependency_columns_with_large_historical_indexes(): void
    {
        $index = $this->largeIndex();
        $base = $this->snapshot('base.zip', $index, [], 60);
        $this->snapshot('retained.zip', $index, [], 1, 'incremental', $base->id);
        for ($i = 0; $i < 120; $i++) {
            $this->snapshot("old-{$i}.zip", $index, [], 60);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        memory_reset_peak_usage();
        $before = memory_get_usage();
        $result = app(RetentionService::class)->cleanup(['dry_run' => true]);
        $additionalMemory = memory_get_peak_usage() - $before;
        $queries = $this->snapshotQueries();
        DB::disableQueryLog();

        $this->assertSame([], $result['errors']);
        $this->assertSame(120, $result['count'], 'The retained increment must protect its full base.');
        $this->assertCount(1, $queries);
        $this->assertSame('id, backup_log_id, base_snapshot_id', $queries[0]['projection']);
        $this->assertLessThan(16 * 1024 * 1024, $additionalMemory, 'Retention must not hydrate historical file indexes.');
        $this->assertDatabaseCount('backup_snapshots', 122);
    }

    public function test_incremental_selection_pages_metadata_and_loads_only_the_eligible_encrypted_base_index(): void
    {
        config(['capsule._policy_name' => 'daily']);
        $index = $this->largeIndex();
        $selected = $this->snapshot('selected.capsule.enc', $index);
        $ineligible = [
            ['policy' => 'other'],
            ['storage' => ['disk' => 'unconfigured', 'backup_path' => 'backups']],
            ['storage' => ['disk' => 'capsule-test', 'backup_path' => 'elsewhere']],
            ['schema_version' => 1],
            ['archive_sha256' => null],
            [], // Otherwise compatible, but its storage object is missing.
        ];
        for ($i = 0; $i < 120; $i++) {
            $this->snapshot("history-{$i}.zip", $index, $ineligible[$i % count($ineligible)]);
        }
        $this->snapshot('failed.zip', $index, [], 1, 'full', null, 'failed');
        $this->snapshot('incremental.zip', $index, [], 1, 'incremental', $selected->id);
        $checked = [];
        $disk = Mockery::mock();
        $disk->shouldReceive('exists')->andReturnUsing(function (string $path) use (&$checked): bool {
            $checked[] = $path;
            $this->assertSame([], array_filter($this->snapshotQueries(), fn (array $query) => $query['projection'] === '*'),
                'No file index may be loaded before storage eligibility is checked.');
            return $path === 'backups/selected.capsule.enc';
        });
        Storage::shouldReceive('disk')->with('capsule-test')->andReturn($disk);

        DB::flushQueryLog();
        DB::enableQueryLog();
        memory_reset_peak_usage();
        $before = memory_get_usage();
        $actual = app(IncrementalService::class)->getLatestFullSnapshot();
        $additionalMemory = memory_get_peak_usage() - $before;
        $queries = $this->snapshotQueries();
        DB::disableQueryLog();

        $this->assertNotNull($actual);
        $this->assertSame($selected->id, $actual->id);
        $this->assertSame($index, $actual->file_index);
        $this->assertSame(json_decode($index, true), $actual->getFileIndexArray());
        $this->assertTrue($actual->relationLoaded('backupLog'));
        $this->assertSame($selected->backup_log_id, $actual->backupLog->id);
        $this->assertSame('full', $actual->type);
        $this->assertFalse($actual->isDirty());
        $this->assertCount(21, $checked);
        $this->assertSame('backups/selected.capsule.enc', end($checked));
        $this->assertCount(3, $queries);
        foreach (array_slice($queries, 0, 2) as $query) {
            $this->assertSame('id, backup_log_id', $query['projection']);
            $this->assertStringContainsString('order by id desc limit 100', $query['sql']);
        }
        $this->assertStringContainsString('id < ?', $queries[1]['sql']);
        $this->assertSame('*', $queries[2]['projection']);
        $this->assertStringContainsString('where backup_snapshots.id = ? limit 1', $queries[2]['sql']);
        $this->assertSame([$selected->id], $queries[2]['bindings']);
        $this->assertLessThan(16 * 1024 * 1024, $additionalMemory, 'Only the selected base index should consume memory.');
    }

    public function test_no_eligible_base_never_reads_an_index(): void
    {
        $this->snapshot('legacy.zip', $this->largeIndex(), ['schema_version' => 1]);
        $this->snapshot('missing.zip', '{}');
        $this->snapshot('failed.zip', '{}', [], 1, 'full', null, 'failed');
        $this->snapshot('incremental.zip', '{}', [], 1, 'incremental');
        $disk = Mockery::mock();
        $disk->shouldReceive('exists')->once()->with('backups/missing.zip')->andReturnFalse();
        Storage::shouldReceive('disk')->once()->with('capsule-test')->andReturn($disk);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->assertNull(app(IncrementalService::class)->getLatestFullSnapshot());
        $queries = $this->snapshotQueries();
        DB::disableQueryLog();

        $this->assertCount(1, $queries);
        $this->assertSame('id, backup_log_id', $queries[0]['projection']);
    }

    public function test_newest_eligible_base_stops_history_traversal_after_the_first_page(): void
    {
        for ($i = 0; $i < 120; $i++) {
            $this->snapshot("older-{$i}.zip", '{}');
        }
        $selected = $this->snapshot('newest.zip', '{"files/root-0/new.txt":{"size":4}}');
        $disk = Mockery::mock();
        $disk->shouldReceive('exists')->once()->with('backups/newest.zip')->andReturnTrue();
        Storage::shouldReceive('disk')->once()->with('capsule-test')->andReturn($disk);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $actual = app(IncrementalService::class)->getLatestFullSnapshot();
        $queries = $this->snapshotQueries();
        DB::disableQueryLog();

        $this->assertNotNull($actual);
        $this->assertSame($selected->id, $actual->id);
        $this->assertSame($selected->file_index, $actual->file_index);
        $this->assertCount(2, $queries);
        $this->assertSame('id, backup_log_id', $queries[0]['projection']);
        $this->assertSame('*', $queries[1]['projection']);
        $this->assertSame([$selected->id], $queries[1]['bindings']);
    }

    public function test_storage_failure_is_not_swallowed_or_allowed_to_load_an_index(): void
    {
        $this->snapshot('older.zip', '{}');
        $this->snapshot('unreachable.zip', $this->largeIndex());
        $disk = Mockery::mock();
        $disk->shouldReceive('exists')->once()->with('backups/unreachable.zip')
            ->andThrow(new \RuntimeException('Storage is unavailable'));
        Storage::shouldReceive('disk')->once()->with('capsule-test')->andReturn($disk);

        DB::flushQueryLog();
        DB::enableQueryLog();
        try {
            app(IncrementalService::class)->getLatestFullSnapshot();
            $this->fail('Storage errors must still propagate to the caller.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Storage is unavailable', $e->getMessage());
        } finally {
            $queries = $this->snapshotQueries();
            DB::disableQueryLog();
        }

        $this->assertCount(1, $queries);
        $this->assertSame('id, backup_log_id', $queries[0]['projection']);
    }

    private function snapshot(
        string $name,
        string $index,
        array $metadata = [],
        int $age = 1,
        string $type = 'full',
        ?int $baseSnapshotId = null,
        string $status = 'success'
    ): BackupSnapshot {
        $log = BackupLog::create([
            'started_at' => now()->subDays($age),
            'completed_at' => now()->subDays($age),
            'status' => $status,
            'file_path' => 'backups/' . $name,
            'file_size' => 1024,
            'metadata' => array_replace([
                'schema_version' => 2,
                'policy' => config('capsule._policy_name'),
                'storage' => ['disk' => 'capsule-test', 'backup_path' => 'backups'],
                'archive_sha256' => str_repeat('a', 64),
                'retention' => ['days' => 30, 'count' => 0, 'min_keep' => 0],
            ], $metadata),
        ]);
        $log->forceFill(['created_at' => now()->subDays($age)])->save();

        return BackupSnapshot::create([
            'backup_log_id' => $log->id,
            'type' => $type,
            'base_snapshot_id' => $baseSnapshotId,
            'file_index' => $index,
            'total_files' => 1500,
            'total_size' => 1500 * 1024,
        ]);
    }

    private function largeIndex(): string
    {
        $index = [];
        for ($i = 0; $i < 1500; $i++) {
            $index["files/root-0/directory/file-{$i}.txt"] = [
                'size' => 1024, 'mtime' => 1700000000, 'hash' => str_repeat('a', 64),
            ];
        }
        return json_encode($index, JSON_THROW_ON_ERROR);
    }

    private function snapshotQueries(): array
    {
        $queries = [];
        foreach (DB::getQueryLog() as $query) {
            $sql = str_replace(['"', '`'], '', strtolower($query['query']));
            if (preg_match('/^select (.*?) from backup_snapshots\b/', $sql, $matches)) {
                $queries[] = ['projection' => $matches[1], 'sql' => $sql, 'bindings' => $query['bindings']];
            }
        }
        return $queries;
    }
}
