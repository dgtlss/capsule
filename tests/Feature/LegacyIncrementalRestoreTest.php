<?php

namespace Dgtlss\Capsule\Tests\Feature;

use Dgtlss\Capsule\Models\BackupLog;
use Dgtlss\Capsule\Models\BackupSnapshot;
use Dgtlss\Capsule\Tests\TestCase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use ZipArchive;

class LegacyIncrementalRestoreTest extends TestCase
{
    public function test_catalog_resolves_the_historical_full_base_and_retains_unrecorded_deletions(): void
    {
        $base = $this->archive(['files/unchanged.txt' => 'keep', 'files/changed.txt' => 'before', 'files/deleted.txt' => 'historical']);
        $snapshot = $this->catalogBase($base);
        $delta = $this->archive(['files/changed.txt' => 'after', 'files/added.txt' => 'new'], $this->legacy($snapshot->id));
        [$code, $result] = $this->restore($delta);

        $this->assertSame(0, $code, json_encode($result));
        $this->assertSame(2, $result['archives_verified']);
        $this->assertSame(0, $result['files_deleted']);
        $this->assertSame('keep', file_get_contents($this->testPath('target/unchanged.txt')));
        $this->assertSame('after', file_get_contents($this->testPath('target/changed.txt')));
        $this->assertSame('historical', file_get_contents($this->testPath('target/deleted.txt')));
        $this->assertStringContainsString('no historical base archive checksum', implode(' ', $result['warnings']));
        $this->assertStringContainsString('not an exact point-in-time snapshot', implode(' ', $result['warnings']));
    }

    public function test_explicit_base_works_without_either_catalog_table(): void
    {
        $base = $this->archive(['files/base.txt' => 'base']);
        $delta = $this->archive(['files/delta.txt' => 'delta'], $this->legacy(123));
        Schema::rename('backup_snapshots', 'unavailable_backup_snapshots');
        Schema::rename('backup_logs', 'unavailable_backup_logs');
        try {
            [$code, $result] = $this->restore($delta, ['--base-archive' => $base]);
        } finally {
            Schema::rename('unavailable_backup_logs', 'backup_logs');
            Schema::rename('unavailable_backup_snapshots', 'backup_snapshots');
        }
        $this->assertSame(0, $code, json_encode($result));
        $this->assertSame(2, $result['files_restored']);
        $this->assertCount(2, $result['warnings']);
    }

    public function test_legacy_limits_are_visible_in_text_list_and_json_dry_run(): void
    {
        $base = $this->archive(['files/base.txt' => 'base']);
        $delta = $this->archive(['files/delta.txt' => 'delta'], $this->legacy(123));
        $options = ['--archive' => $delta, '--base-archive' => $base, '--list' => true];
        $code = Artisan::call('capsule:restore', $options);
        $output = Artisan::output();
        $this->assertSame(0, $code, $output);
        $this->assertStringContainsString('Historical deletions were not recorded', $output);
        $this->assertStringContainsString('no historical base archive checksum', $output);
        [$code, $result] = $this->restore($delta, ['--base-archive' => $base, '--dry-run' => true]);
        $this->assertSame(0, $code, json_encode($result));
        $this->assertCount(2, $result['warnings']);
        $this->assertDirectoryDoesNotExist($this->testPath('target'));
    }

    public function test_missing_catalog_requires_an_explicit_base_without_writing_the_delta(): void
    {
        $delta = $this->archive(['files/delta.txt' => 'delta'], $this->legacy(123));
        Schema::rename('backup_snapshots', 'unavailable_backup_snapshots');
        try {
            [$code, $result] = $this->restore($delta);
        } finally {
            Schema::rename('unavailable_backup_snapshots', 'backup_snapshots');
        }
        $this->assertSame(1, $code);
        $this->assertStringContainsString('--base-archive', $result['error']);
        $this->assertDirectoryDoesNotExist($this->testPath('target'));
    }

