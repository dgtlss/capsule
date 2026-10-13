<?php

namespace Dgtlss\Capsule\Tests\Feature;

use Dgtlss\Capsule\Models\BackupLog;
use Dgtlss\Capsule\Security\EncryptedArchive;
use Dgtlss\Capsule\Services\BackupService;
use Dgtlss\Capsule\Services\ChunkedBackupService;
use Dgtlss\Capsule\Support\BackupDecryption;
use Dgtlss\Capsule\Tests\TestCase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use PDO;
use ZipArchive;

/** These tests exercise real ZIPs, local filesystem writes, SQLite and restore. */
class BackupRestoreIntegrationTest extends TestCase
{
    public function test_standard_backup_restores_files_and_a_live_sqlite_database(): void
    {
        $this->assertRecoveryRoundTrip(BackupService::class);
    }

    public function test_streamed_backup_restores_complete_files_and_sqlite(): void
    {
        $this->assertRecoveryRoundTrip(ChunkedBackupService::class);
    }

    public function test_encrypted_standard_backup_restores_with_the_password(): void
    {
        config(['capsule.security.encrypt_backups' => true, 'capsule.security.backup_password' => 'integration-test-password']);
        $this->assertRecoveryRoundTrip(BackupService::class);
    }

    public function test_encrypted_streamed_backup_restores_files_and_live_sqlite_with_the_password(): void
    {
        config(['capsule.security.encrypt_backups' => true, 'capsule.security.backup_password' => 'integration-test-password']);
        $this->assertRecoveryRoundTrip(ChunkedBackupService::class);
    }

    public function test_public_no_local_encryption_command_reports_capsule_requirement_and_restores_without_catalog(): void
    {
        $password = 'public-cli-recovery-password';
        $source = $this->testPath('cli-source');
        mkdir($source, 0700, true);
        $files = ['private.txt' => 'Private CLI recovery payload', 'binary.bin' => random_bytes(140003), 'empty.txt' => ''];
        foreach ($files as $name => $bytes) {
            file_put_contents($source . '/' . $name, $bytes);
        }
        config([
            'capsule.files.enabled' => true,
            'capsule.files.paths' => [$source],
            'capsule.files.exclude_paths' => [],
            'capsule.security.encrypt_backups' => false,
            'capsule.security.backup_password' => $password,
            'capsule.chunked_backup.chunk_size' => 32768,
            'capsule.retention.cleanup_enabled' => false,
            'capsule.audit.enabled' => false,
        ]);

        $code = Artisan::call('capsule:backup', ['--no-local' => true, '--encrypt' => true, '--format' => 'json']);
        $output = Artisan::output();
        $result = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(0, $code, $output);
        $this->assertSame('success', $result['status']);
        $this->assertSame(EncryptedArchive::FORMAT, $result['archive_format']);
        $this->assertTrue($result['requires_capsule']);
        $this->assertStringNotContainsString($password, $output);
        $backup = BackupLog::successful()->latest('id')->firstOrFail();
        $this->assertStringEndsWith(EncryptedArchive::EXTENSION, $backup->file_path);
        $path = Storage::disk('capsule-test')->path($backup->file_path);
        $this->assertTrue(EncryptedArchive::isEncryptedFile($path));
        $bytes = file_get_contents($path);
        foreach (['manifest.json', 'private.txt', 'files/root-0/', $files['private.txt'], $password] as $secret) {
            $this->assertStringNotContainsString($secret, $bytes);
        }
        $this->assertSame([$backup->file_path], Storage::disk('capsule-test')->allFiles('backups'));

        // The original opaque backup is sufficient even after the application loses its catalog.
        Schema::rename('backup_logs', 'unavailable_backup_logs');
        try {
            foreach (['' => 'missing-password', 'wrong-password' => 'wrong-password'] as $badPassword => $label) {
                config(['capsule.security.backup_password' => $badPassword]);
                $target = $this->testPath($label);
                $code = Artisan::call('capsule:restore', [
                    '--archive' => $path, '--target' => $target, '--force' => true, '--format' => 'json',
                ]);
                $failure = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
                $this->assertSame(1, $code);
                $this->assertSame('failed', $failure['status']);
                $this->assertMatchesRegularExpression('/password|authentication/i', $failure['error']);
                $this->assertDirectoryDoesNotExist($target);
            }
            config(['capsule.security.backup_password' => $password]);
            $code = Artisan::call('capsule:restore', [
                '--archive' => $path, '--target' => $this->testPath('cli-recovery'), '--force' => true, '--format' => 'json',
            ]);
            $restored = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
            $this->assertSame(0, $code, json_encode($restored));
            $this->assertSame('success', $restored['status']);
            $this->assertNull($restored['backup_id']);
            $this->assertSame(count($files), $restored['files_restored']);

            // Capsule decryption also creates an ordinary ZIP usable without an encryption password.
            $decrypted = $this->testPath('decrypted-cli.zip');
            $decryption = BackupDecryption::decryptToFile($path, $decrypted, $password);
            $this->assertSame('zip', $decryption['output_format']);
            $this->assertFalse($decryption['output_requires_capsule']);
            $this->assertSame(0600, fileperms($decrypted) & 0777);
            config(['capsule.security.backup_password' => '']);
            $this->assertSame(0, Artisan::call('capsule:restore', [
                '--archive' => $decrypted, '--target' => $this->testPath('decrypted-recovery'), '--force' => true,
            ]), Artisan::output());
        } finally {
            Schema::rename('unavailable_backup_logs', 'backup_logs');
        }

        foreach ($files as $name => $bytes) {
            foreach (['cli-recovery', 'decrypted-recovery'] as $target) {
                $this->assertSame($bytes, file_get_contents($this->testPath($target . '/root-0/' . $name)));
            }
        }
    }

