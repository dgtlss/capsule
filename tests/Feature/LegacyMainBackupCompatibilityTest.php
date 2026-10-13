<?php

namespace Dgtlss\Capsule\Tests\Feature;

use Dgtlss\Capsule\Models\BackupLog;
use Dgtlss\Capsule\Models\BackupSnapshot;
use Dgtlss\Capsule\Services\BackupService;
use Dgtlss\Capsule\Support\RestoreArchive;
use Dgtlss\Capsule\Tests\TestCase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use ZipArchive;

/** Actual ZIPs created and restored by unmodified main, not hand-built manifests. */
class LegacyMainBackupCompatibilityTest extends TestCase
{
    private const SOURCE_COMMIT = '543a2ea9534eaf6df6d0e5710e972f518adc2306';

    public static function fullBackupCases(): array
    {
        $cases = [];
        foreach (['regular', 'streamed'] as $mode) {
            foreach (['plain', 'aes', 'zero', 'nul'] as $password) {
                foreach (['files', 'database'] as $contents) {
                    foreach (['catalog', 'local', 'storage'] as $route) {
                        $name = "$mode-$password-$contents";
                        $cases["$name/$route"] = [$name, $route];
                    }
                }
            }
        }
        foreach (['regular', 'streamed'] as $mode) {
            foreach (['plain', 'aes'] as $password) {
                foreach (['catalog', 'local', 'storage'] as $route) {
                    $name = "$mode-default-$password-database";
                    $cases["$name/$route"] = [$name, $route];
                }
            }
        }
        return $cases;
    }

    #[DataProvider('fullBackupCases')]
    public function test_old_successful_full_backup_restores_exact_bytes(string $fixture, string $route): void
    {
        $metadata = $this->fixtureCatalog()['records'][$fixture];
        $this->assertTrue($metadata['old_restore_verified']);
        $path = $this->fixture($fixture);
        $this->assertSame($metadata['sha256'], hash_file('sha256', $path));
        $password = $this->effectivePassword($metadata['password_case']);
        config([
            'capsule.audit.enabled' => false,
            'capsule.security.backup_password' => $password,
            'database.connections.recovery' => [
                'driver' => 'sqlite', 'database' => $this->testPath('recovered.sqlite'), 'prefix' => '',
            ],
        ]);
        $options = ['--target' => $this->testPath('recovered'), '--connection' => 'recovery', '--force' => true, '--format' => 'json'];
        if ($route === 'catalog') {
            $log = $this->importLegacyLog($fixture);
            $options['id'] = $log->id;
        } else {
            Schema::rename('backup_logs', 'unavailable_backup_logs');
            Schema::rename('backup_snapshots', 'unavailable_backup_snapshots');
            if ($route === 'storage') {
                Storage::disk('capsule-test')->put("historical/$fixture.zip", file_get_contents($path));
                $options += ['--disk' => 'capsule-test', '--path' => "historical/$fixture.zip"];
            } else {
                $options['--archive'] = $path;
            }
        }
        try {
            $status = Artisan::call('capsule:restore', $options);
            $output = Artisan::output();
            $this->assertSame(0, $status, $output);
            $result = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
            $this->assertSame('success', $result['status']);
            $this->assertSame($metadata['chunked'] ? 4 : 5, $result['files_restored']);
            $this->assertSame($metadata['database'] ? 1 : 0, $result['databases_restored']);
            $this->assertSame("Historical Capsule content\n", file_get_contents($this->testPath('recovered/hello.txt')));
            $this->assertSame(implode('', array_map('chr', range(0, 255))), file_get_contents($this->testPath('recovered/nested/binary.bin')));
            $this->assertSame('', file_get_contents($this->testPath('recovered/empty.txt')));
            $this->assertSame('Second independent root', file_get_contents($this->testPath('recovered/second.txt')));
            if (!$metadata['chunked']) {
                $this->assertSame('Standalone source file', file_get_contents($this->testPath('recovered/standalone.txt')));
            }
            $this->assertDirectoryDoesNotExist($this->testPath('recovered/root-0'));
            if ($metadata['database']) {
                $restored = new PDO('sqlite:' . $this->testPath('recovered.sqlite'));
                $this->assertSame('Created by pinned historical Capsule', $restored->query('SELECT payload FROM historical_records')->fetchColumn());
                $restored = null;
            }
        } finally {
            DB::purge('recovery');
            if ($route !== 'catalog') {
                Schema::rename('unavailable_backup_snapshots', 'backup_snapshots');
                Schema::rename('unavailable_backup_logs', 'backup_logs');
            }
        }
    }

