<?php

namespace Dgtlss\Capsule\Tests\Feature;

use Dgtlss\Capsule\Models\BackupLog;
use Dgtlss\Capsule\Security\EncryptedArchive;
use Dgtlss\Capsule\Support\RestoreArchive;
use Dgtlss\Capsule\Tests\TestCase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use ZipArchive;

/** A trusted encrypted format must survive temporary download names and dispatch. */
class EncryptedFormatExpectationTest extends TestCase
{
    private const PASSWORD = 'format-expectation-test-password';
    private const PAYLOAD = 'verified backup payload';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'capsule.security.backup_password' => self::PASSWORD,
            'capsule.audit.enabled' => false,
        ]);
        mkdir($this->testPath('target'), 0700, true);
        file_put_contents($this->testPath('target/restored.txt'), 'existing value');
        file_put_contents($this->testPath('target/untouched.txt'), 'keep unchanged');
    }

    public function test_plaintext_zip_renamed_as_encrypted_is_rejected_by_direct_reader_and_local_restore(): void
    {
        $plain = $this->plaintextArchive();
        foreach (['renamed.capsule.enc', 'renamed.CAPSULE.ENC'] as $filename) {
            $path = $this->testPath($filename);
            copy($plain, $path);
            $archive = null;
            try {
                $archive = new RestoreArchive($path, self::PASSWORD);
                $archive->verifyOnly();
                $this->fail('A plaintext ZIP was accepted under an encrypted filename.');
            } catch (RuntimeException $error) {
                $this->assertStringContainsString('plaintext ZIP substitution', $error->getMessage());
            } finally {
                $archive?->close();
            }
            $this->assertRestoreRejected(['--archive' => $path], 'plaintext ZIP substitution');
        }
    }

    public function test_explicit_expected_format_rejects_plaintext_with_an_uninformative_temporary_name(): void
    {
        $path = $this->plaintextArchive('download-without-extension');
        $this->assertSame(EncryptedArchive::FORMAT, RestoreArchive::formatExpectation('remote/object.capsule.enc'));
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('plaintext ZIP substitution');
        new RestoreArchive($path, self::PASSWORD, EncryptedArchive::FORMAT);
    }

    public function test_explicit_remote_restore_preserves_encrypted_suffix_after_temporary_download(): void
    {
        $plain = $this->plaintextArchive();
        foreach (['backups/replaced.capsule.enc', 'backups/replaced.CAPSULE.ENC'] as $path) {
            Storage::disk('capsule-test')->put($path, file_get_contents($plain));
            $this->assertRestoreRejected(['--disk' => 'capsule-test', '--path' => $path], 'plaintext ZIP substitution');
        }
    }

    #[DataProvider('encryptedFormatIndicators')]
    public function test_catalog_encryption_requirement_rejects_plaintext_downgrade_in_restore_inspect_and_verify(string $path, array $metadata): void
    {
        $backup = $this->recordArchive($this->plaintextArchive(), $path, $metadata);
        // Keep the recorded hash valid: this test must reject the format itself,
        // not merely reject a stale catalog checksum for the substituted ZIP.
        $this->assertSame($backup->metadata['archive_sha256'], hash_file('sha256', Storage::disk('capsule-test')->path($path)));
        $this->assertRestoreRejected(['id' => $backup->id], 'plaintext ZIP substitution');

        $code = Artisan::call('capsule:inspect', ['id' => $backup->id, '--format' => 'json']);
        $inspection = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(1, $code, json_encode($inspection));
        $this->assertSame('failed', $inspection['status']);
        $this->assertStringContainsString('plaintext ZIP substitution', $inspection['error']);
        $this->assertArrayNotHasKey('manifest', $inspection);

        $code = Artisan::call('capsule:verify', ['--id' => $backup->id, '--format' => 'json']);
        $verification = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR)[0];
        $this->assertSame(1, $code, json_encode($verification));
        $this->assertSame('failed', $verification['status']);
        $this->assertStringContainsString('plaintext ZIP substitution', $verification['error']);
        $this->assertTargetsUnchanged();
    }

    public static function encryptedFormatIndicators(): array
    {
        return [
            'source suffix' => ['backups/expected.capsule.enc', []],
            'requires Capsule without suffix' => ['backups/opaque-object', ['requires_capsule' => true]],
            'recorded archive format without suffix' => ['backups/ordinary-name.zip', ['archive_format' => EncryptedArchive::FORMAT]],
            'recorded archive encryption without suffix' => ['backups/encrypted-object', ['archive_encryption' => EncryptedArchive::FORMAT]],
            'stronger marker overrides zip metadata' => ['backups/conflicting.zip', ['requires_capsule' => true, 'archive_format' => 'zip']],
            'source suffix overrides zip metadata' => ['backups/conflicting.capsule.enc', ['archive_format' => 'zip', 'requires_capsule' => false]],
        ];
    }

    public function test_valid_ciphertext_with_a_mismatched_recorded_hash_is_rejected_before_destination_writes(): void
    {
        $encrypted = $this->encryptedArchive();
        // Establish that the bytes and their embedded manifest are fully authentic.
        $archive = new RestoreArchive($encrypted, self::PASSWORD);
        try {
            $archive->verifyOnly();
            $this->assertCount(1, $archive->entries());
        } finally {
            $archive->close();
        }
        $backup = $this->recordArchive($encrypted, 'backups/authentic.capsule.enc', [
            'archive_format' => EncryptedArchive::FORMAT,
            'requires_capsule' => true,
            'archive_sha256' => str_repeat('0', 64),
        ]);

        $this->assertRestoreRejected(['id' => $backup->id], 'recorded checksum');
        $this->assertRestoreRejected(['id' => $backup->id], 'recorded checksum', 'new-target');
        $this->assertDirectoryDoesNotExist($this->testPath('new-target'));
    }

    public function test_authentic_envelope_without_encrypted_suffix_restores_when_the_catalog_requires_capsule(): void
    {
        $backup = $this->recordArchive($this->encryptedArchive(), 'backups/opaque-object', [
            'archive_format' => EncryptedArchive::FORMAT,
            'requires_capsule' => true,
        ]);
        $code = Artisan::call('capsule:restore', [
            'id' => $backup->id, '--target' => $this->testPath('authentic-target'), '--force' => true, '--format' => 'json',
        ]);
        $this->assertSame(0, $code, Artisan::output());
        $this->assertSame(self::PAYLOAD, file_get_contents($this->testPath('authentic-target/restored.txt')));
    }

    public function test_download_staging_in_a_symlinked_directory_is_canonical_and_decryptable(): void
    {
        $directory = $this->testPath('temporary');
        $link = $this->testPath('temporary-link');
        mkdir($directory, 0700);
        if (!@symlink($directory, $link)) {
            $this->markTestSkipped('Creating directory symlinks is unavailable.');
        }
        $temporary = tempnam($link, 'capsule_restore_');
        $archive = null;
        try {
            $this->assertSame(realpath($temporary), $temporary);
            copy($this->encryptedArchive(), $temporary);
            $archive = new RestoreArchive($temporary, self::PASSWORD, EncryptedArchive::FORMAT);
            $archive->verifyOnly();
            $this->assertCount(1, $archive->entries());
        } finally {
            $archive?->close();
            unlink($temporary);
        }
    }

    #[DataProvider('ordinaryZipEncryption')]
    public function test_plain_and_native_encrypted_zips_remain_restorable_without_an_envelope_requirement(bool $legacyEncrypted): void
    {
        $path = $this->plaintextArchive();
        if ($legacyEncrypted) {
            if (!ZipArchive::isEncryptionMethodSupported(ZipArchive::EM_AES_256, true)) {
                $this->markTestSkipped('Native AES ZIP encryption is unavailable.');
            }
            $zip = new ZipArchive();
            $this->assertTrue($zip->open($path));
            $this->assertTrue($zip->setPassword(self::PASSWORD));
            foreach (['manifest.json', 'files/restored.txt'] as $name) {
                $this->assertTrue($zip->setEncryptionName($name, ZipArchive::EM_AES_256));
            }
            $this->assertTrue($zip->close());
        }
        $backup = $this->recordArchive($path, 'backups/compatible.zip', ['archive_format' => 'zip', 'requires_capsule' => false]);
        foreach ([['--archive' => $path], ['id' => $backup->id]] as $source) {
            $this->assertSame(0, Artisan::call('capsule:restore', $source + [
                '--target' => $this->testPath('target'), '--force' => true, '--format' => 'json',
            ]), Artisan::output());
            $this->assertSame(self::PAYLOAD, file_get_contents($this->testPath('target/restored.txt')));
            $this->assertSame('keep unchanged', file_get_contents($this->testPath('target/untouched.txt')));
        }
        $this->assertSame(0, Artisan::call('capsule:inspect', ['id' => $backup->id, '--format' => 'json']), Artisan::output());
        $this->assertSame(0, Artisan::call('capsule:verify', ['--id' => $backup->id, '--format' => 'json']), Artisan::output());
    }

    public static function ordinaryZipEncryption(): array
    {
        return ['plain ZIP' => [false], 'legacy native AES ZIP' => [true]];
    }

    private function plaintextArchive(string $filename = 'plaintext.zip'): string
    {
        $path = $this->testPath($filename);
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($path, ZipArchive::CREATE | ZipArchive::EXCL));
        $this->assertTrue($zip->addFromString('files/restored.txt', self::PAYLOAD));
        $this->assertTrue($zip->addFromString('manifest.json', json_encode([
            'schema_version' => 1,
            'entries' => [['path' => 'files/restored.txt', 'size' => strlen(self::PAYLOAD), 'sha256' => hash('sha256', self::PAYLOAD)]],
        ], JSON_THROW_ON_ERROR)));
        $this->assertTrue($zip->close());
        return $path;
    }

    private function encryptedArchive(): string
    {
        $plain = $this->plaintextArchive();
        $path = $this->testPath('authentic.capsule.enc');
        $output = fopen($path, 'xb');
        $cipher = new EncryptedArchive(self::PASSWORD);
        try {
            foreach ($cipher->encrypt([file_get_contents($plain)]) as $frame) {
                $this->assertSame(strlen($frame), fwrite($output, $frame));
            }
        } finally {
            fclose($output);
            $cipher->close();
        }
        return $path;
    }

    private function recordArchive(string $local, string $remote, array $metadata = []): BackupLog
    {
        $disk = Storage::disk('capsule-test');
        $this->assertTrue($disk->put($remote, file_get_contents($local)));
        return BackupLog::create([
            'status' => 'success', 'started_at' => now(), 'completed_at' => now(),
            'file_path' => $remote, 'file_size' => $disk->size($remote),
            'metadata' => $metadata + [
                'storage' => ['disk' => 'capsule-test', 'backup_path' => 'backups'],
                'archive_sha256' => hash_file('sha256', $local),
            ],
        ]);
    }

    private function assertRestoreRejected(array $source, string $message, string $target = 'target'): void
    {
        $code = Artisan::call('capsule:restore', $source + [
            '--target' => $this->testPath($target), '--force' => true, '--format' => 'json',
        ]);
        $result = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(1, $code, json_encode($result));
        $this->assertSame('failed', $result['status']);
        $this->assertStringContainsString($message, $result['error']);
        $this->assertTargetsUnchanged();
    }

    private function assertTargetsUnchanged(): void
    {
        $this->assertSame('existing value', file_get_contents($this->testPath('target/restored.txt')));
        $this->assertSame('keep unchanged', file_get_contents($this->testPath('target/untouched.txt')));
        $this->assertSame(['restored.txt', 'untouched.txt'], array_values(array_diff(scandir($this->testPath('target')), ['.', '..'])));
    }
}
