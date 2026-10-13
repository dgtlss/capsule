<?php

namespace Dgtlss\Capsule\Tests\Feature;

use Dgtlss\Capsule\Commands\RestoreCommand;
use Dgtlss\Capsule\Models\BackupLog;
use Dgtlss\Capsule\Tests\TestCase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use ZipArchive;
use PHPUnit\Framework\Attributes\DataProvider;

class RestoreCommandTest extends TestCase
{
    protected string $work;

    protected function setUp(): void
    {
        parent::setUp();
        if (!class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZIP extension is required.');
        }
        $this->work = sys_get_temp_dir() . '/capsule-restore-tests-' . bin2hex(random_bytes(8));
        mkdir($this->work, 0700, true);
        config(['capsule.audit.enabled' => false]);
    }

    protected function tearDown(): void
    {
        if (isset($this->work) && is_dir($this->work)) {
            $this->removeSafely($this->work);
        }
        if (isset($this->app) && Schema::hasTable('unavailable_backup_logs')) {
            Schema::rename('unavailable_backup_logs', 'backup_logs');
        }
        parent::tearDown();
    }

    public function test_explicit_local_restore_works_without_backup_logs_and_emits_one_json_result(): void
    {
        Schema::rename('backup_logs', 'unavailable_backup_logs');
        $archive = $this->archive(['files/root/config/app.php' => 'restored']);
        [$code, $result] = $this->restore($archive);
        $this->assertSame(0, $code);
        $this->assertSame('success', $result['status']);
        $this->assertNull($result['backup_id']);
        $this->assertSame(1, $result['files_restored']);
        $this->assertSame('restored', file_get_contents($this->work . '/target/root/config/app.php'));
        $this->assertFileExists($archive);
    }

    public function test_explicit_storage_restore_works_without_backup_logs(): void
    {
        Schema::rename('backup_logs', 'unavailable_backup_logs');
        $archive = $this->archive(['files/root/file.txt' => 'remote']);
        Storage::disk('capsule-test')->put('restore/object.zip', file_get_contents($archive));
        $code = Artisan::call('capsule:restore', [
            '--disk' => 'capsule-test', '--path' => 'restore/object.zip',
            '--target' => $this->work . '/target', '--force' => true, '--format' => 'json',
        ]);
        $output = Artisan::output();
        $this->assertSame(0, $code, $output);
        $this->assertSame('success', json_decode($output, true, 512, JSON_THROW_ON_ERROR)['status']);
        $this->assertSame('remote', file_get_contents($this->work . '/target/root/file.txt'));
    }

    public function test_backup_storage_metadata_takes_precedence_over_global_disk_and_path(): void
    {
        $archive = $this->archive(['files/file.txt' => 'policy']);
        Storage::disk('capsule-test')->put('policy/archive.zip', file_get_contents($archive));
        $backup = BackupLog::create([
            'status' => 'success', 'started_at' => now(), 'file_path' => 'obsolete.zip',
            'metadata' => ['storage' => ['disk' => 'capsule-test', 'path' => 'policy/archive.zip']],
        ]);
        config(['capsule.default_disk' => 'does-not-exist']);
        $code = Artisan::call('capsule:restore', [
            'id' => $backup->id, '--target' => $this->work . '/target', '--force' => true, '--format' => 'json',
        ]);
        $this->assertSame(0, $code, Artisan::output());
        $this->assertSame('policy', file_get_contents($this->work . '/target/file.txt'));
    }

    #[DataProvider('unsafeNames')]
    public function test_unsafe_archive_names_are_rejected_before_any_writes(string $name): void
    {
        $archive = $this->archive(['files/safe.txt' => 'safe', $name => 'escape'], ['schema_version' => 2]);
        [$code, $result] = $this->restore($archive);
        $this->assertSame(1, $code);
        $this->assertSame('failed', $result['status']);
        $this->assertDirectoryDoesNotExist($this->work . '/target');
    }

    public static function unsafeNames(): array
    {
        return array_map(fn ($name) => [$name], [
            'files/../escape.txt', '/files/escape.txt', 'files//escape.txt',
            'files/./escape.txt', 'files/C:/escape.txt', 'files/..\\escape.txt',
            'files/evil. /escape.txt', 'files/CON', 'files/dir//',
        ]);
    }