    public static function historicalArchiveCases(): array
    {
        $cases = [];
        foreach (glob(__DIR__ . '/../Fixtures/LegacyMain/*.zip') as $path) {
            $name = basename($path, '.zip');
            $cases[$name] = [$name];
        }
        return $cases;
    }

    #[DataProvider('historicalArchiveCases')]
    public function test_public_inspect_and_verify_accept_real_historical_archives_and_report_checksum_limits(string $fixture): void
    {
        if ($fixture === 'regular-posix-files' && PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('Historical POSIX-only names require a compatible filesystem.');
        }
        $metadata = $this->fixtureCatalog()['records'][$fixture];
        config([
            'capsule.audit.enabled' => false,
            'capsule.security.backup_password' => $this->effectivePassword($metadata['password_case']),
        ]);
        $log = $this->importLegacyLog($fixture);
        $checksumsComplete = !$metadata['chunked'] || !$metadata['database'];
        $status = Artisan::call('capsule:inspect', ['id' => $log->id, '--format' => 'json']);
        $output = Artisan::output();
        $this->assertSame(0, $status, $output);
        $inspection = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(1, $inspection['manifest']['schema_version']);
        $this->assertSame('zip', $inspection['archive_format']);
        $this->assertFalse($inspection['requires_capsule']);
        $this->assertSame($checksumsComplete, $inspection['checksums_complete']);
        $status = Artisan::call('capsule:verify', ['--id' => $log->id, '--format' => 'json']);
        $output = Artisan::output();
        $this->assertSame(0, $status, $output);
        $verification = json_decode($output, true, 512, JSON_THROW_ON_ERROR)[0];
        $this->assertSame('passed', $verification['status']);
        $this->assertSame('zip', $verification['archive_format']);
        $this->assertFalse($verification['requires_capsule']);
        $this->assertSame($checksumsComplete, $verification['checksums_complete']);
        if (!$checksumsComplete) {
            $this->assertNotEmpty($inspection['warnings']);
            $this->assertNotEmpty($verification['warnings']);
        }
    }

    public function test_old_zero_password_backups_are_plaintext_and_remain_readable(): void
    {
        // Old !empty('0') silently omitted encryption. Do not relabel these as AES.
        foreach (['regular', 'streamed'] as $mode) {
            $zip = new ZipArchive();
            $this->assertTrue($zip->open($this->fixture("$mode-zero-files"), ZipArchive::RDONLY));
            try {
                for ($index = 0; $index < $zip->numFiles; $index++) {
                    $this->assertSame(ZipArchive::EM_NONE, $zip->statIndex($index)['encryption_method']);
                }
                $manifest = json_decode($zip->getFromName('manifest.json'), true, 512, JSON_THROW_ON_ERROR);
                $this->assertTrue($manifest['capsule']['encryption_enabled']);
            } finally {
                $zip->close();
            }
            $archive = new RestoreArchive($this->fixture("$mode-zero-files"));
            try {
                $archive->verifyAndStage(false);
                $this->assertCount($mode === 'regular' ? 5 : 4, $archive->entries());
            } finally {
                $archive->close();
            }
        }
    }

    public function test_old_nul_password_archives_remain_recoverable_with_the_effective_native_prefix(): void
    {
        // Historical libzip treated a NUL as the end of the password. Its effective
        // prefix restores the unchanged historical archive without weakening new writes.
        foreach (['regular', 'streamed'] as $mode) {
            $archive = new RestoreArchive($this->fixture("$mode-nul-files"), 'legacy-prefix');
            try {
                $archive->verifyAndStage();
                $this->assertSame("Historical Capsule content\n", file_get_contents($archive->entries()['files/hello.txt']['staged_path']));
            } finally {
                $archive->close();
            }
        }
    }

    public function test_old_nul_password_requires_an_explicit_effective_prefix_migration(): void
    {
        config(['capsule.audit.enabled' => false, 'capsule.security.backup_password' => "legacy-prefix\0suffix"]);
        $this->assertSame(1, Artisan::call('capsule:restore', [
            '--archive' => $this->fixture('regular-nul-files'), '--target' => $this->testPath('nul-rejected'),
            '--force' => true, '--format' => 'json',
        ]));
        $this->assertStringContainsString('NUL', Artisan::output());
        $this->assertDirectoryDoesNotExist($this->testPath('nul-rejected'));
    }

