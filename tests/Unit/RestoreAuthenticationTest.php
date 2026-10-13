<?php

namespace Dgtlss\Capsule\Tests\Unit;

use Dgtlss\Capsule\Commands\RestoreCommand;
use Dgtlss\Capsule\Support\RestoreArchive;
use Dgtlss\Capsule\Tests\TestCase;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use SensitiveParameter;
use SensitiveParameterValue;
use ZipArchive;

class RestoreAuthenticationTest extends TestCase
{
    private const PASSWORD = 'native restore authentication password';

    protected function setUp(): void
    {
        parent::setUp();
        if (!ZipArchive::isEncryptionMethodSupported(ZipArchive::EM_AES_256)) {
            $this->markTestSkipped('Native ZIP AES encryption is unavailable.');
        }
        config(['capsule.audit.enabled' => false, 'capsule.security.backup_password' => self::PASSWORD]);
    }

    public static function validArchiveCases(): iterable
    {
        foreach ([1, 2] as $schema) {
            foreach ([ZipArchive::CM_STORE, ZipArchive::CM_DEFLATE] as $compression) {
                foreach ([false, true] as $encrypted) {
                    yield "schema {$schema}, compression {$compression}, encrypted {$encrypted}" => [$schema, $compression, $encrypted];
                }
            }
        }
    }

    #[DataProvider('validArchiveCases')]
    public function test_native_archives_verify_and_stage_with_legacy_compatibility(int $schema, int $compression, bool $encrypted): void
    {
        $files = ['files/empty.txt' => '', 'files/tiny.txt' => 'x', 'files/buffer.bin' => random_bytes(8192),
            'files/large.bin' => str_repeat('boundary', 131072)];
        $path = $this->nativeArchive($files, $schema, $compression, $encrypted);
        $archive = new AuthenticationRestoreArchive($path, $encrypted ? self::PASSWORD : null);
        try {
            $archive->verifyOnly();
            $this->assertSame($schema, $archive->manifest()['schema_version']);
            foreach ($archive->entries() as $entry) {
                $this->assertArrayNotHasKey('staged_path', $entry);
            }
            $archive->verifyAndStage();
            $staged = $archive->entries();
            $this->assertCount(count($files), $staged);
            foreach ($files as $name => $contents) {
                $this->assertSame($contents, file_get_contents($staged[$name]['staged_path']));
            }
            $archive->verifyAndStage();
            $this->assertSame($staged, $archive->entries());
        } finally {
            $archive->close();
        }
        $this->assertRemovedStaging($archive);
    }

    public static function incorrectPasswords(): iterable
    {
        foreach ([null, '', 'incorrect password'] as $password) {
            foreach ([true, false] as $encryptedManifest) {
                yield ($password ?? 'missing') . ', encrypted manifest ' . (int) $encryptedManifest => [$password, $encryptedManifest];
            }
        }
    }

    #[DataProvider('incorrectPasswords')]
    public function test_incorrect_or_missing_password_rejects_manifest_or_empty_payload(?string $password, bool $encryptedManifest): void
    {
        $path = $this->nativeArchive(['files/empty.txt' => ''], encryptedManifest: $encryptedManifest);
        $archive = new AuthenticationRestoreArchive($path, $password);
        $this->assertVerificationFails($archive, true);
        $this->assertSame([], $archive->manifest());
        $this->assertSame([], $archive->entries());
        $this->assertSame([], $archive->deletions());
        if (!$encryptedManifest) {
            $this->assertRemovedStaging($archive);
        }
    }

    public static function corruptedMembers(): iterable
    {
        foreach ([ZipArchive::CM_STORE, ZipArchive::CM_DEFLATE] as $compression) {
            foreach (['manifest.json', 'files/payload.txt', 'files/empty.txt'] as $name) {
                foreach ([false, true] as $stage) {
                    yield "compression {$compression}, {$name}, stage {$stage}" => [$compression, $name, $stage];
                }
            }
        }
    }