    public function test_generated_incremental_restores_changes_and_deletions_without_the_catalog(): void
    {
        $source = $this->testPath('incremental-source');
        mkdir($source, 0700, true);
        file_put_contents($source . '/unchanged.txt', 'keep');
        file_put_contents($source . '/changed.txt', 'before');
        file_put_contents($source . '/removed.txt', 'remove');
        config([
            'capsule.files.enabled' => true,
            'capsule.files.paths' => [$source],
            'capsule.files.exclude_paths' => [],
            'capsule.retention.cleanup_enabled' => false,
            'capsule.audit.enabled' => false,
        ]);

        $service = app(BackupService::class);
        $this->assertTrue($service->run(), $service->getLastError() ?? 'Full backup failed.');
        $full = BackupLog::successful()->latest('id')->firstOrFail();
        $timestamp = filemtime($source . '/changed.txt');
        file_put_contents($source . '/changed.txt', 'after!');
        touch($source . '/changed.txt', $timestamp);
        unlink($source . '/removed.txt');
        file_put_contents($source . '/added.txt', 'new');
        clearstatcache();

        $service->setIncremental(true);
        $this->assertTrue($service->run(), $service->getLastError() ?? 'Incremental backup failed.');
        $incremental = BackupLog::successful()->latest('id')->firstOrFail();
        $this->assertNotSame($full->file_path, $incremental->file_path);
        $fullPath = Storage::disk('capsule-test')->path($full->file_path);
        $incrementalPath = Storage::disk('capsule-test')->path($incremental->file_path);
        Schema::rename('backup_logs', 'unavailable_backup_logs');

        try {
            $this->artisan('capsule:restore', [
                '--archive' => $incrementalPath,
                '--base-archive' => $fullPath,
                '--target' => $this->testPath('incremental-recovery'),
                '--force' => true,
            ])->assertExitCode(0);
        } finally {
            Schema::rename('unavailable_backup_logs', 'backup_logs');
        }

        $target = $this->testPath('incremental-recovery/root-0');
        $this->assertSame('keep', file_get_contents($target . '/unchanged.txt'));
        $this->assertSame('after!', file_get_contents($target . '/changed.txt'));
        $this->assertSame('new', file_get_contents($target . '/added.txt'));
        $this->assertFileDoesNotExist($target . '/removed.txt');
    }

