<?php

namespace Dgtlss\Capsule\Tests\Unit;

use Dgtlss\Capsule\Support\RestoreArchive;
use Dgtlss\Capsule\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use ZipArchive;

class LegacyArchiveValidationTest extends TestCase
{
    private const DATABASE = 'database/legacy_db_2025-01-02_03-04-14.sql';
    private const PASSWORD = 'legacy-fixture-password';

    public static function originalStreamingArchives(): iterable
    {
        yield 'plain' => ['plain', null];
        yield 'native AES' => ['aes', self::PASSWORD];
        yield 'old zero password was not encrypted' => ['zero', '0'];
        yield 'old NUL effective password' => ['nul', 'legacy-prefix'];
    }

    #[DataProvider('originalStreamingArchives')]
    public function test_actual_previous_release_streamed_database_archives_remain_restorable(string $kind, ?string $password): void
    {
        $archive = new RestoreArchive(__DIR__ . '/../Fixtures/LegacyMain/streamed-' . $kind . '-database.zip', $password);
        try {
            $archive->verifyOnly();
            $this->assertCount(1, $archive->warnings());
            $this->assertStringContainsString('no historical SHA-256 checksum', $archive->warnings()[0]);
            $this->assertCount(5, $archive->entries());
            $this->assertCount(4, $archive->manifest()['entries']);
            foreach ($archive->entries() as $entry) {
                $this->assertArrayNotHasKey('staged_path', $entry);
                if (str_starts_with($entry['path'], 'database/')) {
                    $this->assertSame('legacy-zip', $entry['integrity']);
                    $this->assertNull($entry['sha256']);
                    $this->assertSame('legacy_db', $entry['connection']);
                } else {
                    $this->assertSame('sha256', $entry['integrity']);
                }
            }
            $archive->verifyAndStage();
            $this->assertCount(1, $archive->warnings());
            foreach ($archive->entries() as $entry) {
                if (str_starts_with($entry['path'], 'database/')) {
                    $this->assertSame("SQLite format 3\0", file_get_contents($entry['staged_path'], false, null, 0, 16));
                }
            }
        } finally {
            $archive->close();
        }
    }

    public static function invalidLegacyOmissions(): iterable
    {
        yield 'schema 2 remains strict' => [['schema_version' => 2], [self::DATABASE => 'SELECT 1;']];
        yield 'not a streamed backup' => [['capsule.chunked' => false], [self::DATABASE => 'SELECT 1;']];
        yield 'not a full backup' => [['backup_type' => 'incremental'], [self::DATABASE => 'SELECT 1;']];
        yield 'missing old application shape' => [['app' => []], [self::DATABASE => 'SELECT 1;']];
        yield 'missing old database shape' => [['database.include_tables' => null], [self::DATABASE => 'SELECT 1;']];
        yield 'unknown connection' => [[], ['database/other_2025-01-02_03-04-14.sql' => 'SELECT 1;']];
        yield 'untimestamped database' => [[], ['database/legacy_db.sql' => 'SELECT 1;']];
        yield 'invalid timestamp' => [[], ['database/legacy_db_2025-02-31_03-04-14.sql' => 'SELECT 1;']];
        yield 'empty database never emitted by old writer' => [[], [self::DATABASE => '']];
        yield 'extra file' => [[], ['files/unlisted.txt' => 'unlisted']];
        yield 'arbitrary extra member' => [[], ['unexpected.txt' => 'unlisted']];
        yield 'multiple dumps for a connection' => [[], [self::DATABASE => 'SELECT 1;', 'database/legacy_db_2025-01-02_03-04-15.sql' => 'SELECT 2;']];
        yield 'traversal' => [[], ['database/../legacy_db_2025-01-02_03-04-14.sql' => 'SELECT 1;']];
        yield 'schema 2 case duplicate' => [['schema_version' => 2], [self::DATABASE => 'SELECT 1;', strtoupper(self::DATABASE) => 'SELECT 2;']];
    }

    #[DataProvider('invalidLegacyOmissions')]
    public function test_legacy_fallback_accepts_only_old_writer_database_omissions(array $changes, array $members): void
    {
        $manifest = $this->legacyManifest();
        foreach ($changes as $key => $value) {
            $parts = explode('.', $key);
            if (count($parts) === 2) {
                if ($value === null) {
                    unset($manifest[$parts[0]][$parts[1]]);
                } else {
                    $manifest[$parts[0]][$parts[1]] = $value;
                }
            } else {
                $manifest[$key] = $value;
            }
        }
        $this->assertRejected($this->archive($members, $manifest));
    }