    #[DataProvider('corruptedMembers')]
    public function test_tampered_authentication_fails_even_when_plaintext_checksums_still_match(int $compression, string $name, bool $stage): void
    {
        $path = $this->nativeArchive(['files/first.txt' => 'first verified member', 'files/payload.txt' => str_repeat('payload', 2000),
            'files/empty.txt' => ''], compression: $compression);
        $this->tamperAuthentication($path, $name);
        $archive = new AuthenticationRestoreArchive($path, self::PASSWORD);
        $this->assertVerificationFails($archive, $stage);
        $this->assertSame([], $archive->manifest());
        $this->assertSame([], $archive->entries());
        $this->assertSame([], $archive->deletions());
        if ($stage && $name !== 'manifest.json') {
            $this->assertRemovedStaging($archive);
            $this->assertNotEmpty($archive->stagedBeforeClose);
        }
    }

    public function test_password_is_redacted_when_exception_trace_arguments_are_enabled(): void
    {
        $parameter = (new \ReflectionMethod(RestoreArchive::class, '__construct'))->getParameters()[1];
        $this->assertCount(1, $parameter->getAttributes(SensitiveParameter::class));
        $previous = ini_set('zend.exception_ignore_args', '0');
        try {
            new RestoreArchive($this->testPath('missing.zip'), self::PASSWORD);
            $this->fail('Opening a nonexistent archive must fail.');
        } catch (RuntimeException $e) {
            $this->assertInstanceOf(SensitiveParameterValue::class, $e->getTrace()[0]['args'][1]);
            $this->assertStringNotContainsString(self::PASSWORD, $e->getTraceAsString());
        } finally {
            ini_set('zend.exception_ignore_args', $previous);
        }
    }

    public function test_zip_warning_handler_is_restored_after_authentication_failure(): void
    {
        $path = $this->nativeArchive(['files/payload.txt' => 'valid plaintext']);
        $this->tamperAuthentication($path, 'manifest.json');
        $archive = new AuthenticationRestoreArchive($path, self::PASSWORD);
        $handler = static fn (): bool => true;
        set_error_handler($handler);
        try {
            $this->assertVerificationFails($archive, false);
            $previous = set_error_handler(static fn (): bool => false);
            restore_error_handler();
            $this->assertSame($handler, $previous);
        } finally {
            restore_error_handler();
        }
    }

    public static function commandCorruptionCases(): iterable
    {
        foreach ([ZipArchive::CM_STORE, ZipArchive::CM_DEFLATE] as $compression) {
            foreach (['manifest.json', 'files/unselected.txt', 'files/empty.txt'] as $name) {
                foreach ([[], ['--only' => ['selected.txt']], ['--list' => true], ['--dry-run' => true]] as $options) {
                    yield $compression . ' ' . $name . ' ' . json_encode($options) => [$name, $options, $compression];
                }
            }
        }
    }

    #[DataProvider('commandCorruptionCases')]
    public function test_command_authenticates_all_members_before_any_destination_changes(string $name, array $options, int $compression): void
    {
        $target = $this->testPath('target');
        mkdir($target);
        file_put_contents($target . '/selected.txt', 'original destination');
        $path = $this->nativeArchive(['database/testing.sql' => 'SELECT 1;', 'files/selected.txt' => 'replacement',
            'files/unselected.txt' => 'unselected valid plaintext', 'files/empty.txt' => ''], compression: $compression);
        $this->tamperAuthentication($path, $name);
        $command = new AuthenticationRecordingRestoreCommand();
        $command->setLaravel($this->app);
        $this->app['Illuminate\Contracts\Console\Kernel']->registerCommand($command);

        $code = Artisan::call('capsule:restore', array_merge([
            '--archive' => $path, '--target' => $target, '--force' => true, '--format' => 'json',
        ], $options));
        $result = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(1, $code);
        $this->assertSame('failed', $result['status']);
        $this->assertStringContainsString('corrupt', $result['error']);
        $this->assertSame([], $command->imports);
        $this->assertSame('original destination', file_get_contents($target . '/selected.txt'));
        $this->assertSame(['.', '..', 'selected.txt'], scandir($target));
    }