    private function assertRecoveryRoundTrip(string $serviceClass): void
    {
        $firstRoot = $this->testPath('sources/uploads');
        $secondRoot = $this->testPath('sources/private');
        mkdir($firstRoot, 0700, true);
        mkdir($secondRoot, 0700, true);
        // Incompressible binary data spans multiple configured stream chunks.
        $binary = random_bytes(1024 * 1024 + 31);
        file_put_contents($firstRoot . '/same.bin', $binary);
        file_put_contents($secondRoot . '/same.bin', "private upload\0with a duplicate basename");

        $source = $this->testPath('source.sqlite');
        $pdo = new PDO('sqlite:' . $source);
        $pdo->exec('PRAGMA journal_mode=WAL');
        $pdo->exec('PRAGMA wal_autocheckpoint=0');
        $pdo->exec('CREATE TABLE recovery_records (id INTEGER PRIMARY KEY, payload TEXT)');
        $pdo->exec("INSERT INTO recovery_records (payload) VALUES ('committed while WAL is open')");

        config([
            'database.connections.backup-source' => ['driver' => 'sqlite', 'database' => $source, 'prefix' => ''],
            'database.connections.recovery-target' => ['driver' => 'sqlite', 'database' => $this->testPath('restored.sqlite'), 'prefix' => ''],
            'capsule.database.enabled' => true,
            'capsule.database.connections' => ['backup-source'],
            'capsule.files.enabled' => true,
            'capsule.files.paths' => [$firstRoot, $secondRoot],
            'capsule.files.exclude_paths' => [],
            'capsule.chunked_backup.chunk_size' => 32768,
            'capsule.retention.days' => 30,
            'capsule.retention.count' => 10,
        ]);

        $service = app($serviceClass);
        $this->assertTrue($service->run(), $service->getLastError() ?? 'Backup failed.');
        $backup = BackupLog::where('status', 'success')->latest('id')->firstOrFail();
        $archive = Storage::disk('capsule-test')->path($backup->file_path);
        $this->assertGreaterThan(0, filesize($archive));
        $decrypted = null;
        $isEnvelope = EncryptedArchive::isEncryptedFile($archive);
        if ($serviceClass === ChunkedBackupService::class && config('capsule.security.encrypt_backups')) {
            $this->assertTrue($isEnvelope);
            $this->assertStringEndsWith(EncryptedArchive::EXTENSION, $archive);
            $decrypted = BackupDecryption::decryptToTemporaryFile($archive, config('capsule.security.backup_password'));
        }
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($decrypted ?? $archive));
        try {
            if (config('capsule.security.encrypt_backups') && !$isEnvelope) {
                // Preserve legacy native password-protected ZIP compatibility.
                $this->assertSame(BackupService::class, $serviceClass);
                $this->assertStringEndsWith('.zip', $archive);
                $this->assertTrue($zip->setPassword(config('capsule.security.backup_password')));
                for ($index = 0; $index < $zip->numFiles; $index++) {
                    $this->assertSame(ZipArchive::EM_AES_256, $zip->statIndex($index)['encryption_method']);
                }
            }
            if ($isEnvelope) {
                for ($index = 0; $index < $zip->numFiles; $index++) {
                    $this->assertSame(ZipArchive::EM_NONE, $zip->statIndex($index)['encryption_method']);
                }
            }
            $manifest = json_decode($zip->getFromName('manifest.json'), true, 512, JSON_THROW_ON_ERROR);
            $this->assertSame(2, $manifest['schema_version']);
            $this->assertSame($binary, $zip->getFromName('files/root-0/same.bin'));
            $this->assertSame("private upload\0with a duplicate basename", $zip->getFromName('files/root-1/same.bin'));
            $databaseEntries = array_values(array_filter($manifest['entries'], fn (array $entry) => str_starts_with($entry['path'], 'database/')));
            $this->assertCount(1, $databaseEntries);
            $this->assertStringStartsWith("SQLite format 3\0", $zip->getFromName($databaseEntries[0]['path']));
            foreach ($manifest['entries'] as $entry) {
                $contents = $zip->getFromName($entry['path']);
                $this->assertSame(strlen($contents), $entry['size']);
                $this->assertSame(hash('sha256', $contents), $entry['sha256']);
            }
        } finally {
            $zip->close();
            if ($decrypted !== null) {
                unlink($decrypted);
            }
        }

        $this->artisan('capsule:restore', [
            'id' => $backup->id,
            '--target' => $this->testPath('recovered-files'),
            '--connection' => 'recovery-target',
            '--force' => true,
        ])->assertExitCode(0);

        $this->assertSame($binary, file_get_contents($this->testPath('recovered-files/root-0/same.bin')));
        $this->assertSame("private upload\0with a duplicate basename", file_get_contents($this->testPath('recovered-files/root-1/same.bin')));
        $restored = new PDO('sqlite:' . $this->testPath('restored.sqlite'));
        $this->assertSame('committed while WAL is open', $restored->query('SELECT payload FROM recovery_records')->fetchColumn());
        $restored = null;
        $pdo = null;
        DB::purge('backup-source');
        DB::purge('recovery-target');
    }
}