    public function test_existing_parent_symlink_is_rejected_before_database_import_or_file_write(): void
    {
        mkdir($this->work . '/target');
        mkdir($this->work . '/outside');
        symlink($this->work . '/outside', $this->work . '/target/link');
        $archive = $this->archive(['database/testing.sql' => 'database', 'files/link/escape.txt' => 'unsafe']);
        $command = new RecordingRestoreCommand();
        $command->setLaravel($this->app);
        $this->app['Illuminate\Contracts\Console\Kernel']->registerCommand($command);
        [$code] = $this->restore($archive);
        $this->assertSame(1, $code);
        $this->assertSame([], $command->imports);
        $this->assertFileDoesNotExist($this->work . '/outside/escape.txt');
    }

    public function test_existing_file_symlink_is_rejected(): void
    {
        mkdir($this->work . '/target');
        file_put_contents($this->work . '/outside.txt', 'original');
        symlink($this->work . '/outside.txt', $this->work . '/target/file.txt');
        [$code] = $this->restore($this->archive(['files/file.txt' => 'replacement']));
        $this->assertSame(1, $code);
        $this->assertSame('original', file_get_contents($this->work . '/outside.txt'));
    }

    public function test_archive_symlink_is_rejected(): void
    {
        $archive = $this->archive(['files/link' => '../outside']);
        $zip = new ZipArchive();
        $zip->open($archive);
        $zip->setExternalAttributesName('files/link', ZipArchive::OPSYS_UNIX, 0120777 << 16);
        $zip->close();
        [$code] = $this->restore($archive);
        $this->assertSame(1, $code);
        $this->assertDirectoryDoesNotExist($this->work . '/target');
    }

    public function test_corrupt_unselected_member_prevents_all_database_and_file_changes(): void
    {
        $archive = $this->archive(['database/testing.sql' => 'database', 'files/a.txt' => 'new', 'files/z.txt' => 'bad'], [
            'entries' => [
                $this->entry('database/testing.sql', 'database'),
                $this->entry('files/a.txt', 'new'),
                $this->entry('files/z.txt', 'xxx'),
            ],
        ]);
        $command = new RecordingRestoreCommand();
        $command->setLaravel($this->app);
        $this->app['Illuminate\Contracts\Console\Kernel']->registerCommand($command);
        [$code, $result] = $this->restore($archive);
        $this->assertSame(1, $code);
        $this->assertSame([], $command->imports);
        $this->assertStringContainsString('checksum', $result['error']);
        $this->assertDirectoryDoesNotExist($this->work . '/target');
        [$code] = $this->restore($archive, ['--only' => ['a.txt']]);
        $this->assertSame(1, $code);
    }

    public function test_manifest_size_mismatch_is_rejected(): void
    {
        $archive = $this->archive(['files/a.txt' => 'good'], [
            'entries' => [['path' => 'files/a.txt', 'size' => 1, 'sha256' => hash('sha256', 'good')]],
        ]);
        [$code] = $this->restore($archive);
        $this->assertSame(1, $code);
        $this->assertDirectoryDoesNotExist($this->work . '/target');
    }

    public function test_missing_manifest_or_unlisted_payload_is_rejected(): void
    {
        $archive = $this->archive(['files/a.txt' => 'unlisted'], ['entries' => []]);
        [$code] = $this->restore($archive);
        $this->assertSame(1, $code);
        $zip = new ZipArchive();
        $zip->open($archive);
        $zip->deleteName('manifest.json');
        $zip->close();
        [$code] = $this->restore($archive);
        $this->assertSame(1, $code);
    }

    public function test_portable_incremental_restores_base_changes_and_deletions_without_logs(): void
    {
        Schema::rename('backup_logs', 'unavailable_backup_logs');
        $base = $this->archive(['files/root/keep.txt' => 'kept', 'files/root/change.txt' => 'old', 'files/root/gone.txt' => 'old']);
        $delta = $this->archive(['files/root/change.txt' => 'new', 'files/root/added.txt' => 'added'], [
            'schema_version' => 2, 'backup_type' => 'incremental',
            'base_archive' => ['disk' => 'absent', 'path' => 'base.zip', 'sha256' => hash_file('sha256', $base)],
            'deleted_files' => ['files/root/gone.txt'],
        ]);
        mkdir($this->work . '/target/root', 0755, true);
        file_put_contents($this->work . '/target/root/gone.txt', 'previous restore');
        [$code, $result] = $this->restore($delta, ['--base-archive' => $base]);
        $this->assertSame(0, $code, json_encode($result));
        $this->assertSame(2, $result['archives_verified']);
        $this->assertSame(3, $result['files_restored']);
        $this->assertSame(1, $result['files_deleted']);
        $this->assertSame('kept', file_get_contents($this->work . '/target/root/keep.txt'));
        $this->assertSame('new', file_get_contents($this->work . '/target/root/change.txt'));
        $this->assertSame('added', file_get_contents($this->work . '/target/root/added.txt'));
        $this->assertFileDoesNotExist($this->work . '/target/root/gone.txt');
    }