    public function test_missing_or_unsuccessful_catalog_base_is_rejected_without_writes(): void
    {
        $base = $this->archive(['files/base.txt' => 'base']);
        $snapshot = $this->catalogBase($base);
        $snapshot->backupLog->update(['status' => 'failed']);
        foreach ([$snapshot->id, 9999] as $id) {
            [$code, $result] = $this->restore($this->archive(['files/delta.txt' => 'delta'], $this->legacy($id)));
            $this->assertSame(1, $code);
            $this->assertStringContainsString('no successful full backup', $result['error']);
            $this->assertDirectoryDoesNotExist($this->testPath('target'));
        }
    }

    public function test_legacy_catalog_base_retains_recorded_checksum_and_format_requirements(): void
    {
        $base = $this->archive(['files/base.txt' => 'base']);
        $snapshot = $this->catalogBase($base);
        $delta = $this->archive(['files/delta.txt' => 'delta'], $this->legacy($snapshot->id));
        $snapshot->backupLog->update(['metadata' => ['archive_sha256' => str_repeat('a', 64)]]);
        [$code, $result] = $this->restore($delta);
        $this->assertSame(1, $code);
        $this->assertStringContainsString('recorded checksum', $result['error']);
        $snapshot->backupLog->update(['metadata' => ['requires_capsule' => true]]);
        [$code, $result] = $this->restore($delta);
        $this->assertSame(1, $code);
        $this->assertMatchesRegularExpression('/encrypted|format|Capsule/i', $result['error']);
        $this->assertDirectoryDoesNotExist($this->testPath('target'));
    }

    public function test_corrupt_base_is_rejected_before_any_delta_files_are_written(): void
    {
        $base = $this->archive(['files/base.txt' => 'base'], ['entries' => [
            ['path' => 'files/base.txt', 'size' => 4, 'sha256' => str_repeat('0', 64)],
        ]]);
        $delta = $this->archive(['files/delta.txt' => 'delta'], $this->legacy(123));
        [$code, $result] = $this->restore($delta, ['--base-archive' => $base]);
        $this->assertSame(1, $code);
        $this->assertStringContainsString('checksum', $result['error']);
        $this->assertDirectoryDoesNotExist($this->testPath('target'));
    }

    public function test_legacy_incremental_never_invents_or_accepts_deletion_records(): void
    {
        $base = $this->archive(['files/base.txt' => 'base']);
        $delta = $this->archive(['files/delta.txt' => 'delta'], $this->legacy(123) + ['deleted_files' => ['files/base.txt']]);
        [$code, $result] = $this->restore($delta, ['--base-archive' => $base]);
        $this->assertSame(1, $code);
        $this->assertStringContainsString('unexpected deletion records', $result['error']);
        $this->assertDirectoryDoesNotExist($this->testPath('target'));
    }

    public function test_historical_file_to_directory_change_without_a_deletion_record_is_not_destructive(): void
    {
        $base = $this->archive(['files/item' => 'historical file']);
        $delta = $this->archive(['files/item/child.txt' => 'new child'], $this->legacy(123));
        mkdir($this->testPath('target'));
        file_put_contents($this->testPath('target/item'), 'existing file');
        [$code, $result] = $this->restore($delta, ['--base-archive' => $base]);
        $this->assertSame(1, $code);
        $this->assertStringContainsString('conflicting file and directory', $result['error']);
        $this->assertSame('existing file', file_get_contents($this->testPath('target/item')));
    }

    public function test_schema_two_cannot_downgrade_to_an_unbound_legacy_base(): void
    {
        $base = $this->archive(['files/base.txt' => 'base']);
        foreach ([2, 3] as $schema) {
            $delta = $this->archive(['files/delta.txt' => 'delta'], array_merge($this->legacy(123), ['schema_version' => $schema]));
            [$code] = $this->restore($delta, ['--base-archive' => $base]);
            $this->assertSame(1, $code);
            $this->assertDirectoryDoesNotExist($this->testPath('target'));
        }
    }

