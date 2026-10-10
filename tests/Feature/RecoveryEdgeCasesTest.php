<?php

namespace Dgtlss\Capsule\Tests\Feature;

use Dgtlss\Capsule\Tests\TestCase;
use Illuminate\Support\Facades\Artisan;
use ZipArchive;

class RecoveryEdgeCasesTest extends TestCase
{
    protected string $work;

    protected function setUp(): void
    {
        parent::setUp();
        $this->work = $this->testPath('reviewer');
        mkdir($this->work, 0700, true);
        config(['capsule.audit.enabled' => false]);
    }

    protected function restore(string $archive, array $options = []): array
    {
        $code = Artisan::call('capsule:restore', array_merge([
            '--archive' => $archive, '--target' => $this->work . '/target',
            '--force' => true, '--format' => 'json',
        ], $options));
        $result = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
        return [$code, $result];
    }

    protected function archive(array $files, array $manifest = []): string
    {
        $path = $this->work . '/' . bin2hex(random_bytes(8)) . '.zip';
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($path, ZipArchive::CREATE));
        $entries = [];
        foreach ($files as $name => $contents) {
            $zip->addFromString($name, $contents);
            $entries[] = $this->entry($name, $contents);
        }
        $zip->addFromString('manifest.json', json_encode(array_replace([
            'schema_version' => 2, 'backup_type' => 'full', 'entries' => $entries,
        ], $manifest), JSON_THROW_ON_ERROR));
        $this->assertTrue($zip->close());
        return $path;
    }

    protected function entry(string $name, string $contents): array
    {
        return ['path' => $name, 'size' => strlen($contents), 'sha256' => hash('sha256', $contents)];
    }

    public function test_sqlite_restore_replaces_live_wal_database_contents(): void
    {
        $source = $this->work . '/snapshot.sqlite';
        $saved = new \PDO('sqlite:' . $source);
        $saved->exec('CREATE TABLE restored (value TEXT)');
        $saved->exec("INSERT INTO restored VALUES ('backup value')");
        $saved = null;
        $contents = file_get_contents($source);

        $target = $this->work . '/live.sqlite';
        $live = new \PDO('sqlite:' . $target);
        $live->exec('PRAGMA journal_mode=WAL');
        $live->exec('PRAGMA wal_autocheckpoint=0');
        $live->exec('CREATE TABLE restored (value TEXT)');
        $live->exec("INSERT INTO restored VALUES ('stale production value')");
        $this->assertFileExists($target . '-wal');
        config(['database.connections.restore_target' => ['driver' => 'sqlite', 'database' => $target]]);
        $entry = $this->entry('database/restore_target.sqlite', $contents) + ['driver' => 'sqlite', 'format' => 'sqlite'];
        [$code, $result] = $this->restore($this->archive(['database/restore_target.sqlite' => $contents], ['entries' => [$entry]]), ['--db-only' => true]);
        $this->assertSame(0, $code, json_encode($result));
        $read = new \PDO('sqlite:' . $target);
        $actual = $read->query('SELECT value FROM restored')->fetchColumn();
        $read = $live = null;
        $this->assertSame('backup value', $actual);
    }

    public function test_incremental_file_to_directory_transition_restores_to_empty_target(): void
    {
        $base = $this->archive(['files/root/item' => 'old file']);
        $delta = $this->archive(['files/root/item/child.txt' => 'new child'], [
            'schema_version' => 2, 'backup_type' => 'incremental',
            'base_archive' => ['sha256' => hash_file('sha256', $base)],
            'deleted_files' => ['files/root/item'],
        ]);
        [$code, $result] = $this->restore($delta, ['--base-archive' => $base]);
        $this->assertSame(0, $code, json_encode($result));
        $this->assertSame('new child', file_get_contents($this->work . '/target/root/item/child.txt'));
    }

    public function test_incremental_directory_to_file_transition_restores_to_empty_target(): void
    {
        $base = $this->archive(['files/root/item/child.txt' => 'old child']);
        $delta = $this->archive(['files/root/item' => 'new file'], [
            'schema_version' => 2, 'backup_type' => 'incremental',
            'base_archive' => ['sha256' => hash_file('sha256', $base)],
            'deleted_files' => ['files/root/item/child.txt'],
        ]);
        [$code, $result] = $this->restore($delta, ['--base-archive' => $base]);
        $this->assertSame(0, $code, json_encode($result));
        $this->assertSame('new file', file_get_contents($this->work . '/target/root/item'));
    }
    public function test_orphan_cleanup_preserves_recorded_recovery_chunks(): void
    {
        $chunk = 'capsule_chunk_failed_source.part0';
        \Illuminate\Support\Facades\Storage::disk('capsule-test')->put('backups/' . $chunk, 'only surviving backup source');
        $backup = \Dgtlss\Capsule\Models\BackupLog::create([
            'started_at' => now(), 'status' => 'failed',
            'metadata' => [
                'storage' => ['disk' => 'capsule-test', 'backup_path' => 'backups'],
                'recovery_chunks' => [['name' => $chunk, 'index' => 0, 'base_name' => 'files_run', 'size' => 27]],
            ],
        ]);
        $result = app(\Dgtlss\Capsule\Services\RetentionService::class)->cleanup(['storage' => true]);
        $this->assertNotNull($backup->fresh());
        $this->assertSame(0, $result['count'], json_encode($result));
        \Illuminate\Support\Facades\Storage::disk('capsule-test')->assertExists('backups/' . $chunk);
    }

    public function test_retention_preserves_archive_shared_by_retained_record(): void
    {
        $path = 'backups/legacy-same-second.zip';
        \Illuminate\Support\Facades\Storage::disk('capsule-test')->put($path, 'latest physical backup');
        $metadata = [
            'storage' => ['disk' => 'capsule-test', 'backup_path' => 'backups'],
            'retention' => ['days' => 30, 'count' => 0, 'min_keep' => 1],
        ];
        $logs = [];
        for ($i = 0; $i < 2; $i++) {
            $logs[] = $log = \Dgtlss\Capsule\Models\BackupLog::create([
                'started_at' => now()->subDays(60), 'status' => 'success',
                'file_path' => $path, 'file_size' => 22, 'metadata' => $metadata,
            ]);
            $log->forceFill(['created_at' => now()->subDays(60)])->save();
        }
        app(\Dgtlss\Capsule\Services\RetentionService::class)->cleanup();
        $this->assertNotNull($logs[1]->fresh());
        \Illuminate\Support\Facades\Storage::disk('capsule-test')->assertExists($path);
    }

    public function test_failed_recovery_chunk_delete_preserves_record(): void
    {
        $backup = \Dgtlss\Capsule\Models\BackupLog::create([
            'started_at' => now()->subDays(60), 'status' => 'failed',
            'metadata' => [
                'storage' => ['disk' => 'broken', 'backup_path' => 'backups'],
                'recovery_chunks' => [['name' => 'surviving.part0', 'index' => 0]],
            ],
        ]);
        $backup->forceFill(['created_at' => now()->subDays(60)])->save();
        $disk = \Mockery::mock();
        $disk->shouldReceive('delete')->once()->with('backups/surviving.part0')->andReturnFalse();
        \Illuminate\Support\Facades\Storage::shouldReceive('disk')->with('broken')->andReturn($disk);
        $result = app(\Dgtlss\Capsule\Services\RetentionService::class)->cleanup(['failed' => true]);
        $this->assertSame(0, $result['count']);
        $this->assertCount(1, $result['errors']);
        $this->assertNotNull($backup->fresh());
        $this->assertSame('surviving.part0', $backup->fresh()->metadata['recovery_chunks'][0]['name']);
    }

    public function test_incremental_file_to_directory_over_existing_base(): void
    {
        $base = $this->archive(['files/root/item' => 'old file']);
        mkdir($this->work . '/target/root', 0700, true);
        file_put_contents($this->work . '/target/root/item', 'old file');
        $delta = $this->archive(['files/root/item/child.txt' => 'new child'], [
            'backup_type' => 'incremental', 'base_archive' => ['sha256' => hash_file('sha256', $base)],
            'deleted_files' => ['files/root/item'],
        ]);
        [$code, $result] = $this->restore($delta, ['--base-archive' => $base]);
        $this->assertSame(0, $code, json_encode($result));
        $this->assertSame('new child', file_get_contents($this->work . '/target/root/item/child.txt'));
    }

    public function test_incremental_directory_to_file_over_existing_base(): void
    {
        $base = $this->archive(['files/root/item/child.txt' => 'old child']);
        mkdir($this->work . '/target/root/item', 0700, true);
        file_put_contents($this->work . '/target/root/item/child.txt', 'old child');
        $delta = $this->archive(['files/root/item' => 'new file'], [
            'backup_type' => 'incremental', 'base_archive' => ['sha256' => hash_file('sha256', $base)],
            'deleted_files' => ['files/root/item/child.txt'],
        ]);
        [$code, $result] = $this->restore($delta, ['--base-archive' => $base]);
        $this->assertSame(0, $code, json_encode($result));
        $this->assertSame('new file', file_get_contents($this->work . '/target/root/item'));
    }

    public function test_transition_rejects_untracked_child_before_deleting_anything(): void
    {
        $base = $this->archive(['files/root/item/child.txt' => 'old child']);
        mkdir($this->work . '/target/root/item', 0700, true);
        file_put_contents($this->work . '/target/root/item/child.txt', 'old child');
        file_put_contents($this->work . '/target/root/item/untracked.txt', 'keep untracked');
        $delta = $this->archive(['files/root/item' => 'new file'], [
            'backup_type' => 'incremental', 'base_archive' => ['sha256' => hash_file('sha256', $base)],
            'deleted_files' => ['files/root/item/child.txt'],
        ]);
        [$code] = $this->restore($delta, ['--base-archive' => $base]);
        $this->assertSame(1, $code);
        $this->assertSame('old child', file_get_contents($this->work . '/target/root/item/child.txt'));
        $this->assertSame('keep untracked', file_get_contents($this->work . '/target/root/item/untracked.txt'));
    }

    public function test_database_aliases_do_not_publish_duplicate_manifest_entries(): void
    {
        $path = $this->work . '/source.sqlite';
        $pdo = new \PDO('sqlite:' . $path);
        $pdo->exec('CREATE TABLE saved (value TEXT)');
        $pdo->exec("INSERT INTO saved VALUES ('backup')");
        $pdo = null;
        $originalDefault = config('database.default');
        try {
            config([
                'database.connections.source' => ['driver' => 'sqlite', 'database' => $path],
                'database.default' => 'source',
                'capsule.database.enabled' => true,
                'capsule.database.connections' => ['default', 'source'],
                'capsule.files.enabled' => false,
                'capsule.retention.cleanup_enabled' => false,
            ]);
            // The source has no catalog tables; publication must remain verifiable without them.
            $service = app(\Dgtlss\Capsule\Services\BackupService::class);
            $ok = $service->run();
            $this->assertTrue($ok, $service->getLastError() ?? 'No backup');
            $paths = \Illuminate\Support\Facades\Storage::disk('capsule-test')->files('backups');
            $this->assertCount(1, $paths);
            $archive = new \Dgtlss\Capsule\Support\RestoreArchive(\Illuminate\Support\Facades\Storage::disk('capsule-test')->path($paths[0]));
            try {
                $archive->verifyOnly();
                $this->assertCount(1, $archive->entries());
            } finally {
                $archive->close();
            }
        } finally {
            config(['database.default' => $originalDefault]);
            \Illuminate\Support\Facades\DB::purge('source');
        }
    }

}