    public function test_incremental_downloads_its_checksum_bound_base_from_storage(): void
    {
        $base = $this->archive(['files/root/keep.txt' => 'base']);
        Storage::disk('capsule-test')->put('base.zip', file_get_contents($base));
        $delta = $this->archive(['files/root/added.txt' => 'delta'], [
            'backup_type' => 'incremental',
            'base_archive' => ['disk' => 'capsule-test', 'path' => 'base.zip', 'sha256' => hash_file('sha256', $base)],
        ]);
        [$code] = $this->restore($delta);
        $this->assertSame(0, $code);
        $this->assertSame('base', file_get_contents($this->work . '/target/root/keep.txt'));
    }

    public function test_wrong_base_checksum_prevents_changes(): void
    {
        $base = $this->archive(['files/root/keep.txt' => 'base']);
        $delta = $this->archive(['files/root/add.txt' => 'delta'], [
            'backup_type' => 'incremental', 'base_archive' => ['sha256' => str_repeat('a', 64)],
        ]);
        [$code] = $this->restore($delta, ['--base-archive' => $base]);
        $this->assertSame(1, $code);
        $this->assertDirectoryDoesNotExist($this->work . '/target');
    }

    public function test_unsafe_deletion_is_rejected_before_changes(): void
    {
        $base = $this->archive(['files/root/keep.txt' => 'base']);
        $delta = $this->archive(['files/root/add.txt' => 'delta'], [
            'backup_type' => 'incremental', 'base_archive' => ['sha256' => hash_file('sha256', $base)],
            'deleted_files' => ['files/../../outside'],
        ]);
        [$code] = $this->restore($delta, ['--base-archive' => $base]);
        $this->assertSame(1, $code);
        $this->assertDirectoryDoesNotExist($this->work . '/target');
    }

    public function test_dry_run_verifies_but_never_creates_target(): void
    {
        [$code, $result] = $this->restore($this->archive(['files/root/a.txt' => 'new']), ['--dry-run' => true]);
        $this->assertSame(0, $code);
        $this->assertTrue($result['dry_run']);
        $this->assertSame(1, $result['files_restored']);
        $this->assertDirectoryDoesNotExist($this->work . '/target');
    }

    public function test_import_failures_return_nonzero_and_one_json_error(): void
    {
        $command = new RecordingRestoreCommand();
        $command->failImport = true;
        config(['database.connections.fake_mysql' => ['driver' => 'mysql', 'database' => 'fake']]);
        $command->setLaravel($this->app);
        $this->app['Illuminate\Contracts\Console\Kernel']->registerCommand($command);
        [$code, $result] = $this->restore($this->archive(['database/fake_mysql.sql' => 'broken', 'files/a.txt' => 'new']));
        $this->assertSame(1, $code);
        $this->assertSame('failed', $result['status']);
        $this->assertStringContainsString('import failed', $result['error']);
        $this->assertDirectoryDoesNotExist($this->work . '/target');
    }

    public function test_wrong_archive_password_fails_without_changes(): void
    {
        if (!ZipArchive::isEncryptionMethodSupported(ZipArchive::EM_AES_256)) {
            $this->markTestSkipped('AES ZIP encryption is unavailable.');
        }
        $archive = $this->archive(['files/a.txt' => 'private']);
        $zip = new ZipArchive();
        $zip->open($archive);
        $zip->setPassword('correct');
        $zip->setEncryptionName('manifest.json', ZipArchive::EM_AES_256);
        $zip->setEncryptionName('files/a.txt', ZipArchive::EM_AES_256);
        $zip->close();
        config(['capsule.security.backup_password' => 'wrong']);
        [$code, $result] = $this->restore($archive);
        $this->assertSame(1, $code);
        $this->assertStringContainsString('password', $result['error']);
        $this->assertDirectoryDoesNotExist($this->work . '/target');
    }

