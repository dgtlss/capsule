<?php

namespace Dgtlss\Capsule\Tests\Unit;

use Dgtlss\Capsule\Database\DatabaseDumper;
use Dgtlss\Capsule\Database\DumpValidator;
use Dgtlss\Capsule\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PDO;

class SqliteSnapshotTest extends TestCase
{
    private string $directory;
    private PDO $writer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir() . '/capsule-wal-' . bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
        $source = $this->directory . "/live ' database.sqlite";
        $this->writer = new PDO('sqlite:' . $source, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->writer->exec('PRAGMA journal_mode = WAL');
        $this->writer->exec('PRAGMA wal_autocheckpoint = 0');
        $this->writer->exec('CREATE TABLE records (id INTEGER PRIMARY KEY, value TEXT)');
        $this->writer->exec('PRAGMA wal_checkpoint(TRUNCATE)');
        $this->writer->exec("INSERT INTO records (value) VALUES ('committed in WAL')");
        config()->set('database.connections.live_sqlite', ['driver' => 'sqlite', 'database' => $source]);
        $this->assertGreaterThan(0, filesize($source . '-wal'));
    }

    protected function tearDown(): void
    {
        if ($this->writer->inTransaction()) {
            $this->writer->rollBack();
        }
        unset($this->writer);
        $this->removeDirectory($this->directory);
        parent::tearDown();
    }

    public static function modes(): array
    {
        return [['sequential'], ['parallel'], ['streaming']];
    }

    #[DataProvider('modes')]
    public function test_live_wal_snapshot_contains_committed_rows_and_excludes_uncommitted_rows(string $mode): void
    {
        $source = config('database.connections.live_sqlite.database');
        $rawCopy = $this->directory . '/unsafe-copy.sqlite';
        copy($source, $rawCopy);
        $copy = new PDO('sqlite:' . $rawCopy);
        $this->assertSame(0, (int) $copy->query('SELECT COUNT(*) FROM records')->fetchColumn());
        $copy = null;

        $this->writer->beginTransaction();
        $this->writer->exec("INSERT INTO records (value) VALUES ('not committed')");
        $dumper = new DatabaseDumper();
        $destination = $this->directory . "/snapshot ' file.sqlite";
        if ($mode === 'sequential') {
            $dumper->dump('live_sqlite', $destination);
        } elseif ($mode === 'parallel') {
            $this->assertNull($dumper->startDumpProcess('live_sqlite', $destination));
        } else {
            $stream = $dumper->buildStreamCommand('live_sqlite');
            try {
                $this->assertNotSame($source, $stream['cleanup']);
                $process = proc_open($stream['command'], [1 => ['file', $destination, 'wb']], $pipes);
                $this->assertSame(0, proc_close($process));
            } finally {
                unlink($stream['cleanup']);
            }
        }

        DumpValidator::validate($destination, 'sqlite');
        $restored = new PDO('sqlite:' . $destination);
        $this->assertSame(['committed in WAL'], $restored->query('SELECT value FROM records')->fetchAll(PDO::FETCH_COLUMN));
        $this->assertSame('ok', $restored->query('PRAGMA integrity_check')->fetchColumn());
        $this->assertFileDoesNotExist($destination . '-wal');
    }

    public function test_stream_command_reads_a_fixed_snapshot_after_source_changes(): void
    {
        $stream = (new DatabaseDumper())->buildStreamCommand('live_sqlite');
        try {
            $this->writer->exec("INSERT INTO records (value) VALUES ('committed later')");
            $snapshot = new PDO('sqlite:' . $stream['cleanup']);
            $this->assertSame(1, (int) $snapshot->query('SELECT COUNT(*) FROM records')->fetchColumn());
            $this->assertSame(2, (int) $this->writer->query('SELECT COUNT(*) FROM records')->fetchColumn());
        } finally {
            unlink($stream['cleanup']);
        }
    }

    #[DataProvider('modes')]
    public function test_snapshot_preserves_implicit_row_ids(string $mode): void
    {
        $this->writer->exec('CREATE TABLE implicit_records (value TEXT)');
        $this->writer->exec("INSERT INTO implicit_records (rowid, value) VALUES (10, 'first'), (20, 'second')");
        $destination = $this->directory . '/rowids.sqlite';
        $dumper = new DatabaseDumper();
        if ($mode === 'streaming') {
            $stream = $dumper->buildStreamCommand('live_sqlite');
            try {
                $process = proc_open($stream['command'], [1 => ['file', $destination, 'wb']], $pipes);
                $this->assertSame(0, proc_close($process));
            } finally {
                unlink($stream['cleanup']);
            }
        } elseif ($mode === 'parallel') {
            $this->assertNull($dumper->startDumpProcess('live_sqlite', $destination));
        } else {
            $dumper->dump('live_sqlite', $destination);
        }
        $snapshot = new PDO('sqlite:' . $destination);
        $this->assertSame([[10, 'first'], [20, 'second']],
            $snapshot->query('SELECT rowid, value FROM implicit_records ORDER BY rowid')->fetchAll(PDO::FETCH_NUM));
    }

    public function test_snapshot_never_overwrites_its_source(): void
    {
        $source = config('database.connections.live_sqlite.database');
        $this->expectExceptionMessage('destination must differ');
        (new DatabaseDumper())->dump('live_sqlite', $source);
    }
}