    public static function invalidChecksums(): iterable
    {
        foreach ([1, 2] as $schema) {
            foreach ([null, '', 'unknown', str_repeat('a', 63), str_repeat('0', 64)] as $checksum) {
                yield 'schema ' . $schema . ', ' . var_export($checksum, true) => [$schema, $checksum];
            }
        }
    }

    #[DataProvider('invalidChecksums')]
    public function test_declared_file_checksums_are_never_relaxed(int $schema, ?string $checksum): void
    {
        $manifest = $this->legacyManifest();
        $manifest['schema_version'] = $schema;
        $manifest['entries'] = [['path' => 'files/hello.txt', 'size' => 5, 'sha256' => $checksum, 'integrity' => 'legacy-zip']];
        $this->assertRejected($this->archive(['files/hello.txt' => 'hello'], $manifest));
    }

    public function test_declared_database_entry_does_not_enable_additional_unlisted_dump(): void
    {
        $contents = 'SELECT 1;';
        $manifest = $this->legacyManifest();
        $declaredName = 'database/legacy_db_2025-01-02_03-04-13.sql';
        $manifest['entries'] = [['path' => $declaredName, 'size' => strlen($contents), 'sha256' => hash('sha256', $contents)]];
        $this->assertRejected($this->archive([$declaredName => $contents, self::DATABASE => $contents], $manifest));
    }

    public function test_integrity_labels_and_staged_paths_cannot_be_supplied_by_manifest(): void
    {
        $manifest = $this->legacyManifest();
        $manifest['entries'] = [['path' => 'files/hello.txt', 'size' => 5, 'sha256' => hash('sha256', 'hello'),
            'integrity' => 'legacy-zip', 'staged_path' => '/untrusted/path']];
        $archive = new RestoreArchive($this->archive(['files/hello.txt' => 'hello'], $manifest));
        try {
            $archive->verifyOnly();
            $this->assertSame('sha256', $archive->entries()['files/hello.txt']['integrity']);
            $this->assertArrayNotHasKey('staged_path', $archive->entries()['files/hello.txt']);
            $this->assertSame([], $archive->warnings());
        } finally {
            $archive->close();
        }
    }