    public function test_legacy_schema_one_original_path_archive_remains_restorable(): void
    {
        $archive = $this->archive(['files/config/app.php' => '<?php return ["legacy" => true];'], [
            'schema_version' => 1,
            'app' => ['name' => 'Legacy Laravel app'],
            'storage' => ['disk' => 'retired-server-disk', 'backup_path' => 'backups'],
            'files' => ['paths' => ['/old/server/app/config']],
        ]);
        [$code, $result] = $this->restore($archive);
        $this->assertSame(0, $code, json_encode($result));
        $this->assertSame('<?php return ["legacy" => true];', file_get_contents($this->work . '/target/config/app.php'));
    }

    public function test_manifest_cannot_override_private_staging_locations(): void
    {
        $outside = $this->work . '/outside.txt';
        file_put_contents($outside, 'untouched');
        $entry = $this->entry('files/a.txt', 'expected') + ['staged_path' => $outside];
        [$code] = $this->restore($this->archive(['files/a.txt' => 'expected'], ['entries' => [$entry]]));
        $this->assertSame(0, $code);
        $this->assertSame('expected', file_get_contents($this->work . '/target/a.txt'));
        $this->assertSame('untouched', file_get_contents($outside));
    }

    public function test_sqlite_database_image_is_restored_from_new_extension(): void
    {
        $source = $this->work . '/source.sqlite';
        $database = new \PDO('sqlite:' . $source);
        $database->exec('CREATE TABLE recovered (value TEXT)');
        $database->exec("INSERT INTO recovered VALUES ('saved')");
        $database = null;
        $contents = file_get_contents($source);
        $target = $this->work . '/restored.sqlite';
        config(['database.connections.restore_target' => ['driver' => 'sqlite', 'database' => $target]]);
        $entry = $this->entry('database/original.sqlite', $contents) + [
            'connection' => 'original', 'driver' => 'sqlite', 'format' => 'sqlite',
        ];
        [$code, $result] = $this->restore($this->archive(['database/original.sqlite' => $contents], ['entries' => [$entry]]), [
            '--db-only' => true, '--connection' => 'restore_target',
        ]);
        $this->assertSame(0, $code, json_encode($result));
        $this->assertSame(1, $result['databases_restored']);
        $restored = new \PDO('sqlite:' . $target);
        $this->assertSame('saved', $restored->query('SELECT value FROM recovered')->fetchColumn());
    }

    public function test_invalid_sqlite_image_does_not_replace_database(): void
    {
        $target = $this->work . '/restored.sqlite';
        file_put_contents($target, 'original database');
        config(['database.connections.restore_target' => ['driver' => 'sqlite', 'database' => $target]]);
        [$code] = $this->restore($this->archive(['database/restore_target.sqlite' => 'not a database']), ['--db-only' => true]);
        $this->assertSame(1, $code);
        $this->assertSame('original database', file_get_contents($target));
    }

    public function test_postgresql_import_stops_on_sql_errors_and_returns_failure(): void
    {
        $binary = $this->work . '/psql';
        $arguments = $this->work . '/psql-arguments';
        file_put_contents($binary, "#!/bin/sh\nprintf '%s\\n' \"\$@\" > " . escapeshellarg($arguments) . "\nexit 3\n");
        chmod($binary, 0700);
        $oldPath = getenv('PATH');
        putenv('PATH=' . $this->work . PATH_SEPARATOR . $oldPath);
        config(['database.connections.restore_pg' => [
            'driver' => 'pgsql', 'host' => 'localhost', 'database' => 'restore_test',
            'username' => 'restore_test', 'password' => 'unused',
        ]]);
        try {
            [$code, $result] = $this->restore($this->archive(['database/restore_pg.sql' => 'invalid SQL;']), ['--db-only' => true]);
            $this->assertSame(1, $code);
            $this->assertStringContainsString('PostgreSQL import failed', $result['error']);
            $this->assertStringContainsString('--set=ON_ERROR_STOP=1', file_get_contents($arguments));
            $this->assertStringContainsString('--no-psqlrc', file_get_contents($arguments));
        } finally {
            putenv('PATH=' . $oldPath);
        }
    }