    public function test_successful_native_encrypted_restore_includes_empty_files(): void
    {
        $path = $this->nativeArchive(['files/selected.txt' => 'authenticated', 'files/empty.txt' => '']);
        $code = Artisan::call('capsule:restore', [
            '--archive' => $path, '--target' => $this->testPath('target'), '--force' => true, '--format' => 'json',
        ]);
        $this->assertSame(0, $code, Artisan::output());
        $this->assertSame('authenticated', file_get_contents($this->testPath('target/selected.txt')));
        $this->assertSame('', file_get_contents($this->testPath('target/empty.txt')));
    }

    private function assertVerificationFails(AuthenticationRestoreArchive $archive, bool $stage): void
    {
        try {
            $archive->verifyAndStage($stage);
            $this->fail('Unauthenticated ZIP contents must be rejected.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('corrupt or its password is incorrect', $e->getMessage());
        } finally {
            $archive->close();
        }
    }

    private function assertRemovedStaging(AuthenticationRestoreArchive $archive): void
    {
        $this->assertNotNull($archive->stagingBeforeClose);
        $this->assertDirectoryDoesNotExist($archive->stagingBeforeClose);
        foreach ($archive->stagedBeforeClose as $path) {
            $this->assertFileDoesNotExist($path);
        }
    }

    private function nativeArchive(array $files, int $schema = 2, int $compression = ZipArchive::CM_STORE,
        bool $encrypted = true, bool $encryptedManifest = true): string
    {
        $path = $this->testPath(bin2hex(random_bytes(8)) . '.zip');
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE));
        $entries = [];
        foreach ($files as $name => $contents) {
            $entries[] = ['path' => $name, 'size' => strlen($contents), 'sha256' => hash('sha256', $contents)];
        }
        $files['manifest.json'] = json_encode(['schema_version' => $schema, 'backup_type' => 'full', 'entries' => $entries], JSON_THROW_ON_ERROR);
        foreach ($files as $name => $contents) {
            $this->assertTrue($zip->addFromString($name, $contents));
            $this->assertTrue($zip->setCompressionName($name, $compression));
            if ($encrypted && ($name !== 'manifest.json' || $encryptedManifest)) {
                $this->assertTrue($zip->setEncryptionName($name, ZipArchive::EM_AES_256, self::PASSWORD));
            }
        }
        $this->assertTrue($zip->close());

        return $path;
    }

    private function tamperAuthentication(string $path, string $name): void
    {
        $bytes = file_get_contents($path);
        $end = strrpos($bytes, "PK\x05\x06");
        $this->assertNotFalse($end);
        $offset = unpack('Voffset', substr($bytes, $end + 16, 4))['offset'];
        while (substr($bytes, $offset, 4) === "PK\x01\x02") {
            $lengths = unpack('vname/vextra/vcomment', substr($bytes, $offset + 28, 6));
            $memberName = substr($bytes, $offset + 46, $lengths['name']);
            if ($memberName === $name) {
                $compressedSize = unpack('Vsize', substr($bytes, $offset + 20, 4))['size'];
                $local = unpack('Voffset', substr($bytes, $offset + 42, 4))['offset'];
                $localLengths = unpack('vname/vextra', substr($bytes, $local + 26, 4));
                $authenticationByte = $local + 30 + $localLengths['name'] + $localLengths['extra'] + $compressedSize - 1;
                $bytes[$authenticationByte] = chr(ord($bytes[$authenticationByte]) ^ 1);
                $this->assertSame(strlen($bytes), file_put_contents($path, $bytes));

                return;
            }
            $offset += 46 + $lengths['name'] + $lengths['extra'] + $lengths['comment'];
        }
        $this->fail("Native fixture is missing {$name}.");
    }
}

class AuthenticationRestoreArchive extends RestoreArchive
{
    public ?string $stagingBeforeClose = null;
    public array $stagedBeforeClose = [];

    public function close(): void
    {
        if ($this->stagingDirectory !== null) {
            $this->stagingBeforeClose = $this->stagingDirectory;
            $this->stagedBeforeClose = array_column($this->entries, 'staged_path');
        }
        parent::close();
    }
}

class AuthenticationRecordingRestoreCommand extends RestoreCommand
{
    public array $imports = [];

    protected function importSqlDump(string $connection, string $sqlPath, bool $verbose): void
    {
        $this->imports[] = $connection;
    }
}
