<?php

namespace Dgtlss\Capsule\Tests\Unit;

use Dgtlss\Capsule\Database\DatabaseDumper;
use Dgtlss\Capsule\Database\DumpValidator;
use Dgtlss\Capsule\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class DatabaseDumperTest extends TestCase
{
    private string $directory;
    private string $originalPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir() . '/capsule-dumper-' . bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
        $this->originalPath = (string) getenv('PATH');
        putenv('PATH=' . $this->directory . PATH_SEPARATOR . $this->originalPath);
        config()->set('database.connections.audit_mysql', [
            'driver' => 'mysql', 'database' => 'audit', 'host' => 'localhost',
            'username' => 'audit', 'password' => 'test-password',
        ]);
        config()->set('database.connections.audit_pgsql', [
            'driver' => 'pgsql', 'database' => 'audit', 'host' => 'localhost',
            'username' => 'audit', 'password' => 'test-password',
        ]);
    }

    protected function tearDown(): void
    {
        putenv('PATH=' . $this->originalPath);
        $this->removeDirectory($this->directory);
        parent::tearDown();
    }

    public static function driversAndModes(): array
    {
        return [
            'mysql sequential' => ['mysql', false],
            'mysql parallel' => ['mysql', true],
            'postgres sequential' => ['pgsql', false],
            'postgres parallel' => ['pgsql', true],
        ];
    }

    #[DataProvider('driversAndModes')]
    public function test_nonzero_dump_exit_survives_cleanup_and_valid_looking_output(string $driver, bool $parallel): void
    {
        $this->installClient($driver, $this->validSql($driver), 23);
        $dumper = new RecordingDatabaseDumper();
        $destination = $this->directory . '/dump.sql';

        if ($parallel) {
            $process = $dumper->startDumpProcess('audit_' . $driver, $destination);
            $this->assertIsResource($process);
            $this->assertSame(23, proc_close($process));
        } else {
            try {
                $dumper->dump('audit_' . $driver, $destination);
                $this->fail('A failed dump must throw even when stdout looks valid.');
            } catch (\Exception $e) {
                $this->assertStringContainsString('return code: 23', $e->getMessage());
                $this->assertStringContainsString('fixture diagnostic', $e->getMessage());
            }
        }

        $this->assertSame($this->validSql($driver), file_get_contents($destination));
        $this->assertFileDoesNotExist($dumper->credentialFile);
        $this->assertSame("invoked\n", file_get_contents($this->directory . '/calls'));
    }

    #[DataProvider('driversAndModes')]
    public function test_successful_dump_is_validated_without_stderr_in_sql(string $driver, bool $parallel): void
    {
        $this->installClient($driver, $this->validSql($driver), 0);
        $dumper = new RecordingDatabaseDumper();
        $destination = $this->directory . '/dump.sql';
        if ($parallel) {
            $this->assertSame(0, proc_close($dumper->startDumpProcess('audit_' . $driver, $destination)));
        } else {
            $dumper->dump('audit_' . $driver, $destination);
        }

        DumpValidator::validate($destination, $driver);
        $this->assertSame($this->validSql($driver), file_get_contents($destination));
        $this->assertFileDoesNotExist($dumper->credentialFile);
    }

    public function test_streaming_mysql_preserves_configured_flags_and_cleanup_contract(): void
    {
        $this->installClient('mysql', $this->validSql('mysql'), 0);
        config()->set('capsule.database.mysqldump_flags', '--max-allowed-packet=16M');
        $dumper = new RecordingDatabaseDumper();
        $stream = $dumper->buildStreamCommand('audit_mysql');
        try {
            $this->assertStringContainsString('--max-allowed-packet=16M', $stream['command']);
            $this->assertSame($dumper->credentialFile, $stream['cleanup']);
            $this->assertFileExists($stream['cleanup']);
        } finally {
            unlink($stream['cleanup']);
        }
    }

    public function test_parallel_shell_signal_still_removes_credentials(): void
    {
        $client = $this->directory . '/mysqldump';
        file_put_contents($client, "#!/bin/sh\nkill -TERM \"\$PPID\"\nexit 0\n");
        chmod($client, 0700);
        $dumper = new RecordingDatabaseDumper();
        $process = $dumper->startDumpProcess('audit_mysql', $this->directory . '/dump.sql');
        $this->assertSame(143, proc_close($process));
        $this->assertFileDoesNotExist($dumper->credentialFile);
    }

    private function installClient(string $driver, string $stdout, int $exit): void
    {
        $client = $this->directory . '/' . ($driver === 'mysql' ? 'mysqldump' : 'pg_dump');
        $script = "#!/bin/sh\n"
            . 'printf "invoked\\n" >> ' . escapeshellarg($this->directory . '/calls') . "\n"
            . 'printf %s ' . escapeshellarg($stdout) . "\n"
            . "printf 'fixture diagnostic\\n' >&2\n"
            . "exit {$exit}\n";
        file_put_contents($client, $script);
        chmod($client, 0700);
    }

    private function validSql(string $driver): string
    {
        return $driver === 'mysql'
            ? "-- MySQL dump 10.13\nCREATE TABLE test (id INT);\n-- Dump completed on 2026-10-10\n"
            : "--\n-- PostgreSQL database dump\n--\nCREATE TABLE test (id INT);\n-- PostgreSQL database dump complete\n";
    }
}

class RecordingDatabaseDumper extends DatabaseDumper
{
    public string $credentialFile;

    protected function buildMysqlConfigFile(array $config, string $dumpCommand): string
    {
        return $this->credentialFile = parent::buildMysqlConfigFile($config, $dumpCommand);
    }

    protected function buildPgpassFile(array $config): string
    {
        return $this->credentialFile = parent::buildPgpassFile($config);
    }
}