    public function test_locked_sqlite_target_fails_without_replacing_live_data(): void
    {
        $source = $this->work . '/snapshot.sqlite';
        $saved = new \PDO('sqlite:' . $source);
        $saved->exec('CREATE TABLE records (value TEXT)');
        $saved->exec("INSERT INTO records VALUES ('backup')");
        $saved = null;
        $contents = file_get_contents($source);
        $target = $this->work . '/live.sqlite';
        $live = new \PDO('sqlite:' . $target);
        $live->exec('PRAGMA journal_mode=WAL');
        $live->exec('CREATE TABLE records (value TEXT)');
        $live->exec("INSERT INTO records VALUES ('production')");
        $live->exec('BEGIN IMMEDIATE');
        $live->exec("UPDATE records SET value = 'in flight'");
        config(['database.connections.restore_locked' => ['driver' => 'sqlite', 'database' => $target]]);
        try {
            [$code, $result] = $this->restore($this->archive(['database/restore_locked.sqlite' => $contents]), ['--db-only' => true]);
            $this->assertSame(1, $code);
            $this->assertStringContainsString('SQLite restore failed', $result['error']);
            $this->assertSame('in flight', $live->query('SELECT value FROM records')->fetchColumn());
        } finally {
            $live->exec('ROLLBACK');
        }
        $read = new \PDO('sqlite:' . $target);
        $this->assertSame('production', $read->query('SELECT value FROM records')->fetchColumn());
    }

    public function test_all_sqlite_images_are_validated_before_first_database_is_changed(): void
    {
        $source = $this->work . '/snapshot.sqlite';
        $saved = new \PDO('sqlite:' . $source);
        $saved->exec('CREATE TABLE records (value TEXT)');
        $saved->exec("INSERT INTO records VALUES ('backup')");
        $saved = null;
        $target = $this->work . '/target.sqlite';
        $live = new \PDO('sqlite:' . $target);
        $live->exec('CREATE TABLE records (value TEXT)');
        $live->exec("INSERT INTO records VALUES ('production')");
        config([
            'database.connections.restore_first' => ['driver' => 'sqlite', 'database' => $target],
            'database.connections.restore_second' => ['driver' => 'sqlite', 'database' => $this->work . '/second.sqlite'],
        ]);
        [$code] = $this->restore($this->archive([
            'database/restore_first.sqlite' => file_get_contents($source),
            'database/restore_second.sqlite' => 'malformed SQLite image',
        ]), ['--db-only' => true]);
        $this->assertSame(1, $code);
        $this->assertSame('production', $live->query('SELECT value FROM records')->fetchColumn());
        $this->assertFileDoesNotExist($this->work . '/second.sqlite');
    }

    public function test_duplicate_database_aliases_fail_before_restoring_either_image(): void
    {
        $source = $this->work . '/snapshot.sqlite';
        $saved = new \PDO('sqlite:' . $source);
        $saved->exec('CREATE TABLE records (value TEXT)');
        $saved = null;
        $target = $this->work . '/same.sqlite';
        config([
            'database.connections.restore_first' => ['driver' => 'sqlite', 'database' => $target],
            'database.connections.restore_second' => ['driver' => 'sqlite', 'database' => $target],
        ]);
        [$code, $result] = $this->restore($this->archive([
            'database/restore_first.sqlite' => file_get_contents($source),
            'database/restore_second.sqlite' => file_get_contents($source),
        ]), ['--db-only' => true]);
        $this->assertSame(1, $code);
        $this->assertStringContainsString('same restore target', $result['error']);
        $this->assertFileDoesNotExist($target);
    }

    protected function restore(string $archive, array $options = []): array
    {
        $code = Artisan::call('capsule:restore', array_merge([
            '--archive' => $archive, '--target' => $this->work . '/target',
            '--force' => true, '--format' => 'json',
        ], $options));
        $result = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($result);
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
            if (!str_ends_with($name, '/')) {
                $entries[] = $this->entry($name, $contents);
            }
        }
        $zip->addFromString('manifest.json', json_encode(array_replace([
            'schema_version' => 1, 'backup_type' => 'full', 'entries' => $entries,
        ], $manifest), JSON_THROW_ON_ERROR));
        $this->assertTrue($zip->close());
        return $path;
    }

    protected function entry(string $name, string $contents): array
    {
        return ['path' => $name, 'size' => strlen($contents), 'sha256' => hash('sha256', $contents)];
    }

    protected function removeSafely(string $directory): void
    {
        foreach (scandir($directory) as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $path = $directory . '/' . $name;
            if (is_dir($path) && !is_link($path)) {
                $this->removeSafely($path);
            } else {
                unlink($path);
            }
        }
        rmdir($directory);
    }
}

class RecordingRestoreCommand extends RestoreCommand
{
    public array $imports = [];
    public bool $failImport = false;

    protected function importSqlDump(string $connection, string $sqlPath, bool $verbose): void
    {
        $this->imports[] = $connection;
        if ($this->failImport) {
            throw new \RuntimeException('Database import failed.');
        }
    }
}