    public function test_upgrading_with_an_old_snapshot_starts_a_new_full_backup_and_keeps_old_artifacts(): void
    {
        $catalog = $this->fixtureCatalog();
        $base = $this->importLegacyLog('incremental-base');
        $old = $catalog['incremental_snapshots'][0];
        $snapshot = new BackupSnapshot();
        $snapshot->forceFill($old)->save();
        $originalHash = hash_file('sha256', Storage::disk('capsule-test')->path($base->file_path));
        mkdir($this->testPath('new-source'));
        file_put_contents($this->testPath('new-source/current.txt'), 'New backup after upgrade');
        config([
            'capsule.audit.enabled' => false,
            'capsule.retention.cleanup_enabled' => false,
            'capsule.files.enabled' => true,
            'capsule.files.paths' => [$this->testPath('new-source')],
            'capsule.files.exclude_paths' => [],
        ]);
        $service = app(BackupService::class);
        $service->setIncremental(true);
        $this->assertTrue($service->run(), $service->getLastError() ?? '');
        $new = BackupLog::successful()->latest('id')->firstOrFail();
        $this->assertNotSame($base->id, $new->id);
        $archive = new RestoreArchive(Storage::disk('capsule-test')->path($new->file_path));
        try {
            $archive->verifyAndStage(false);
            $this->assertSame('full', $archive->manifest()['backup_type']);
            $this->assertSame(2, $archive->manifest()['schema_version']);
            $this->assertNull($archive->manifest()['base_archive'] ?? null);
        } finally {
            $archive->close();
        }
        $this->assertSame($originalHash, hash_file('sha256', Storage::disk('capsule-test')->path($base->file_path)));
        $this->assertSame($old['file_index'], $snapshot->fresh()->file_index);
    }