    public function test_actual_previous_release_unix_names_and_case_distinct_paths_are_preserved(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('These historical names require a Unix filesystem.');
        }
        $archive = new RestoreArchive(__DIR__ . '/../Fixtures/LegacyMain/regular-posix-files.zip');
        try {
            $archive->verifyAndStage();
            $this->assertStringContainsString('compatible target filesystem', implode(' ', $archive->warnings()));
            foreach (['colon:name.txt', 'trailing.', 'A.txt', 'a.txt', 'Folder/file.txt', 'folder/file.txt', 'literal\\name.txt'] as $name) {
                $entry = $archive->entries()['files/' . $name];
                $this->assertTrue($entry['legacy_paths']);
                $this->assertSame('sha256', $entry['integrity']);
                $this->assertSame($entry['sha256'], hash_file('sha256', $entry['staged_path']));
            }
        } finally {
            $archive->close();
        }
    }

    public static function originalDefaultAliasArchives(): iterable
    {
        yield 'plain' => ['plain', null];
        yield 'native AES' => ['aes', self::PASSWORD];
    }

    #[DataProvider('originalDefaultAliasArchives')]
    public function test_original_default_alias_is_resolved_from_recorded_member_without_current_application_config(string $kind, ?string $password): void
    {
        $originalDefault = config('database.default');
        config(['database.default' => 'unrelated_current_default']);
        $archive = new RestoreArchive(__DIR__ . '/../Fixtures/LegacyMain/streamed-default-' . $kind . '-database.zip', $password);
        try {
            $archive->verifyOnly();
            $this->assertSame(['default'], $archive->manifest()['database']['connections']);
            $this->assertStringContainsString('Legacy default database connection was recorded as alias_source', implode(' ', $archive->warnings()));
            $database = array_values(array_filter($archive->entries(), static fn ($entry) => str_starts_with($entry['path'], 'database/')));
            $this->assertCount(1, $database);
            $this->assertSame('alias_source', $database[0]['connection']);
            $this->assertSame('legacy-zip', $database[0]['integrity']);
            $this->assertNull($database[0]['sha256']);
        } finally {
            $archive->close();
            config(['database.default' => $originalDefault]);
        }
    }

    public function test_default_placeholder_cannot_admit_multiple_unknown_connections(): void
    {
        $manifest = $this->legacyManifest();
        $manifest['database']['connections'] = ['default'];
        $this->assertRejected($this->archive([self::DATABASE => 'SELECT 1;', 'database/unrelated_2025-01-02_03-04-14.sql' => 'SELECT 2;'], $manifest));
    }

    public static function unixOnlyPaths(): iterable
    {
        foreach (['colon:name.txt', 'a:b.txt', 'C:/name.txt', 'literal\\name.txt', 'trailing.', 'trailing ', 'CON.txt'] as $name) {
            yield $name => [[$name]];
        }
        yield 'case-distinct files' => [['A.txt', 'a.txt']];
    }

    #[DataProvider('unixOnlyPaths')]
    public function test_schema_two_never_trusts_manifest_legacy_path_flags(array $names): void
    {
        $manifest = $this->legacyManifest();
        $manifest['schema_version'] = 2;
        $members = [];
        foreach ($names as $name) {
            $members['files/' . $name] = 'x';
            $manifest['entries'][] = ['path' => 'files/' . $name, 'size' => 1,
                'sha256' => hash('sha256', 'x'), 'legacy_paths' => true];
        }
        $this->assertRejected($this->archive($members, $manifest));
    }

    public function test_schema_two_discards_forged_legacy_path_flag_on_portable_entry(): void
    {
        $manifest = $this->legacyManifest();
        $manifest['schema_version'] = 2;
        $manifest['entries'] = [['path' => 'files/hello.txt', 'size' => 1,
            'sha256' => hash('sha256', 'x'), 'legacy_paths' => true]];
        $archive = new RestoreArchive($this->archive(['files/hello.txt' => 'x'], $manifest));
        try {
            $archive->verifyOnly();
            $this->assertArrayNotHasKey('legacy_paths', $archive->entries()['files/hello.txt']);
        } finally {
            $archive->close();
        }
    }

    public static function corruptionCases(): iterable
    {
        foreach ([false, true] as $stage) {
            yield 'plain stored CRC, stage ' . (int) $stage => [false, ZipArchive::CM_STORE, $stage, ZipArchive::EM_NONE];
            foreach ([ZipArchive::EM_AES_128, ZipArchive::EM_AES_192, ZipArchive::EM_AES_256] as $encryption) {
                foreach ([ZipArchive::CM_STORE, ZipArchive::CM_DEFLATE] as $compression) {
                    yield 'native AES ' . $encryption . ', compression ' . $compression . ', stage ' . (int) $stage => [true, $compression, $stage, $encryption];
                }
            }
        }
    }

    #[DataProvider('corruptionCases')]
    public function test_checksumless_database_still_requires_zip_crc_and_native_aes_authentication(bool $encrypted, int $compression, bool $stage, int $encryption): void
    {
        $path = $this->archive([self::DATABASE => str_repeat('SELECT 1;', 100)], $this->legacyManifest(), $encrypted, $compression, $encryption);
        $this->corruptMember($path, self::DATABASE, $encrypted);
        $archive = new LegacyValidationRestoreArchive($path, $encrypted ? self::PASSWORD : null);
        try {
            $archive->verifyAndStage($stage);
            $this->fail('A corrupt legacy database must be rejected.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('corrupt or its password is incorrect', $e->getMessage());
            $this->assertSame([], $archive->manifest());
            $this->assertSame([], $archive->entries());
            $this->assertSame([], $archive->warnings());
            if ($encrypted) {
                // The native compressed-stream pass must reject the bad tag
                // before decoded plaintext reaches the staging consumer.
                $this->assertArrayNotHasKey(self::DATABASE, $archive->consumedMembers);
            }
            if ($stage) {
                $this->assertNotNull($archive->stagingBeforeClose);
                $this->assertDirectoryDoesNotExist($archive->stagingBeforeClose);
            }
        } finally {
            $archive->close();
        }
    }

    public function test_wrong_password_rejects_checksumless_legacy_database(): void
    {
        $path = $this->archive([self::DATABASE => 'SELECT 1;'], $this->legacyManifest(), true);
        $this->assertRejected($path, 'wrong password');
    }

    public function test_nul_password_requires_explicit_effective_password_migration(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('configure the effective legacy password');
        new RestoreArchive(__DIR__ . '/../Fixtures/LegacyMain/streamed-nul-database.zip', "legacy-prefix\0ignored");
    }

    public static function invalidManifestBudgets(): iterable
    {
        foreach ([0, -1, '0', '01', '1.0', '1e6', ' 100', 1.5, true, null, [], (string) PHP_INT_MAX . '0'] as $index => $value) {
            yield (string) $index => [$value];
        }
    }

    #[DataProvider('invalidManifestBudgets')]
    public function test_manifest_budget_rejects_noncanonical_nonpositive_and_overflow_values(mixed $value): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('--max-manifest-bytes or capsule.restore.max_manifest_bytes');
        RestoreArchive::manifestByteLimit($value);
    }

    public function test_manifest_budget_accepts_positive_integer_or_canonical_decimal(): void
    {
        $this->assertSame(1, RestoreArchive::manifestByteLimit(1));
        $this->assertSame(16777217, RestoreArchive::manifestByteLimit('16777217'));
        $this->assertSame(PHP_INT_MAX, RestoreArchive::manifestByteLimit((string) PHP_INT_MAX));
    }

    private function legacyManifest(): array
    {
        return [
            'schema_version' => 1,
            'generated_at' => '2025-01-02T03:04:14.000000Z',
            'app' => ['name' => 'Legacy fixture', 'env' => 'testing', 'laravel_version' => '12.0', 'host' => null],
            'capsule' => ['version' => null, 'chunked' => true, 'compression_level' => 6, 'encryption_enabled' => false],
            'storage' => ['disk' => 'legacy-fixture', 'backup_path' => 'backups'],
            'database' => ['connections' => ['legacy_db'], 'include_tables' => [], 'exclude_tables' => []],
            'files' => ['paths' => [], 'exclude_paths' => []],
            'entries' => [],
        ];
    }

    private function archive(array $members, array $manifest, bool $encrypted = false, int $compression = ZipArchive::CM_STORE,
        int $encryption = ZipArchive::EM_AES_256): string
    {
        $path = $this->testPath(bin2hex(random_bytes(8)) . '.zip');
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE));
        $members['manifest.json'] = json_encode($manifest, JSON_THROW_ON_ERROR);
        foreach ($members as $name => $contents) {
            $this->assertTrue($zip->addFromString($name, $contents));
            $this->assertTrue($zip->setCompressionName($name, $compression));
            if ($encrypted) {
                $this->assertTrue($zip->setEncryptionName($name, $encryption, self::PASSWORD));
            }
        }
        $this->assertTrue($zip->close());
        return $path;
    }

    private function assertRejected(string $path, ?string $password = null): void
    {
        $archive = new RestoreArchive($path, $password);
        try {
            $archive->verifyOnly();
            $this->fail('A malformed legacy archive must be rejected.');
        } catch (RuntimeException $e) {
            $this->assertNotSame('', $e->getMessage());
            $this->assertSame([], $archive->entries());
            $this->assertSame([], $archive->warnings());
        } finally {
            $archive->close();
        }
    }

    private function corruptMember(string $path, string $name, bool $encrypted): void
    {
        $bytes = file_get_contents($path);
        $end = strrpos($bytes, "PK\x05\x06");
        $this->assertNotFalse($end);
        $offset = unpack('Voffset', substr($bytes, $end + 16, 4))['offset'];
        while (substr($bytes, $offset, 4) === "PK\x01\x02") {
            $lengths = unpack('vname/vextra/vcomment', substr($bytes, $offset + 28, 6));
            if (substr($bytes, $offset + 46, $lengths['name']) === $name) {
                $size = unpack('Vsize', substr($bytes, $offset + 20, 4))['size'];
                $local = unpack('Voffset', substr($bytes, $offset + 42, 4))['offset'];
                $localLengths = unpack('vname/vextra', substr($bytes, $local + 26, 4));
                $byte = $local + 30 + $localLengths['name'] + $localLengths['extra'] + ($encrypted ? $size - 1 : 0);
                $bytes[$byte] = chr(ord($bytes[$byte]) ^ 1);
                $this->assertSame(strlen($bytes), file_put_contents($path, $bytes));
                return;
            }
            $offset += 46 + $lengths['name'] + $lengths['extra'] + $lengths['comment'];
        }
        $this->fail('Fixture database member is missing.');
    }
}

class LegacyValidationRestoreArchive extends RestoreArchive
{
    public ?string $stagingBeforeClose = null;
    public array $consumedMembers = [];

    protected function readMember(string $name, int $expectedSize, callable $consume): void
    {
        parent::readMember($name, $expectedSize, function (#[\SensitiveParameter] string $chunk) use ($name, $consume): void {
            $this->consumedMembers[$name] = true;
            $consume($chunk);
        });
    }

    public function close(): void
    {
        if ($this->stagingDirectory !== null) {
            $this->stagingBeforeClose = $this->stagingDirectory;
        }
        parent::close();
    }
}
