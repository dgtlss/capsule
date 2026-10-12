<?php

namespace Dgtlss\Capsule\Tests\Feature;

use Dgtlss\Capsule\Commands\DecryptCommand;
use Dgtlss\Capsule\Security\EncryptedArchive;
use Dgtlss\Capsule\Tests\TestCase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use ZipArchive;

class DecryptCommandTest extends TestCase
{
    private const PASSWORD = 'feature test password';

    public function test_command_is_registered_and_has_no_literal_password_option(): void
    {
        $this->assertArrayHasKey('capsule:decrypt', Artisan::all());
        $command = new DecryptCommand();
        $this->assertFalse($command->getDefinition()->hasOption('password'));
        $this->assertTrue($command->getDefinition()->hasOption('password-stdin'));
        $this->assertStringContainsString('Capsule required to decrypt .capsule.enc', $command->getHelp());
    }

    public function test_local_decryption_does_not_need_backup_catalog_or_storage_config(): void
    {
        Schema::rename('backup_logs', 'unavailable_decrypt_backup_logs');
        try {
            config(['capsule' => [], 'filesystems.disks' => []]);
            $source = $this->fixture();
            [$code, $result] = $this->decrypt($source, self::PASSWORD);
            $this->assertSame(0, $code, $result['error'] ?? '');
            $this->assertSame('success', $result['status']);
            $this->assertSame(EncryptedArchive::FORMAT, $result['format']);
            $this->assertTrue($result['requires_capsule']);
            $this->assertFalse($result['output_requires_capsule']);
            $this->assertSame(file_get_contents($this->testPath('source.zip')), file_get_contents($this->testPath('recovered.zip')));
            $this->assertSame(0600, fileperms($this->testPath('recovered.zip')) & 0777);
        } finally {
            Schema::rename('unavailable_decrypt_backup_logs', 'backup_logs');
        }
    }

    public function test_wrong_password_is_one_json_error_with_no_output_or_staging(): void
    {
        $source = $this->fixture();
        [$code, $result] = $this->decrypt($source, 'wrong fixture password');
        $this->assertSame(1, $code);
        $this->assertSame('failed', $result['status']);
        $this->assertFileDoesNotExist($this->testPath('recovered.zip'));
        $this->assertSame([], glob($this->testPath('.capsule_decrypt_*')));
        $this->assertStringNotContainsString('wrong fixture password', json_encode($result));
    }

    public function test_missing_password_does_not_fall_back_to_configuration(): void
    {
        $source = $this->fixture();
        config(['capsule.security.backup_password' => self::PASSWORD]);
        [$code, $result] = $this->decrypt($source, self::PASSWORD, false);
        $this->assertSame(1, $code);
        $this->assertSame('failed', $result['status']);
        $this->assertStringContainsString('password is required', $result['error']);
        $this->assertFileDoesNotExist($this->testPath('recovered.zip'));
    }

    #[DataProvider('invalidArguments')]
    public function test_argument_parser_errors_are_sanitized_before_laravel_can_render_them(array $arguments, string $format): void
    {
        $input = new ArgvInput(['artisan', 'source.capsule.enc', ...$arguments, '--format=' . $format]);
        $input->setInteractive(false);
        $output = new BufferedOutput(BufferedOutput::VERBOSITY_DEBUG);
        $command = new DecryptCommand();
        $command->setLaravel($this->app);
        $this->assertSame(1, $command->run($input, $output));
        $text = $output->fetch();
        $this->assertStringContainsString('Invalid decryption arguments', $text);
        $this->assertStringNotContainsString(self::PASSWORD, $text);
        $this->assertStringNotContainsString('Stack trace', $text);
        if ($format === 'json') {
            $this->assertSame('failed', json_decode($text, true, 512, JSON_THROW_ON_ERROR)['status']);
        }
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

    private function decrypt(string $source, string $password, bool $explicitStdin = true): array
    {
        $input = new ArrayInput(['source' => $source, '--output' => $this->testPath('recovered.zip'), '--password-stdin' => $explicitStdin, '--format' => 'json']);
        $input->setInteractive(false);
        $stream = fopen('php://memory', 'w+b');
        fwrite($stream, $password . "\n");
        rewind($stream);
        $input->setStream($stream);
        $output = new BufferedOutput();
        $command = new DecryptCommand();
        $command->setLaravel($this->app);
        try {
            $code = $command->run($input, $output);
        } finally {
            fclose($stream);
        }

        return [$code, json_decode($output->fetch(), true, 512, JSON_THROW_ON_ERROR)];
    }

    private function fixture(): string
    {
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($this->testPath('source.zip'), ZipArchive::CREATE));
        $zip->addFromString('files/hello.txt', 'hello');
        $zip->addFromString('manifest.json', json_encode(['schema_version' => 2, 'entries' => [[
            'path' => 'files/hello.txt', 'size' => 5, 'sha256' => hash('sha256', 'hello'),
        ]]], JSON_THROW_ON_ERROR));
        $zip->close();
        $codec = new EncryptedArchive(self::PASSWORD);
        try {
            file_put_contents($this->testPath('backup.capsule.enc'), implode('', iterator_to_array($codec->encrypt([file_get_contents($this->testPath('source.zip'))]))));
        } finally {
            $codec->close();
        }

        return $this->testPath('backup.capsule.enc');
    }
}