    public function test_malformed_legacy_references_are_not_treated_as_valid_historical_archives(): void
    {
        $base = $this->archive(['files/base.txt' => 'base']);
        foreach ([null, 0, -1, '123', [], true] as $id) {
            $delta = $this->archive(['files/delta.txt' => 'delta'], $this->legacy($id));
            [$code, $result] = $this->restore($delta, ['--base-archive' => $base]);
            $this->assertSame(1, $code);
            $this->assertStringContainsString('base_snapshot_id', $result['error']);
        }
        $delta = $this->archive(['files/delta.txt' => 'delta'], $this->legacy(123) + ['base_archive' => null]);
        [$code, $result] = $this->restore($delta, ['--base-archive' => $base]);
        $this->assertSame(1, $code);
        $this->assertStringContainsString('base_archive', $result['error']);
        $this->assertDirectoryDoesNotExist($this->testPath('target'));
    }

    public function test_legacy_self_referential_base_fails_cycle_validation(): void
    {
        $delta = $this->archive(['files/delta.txt' => 'delta'], $this->legacy(123));
        [$code, $result] = $this->restore($delta, ['--base-archive' => $delta]);
        $this->assertSame(1, $code);
        $this->assertStringContainsString('cyclic', $result['error']);
        $this->assertDirectoryDoesNotExist($this->testPath('target'));
    }

    public function test_archive_chain_limit_remains_sixty_four_after_legacy_support(): void
    {
        $archive = $this->archive(['files/layer.txt' => '0'], ['schema_version' => 2]);
        for ($layer = 1; $layer <= 64; $layer++) {
            $basePath = 'backups/layer-' . $layer . '.zip';
            Storage::disk('capsule-test')->put($basePath, file_get_contents($archive));
            $archive = $this->archive(['files/layer.txt' => (string) $layer], [
                'schema_version' => 2, 'backup_type' => 'incremental',
                'base_archive' => ['disk' => 'capsule-test', 'path' => $basePath, 'sha256' => hash_file('sha256', $archive)],
            ]);
            if ($layer === 63) {
                [$code, $result] = $this->restore($archive, ['--dry-run' => true]);
                $this->assertSame(0, $code, json_encode($result));
                $this->assertSame(64, $result['archives_verified']);
            }
        }
        [$code, $result] = $this->restore($archive);
        $this->assertSame(1, $code);
        $this->assertStringContainsString('excessively deep', $result['error']);
        $this->assertDirectoryDoesNotExist($this->testPath('target'));
    }