    #[DataProvider('incrementalRoutes')]
    public function test_valid_historical_posix_names_restore_without_renaming_or_merging(string $route): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('Historical POSIX-only names require a compatible destination filesystem.');
        }
        config(['capsule.audit.enabled' => false]);
        $fixture = 'regular-posix-files';
        $record = $this->fixtureCatalog()['records'][$fixture];
        $this->assertTrue($record['old_restore_verified']);
        $this->assertSame($record['sha256'], hash_file('sha256', $this->fixture($fixture)));
        $options = ['--target' => $this->testPath('posix-restored'), '--force' => true, '--format' => 'json'];
        if ($route === 'catalog') {
            $options['id'] = $this->importLegacyLog($fixture)->id;
        } elseif ($route === 'storage') {
            Storage::disk('capsule-test')->put('historical/posix.zip', file_get_contents($this->fixture($fixture)));
            $options += ['--disk' => 'capsule-test', '--path' => 'historical/posix.zip'];
        } else {
            $options['--archive'] = $this->fixture($fixture);
        }
        $status = Artisan::call('capsule:restore', $options);
        $this->assertSame(0, $status, Artisan::output());
        foreach ([
            'colon:name.txt' => 'A literal POSIX colon',
            'literal\\name.txt' => 'A literal POSIX backslash',
            'trailing.' => 'A literal trailing dot',
            'A.txt' => 'Uppercase filename',
            'a.txt' => 'Lowercase filename',
            'Folder/file.txt' => 'Uppercase directory',
            'folder/file.txt' => 'Lowercase directory',
        ] as $name => $bytes) {
            $this->assertSame($bytes, file_get_contents($this->testPath('posix-restored/' . $name)));
        }
    }

    public static function incrementalRoutes(): array
    {
        return [['catalog'], ['local'], ['storage']];
    }

    #[DataProvider('incrementalRoutes')]
    public function test_old_incremental_chain_recovers_existing_bytes_without_inventing_deletions(string $route): void
    {
        $catalog = $this->fixtureCatalog();
        config(['capsule.audit.enabled' => false]);
        $options = ['--target' => $this->testPath('incremental'), '--force' => true, '--format' => 'json'];
        if ($route === 'catalog') {
            $this->importLegacyLog('incremental-base');
            $delta = $this->importLegacyLog('incremental-delta');
            foreach ($catalog['incremental_snapshots'] as $record) {
                (new BackupSnapshot())->forceFill($record)->save();
            }
            $options['id'] = $delta->id;
        } else {
            Schema::rename('backup_logs', 'unavailable_backup_logs');
            Schema::rename('backup_snapshots', 'unavailable_backup_snapshots');
            $options['--base-archive'] = $this->fixture('incremental-base');
            if ($route === 'local') {
                $options['--archive'] = $this->fixture('incremental-delta');
            } else {
                Storage::disk('capsule-test')->put('historical/delta.zip', file_get_contents($this->fixture('incremental-delta')));
                $options += ['--disk' => 'capsule-test', '--path' => 'historical/delta.zip'];
            }
        }
        try {
            $status = Artisan::call('capsule:restore', $options);
            $output = Artisan::output();
            $this->assertSame(0, $status, $output);
            $result = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
            $this->assertSame(2, $result['archives_verified']);
            $this->assertSame(4, $result['files_restored']);
            $this->assertSame(0, $result['files_deleted']);
            $this->assertSame("Historical incremental replacement\n", file_get_contents($this->testPath('incremental/hello.txt')));
            $this->assertSame('Historical addition', file_get_contents($this->testPath('incremental/added.txt')));
            $this->assertSame(implode('', array_map('chr', range(0, 255))), file_get_contents($this->testPath('incremental/nested/binary.bin')));
            // Old manifests never recorded deletions; warn and preserve rather
            // than inventing a deletion based only on absent delta entries.
            $this->assertSame('', file_get_contents($this->testPath('incremental/empty.txt')));
            $this->assertStringContainsString('deletion', strtolower(json_encode($result['warnings'] ?? [])));
        } finally {
            if ($route !== 'catalog') {
                Schema::rename('unavailable_backup_snapshots', 'backup_snapshots');
                Schema::rename('unavailable_backup_logs', 'backup_logs');
            }
        }
    }

    public static function databaseConnectionCases(): array
    {
        return [
            ['regular-plain-database'], ['regular-aes-database'], ['streamed-plain-database'], ['streamed-aes-database'],
            ['regular-default-plain-database'], ['regular-default-aes-database'],
            ['streamed-default-plain-database'], ['streamed-default-aes-database'],
        ];
    }

    #[DataProvider('databaseConnectionCases')]
    public function test_historical_database_connection_names_are_resolved_without_an_override(string $fixture): void
    {
        $connection = str_contains($fixture, '-default-') ? 'alias_source' : 'legacy_db';
        config([
            'capsule.audit.enabled' => false,
            'capsule.security.backup_password' => str_contains($fixture, '-aes-') ? 'legacy-fixture-password' : null,
            'database.connections.' . $connection => [
                'driver' => 'sqlite', 'database' => $this->testPath('automatic.sqlite'), 'prefix' => '',
            ],
        ]);
        try {
            $status = Artisan::call('capsule:restore', [
                '--archive' => $this->fixture($fixture), '--db-only' => true,
                '--force' => true, '--format' => 'json',
            ]);
            $output = Artisan::output();
            $this->assertSame(0, $status, $output);
            $result = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
            $this->assertSame(1, $result['databases_restored']);
            $this->assertSame(0, $result['files_restored']);
            $pdo = new PDO('sqlite:' . $this->testPath('automatic.sqlite'));
            $this->assertSame('Created by pinned historical Capsule', $pdo->query('SELECT payload FROM historical_records')->fetchColumn());
            $pdo = null;
        } finally {
            DB::purge($connection);
        }
    }

    public static function aesPasswordFailureCases(): array
    {
        return [
            ['regular-aes-files', null], ['regular-aes-files', 'incorrect-password'],
            ['streamed-aes-files', null], ['streamed-aes-files', 'incorrect-password'],
        ];
    }

    #[DataProvider('aesPasswordFailureCases')]
    public function test_old_aes_backups_still_fail_closed_without_the_correct_password(string $fixture, ?string $password): void
    {
        config(['capsule.audit.enabled' => false, 'capsule.security.backup_password' => $password]);
        $status = Artisan::call('capsule:restore', [
            '--archive' => $this->fixture($fixture), '--target' => $this->testPath('rejected'),
            '--force' => true, '--format' => 'json',
        ]);
        $this->assertSame(1, $status, Artisan::output());
        $this->assertDirectoryDoesNotExist($this->testPath('rejected'));
    }

    private function fixture(string $name): string
    {
        return __DIR__ . '/../Fixtures/LegacyMain/' . $name . '.zip';
    }

    private function fixtureCatalog(): array
    {
        $catalog = json_decode(file_get_contents(__DIR__ . '/../Fixtures/LegacyMain/catalog.json'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(self::SOURCE_COMMIT, $catalog['source_commit']);
        return $catalog;
    }

    private function effectivePassword(string $case): ?string
    {
        return match ($case) {
            'aes' => 'legacy-fixture-password',
            'zero' => '0',
            'nul' => 'legacy-prefix',
            default => null,
        };
    }

    private function importLegacyLog(string $fixture): BackupLog
    {
        $record = $this->fixtureCatalog()['records'][$fixture]['catalog'];
        $log = new BackupLog();
        $log->forceFill($record)->save();
        Storage::disk('capsule-test')->put($log->file_path, file_get_contents($this->fixture($fixture)));
        return $log;
    }
}
