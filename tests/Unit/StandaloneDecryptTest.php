<?php

namespace Dgtlss\Capsule\Tests\Unit;

use Dgtlss\Capsule\Security\EncryptedArchive;
use Dgtlss\Capsule\Support\BackupDecryption;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use ZipArchive;

class StandaloneDecryptTest extends TestCase
{
    private const PASSWORD = 'local fixture password only';
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = realpath(sys_get_temp_dir()) . '/capsule-decrypt-tests-' . bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
    }

    protected function tearDown(): void
    {
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $item) {
            if ($item->isDir() && !$item->isLink()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }
        rmdir($this->directory);
        parent::tearDown();
    }

    public function test_standalone_recovers_a_normal_zip_outside_a_laravel_application(): void
    {
        [$encrypted, $plain] = $this->fixture();
        mkdir($this->directory . '/empty-cwd', 0700);
        [$code, $stdout, $stderr] = $this->runCli([$encrypted, '--output=' . $this->directory . '/recovered.zip', '--password-stdin', '--format=json'], self::PASSWORD . "\n");

        $this->assertSame(0, $code, $stdout . $stderr);
        $this->assertSame('', $stderr);
        $result = json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('success', $result['status']);
        $this->assertSame(EncryptedArchive::FORMAT, $result['format']);
        $this->assertTrue($result['requires_capsule']);
        $this->assertSame('zip', $result['output_format']);
        $this->assertFalse($result['output_requires_capsule']);
        $this->assertSame(1, $result['entries_checked']);
        $this->assertSame(file_get_contents($plain), file_get_contents($result['output']));
        $this->assertSame(0600, fileperms($result['output']) & 0777);
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($result['output']));
        $this->assertSame('recovered data', $zip->getFromName('files/example.txt'));
        $zip->close();
        $this->assertSame([], glob($this->directory . '/.capsule_decrypt_*'));
        $this->assertStringNotContainsString(self::PASSWORD, $stdout . $stderr);
    }

    public function test_temporary_api_returns_verified_private_zip_owned_by_caller(): void
    {
        [$encrypted, $plain] = $this->fixture();
        $temporary = BackupDecryption::decryptToTemporaryFile($encrypted, self::PASSWORD);
        try {
            $this->assertSame(0600, fileperms($temporary) & 0777);
            $this->assertSame(file_get_contents($plain), file_get_contents($temporary));
        } finally {
            unlink($temporary);
        }
    }

    #[DataProvider('corruptions')]
    public function test_no_plaintext_is_published_or_left_staged_after_failure(string $corruption): void
    {
        [$encrypted] = $this->fixture();
        $password = self::PASSWORD;
        $bytes = file_get_contents($encrypted);
        if ($corruption === 'wrong-password') {
            $password = 'incorrect fixture password';
        } elseif ($corruption === 'truncated') {
            file_put_contents($encrypted, substr($bytes, 0, -1));
        } elseif ($corruption === 'missing-final') {
            file_put_contents($encrypted, substr($bytes, 0, -21));
        } elseif ($corruption === 'trailing-data') {
            file_put_contents($encrypted, $bytes . 'trailing bytes');
        } else {
            $bytes[80] = chr(ord($bytes[80]) ^ 1);
            file_put_contents($encrypted, $bytes);
        }
        [$code, $stdout, $stderr] = $this->runCli([$encrypted, '--output=' . $this->directory . '/recovered.zip', '--password-stdin', '--format=json'], $password . "\n");
        $this->assertSame(1, $code, $stdout . $stderr);
        $this->assertSame('failed', json_decode($stdout, true, 512, JSON_THROW_ON_ERROR)['status']);
        $this->assertFileDoesNotExist($this->directory . '/recovered.zip');
        $this->assertSame([], glob($this->directory . '/.capsule_decrypt_*'));
        $this->assertStringNotContainsString($password, $stdout . $stderr);
    }

    public static function corruptions(): array
    {
        return array_map(static fn ($value) => [$value], ['wrong-password', 'truncated', 'missing-final', 'trailing-data', 'tampered']);
    }

    public function test_authenticated_zip_with_bad_checksum_is_not_published(): void
    {
        [$encrypted] = $this->fixture(badChecksum: true);
        try {
            BackupDecryption::decryptToFile($encrypted, $this->directory . '/recovered.zip', self::PASSWORD);
            $this->fail('An invalid checksum must prevent publication.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('checksum', $e->getMessage());
        }
        $this->assertFileDoesNotExist($this->directory . '/recovered.zip');
        $this->assertSame([], glob($this->directory . '/.capsule_decrypt_*'));
    }

    public function test_temporary_api_cleans_plaintext_when_manifest_verification_fails(): void
    {
        [$encrypted] = $this->fixture(badChecksum: true);
        $before = glob(realpath(sys_get_temp_dir()) . '/.capsule_decrypt_*');
        try {
            BackupDecryption::decryptToTemporaryFile($encrypted, self::PASSWORD);
            $this->fail('An invalid checksum must not return a temporary archive.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('checksum', $e->getMessage());
        }
        $this->assertSame($before, glob(realpath(sys_get_temp_dir()) . '/.capsule_decrypt_*'));
    }

    public function test_missing_password_never_uses_environment_or_implicitly_reads_stdin(): void
    {
        [$encrypted] = $this->fixture();
        putenv('CAPSULE_BACKUP_PASSWORD=' . self::PASSWORD);
        try {
            [$code, $stdout, $stderr] = $this->runCli([$encrypted, '--output=' . $this->directory . '/recovered.zip', '--format=json', '--no-interaction'], self::PASSWORD . "\n");
        } finally {
            putenv('CAPSULE_BACKUP_PASSWORD');
        }
        $this->assertSame(1, $code, $stdout . $stderr);
        $result = json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
        $this->assertStringContainsString('password is required', $result['error']);
        $this->assertFileDoesNotExist($this->directory . '/recovered.zip');
        $this->assertSame([], glob($this->directory . '/.capsule_decrypt_*'));
    }

    public function test_empty_password_stdin_fails_without_output(): void
    {
        [$encrypted] = $this->fixture();
        [$code, $stdout, $stderr] = $this->runCli([$encrypted, '--output=' . $this->directory . '/recovered.zip', '--password-stdin', '--format=json']);
        $this->assertSame(1, $code, $stdout . $stderr);
        $this->assertSame('failed', json_decode($stdout, true, 512, JSON_THROW_ON_ERROR)['status']);
        $this->assertFileDoesNotExist($this->directory . '/recovered.zip');
    }

    public function test_password_arguments_are_rejected_without_echoing_them(): void
    {
        foreach ([['--password=' . self::PASSWORD], ['--password', self::PASSWORD]] as $arguments) {
            [$code, $stdout, $stderr] = $this->runCli([...$arguments, '--format=json']);
            $this->assertSame(1, $code);
            $this->assertStringContainsString('Passwords cannot be supplied as arguments', $stdout . $stderr);
            $this->assertStringNotContainsString(self::PASSWORD, $stdout . $stderr);
            $this->assertSame('failed', json_decode($stdout, true, 512, JSON_THROW_ON_ERROR)['status']);
        }
    }

    #[DataProvider('invalidArguments')]
    public function test_parser_failures_do_not_reflect_any_argument_values(array $arguments, string $format): void
    {
        [$code, $stdout, $stderr] = $this->runCli(['source.capsule.enc', ...$arguments, '--format=' . $format, '-vvv']);
        $this->assertSame(1, $code);
        $this->assertSame('', $stderr);
        $this->assertStringContainsString('Invalid decryption arguments', $stdout);
        $this->assertStringNotContainsString(self::PASSWORD, $stdout . $stderr);
        $this->assertStringNotContainsString('Stack trace', $stdout . $stderr);
        if ($format === 'json') {
            $result = json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
            $this->assertSame('failed', $result['status']);
            $this->assertSame(EncryptedArchive::FORMAT, $result['format']);
            $this->assertTrue($result['requires_capsule']);
        }
        $this->assertSame([], glob($this->directory . '/.capsule_decrypt_*'));
    }

    public static function invalidArguments(): array
    {
        $cases = [];
        foreach (['text', 'json'] as $format) {
            foreach ([
                ['--passwrod=' . self::PASSWORD],
                ['--pass=' . self::PASSWORD],
                [self::PASSWORD],
                ['--' . self::PASSWORD],
                ['--password-stdin=' . self::PASSWORD],
            ] as $arguments) {
                $cases[] = [$arguments, $format];
            }
        }

        return $cases;
    }

    public function test_destination_created_during_decryption_is_not_overwritten(): void
    {
        [$encrypted] = $this->fixture();
        $destination = $this->directory . '/recovered.zip';
        [$code, $stdout, $stderr] = $this->runCli([$encrypted, '--output=' . $destination, '--password-stdin', '--format=json'], self::PASSWORD . "\n", function () use ($destination): void {
            $this->waitForStaging();
            file_put_contents($destination, 'created concurrently');
        });
        $this->assertSame(1, $code, $stdout . $stderr);
        $this->assertSame('created concurrently', file_get_contents($destination));
        $this->assertStringContainsString('Cannot publish', json_decode($stdout, true, 512, JSON_THROW_ON_ERROR)['error']);
        $this->assertSame([], glob($this->directory . '/.capsule_decrypt_*'));
    }

    public function test_sigterm_cancellation_cleans_partial_plaintext(): void
    {
        if (!extension_loaded('pcntl')) {
            $this->markTestSkipped('Signal-aware cancellation requires pcntl.');
        }
        [$encrypted] = $this->fixture();
        [$code, $stdout, $stderr] = $this->runCli([$encrypted, '--output=' . $this->directory . '/recovered.zip', '--password-stdin', '--format=json'], self::PASSWORD . "\n", function ($process): void {
            $this->waitForStaging();
            $this->assertTrue(proc_terminate($process, SIGTERM));
        });
        $this->assertSame(1, $code, $stdout . $stderr);
        $this->assertSame('Decryption cancelled.', json_decode($stdout, true, 512, JSON_THROW_ON_ERROR)['error']);
        $this->assertFileDoesNotExist($this->directory . '/recovered.zip');
        $this->assertSame([], glob($this->directory . '/.capsule_decrypt_*'));
    }

    private function waitForStaging(): void
    {
        $deadline = microtime(true) + 5;
        do {
            if (glob($this->directory . '/.capsule_decrypt_*') !== []) {
                return;
            }
            usleep(1000);
        } while (microtime(true) < $deadline);
        $this->fail('The subprocess did not reach private staging.');
    }

    public function test_plain_zip_is_not_misidentified_or_modified(): void
    {
        [, $plain] = $this->fixture();
        $hash = hash_file('sha256', $plain);
        [$code, $stdout, $stderr] = $this->runCli([$plain, '--output=' . $this->directory . '/recovered.zip', '--password-stdin', '--format=json'], self::PASSWORD . "\n");
        $this->assertSame(1, $code, $stdout . $stderr);
        $this->assertStringContainsString('Ordinary ZIPs do not need this command', json_decode($stdout, true, 512, JSON_THROW_ON_ERROR)['error']);
        $this->assertSame($hash, hash_file('sha256', $plain));
        $this->assertFileDoesNotExist($this->directory . '/recovered.zip');
        $this->assertSame([], glob($this->directory . '/.capsule_decrypt_*'));
    }

    public function test_legacy_encrypted_zip_directs_users_to_restore_without_converting_it(): void
    {
        [, $plain] = $this->fixture();
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($plain));
        $zip->setPassword(self::PASSWORD);
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $this->assertTrue($zip->setEncryptionIndex($index, ZipArchive::EM_AES_256));
        }
        $zip->close();
        $hash = hash_file('sha256', $plain);
        [$code, $stdout, $stderr] = $this->runCli([$plain, '--output=' . $this->directory . '/recovered.zip', '--password-stdin', '--format=json'], self::PASSWORD . "\n");
        $this->assertSame(1, $code, $stdout . $stderr);
        $this->assertStringContainsString('use capsule:restore for legacy password-protected ZIPs', json_decode($stdout, true, 512, JSON_THROW_ON_ERROR)['error']);
        $this->assertSame($hash, hash_file('sha256', $plain));
        $this->assertFileDoesNotExist($this->directory . '/recovered.zip');
        $this->assertSame([], glob($this->directory . '/.capsule_decrypt_*'));
    }

    #[DataProvider('unsafeDestinations')]
    public function test_existing_or_symlink_destinations_are_never_overwritten(string $kind): void
    {
        [$encrypted] = $this->fixture();
        file_put_contents($this->directory . '/untouched.txt', 'leave alone');
        $destination = $this->directory . '/recovered.zip';
        if ($kind === 'existing') {
            file_put_contents($destination, 'leave alone');
        } elseif ($kind === 'symlink') {
            symlink($this->directory . '/untouched.txt', $destination);
        } elseif ($kind === 'dangling-symlink') {
            symlink($this->directory . '/missing.txt', $destination);
        } else {
            mkdir($this->directory . '/actual', 0700);
            symlink($this->directory . '/actual', $this->directory . '/linked');
            $destination = $this->directory . '/linked/recovered.zip';
        }
        try {
            BackupDecryption::decryptToFile($encrypted, $destination, self::PASSWORD);
            $this->fail('The destination must be rejected.');
        } catch (RuntimeException $e) {
            $this->assertMatchesRegularExpression('/exists|symbolic links/', $e->getMessage());
        }
        $this->assertSame('leave alone', file_get_contents($this->directory . '/untouched.txt'));
        if ($kind === 'existing') {
            $this->assertSame('leave alone', file_get_contents($destination));
        }
        $this->assertFileDoesNotExist($this->directory . '/actual/recovered.zip');
        $this->assertSame([], glob($this->directory . '/.capsule_decrypt_*'));
    }

    public static function unsafeDestinations(): array
    {
        return array_map(static fn ($value) => [$value], ['existing', 'symlink', 'dangling-symlink', 'symlink-parent']);
    }

    public function test_source_symlink_is_rejected_without_plaintext(): void
    {
        [$encrypted] = $this->fixture();
        symlink($encrypted, $this->directory . '/linked.capsule.enc');
        try {
            BackupDecryption::decryptToFile($this->directory . '/linked.capsule.enc', $this->directory . '/recovered.zip', self::PASSWORD);
            $this->fail('Symlink source should fail.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('symbolic links', $e->getMessage());
        }
        $this->assertFileDoesNotExist($this->directory . '/recovered.zip');
        $this->assertSame([], glob($this->directory . '/.capsule_decrypt_*'));
    }

    public function test_help_explicitly_discloses_capsule_and_plaintext_disk_requirements(): void
    {
        [$code, $stdout, $stderr] = $this->runCli(['--help']);
        $this->assertSame(0, $code, $stderr);
        $this->assertStringContainsString('Capsule required to decrypt .capsule.enc; result is a normal ZIP', $stdout);
        $this->assertStringContainsString('plaintext ZIP to disk', $stdout);
        $this->assertStringContainsString('publishes only after authentication and checksum verification', $stdout);
        $this->assertStringContainsString('--password-stdin', $stdout);
        $this->assertStringNotContainsString('--password=', $stdout);
    }

    private function fixture(bool $badChecksum = false): array
    {
        $plain = $this->directory . '/original.zip';
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($plain, ZipArchive::CREATE | ZipArchive::OVERWRITE));
        $zip->addFromString('files/example.txt', 'recovered data');
        $zip->addFromString('manifest.json', json_encode(['schema_version' => 2, 'entries' => [[
            'path' => 'files/example.txt', 'size' => strlen('recovered data'),
            'sha256' => $badChecksum ? str_repeat('0', 64) : hash('sha256', 'recovered data'),
        ]]], JSON_THROW_ON_ERROR));
        $zip->close();
        $codec = new EncryptedArchive(self::PASSWORD);
        try {
            $encrypted = $this->directory . '/backup.capsule.enc';
            file_put_contents($encrypted, implode('', iterator_to_array($codec->encrypt([file_get_contents($plain)]))));
        } finally {
            $codec->close();
        }

        return [$encrypted, $plain];
    }

    private function runCli(array $arguments, string $stdin = '', ?callable $whileRunning = null): array
    {
        $pipes = [];
        $process = proc_open([PHP_BINARY, dirname(__DIR__, 2) . '/bin/capsule-decrypt', ...$arguments], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, is_dir($this->directory . '/empty-cwd') ? $this->directory . '/empty-cwd' : $this->directory);
        $this->assertIsResource($process);
        fwrite($pipes[0], $stdin);
        fclose($pipes[0]);
        if ($whileRunning !== null) {
            $whileRunning($process);
        }
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $stdout, $stderr];
    }
}
