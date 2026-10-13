<?php

namespace Dgtlss\Capsule\Tests\Feature;

use Closure;
use Dgtlss\Capsule\Models\BackupLog;
use Dgtlss\Capsule\Security\EncryptedArchive;
use Dgtlss\Capsule\Security\EncryptedChunkCodec;
use Dgtlss\Capsule\Services\ChunkedBackupService;
use Dgtlss\Capsule\Storage\StorageManager;
use Dgtlss\Capsule\Support\BackupDecryption;
use Dgtlss\Capsule\Support\RestoreArchive;
use Dgtlss\Capsule\Tests\TestCase;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Mockery;
use RuntimeException;
use Throwable;
use ZipArchive;

/** Exercise the real encrypted service and inspect bytes at the remote-write boundary. */
class EncryptedStreamingBackupTest extends TestCase
{
    private const PASSWORD = 'streaming-test-only-secret-9!';
    private const CONTENT_MARKER = 'PRIVATE-CAPSULE-PAYLOAD-DO-NOT-UPLOAD-CLEAR';
    private const MANIFEST_MARKER = 'PRIVATE-MANIFEST-APPLICATION-NAME';

    private string $source;
    private array $writes = [];
    private array $failuresRemaining = [];
    private bool $rejectAllArchives = false;
    private bool $leavePartialArchives = false;
    private array $rejectArchivesOnDisks = [];
    private ?Closure $afterFailedArchiveWrite = null;
    private array $interruptedWrites = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->source = $this->testPath('source');
        mkdir($this->source, 0700, true);
        config([
            'app.name' => self::MANIFEST_MARKER,
            'capsule.files.enabled' => true,
            'capsule.files.paths' => [$this->source],
            'capsule.files.exclude_paths' => [],
            'capsule.security.encrypt_backups' => true,
            'capsule.security.backup_password' => self::PASSWORD,
            'capsule.chunked_backup.chunk_size' => 32768,
            'capsule.retention.cleanup_enabled' => false,
            'capsule.audit.enabled' => false,
            'capsule.storage.retries' => 0,
            'capsule.storage.backoff_ms' => 0,
            'capsule.performance.memory_limit' => null,
        ]);
    }

    public function test_every_remote_write_is_encrypted_and_capsule_decryption_produces_a_normal_zip(): void
    {
        // Cross compression windows and chunk boundaries with genuinely incompressible data.
        $binary = random_bytes(1024 * 1024 + 31);
        $files = [
            'large.bin' => $binary,
            'private.txt' => self::CONTENT_MARKER,
            'empty.txt' => '',
            'résumé.txt' => "Unicode name and binary data\0" . self::CONTENT_MARKER,
        ];
        $this->writeSources($files);
        $storage = $this->recordingStorage();
        $service = new ChunkedBackupService($this->app, $storage);

        $this->assertTrue($service->run(), $service->getLastError() ?? 'Encrypted backup failed.');
        $backup = BackupLog::successful()->firstOrFail();
        $this->assertGreaterThan(32, $backup->metadata['chunks_count']);
        $this->assertSame(EncryptedArchive::FORMAT, $backup->metadata['archive_encryption']);
        $this->assertSame(EncryptedArchive::FORMAT, $backup->metadata['archive_format']);
        $this->assertTrue($backup->metadata['requires_capsule']);
        $this->assertStringEndsWith(EncryptedArchive::EXTENSION, $backup->file_path);
        $this->assertSame('xchacha20poly1305-ietf', $backup->metadata['chunk_encryption']['cipher']);
        $this->assertAllWritesEncrypted();
        $this->assertFalse($storage->hasChunkCodec(), 'The per-run encryption key must be released.');
        $this->assertSame([$backup->file_path], Storage::disk('capsule-test')->allFiles('backups'));
        $this->assertArchiveContents($backup->file_path, $files);

        // Verification uses the production reader, including complete member authentication.
        $archive = new RestoreArchive(Storage::disk('capsule-test')->path($backup->file_path), self::PASSWORD);
        try {
            $archive->verifyOnly();
            $this->assertCount(count($files), $archive->entries());
        } finally {
            $archive->close();
        }
    }

    public function test_missing_and_wrong_passwords_cannot_read_payload_or_verify_the_archive(): void
    {
        $this->writeSources(['private.txt' => self::CONTENT_MARKER, 'empty.txt' => '']);
        $service = new ChunkedBackupService($this->app, $this->recordingStorage());
        $this->assertTrue($service->run(), $service->getLastError() ?? 'Encrypted backup failed.');
        $backup = BackupLog::successful()->firstOrFail();
        $path = Storage::disk('capsule-test')->path($backup->file_path);

        $zip = new ZipArchive();
        $this->assertNotSame(true, @$zip->open($path), 'The encrypted container must not expose a ZIP directory.');
        foreach ([null, 'definitely-the-wrong-password'] as $password) {
            $archive = null;
            try {
                $archive = new RestoreArchive($path, $password);
                $archive->verifyOnly();
                $this->fail('Archive verification accepted a missing or wrong password.');
            } catch (RuntimeException | \InvalidArgumentException $exception) {
                $this->assertMatchesRegularExpression('/password|authentication|decrypt/i', $exception->getMessage());
            } finally {
                $archive?->close();
            }

            $destination = $this->testPath('must-not-publish.zip');
            try {
                BackupDecryption::decryptToFile($path, $destination, $password ?? '');
                $this->fail('Decryption published plaintext without the correct password.');
            } catch (RuntimeException | \InvalidArgumentException $exception) {
                $this->assertMatchesRegularExpression('/password|authentication|decrypt/i', $exception->getMessage());
            }
            $this->assertFileDoesNotExist($destination);
            $this->assertSame([], glob($this->testPath('.capsule_decrypt_*.tmp')));
        }
    }

    public function test_malformed_envelopes_do_not_publish_plaintext_or_restore_files(): void
    {
        $this->writeSources(['private.txt' => self::CONTENT_MARKER . random_bytes(140000)]);
        $service = new ChunkedBackupService($this->app, $this->recordingStorage());
        $this->assertTrue($service->run(), $service->getLastError() ?? 'Encrypted backup failed.');
        $backup = BackupLog::successful()->firstOrFail();
        $original = Storage::disk('capsule-test')->get($backup->file_path);
        $tampered = $original;
        $tampered[100] = chr(ord($tampered[100]) ^ 1);
        $unsupported = $original;
        $unsupported[8] = chr(127);
        foreach (['tampered' => $tampered, 'truncated' => substr($original, 0, -1),
            'missing-final' => substr($original, 0, -21), 'trailing' => $original . 'extra',
            'unsupported' => $unsupported] as $damage => $bytes) {
            $path = $this->testPath($damage . EncryptedArchive::EXTENSION);
            file_put_contents($path, $bytes);
            $destination = $this->testPath($damage . '.zip');
            try {
                BackupDecryption::decryptToFile($path, $destination, self::PASSWORD);
                $this->fail('Accepted ' . $damage . ' encrypted archive.');
            } catch (RuntimeException | \InvalidArgumentException $exception) {
                $this->assertMatchesRegularExpression('/archive|authentication|frame|header|truncat/i', $exception->getMessage());
            }
            $this->assertFileDoesNotExist($destination);
            $this->assertSame([], glob($this->testPath('.capsule_decrypt_*.tmp')));

            $target = $this->testPath('restore-' . $damage);
            $this->artisan('capsule:restore', ['--archive' => $path, '--target' => $target, '--force' => true])->assertExitCode(1);
            $this->assertDirectoryDoesNotExist($target);
        }
    }

    public function test_missing_backup_password_fails_before_any_remote_write(): void
    {
        $this->writeSources(['private.txt' => self::CONTENT_MARKER]);
        config(['capsule.security.backup_password' => '']);
        $storage = $this->recordingStorage();
        $service = new ChunkedBackupService($this->app, $storage);

        $this->assertFalse($service->run());
        $this->assertStringContainsString('password', $service->getLastError());
        $this->assertSame([], $this->writes);
        $this->assertSame([], Storage::disk('capsule-test')->allFiles('backups'));
        $this->assertFalse($storage->hasChunkCodec());
    }

    public function test_explicit_encryption_flag_encrypts_chunks_and_manifest_when_config_is_disabled(): void
    {
        $this->writeSources(['private.txt' => self::CONTENT_MARKER]);
        config(['capsule.security.encrypt_backups' => false]);
        $service = new ChunkedBackupService($this->app, $this->recordingStorage());
        $service->setEncryption(true);
        $this->assertTrue($service->run(), $service->getLastError() ?? 'Explicit encryption failed.');
        $this->assertAllWritesEncrypted();
        $this->assertArchiveContents(BackupLog::successful()->firstOrFail()->file_path, ['private.txt' => self::CONTENT_MARKER]);
    }

    public function test_failed_final_upload_retains_only_authenticated_chunks_that_can_rebuild_the_archive(): void
    {
        $files = ['private.txt' => self::CONTENT_MARKER . random_bytes(100001), 'empty.txt' => ''];
        $this->writeSources($files);
        $this->rejectAllArchives = true;
        $storage = $this->recordingStorage();
        $service = new ChunkedBackupService($this->app, $storage);

        $this->assertFalse($service->run());
        $this->assertStringContainsString('Storage write failed', $service->getLastError());
        $this->assertFalse($storage->hasChunkCodec());
        $backup = BackupLog::failed()->firstOrFail();
        $metadata = $backup->metadata;
        $this->assertNotEmpty($metadata['recovery_chunks']);
        $this->assertStringNotContainsString(self::PASSWORD, json_encode($metadata, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString(self::CONTENT_MARKER, json_encode($metadata, JSON_THROW_ON_ERROR));
        $remoteFiles = Storage::disk('capsule-test')->allFiles('backups');
        $expectedFiles = array_map(fn (array $chunk) => 'backups/' . $chunk['name'], $metadata['recovery_chunks']);
        sort($remoteFiles);
        sort($expectedFiles);
        $this->assertSame($expectedFiles, $remoteFiles);
        $this->assertAllWritesEncrypted();

        // The recorded metadata and password suffice after the original service/key is gone.
        unset($service, $storage);
        $codec = EncryptedChunkCodec::fromMetadata(self::PASSWORD, $metadata['chunk_encryption']);
        $recovery = new StorageManager();
        $recovery->setChunkCodec($codec);
        try {
            foreach ($metadata['recovery_chunks'] as $chunk) {
                $path = 'backups/' . $chunk['name'];
                $bytes = Storage::disk('capsule-test')->get($path);
                $this->assertTrue($chunk['encrypted']);
                $this->assertSame(strlen($bytes), $chunk['stored_size']);
                $this->assertSame(hash('sha256', $bytes), $chunk['stored_sha256']);
                $plaintext = $codec->decrypt($bytes, $path);
                $this->assertSame(strlen($plaintext), $chunk['size']);
                $this->assertSame(hash('sha256', $plaintext), $chunk['sha256']);
            }
            $path = $recovery->collateChunksAdvanced($metadata['recovery_chunks'], 'recovered' . EncryptedArchive::EXTENSION, 1, true, self::PASSWORD);
            $this->assertArchiveContents($path, $files);
        } finally {
            $recovery->setChunkCodec(null);
            $codec->close();
        }

        $wrongCodec = EncryptedChunkCodec::fromMetadata('wrong-recovery-password', $metadata['chunk_encryption']);
        try {
            $first = $metadata['recovery_chunks'][0];
            $path = 'backups/' . $first['name'];
            try {
                $wrongCodec->decrypt(Storage::disk('capsule-test')->get($path), $path);
                $this->fail('Recovery accepted the wrong password.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('authentication failed', $exception->getMessage());
            }
        } finally {
            $wrongCodec->close();
        }
    }

    public function test_partial_final_upload_is_removed_without_deleting_encrypted_recovery_chunks(): void
    {
        $this->writeSources(['private.txt' => self::CONTENT_MARKER . random_bytes(70000)]);
        $this->rejectAllArchives = true;
        $this->leavePartialArchives = true;
        $service = new ChunkedBackupService($this->app, $this->recordingStorage());

        $this->assertFalse($service->run());
        $backup = BackupLog::failed()->firstOrFail();
        $this->assertNotEmpty($backup->metadata['recovery_chunks']);
        $expected = array_map(fn (array $chunk) => 'backups/' . $chunk['name'], $backup->metadata['recovery_chunks']);
        $actual = Storage::disk('capsule-test')->allFiles('backups');
        sort($expected);
        sort($actual);
        $this->assertSame($expected, $actual, 'A partial encrypted archive must not look like a published backup.');
        $this->assertAllWritesEncrypted();
    }

    public function test_partial_replica_failure_cleans_new_archive_objects_and_preserves_primary_recovery_chunks(): void
    {
        $this->writeSources(['private.txt' => self::CONTENT_MARKER . random_bytes(70000)]);
        config([
            'capsule.additional_disks' => ['replica'],
            'filesystems.disks.replica' => ['driver' => 'local', 'root' => $this->testPath('replica'), 'throw' => true],
        ]);
        $this->rejectArchivesOnDisks = ['replica'];
        $this->leavePartialArchives = true;
        $service = new ChunkedBackupService($this->app, $this->recordingStorage(['capsule-test', 'replica']));

        $this->assertFalse($service->run());
        $this->assertStringContainsString('Storage write failed', $service->getLastError());
        $backup = BackupLog::failed()->firstOrFail();
        $expected = array_map(fn (array $chunk) => 'backups/' . $chunk['name'], $backup->metadata['recovery_chunks']);
        $actual = Storage::disk('capsule-test')->allFiles('backups');
        sort($expected);
        sort($actual);
        $this->assertSame($expected, $actual);
        $this->assertSame([], Storage::disk('replica')->allFiles('backups'));
        $this->assertSame(0, BackupLog::successful()->count());
        $this->assertAllWritesEncrypted();
    }

    public function test_tampered_ciphertext_cannot_publish_a_final_archive_even_with_a_recomputed_storage_hash(): void
    {
        $this->writeSources(['private.txt' => self::CONTENT_MARKER . random_bytes(70000)]);
        $storage = $this->recordingStorage();
        $storage->beforeCollation = function (array $chunks): array {
            $path = 'backups/' . $chunks[0]['name'];
            $bytes = Storage::disk('capsule-test')->get($path);
            $bytes[strlen($bytes) - 1] = chr(ord($bytes[strlen($bytes) - 1]) ^ 1);
            Storage::disk('capsule-test')->put($path, $bytes);
            // The authenticated cipher must catch this even if a storage checksum is replaced.
            $chunks[0]['stored_sha256'] = hash('sha256', $bytes);
            return $chunks;
        };
        $service = new ChunkedBackupService($this->app, $storage);

        $this->assertFalse($service->run());
        $this->assertStringContainsString('authentication failed', $service->getLastError());
        $this->assertNoArchiveWritten();
        $this->assertNotEmpty(BackupLog::failed()->firstOrFail()->metadata['recovery_chunks']);
        $this->assertFalse($storage->hasChunkCodec());
    }

    public function test_truncated_or_missing_encrypted_chunks_fail_before_publishing(): void
    {
        foreach (['truncate', 'missing'] as $damage) {
            $this->writeSources(['private.txt' => self::CONTENT_MARKER . random_bytes(70000)]);
            $storage = $this->recordingStorage();
            $storage->beforeCollation = function (array $chunks) use ($damage): array {
                $path = 'backups/' . $chunks[0]['name'];
                $disk = Storage::disk('capsule-test');
                if ($damage === 'truncate') {
                    $disk->put($path, substr($disk->get($path), 0, -1));
                } else {
                    $disk->delete($path);
                }
                return $chunks;
            };
            $service = new ChunkedBackupService($this->app, $storage);
            $this->assertFalse($service->run(), 'Accepted ' . $damage . ' encrypted chunk.');
            $this->assertNoArchiveWritten();
            $this->assertFalse($storage->hasChunkCodec());
        }
    }

    public function test_encrypted_replicas_and_retries_replay_identical_ciphertext(): void
    {
        $files = ['private.txt' => self::CONTENT_MARKER . random_bytes(140003), 'empty.txt' => ''];
        $this->writeSources($files);
        config([
            'capsule.additional_disks' => ['replica'],
            'filesystems.disks.replica' => ['driver' => 'local', 'root' => $this->testPath('replica'), 'throw' => true],
            'capsule.storage.retries' => 1,
        ]);
        // Fail the first chunk and first complete archive separately on the primary disk,
        // then fail the replica's first full upload. All retry streams must start at zero.
        $this->failuresRemaining = ['capsule-test:chunk' => 1, 'capsule-test:archive' => 1, 'replica:archive' => 1];
        $storage = $this->recordingStorage(['capsule-test', 'replica']);
        $service = new ChunkedBackupService($this->app, $storage);
        $this->assertTrue($service->run(), $service->getLastError() ?? 'Encrypted replica/retry failed.');
        $this->assertAllWritesEncrypted();
        $this->assertSame(['capsule-test:chunk' => 0, 'capsule-test:archive' => 0, 'replica:archive' => 0], $this->failuresRemaining);
        $backup = BackupLog::successful()->firstOrFail();
        $archiveWrites = array_values(array_filter($this->writes, fn (array $write) => str_ends_with($write['path'], EncryptedArchive::EXTENSION)));
        $this->assertCount(4, $archiveWrites);
        $this->assertSame(['capsule-test', 'capsule-test', 'replica', 'replica'], array_column($archiveWrites, 'disk'));
        foreach ($archiveWrites as $write) {
            $this->assertSame($archiveWrites[0]['bytes'], $write['bytes']);
        }
        $this->assertSame($this->writes[0]['path'], $this->writes[1]['path']);
        $this->assertSame($this->writes[0]['bytes'], $this->writes[1]['bytes']);
        $this->assertSame(
            Storage::disk('capsule-test')->get($backup->file_path),
            Storage::disk('replica')->get($backup->file_path),
        );
        $this->assertSame([$backup->file_path], Storage::disk('replica')->allFiles('backups'));
        $this->assertArchiveContents($backup->file_path, $files);
    }

    public function test_modified_source_chunk_is_rejected_before_yielding_changed_plaintext_on_an_upload_retry(): void
    {
        $this->writeSources(['private.txt' => self::CONTENT_MARKER . random_bytes(140003)]);
        config(['capsule.storage.retries' => 1]);
        $this->failuresRemaining = ['capsule-test:archive' => 1];
        $storage = $this->recordingStorage();
        $firstChunkPath = null;
        $storage->beforeCollation = function (array $chunks) use (&$firstChunkPath): array {
            $firstChunkPath = 'backups/' . $chunks[0]['name'];
            return $chunks;
        };
        $this->afterFailedArchiveWrite = function () use (&$firstChunkPath): void {
            // The sizing pass and first upload both consumed the authentic source.
            // Damage its last byte only after a failed upload, before regeneration.
            $bytes = Storage::disk('capsule-test')->get($firstChunkPath);
            $bytes[strlen($bytes) - 1] = chr(ord($bytes[strlen($bytes) - 1]) ^ 1);
            Storage::disk('capsule-test')->put($firstChunkPath, $bytes);
        };
        $service = new ChunkedBackupService($this->app, $storage);

        $this->assertFalse($service->run());
        $this->assertStringContainsString('integrity check failed', $service->getLastError());
        $archiveWrites = array_values(array_filter($this->writes, fn (array $write) => str_ends_with($write['path'], EncryptedArchive::EXTENSION)));
        $this->assertCount(1, $archiveWrites, 'Only the initial failed upload may consume the complete archive.');
        $this->assertCount(1, $this->interruptedWrites, 'The retry must stop while rereading its modified source.');
        $retryBytes = $this->interruptedWrites[0]['bytes'];
        // The first source chunk must authenticate before its first plaintext byte
        // reaches ZIP/secretstream. Reusing a nonce must never encrypt changed data.
        $this->assertLessThanOrEqual(64, strlen($retryBytes), 'The retry emitted an encrypted data frame from modified input.');
        $this->assertSame(substr($archiveWrites[0]['bytes'], 0, strlen($retryBytes)), $retryBytes);
        $this->assertSame(0, BackupLog::successful()->count());
        $failed = BackupLog::failed()->firstOrFail();
        $this->assertNotEmpty($failed->metadata['recovery_chunks']);
        foreach (Storage::disk('capsule-test')->allFiles('backups') as $path) {
            $this->assertStringNotContainsString(EncryptedArchive::EXTENSION, $path);
        }
        $this->assertFalse($storage->hasChunkCodec());
        $this->assertAllWritesEncrypted();
    }

    public function test_reusing_a_service_rotates_salts_and_does_not_reuse_entries_or_password_context(): void
    {
        $files = ['first.txt' => self::CONTENT_MARKER];
        $this->writeSources($files);
        $storage = $this->recordingStorage();
        $service = new ChunkedBackupService($this->app, $storage);
        $this->assertTrue($service->run(), $service->getLastError() ?? 'First encrypted run failed.');
        $first = BackupLog::successful()->latest('id')->firstOrFail();
        $firstWrites = $this->writes;
        $this->writes = [];

        $this->assertTrue($service->run(), $service->getLastError() ?? 'Second encrypted run failed.');
        $second = BackupLog::successful()->latest('id')->firstOrFail();
        $this->assertNotSame($first->metadata['backup_id'], $second->metadata['backup_id']);
        $this->assertNotSame($first->metadata['chunk_encryption']['salt'], $second->metadata['chunk_encryption']['salt']);
        $this->assertNotSame($first->file_path, $second->file_path);
        $this->assertNotSame($firstWrites[0]['bytes'], $this->writes[0]['bytes']);
        $firstBytes = Storage::disk('capsule-test')->get($first->file_path);
        $secondBytes = Storage::disk('capsule-test')->get($second->file_path);
        $this->assertNotSame($firstBytes, $secondBytes);
        // Compare the actual independently randomized Argon2id salt and secretstream header.
        $this->assertNotSame(substr($firstBytes, 24, 16), substr($secondBytes, 24, 16));
        $this->assertNotSame(substr($firstBytes, 40, 24), substr($secondBytes, 40, 24));
        $this->assertAllWritesEncrypted();
        $this->assertArchiveContents($first->file_path, $files);
        $this->assertArchiveContents($second->file_path, $files);

        unlink($this->source . '/first.txt');
        $this->writeSources(['second.txt' => 'new run only']);
        config(['capsule.security.encrypt_backups' => false, 'capsule.security.backup_password' => '']);
        $this->writes = [];
        $this->assertTrue($service->run(), $service->getLastError() ?? 'Unencrypted follow-up failed.');
        $third = BackupLog::successful()->latest('id')->firstOrFail();
        $this->assertFalse($storage->hasChunkCodec());
        $this->assertArrayNotHasKey('chunk_encryption', $third->metadata);
        $this->assertArrayNotHasKey('archive_encryption', $third->metadata);
        $zip = $this->openArchive($third->file_path);
        try {
            $this->assertFalse($zip->locateName('files/root-0/first.txt'));
            $this->assertSame('new run only', $zip->getFromName('files/root-0/second.txt'));
            $manifest = json_decode($zip->getFromName('manifest.json'), true, 512, JSON_THROW_ON_ERROR);
            $this->assertFalse($manifest['capsule']['encryption_enabled']);
            $this->assertSame(['files/root-0/second.txt'], array_column($manifest['entries'], 'path'));
        } finally {
            $zip->close();
        }
        $this->assertStringNotContainsString("CAPSULE\x01", $this->writes[0]['bytes']);
    }

    public function test_failed_encrypted_run_can_be_retried_on_the_same_service_without_contaminating_its_manifest(): void
    {
        $this->writeSources(['old.txt' => self::CONTENT_MARKER]);
        $storage = $this->recordingStorage();
        $service = new ChunkedBackupService($this->app, $storage);
        $this->rejectAllArchives = true;
        $this->assertFalse($service->run());
        $failed = BackupLog::failed()->firstOrFail();
        $this->assertFalse($storage->hasChunkCodec());

        $this->rejectAllArchives = false;
        unlink($this->source . '/old.txt');
        $this->writeSources(['new.txt' => self::CONTENT_MARKER . ' second run']);
        $this->assertTrue($service->run(), $service->getLastError() ?? 'Retry failed.');
        $this->assertNull($service->getLastError());
        $success = BackupLog::successful()->firstOrFail();
        $this->assertNotSame($failed->metadata['chunk_encryption']['salt'], $success->metadata['chunk_encryption']['salt']);
        $this->assertArchiveContents($success->file_path, ['new.txt' => self::CONTENT_MARKER . ' second run']);
        foreach ($failed->metadata['recovery_chunks'] as $chunk) {
            $this->assertTrue(Storage::disk('capsule-test')->exists('backups/' . $chunk['name']), 'Retry deleted a prior failed run\'s recovery chunk.');
        }
        $this->assertAllWritesEncrypted();
    }

    private function writeSources(array $files): void
    {
        foreach ($files as $name => $bytes) {
            file_put_contents($this->source . '/' . $name, $bytes);
        }
    }

    private function recordingStorage(array $names = ['capsule-test']): EncryptedStreamingRecordingStorage
    {
        $disks = [];
        foreach ($names as $name) {
            $real = Storage::disk($name);
            $recording = Mockery::mock(Filesystem::class);
            foreach (['exists', 'size', 'readStream', 'delete', 'files'] as $method) {
                $recording->shouldReceive($method)->andReturnUsing(fn (...$arguments) => $real->{$method}(...$arguments));
            }
            $recording->shouldReceive('put')->andReturnUsing(function (string $path, $stream) use ($real, $name): bool {
                $this->assertIsResource($stream, 'Backup writes must use streams.');
                $this->assertSame(0, ftell($stream), 'Each upload/retry must start at byte zero.');
                $metadata = stream_get_meta_data($stream);
                $bytes = '';
                try {
                    while (!feof($stream)) {
                        $part = fread($stream, 8192);
                        $this->assertIsString($part);
                        $bytes .= $part;
                    }
                } catch (Throwable $error) {
                    $this->interruptedWrites[] = ['disk' => $name, 'path' => $path, 'bytes' => $bytes];
                    throw $error;
                }
                $this->writes[] = ['disk' => $name, 'path' => $path, 'bytes' => $bytes, 'uri' => $metadata['uri']];
                $type = str_ends_with($path, EncryptedArchive::EXTENSION) ? 'archive' : 'chunk';
                $key = $name . ':' . $type;
                if (($type === 'archive' && ($this->rejectAllArchives || in_array($name, $this->rejectArchivesOnDisks, true))) || ($this->failuresRemaining[$key] ?? 0) > 0) {
                    if (($this->failuresRemaining[$key] ?? 0) > 0) {
                        $this->failuresRemaining[$key]--;
                    }
                    if ($type === 'archive' && $this->leavePartialArchives) {
                        // A remote adapter may leave an object after consuming only part of a stream.
                        $real->put($path, substr($bytes, 0, 137));
                    }
                    if ($type === 'archive' && $this->afterFailedArchiveWrite !== null) {
                        ($this->afterFailedArchiveWrite)();
                    }
                    return false;
                }
                $this->assertTrue(rewind($stream));
                return $real->put($path, $stream);
            });
            $disks[$name] = $recording;
        }
        return new EncryptedStreamingRecordingStorage($disks);
    }

    private function assertAllWritesEncrypted(): void
    {
        $this->assertNotEmpty($this->writes);
        foreach ($this->writes as $write) {
            foreach ([self::CONTENT_MARKER, self::MANIFEST_MARKER, self::PASSWORD, 'manifest.json', 'private.txt', 'files/root-0/', 'résumé.txt'] as $secret) {
                $this->assertStringNotContainsString($secret, $write['bytes'], 'Plaintext leaked into ' . $write['path']);
            }
            if (str_ends_with($write['path'], EncryptedArchive::EXTENSION)) {
                $this->assertStringStartsWith("CAPSENC\0", $write['bytes']);
                $this->assertStringStartsWith('capsule-archive://', $write['uri'], 'Final encrypted archive was locally spooled.');
            } else {
                $this->assertStringStartsWith("CAPSULE\x01", $write['bytes']);
                $this->assertSame('php://memory', $write['uri'], 'A capture chunk was locally spooled.');
                $this->assertLessThanOrEqual(32768 + EncryptedChunkCodec::OVERHEAD_BYTES, strlen($write['bytes']));
                $this->assertMatchesRegularExpression('#^backups/capsule_chunk_[a-f0-9]{32}_[a-f0-9]{64}\.part[0-9]+$#', $write['path']);
            }
        }
    }

    private function assertNoArchiveWritten(): void
    {
        $this->assertSame([], array_values(array_filter($this->writes, fn (array $write) => str_ends_with($write['path'], EncryptedArchive::EXTENSION))));
        foreach (Storage::disk('capsule-test')->allFiles('backups') as $path) {
            $this->assertStringNotContainsString(EncryptedArchive::EXTENSION, $path);
        }
    }

    private function assertArchiveContents(string $path, array $expectedFiles): void
    {
        $encryptedPath = Storage::disk('capsule-test')->path($path);
        $this->assertTrue(EncryptedArchive::isEncryptedFile($encryptedPath));
        $plaintextPath = BackupDecryption::decryptToTemporaryFile($encryptedPath, self::PASSWORD);
        $this->assertSame(0600, fileperms($plaintextPath) & 0777);
        $zip = new ZipArchive();
        try {
            $this->assertTrue($zip->open($plaintextPath, ZipArchive::CHECKCONS));
            $this->assertSame(count($expectedFiles) + 1, $zip->numFiles);
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $stat = $zip->statIndex($index);
                $this->assertSame(ZipArchive::EM_NONE, $stat['encryption_method'], 'Decrypted ZIP must be readable without Capsule: ' . $stat['name']);
            }
            $manifest = json_decode($zip->getFromName('manifest.json'), true, 512, JSON_THROW_ON_ERROR);
            $this->assertTrue($manifest['capsule']['chunked']);
            $this->assertTrue($manifest['capsule']['encryption_enabled']);
            $this->assertSame(self::MANIFEST_MARKER, $manifest['app']['name']);
            $entries = array_column($manifest['entries'], null, 'path');
            $this->assertCount(count($expectedFiles), $entries);
            foreach ($expectedFiles as $name => $bytes) {
                $entryName = 'files/root-0/' . $name;
                $this->assertSame($bytes, $zip->getFromName($entryName));
                $this->assertSame(strlen($bytes), $entries[$entryName]['size']);
                $this->assertSame(hash('sha256', $bytes), $entries[$entryName]['sha256']);
            }
        } finally {
            $zip->close();
            unlink($plaintextPath);
        }
    }

    private function openArchive(string $path): ZipArchive
    {
        $zip = new ZipArchive();
        $this->assertTrue($zip->open(Storage::disk('capsule-test')->path($path), ZipArchive::CHECKCONS));
        return $zip;
    }
}

/** Test-only storage boundary that preserves all production streaming/collation code. */
class EncryptedStreamingRecordingStorage extends StorageManager
{
    public ?Closure $beforeCollation = null;

    public function __construct(private array $recordingDisks)
    {
        parent::__construct();
    }

    protected function resolveDisk(string $name): Filesystem
    {
        return $this->recordingDisks[$name] ?? parent::resolveDisk($name);
    }

    public function hasChunkCodec(): bool
    {
        return $this->chunkCodec !== null;
    }

    public function collateChunksAdvanced(array $chunks, string $finalFileName, int $compressionLevel = 1, bool $encrypt = false, string $password = ''): string
    {
        if ($this->beforeCollation !== null) {
            $chunks = ($this->beforeCollation)($chunks);
        }
        return parent::collateChunksAdvanced($chunks, $finalFileName, $compressionLevel, $encrypt, $password);
    }
}