    public function test_legacy_unix_filenames_and_case_distinct_files_and_directories_are_preserved(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('Historical Unix names require a compatible filesystem.');
        }
        $files = [
            'files/report:2025.txt' => 'colon', 'files/trailing.' => 'dot', 'files/trailing ' => 'space',
            'files/CON.txt' => 'reserved on Windows', 'files/HELLO.txt' => 'upper', 'files/hello.txt' => 'lower',
            'files/Dir/x.txt' => 'upper directory', 'files/dir/y.txt' => 'lower directory',
        ];
        [$code, $result] = $this->restore($this->archive($files));
        $this->assertSame(0, $code, json_encode($result));
        foreach ($files as $name => $bytes) {
            $this->assertSame($bytes, file_get_contents($this->testPath('target/' . substr($name, 6))));
        }
        $this->assertSame([], glob($this->testPath('.capsule_case_*')));
        $this->assertSame([], glob($this->testPath('.CAPSULE_case_*')));
    }

    public function test_legacy_case_probe_dry_run_leaves_no_files_or_target_directory(): void
    {
        $archive = $this->archive(['files/Alpha/x.txt' => 'upper', 'files/alpha/y.txt' => 'lower']);
        [$code, $result] = $this->restore($archive, ['--dry-run' => true]);
        $this->assertSame(0, $code, json_encode($result));
        $this->assertDirectoryDoesNotExist($this->testPath('target'));
        $this->assertSame([], glob($this->testPath('.capsule_case_*')));
        $this->assertSame([], glob($this->testPath('.CAPSULE_case_*')));
    }

    public function test_schema_two_cannot_enable_legacy_filename_treatment(): void
    {
        $entries = [];
        foreach (['files/Alpha/x.txt', 'files/alpha/y.txt'] as $name) {
            $entries[] = ['path' => $name, 'size' => 1, 'sha256' => hash('sha256', 'x'), 'legacy_paths' => true];
        }
        $archive = $this->archive(['files/Alpha/x.txt' => 'x', 'files/alpha/y.txt' => 'x'], [
            'schema_version' => 2, 'entries' => $entries,
        ]);
        [$code, $result] = $this->restore($archive);
        $this->assertSame(1, $code);
        $this->assertStringContainsString('conflicting', strtolower($result['error']));
        $this->assertDirectoryDoesNotExist($this->testPath('target'));
    }

    public function test_legacy_case_aliases_do_not_bypass_destination_symlink_validation(): void
    {
        mkdir($this->testPath('outside'));
        symlink($this->testPath('outside'), $this->testPath('target'));
        $archive = $this->archive(['files/Alpha.txt' => 'upper', 'files/alpha.txt' => 'lower']);
        [$code, $result] = $this->restore($archive);
        $this->assertSame(1, $code);
        $this->assertStringContainsString('symbolic link', $result['error']);
        $this->assertSame([], glob($this->testPath('outside/*')));
        $this->assertSame([], glob($this->testPath('.capsule_case_*')));
    }

    public function test_database_layers_keep_only_the_latest_dump_for_the_original_connection(): void
    {
        $base = $this->archive(['database/my_sqlite.sql' => $this->sqliteBytes('before')]);
        $delta = $this->archive(['database/my_sqlite.sql' => $this->sqliteBytes('after')], $this->legacy(123));
        config(['database.connections.my_sqlite' => ['driver' => 'sqlite', 'database' => $this->testPath('restored.sqlite')]]);
        [$code, $result] = $this->restore($delta, ['--base-archive' => $base]);
        $this->assertSame(0, $code, json_encode($result));
        $this->assertSame(1, $result['databases_restored']);
        $database = new \PDO('sqlite:' . $this->testPath('restored.sqlite'));
        $this->assertSame('after', $database->query('SELECT value FROM recovered')->fetchColumn());
    }

    public function test_timestamped_schema_one_sqlite_dump_selects_the_recorded_connection(): void
    {
        $archive = $this->archive(['database/my_sqlite_2025-01-02_03-04-05.sql' => $this->sqliteBytes('saved')], [
            'capsule' => ['chunked' => true], 'database' => ['connections' => ['my_sqlite']],
        ]);
        config(['database.connections.my_sqlite' => ['driver' => 'sqlite', 'database' => $this->testPath('restored.sqlite')]]);
        [$code, $result] = $this->restore($archive);
        $this->assertSame(0, $code, json_encode($result));
        $database = new \PDO('sqlite:' . $this->testPath('restored.sqlite'));
        $this->assertSame('saved', $database->query('SELECT value FROM recovered')->fetchColumn());
    }

    public function test_invalid_legacy_sqlite_payload_never_creates_database_or_file_target(): void
    {
        $archive = $this->archive(['database/my_sqlite.sql' => 'invalid SQLite bytes', 'files/delta.txt' => 'delta']);
        config(['database.connections.my_sqlite' => ['driver' => 'sqlite', 'database' => $this->testPath('restored.sqlite')]]);
        [$code, $result] = $this->restore($archive);
        $this->assertSame(1, $code);
        $this->assertStringContainsString('SQLite', $result['error']);
        $this->assertFileDoesNotExist($this->testPath('restored.sqlite'));
        $this->assertDirectoryDoesNotExist($this->testPath('target'));
    }

    public function test_manifest_budget_option_overrides_config_and_is_applied_to_the_base(): void
    {
        $base = $this->archive(['files/base.txt' => 'base'], ['legacy_metadata' => str_repeat('x', 4096)]);
        $delta = $this->archive(['files/delta.txt' => 'delta'], $this->legacy(123));
        config(['capsule.restore.max_manifest_bytes' => 1024]);
        [$code, $result] = $this->restore($delta, ['--base-archive' => $base]);
        $this->assertSame(1, $code);
        $this->assertStringContainsString('manifest', strtolower($result['error']));
        $this->assertDirectoryDoesNotExist($this->testPath('target'));
        [$code, $result] = $this->restore($delta, ['--base-archive' => $base, '--max-manifest-bytes' => '8192']);
        $this->assertSame(0, $code, json_encode($result));
        $this->assertSame(2, $result['archives_verified']);
        $this->assertSame('base', file_get_contents($this->testPath('target/base.txt')));
    }

    public function test_invalid_manifest_budgets_are_rejected_without_outputs(): void
    {
        $archive = $this->archive(['files/payload.txt' => 'payload']);
        foreach (['0', '00123', '-1', '1.5', '1e6', '+123', ' 123', (string) PHP_INT_MAX . '0'] as $budget) {
            [$code, $result] = $this->restore($archive, ['--max-manifest-bytes' => $budget]);
            $this->assertSame(1, $code, $budget);
            $this->assertStringContainsString('positive integer', $result['error']);
            $this->assertDirectoryDoesNotExist($this->testPath('target'));
        }
        config(['capsule.restore.max_manifest_bytes' => false]);
        [$code, $result] = $this->restore($archive);
        $this->assertSame(1, $code);
        $this->assertStringContainsString('capsule.restore.max_manifest_bytes', $result['error']);
    }

    private function legacy(mixed $snapshotId): array
    {
        return ['backup_type' => 'incremental', 'base_snapshot_id' => $snapshotId];
    }

    private function archive(array $contents, array $overrides = []): string
    {
        $path = $this->testPath(bin2hex(random_bytes(6)) . '.zip');
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($path, ZipArchive::CREATE));
        $entries = [];
        foreach ($contents as $name => $bytes) {
            $zip->addFromString($name, $bytes);
            $entries[] = ['path' => $name, 'size' => strlen($bytes), 'sha256' => hash('sha256', $bytes)];
        }
        $zip->addFromString('manifest.json', json_encode(array_merge([
            'schema_version' => 1, 'backup_type' => 'full', 'entries' => $entries,
        ], $overrides), JSON_THROW_ON_ERROR));
        $this->assertTrue($zip->close());
        return $path;
    }

    private function catalogBase(string $archive): BackupSnapshot
    {
        $path = 'backups/' . basename($archive);
        Storage::disk('capsule-test')->put($path, file_get_contents($archive));
        $backup = BackupLog::create(['status' => 'success', 'started_at' => now(), 'file_path' => $path]);
        return BackupSnapshot::create(['backup_log_id' => $backup->id, 'type' => 'full', 'file_index' => '{}']);
    }

    private function restore(string $archive, array $options = []): array
    {
        config(['capsule.audit.enabled' => false]);
        $code = Artisan::call('capsule:restore', array_merge([
            '--archive' => $archive, '--target' => $this->testPath('target'), '--force' => true, '--format' => 'json',
        ], $options));
        return [$code, json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR)];
    }

    private function sqliteBytes(string $value): string
    {
        $path = $this->testPath(bin2hex(random_bytes(6)) . '.sqlite');
        $database = new \PDO('sqlite:' . $path);
        $database->exec('CREATE TABLE recovered (value TEXT)');
        $database->prepare('INSERT INTO recovered VALUES (?)')->execute([$value]);
        $database = null;
        return file_get_contents($path);
    }
}
