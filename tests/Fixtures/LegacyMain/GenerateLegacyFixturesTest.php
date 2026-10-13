<?php

use Dgtlss\Capsule\CapsuleServiceProvider;
use Dgtlss\Capsule\Models\BackupLog;
use Dgtlss\Capsule\Models\BackupSnapshot;
use Dgtlss\Capsule\Services\BackupService;
use Dgtlss\Capsule\Services\ChunkedBackupService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Orchestra\Testbench\TestCase;

/** Run only through regenerate.sh; these ZIPs are written by unmodified old code. */
class GenerateLegacyFixturesTest extends TestCase
{
    private string $sandbox;

    protected function getPackageProviders($app): array
    {
        return [CapsuleServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $this->sandbox = sys_get_temp_dir() . '/capsule-legacy-input-' . bin2hex(random_bytes(6));
        mkdir($this->sandbox . '/storage/framework/cache', 0700, true);
        mkdir($this->sandbox . '/storage/logs', 0700, true);
        $app->useStoragePath($this->sandbox . '/storage');
        $app['config']->set([
            'app.name' => 'Synthetic compatibility fixture',
            'app.key' => 'base64:' . base64_encode(str_repeat('a', 32)),
            'cache.default' => 'array',
            'database.default' => 'testing',
            'database.connections.testing' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
            'filesystems.disks.legacy-fixture' => ['driver' => 'local', 'root' => $this->sandbox . '/disk', 'throw' => true],
            'capsule.default_disk' => 'legacy-fixture',
            'capsule.backup_path' => 'backups',
            'capsule.database.enabled' => false,
            'capsule.files.enabled' => true,
            'capsule.files.exclude_paths' => [],
            'capsule.notifications.enabled' => false,
            'capsule.schedule.enabled' => false,
            'capsule.retention.cleanup_enabled' => false,
            'capsule.audit.enabled' => false,
            'capsule.performance.memory_limit' => null,
            'capsule.chunked_backup.chunk_size' => 65536,
        ]);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(getenv('CAPSULE_LEGACY_SOURCE') . '/database/migrations');
    }

    public function test_generate_real_historical_archives_and_catalog_state(): void
    {
        $output = getenv('CAPSULE_LEGACY_FIXTURES');
        $this->assertNotFalse($output);
        $this->assertStringStartsWith(getenv('CAPSULE_LEGACY_SOURCE') . '/', (new ReflectionClass(BackupService::class))->getFileName());
        mkdir($this->sandbox . '/source/nested', 0700, true);
        mkdir($this->sandbox . '/second-root', 0700, true);
        $files = [
            'hello.txt' => "Historical Capsule content\n",
            'nested/binary.bin' => implode('', array_map('chr', range(0, 255))),
            'empty.txt' => '',
        ];
        foreach ($files as $name => $bytes) {
            file_put_contents($this->sandbox . '/source/' . $name, $bytes);
        }
        file_put_contents($this->sandbox . '/second-root/second.txt', 'Second independent root');
        file_put_contents($this->sandbox . '/standalone.txt', 'Standalone source file');
        $pdo = new PDO('sqlite:' . $this->sandbox . '/source.sqlite');
        $pdo->exec('CREATE TABLE historical_records (id INTEGER PRIMARY KEY, payload TEXT)');
        $pdo->exec("INSERT INTO historical_records VALUES (1, 'Created by pinned historical Capsule')");
        $pdo = null;
        config(['database.connections.legacy_db' => [
            'driver' => 'sqlite', 'database' => $this->sandbox . '/source.sqlite', 'prefix' => '',
        ], 'capsule.database.connections' => ['legacy_db']]);
        $records = [];
        $scenario = 0;
        foreach ([false, true] as $chunked) {
            foreach (['plain' => null, 'aes' => 'legacy-fixture-password', 'zero' => '0', 'nul' => "legacy-prefix\0suffix"] as $label => $password) {
                foreach ([false, true] as $database) {
                    $name = ($chunked ? 'streamed' : 'regular') . '-' . $label . ($database ? '-database' : '-files');
                    Carbon::setTestNow(Carbon::parse('2025-01-02 03:04:05')->addSeconds($scenario++));
                    $paths = [$this->sandbox . '/source', $this->sandbox . '/second-root'];
                    if (!$chunked) {
                        $paths[] = $this->sandbox . '/standalone.txt';
                    }
                    config([
                        'capsule.database.enabled' => $database,
                        'capsule.files.paths' => $paths,
                        'capsule.security.encrypt_backups' => $password !== null,
                        'capsule.security.backup_password' => $password,
                    ]);
                    $class = $chunked ? ChunkedBackupService::class : BackupService::class;
                    $service = new $class($this->app);
                    $this->assertTrue($service->run(), $service->getLastError() ?? $name);
                    $record = BackupLog::successful()->latest('id')->firstOrFail();
                    $archive = Storage::disk('legacy-fixture')->path($record->file_path);
                    copy($archive, "$output/$name.zip");
                    $records[$name] = $this->record($record, "$output/$name.zip", $chunked, $database, $label);

                    // Establish that old restore really recovers the bytes. Override the
                    // timestamped DB connection name in old streamed backups explicitly.
                    $destination = $this->sandbox . '/old-restore-' . $name;
                    config(['database.connections.recovered' => [
                        'driver' => 'sqlite', 'database' => "$destination.sqlite", 'prefix' => '',
                    ]]);
                    $exit = Artisan::call('capsule:restore', [
                        'id' => $record->id, '--target' => $destination,
                        '--connection' => 'recovered', '--force' => true,
                    ]);
                    $this->assertSame(0, $exit, Artisan::output());
                    foreach ($files as $path => $bytes) {
                        $this->assertSame($bytes, file_get_contents($destination . '/' . $path), $name . ': ' . $path);
                    }
                    if ($database) {
                        $restored = new PDO('sqlite:' . "$destination.sqlite");
                        $this->assertSame('Created by pinned historical Capsule', $restored->query('SELECT payload FROM historical_records')->fetchColumn(), $name);
                        $restored = null;
                    }
                    DB::purge('recovered');
                    $records[$name]['old_restore_verified'] = true;
                }
            }
        }

        // Valid POSIX names are deliberately distinct from traversal or ZIP links.
        mkdir($this->sandbox . '/posix/Folder', 0700, true);
        mkdir($this->sandbox . '/posix/folder', 0700, true);
        $posixFiles = [
            'colon:name.txt' => 'A literal POSIX colon',
            'literal\\name.txt' => 'A literal POSIX backslash',
            'trailing.' => 'A literal trailing dot',
            'A.txt' => 'Uppercase filename',
            'a.txt' => 'Lowercase filename',
            'Folder/file.txt' => 'Uppercase directory',
            'folder/file.txt' => 'Lowercase directory',
        ];
        foreach ($posixFiles as $name => $bytes) {
            file_put_contents($this->sandbox . '/posix/' . $name, $bytes);
        }
        config([
            'capsule.database.enabled' => false,
            'capsule.files.paths' => [$this->sandbox . '/posix'],
            'capsule.security.encrypt_backups' => false,
            'capsule.security.backup_password' => null,
        ]);
        Carbon::setTestNow('2025-01-02 04:00:00');
        $service = new BackupService($this->app);
        $this->assertTrue($service->run(), $service->getLastError() ?? 'regular-posix-files');
        $record = BackupLog::successful()->latest('id')->firstOrFail();
        copy(Storage::disk('legacy-fixture')->path($record->file_path), "$output/regular-posix-files.zip");
        $records['regular-posix-files'] = $this->record($record, "$output/regular-posix-files.zip", false, false, 'plain');
        $target = $this->sandbox . '/old-posix-recovery';
        $this->assertSame(0, Artisan::call('capsule:restore', [
            'id' => $record->id, '--target' => $target, '--force' => true,
        ]), Artisan::output());
        foreach ($posixFiles as $name => $bytes) {
            $this->assertSame($bytes, file_get_contents($target . '/' . $name));
        }
        $records['regular-posix-files']['old_restore_verified'] = true;

        // Use a clean catalog to export a real schema-1 incremental chain.
        BackupSnapshot::query()->delete();
        BackupLog::query()->delete();
        config([
            'capsule.database.enabled' => false,
            'capsule.files.paths' => [$this->sandbox . '/source'],
            'capsule.security.encrypt_backups' => false,
            'capsule.security.backup_password' => null,
        ]);
        Carbon::setTestNow('2025-01-03 00:00:00');
        $service = new BackupService($this->app);
        $this->assertTrue($service->run(), $service->getLastError() ?? 'incremental-base');
        $base = BackupLog::successful()->latest('id')->firstOrFail();
        copy(Storage::disk('legacy-fixture')->path($base->file_path), "$output/incremental-base.zip");
        $records['incremental-base'] = $this->record($base, "$output/incremental-base.zip", false, false, 'plain');
        file_put_contents($this->sandbox . '/source/hello.txt', "Historical incremental replacement\n");
        file_put_contents($this->sandbox . '/source/added.txt', 'Historical addition');
        unlink($this->sandbox . '/source/empty.txt');
        clearstatcache();
        Carbon::setTestNow('2025-01-03 00:01:00');
        $service = new BackupService($this->app);
        $service->setIncremental(true);
        $this->assertTrue($service->run(), $service->getLastError() ?? 'incremental-delta');
        $delta = BackupLog::successful()->latest('id')->firstOrFail();
        copy(Storage::disk('legacy-fixture')->path($delta->file_path), "$output/incremental-delta.zip");
        $records['incremental-delta'] = $this->record($delta, "$output/incremental-delta.zip", false, false, 'plain');
        $target = $this->sandbox . '/old-incremental-recovery';
        foreach ([$base, $delta] as $layer) {
            $this->assertSame(0, Artisan::call('capsule:restore', [
                'id' => $layer->id, '--target' => $target, '--force' => true,
            ]), Artisan::output());
        }
        $this->assertSame("Historical incremental replacement\n", file_get_contents($target . '/hello.txt'));
        $this->assertSame('Historical addition', file_get_contents($target . '/added.txt'));
        $this->assertSame($files['nested/binary.bin'], file_get_contents($target . '/nested/binary.bin'));
        // The old restore never applied historical removals: capture that fact.
        $this->assertSame('', file_get_contents($target . '/empty.txt'));
        $records['incremental-base']['old_restore_verified'] = true;
        $records['incremental-delta']['old_restore_verified'] = true;
        // The old manifest kept the literal alias while the dumper resolved it.
        // Use a separate real SQLite app catalog so default DB backup is genuine.
        config(['database.connections.alias_source' => [
            'driver' => 'sqlite', 'database' => $this->sandbox . '/source.sqlite', 'prefix' => '',
        ]]);
        $this->assertSame(0, Artisan::call('migrate', [
            '--database' => 'alias_source',
            '--path' => getenv('CAPSULE_LEGACY_SOURCE') . '/database/migrations',
            '--realpath' => true, '--force' => true,
        ]), Artisan::output());
        foreach ($files as $name => $bytes) {
            file_put_contents($this->sandbox . '/source/' . $name, $bytes);
        }
        unlink($this->sandbox . '/source/added.txt');
        config(['database.default' => 'alias_source', 'capsule.database.connections' => ['default']]);
        foreach ([false, true] as $chunked) {
            foreach (['plain' => null, 'aes' => 'legacy-fixture-password'] as $label => $password) {
                $name = ($chunked ? 'streamed' : 'regular') . "-default-$label-database";
                Carbon::setTestNow(Carbon::parse('2025-01-04 00:00:00')->addSeconds($scenario++));
                $paths = [$this->sandbox . '/source', $this->sandbox . '/second-root'];
                if (!$chunked) {
                    $paths[] = $this->sandbox . '/standalone.txt';
                }
                config([
                    'capsule.database.enabled' => true,
                    'capsule.files.paths' => $paths,
                    'capsule.security.encrypt_backups' => $password !== null,
                    'capsule.security.backup_password' => $password,
                ]);
                $class = $chunked ? ChunkedBackupService::class : BackupService::class;
                $service = new $class($this->app);
                $this->assertTrue($service->run(), $service->getLastError() ?? $name);
                $record = BackupLog::successful()->latest('id')->firstOrFail();
                copy(Storage::disk('legacy-fixture')->path($record->file_path), "$output/$name.zip");
                $records[$name] = $this->record($record, "$output/$name.zip", $chunked, true, $label);
                $target = $this->sandbox . '/old-restore-' . $name;
                config(['database.connections.recovered' => [
                    'driver' => 'sqlite', 'database' => "$target.sqlite", 'prefix' => '',
                ]]);
                $this->assertSame(0, Artisan::call('capsule:restore', [
                    'id' => $record->id, '--target' => $target,
                    '--connection' => 'recovered', '--force' => true,
                ]), Artisan::output());
                foreach ($files as $path => $bytes) {
                    $this->assertSame($bytes, file_get_contents($target . '/' . $path), $name . ': ' . $path);
                }
                $pdo = new PDO('sqlite:' . "$target.sqlite");
                $this->assertSame('Created by pinned historical Capsule', $pdo->query('SELECT payload FROM historical_records')->fetchColumn(), $name);
                $pdo = null;
                DB::purge('recovered');
                $records[$name]['old_restore_verified'] = true;
            }
        }
        config(['database.default' => 'testing']);
        DB::purge('alias_source');

        file_put_contents("$output/catalog.json", json_encode([
            'source_commit' => '543a2ea9534eaf6df6d0e5710e972f518adc2306',
            'records' => $records,
            'incremental_snapshots' => BackupSnapshot::orderBy('id')->get()->toArray(),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
        Carbon::setTestNow();
    }

    private function record(BackupLog $record, string $path, bool $chunked, bool $database, string $password): array
    {
        return [
            'sha256' => hash_file('sha256', $path),
            'chunked' => $chunked,
            'database' => $database,
            'password_case' => $password,
            'catalog' => $record->fresh()->toArray(),
        ];
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
        if (isset($this->sandbox) && is_dir($this->sandbox)) {
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->sandbox, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($iterator as $item) {
                $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
            }
            rmdir($this->sandbox);
        }
    }
}
