<?php

namespace Dgtlss\Capsule\Database;

use Dgtlss\Capsule\Support\Helpers;
use Exception;

class DatabaseDumper
{
    /** @var callable|null */
    protected $logger = null;

    public function setLogger(callable $logger): void
    {
        $this->logger = $logger;
    }

    protected function log(string $message): void
    {
        if ($this->logger) {
            call_user_func($this->logger, $message);
        }
    }

    public function resolveConnections(): array
    {
        $connections = config('capsule.database.connections');

        $connections = $connections === null ? [config('database.default')] : (is_string($connections) ? [$connections] : $connections);
        $resolved = array_map(fn ($name) => $name === 'default' ? config('database.default') : $name, $connections);
        foreach ($resolved as $name) {
            if (!is_string($name) || !preg_match('/^[A-Za-z0-9_-]+$/D', $name)) {
                throw new Exception('Database connection names must contain only letters, numbers, underscores and hyphens for portable recovery.');
            }
        }
        return array_values(array_unique($resolved));
    }

    public function dump(string $connection, string $dumpPath): void
    {
        $config = config("database.connections.{$connection}");
        $driver = $config['driver'] ?? 'unknown';

        match ($driver) {
            'mysql', 'mariadb' => $this->dumpMysql($config, $dumpPath),
            'pgsql' => $this->dumpPostgres($config, $dumpPath),
            'sqlite' => $this->dumpSqlite($config, $dumpPath),
            default => throw new Exception("Unsupported database driver: {$driver}"),
        };
    }

    /**
     * Start a background dump process (for parallel mode).
     * Returns a proc_open resource, or null for SQLite (handled synchronously).
     *
     * @return resource|null
     */
    public function startDumpProcess(string $connection, string $dumpPath)
    {
        $config = config("database.connections.{$connection}");
        $driver = $config['driver'] ?? 'unknown';

        return match ($driver) {
            'mysql', 'mariadb' => $this->startMysqlDumpProcess($config, $dumpPath),
            'pgsql' => $this->startPostgresDumpProcess($config, $dumpPath),
            'sqlite' => $this->dumpSqliteSynchronously($config, $dumpPath),
            default => throw new Exception("Unsupported database driver for parallel processing: {$driver}"),
        };
    }

    /**
     * Build the streaming command string for a database (used by chunked backup).
     */
    public function buildStreamCommand(string $connection): array
    {
        $config = config("database.connections.{$connection}");
        $driver = $config['driver'] ?? 'unknown';

        return match ($driver) {
            'mysql', 'mariadb' => $this->buildMysqlStreamCommand($config),
            'pgsql' => $this->buildPostgresStreamCommand($config),
            'sqlite' => $this->buildSqliteStreamCommand($config),
            default => throw new Exception("Unsupported database driver for streaming: {$driver}"),
        };
    }

    public function getMysqlDumpCommand(): string
    {
        if (Helpers::commandExists('mysqldump')) {
            return 'mysqldump';
        }

        if (Helpers::commandExists('mariadb-dump')) {
            return 'mariadb-dump';
        }

        throw new Exception('Neither mysqldump nor mariadb-dump command found. Please install MySQL or MariaDB client tools.');
    }

    // ─── MySQL ──────────────────────────────────────────────────────────

    protected function buildMysqlConfigFile(array $config, string $dumpCommand): string
    {
        $configFile = tempnam(sys_get_temp_dir(), 'mysql_config_');
        $escape = fn($value) => '"' . str_replace(["\\", "\n", "\r", '"'], ["\\\\", "\\n", "\\r", '\\"'], (string) $value) . '"';

        $content = sprintf("[%s]\nuser=%s\npassword=%s\n", $dumpCommand, $escape($config['username'] ?? ''), $escape($config['password'] ?? ''));

        if (!empty($config['unix_socket'])) {
            $content .= 'socket=' . $escape($config['unix_socket']) . "\n";
        } else {
            $content .= sprintf("host=%s\nport=%s\n", $escape($config['host'] ?? 'localhost'), $escape($config['port'] ?? 3306));
        }

        $sslMode = config("database.connections." . ($config['name'] ?? 'mysql') . ".sslmode") ?? env('DB_SSL_MODE');
        foreach (['ssl-mode' => $sslMode, 'ssl-ca' => env('MYSQL_ATTR_SSL_CA'), 'ssl-cert' => env('MYSQL_ATTR_SSL_CERT'), 'ssl-key' => env('MYSQL_ATTR_SSL_KEY')] as $key => $value) {
            if (!empty($value)) {
                $content .= "{$key}=" . $escape($value) . "\n";
            }
        }

        file_put_contents($configFile, $content);
        chmod($configFile, config('capsule.security.temp_file_permissions', 0600));

        return $configFile;
    }

    protected function buildMysqlFlags(): array
    {
        $includeTriggers = (bool) config('capsule.database.include_triggers', true);
        $includeRoutines = (bool) config('capsule.database.include_routines', false);

        $flags = ['--single-transaction', '--hex-blob'];
        $flags[] = $includeTriggers ? '--triggers' : '--skip-triggers';
        if ($includeRoutines) {
            $flags[] = '--routines';
        }

        return $flags;
    }

    protected function buildMysqlTableArgs(string $dbName): array
    {
        $includeTables = (array) (config('capsule.database.include_tables', []) ?? []);
        $excludeTables = (array) (config('capsule.database.exclude_tables', []) ?? []);
        $extraFlags = trim((string) (config('capsule.database.mysqldump_flags') ?? env('CAPSULE_MYSQLDUMP_FLAGS', '')));

        $ignoreFlags = '';
        $tablesArg = '';
        $useInclude = false;

        if (!empty($includeTables)) {
            $tablesArg = implode(' ', array_map('escapeshellarg', $includeTables));
            $useInclude = true;
        } elseif (!empty($excludeTables)) {
            foreach ($excludeTables as $table) {
                $ignoreFlags .= ' --ignore-table=' . escapeshellarg($dbName . '.' . $table);
            }
        }

        return compact('includeTables', 'excludeTables', 'extraFlags', 'ignoreFlags', 'tablesArg', 'useInclude');
    }

    protected function dumpMysql(array $config, string $dumpPath): void
    {
        $this->runDumpToFile($this->buildMysqlStreamCommand($config), $dumpPath, 'MySQL');
    }

    protected function startMysqlDumpProcess(array $config, string $dumpPath)
    {
        return $this->startDumpToFile($this->buildMysqlStreamCommand($config), $dumpPath);
    }

    protected function buildMysqlStreamCommand(array $config): array
    {
        $dumpCommand = $this->getMysqlDumpCommand();
        $configFile = $this->buildMysqlConfigFile($config, $dumpCommand);
        $safeFlags = implode(' ', $this->buildMysqlFlags());
        $tableArgs = $this->buildMysqlTableArgs($config['database']);
        $safeFlags = trim($safeFlags . ' ' . $tableArgs['extraFlags']);

        if ($tableArgs['useInclude']) {
            $command = sprintf('%s --defaults-extra-file=%s %s %s %s', $dumpCommand, escapeshellarg($configFile), $safeFlags, escapeshellarg($config['database']), $tableArgs['tablesArg']);
        } else {
            $command = sprintf('%s --defaults-extra-file=%s %s %s %s', $dumpCommand, escapeshellarg($configFile), $safeFlags, $tableArgs['ignoreFlags'], escapeshellarg($config['database']));
        }

        return ['command' => $command, 'cleanup' => $configFile];
    }

    // ─── PostgreSQL ─────────────────────────────────────────────────────

    protected function buildPgpassFile(array $config): string
    {
        $pgpassFile = tempnam(sys_get_temp_dir(), 'pgpass_');
        $escape = fn($value) => str_replace(['\\', ':'], ['\\\\', '\\:'], (string) $value);

        $content = sprintf(
            "%s:%s:%s:%s:%s\n",
            $escape($config['host']),
            $escape($config['port'] ?? 5432),
            $escape($config['database']),
            $escape($config['username']),
            $escape($config['password'])
        );

        file_put_contents($pgpassFile, $content);
        chmod($pgpassFile, config('capsule.security.temp_file_permissions', 0600));

        return $pgpassFile;
    }

    protected function buildPostgresTableFlags(): string
    {
        $includeTables = (array) (config('capsule.database.include_tables', []) ?? []);
        $excludeTables = (array) (config('capsule.database.exclude_tables', []) ?? []);

        $tableFlags = '';
        if (!empty($includeTables)) {
            foreach ($includeTables as $table) {
                $tableFlags .= ' -t ' . escapeshellarg($table);
            }
        } elseif (!empty($excludeTables)) {
            foreach ($excludeTables as $table) {
                $tableFlags .= ' --exclude-table=' . escapeshellarg($table);
            }
        }

        return $tableFlags;
    }

    protected function dumpPostgres(array $config, string $dumpPath): void
    {
        $this->runDumpToFile($this->buildPostgresStreamCommand($config), $dumpPath, 'PostgreSQL');
    }

    protected function startPostgresDumpProcess(array $config, string $dumpPath)
    {
        return $this->startDumpToFile($this->buildPostgresStreamCommand($config), $dumpPath);
    }

    protected function buildPostgresStreamCommand(array $config): array
    {
        $pgpassFile = $this->buildPgpassFile($config);
        $tableFlags = $this->buildPostgresTableFlags();

        $command = sprintf(
            'PGPASSFILE=%s pg_dump --host=%s --port=%s --username=%s --dbname=%s --no-owner --no-privileges --format=plain %s',
            escapeshellarg($pgpassFile),
            escapeshellarg($config['host']),
            escapeshellarg($config['port'] ?? 5432),
            escapeshellarg($config['username']),
            escapeshellarg($config['database']),
            $tableFlags
        );

        return ['command' => $command, 'cleanup' => $pgpassFile];
    }

    /**
     * Keep stderr out of the SQL file, and always check the dump command itself.
     * A failed dump must not be retried with fewer schema objects: that would
     * silently produce a different backup from the one the user requested.
     */
    protected function runDumpToFile(array $streamInfo, string $dumpPath, string $driver): void
    {
        $stderr = tmpfile();
        if ($stderr === false) {
            $this->cleanupStream($streamInfo);
            throw new Exception('Unable to create database dump error stream.');
        }

        try {
            $process = proc_open($streamInfo['command'], [
                1 => ['file', $dumpPath, 'wb'],
                2 => $stderr,
            ], $pipes);
            if (!is_resource($process)) {
                throw new Exception("Unable to start {$driver} dump process.");
            }

            $returnCode = proc_close($process);
            if ($returnCode !== 0) {
                rewind($stderr);
                $error = trim((string) stream_get_contents($stderr, 8192));
                throw new Exception("{$driver} dump failed with return code: {$returnCode}" . ($error !== '' ? "\n{$error}" : ''));
            }
        } finally {
            fclose($stderr);
            $this->cleanupStream($streamInfo);
        }
    }

    /**
     * The shell owns credential cleanup while the caller owns proc_close().
     * Capture the dump exit status before cleanup so rm cannot mask a failure.
     * Stderr is inherited separately and is never written into the SQL file.
     */
    protected function startDumpToFile(array $streamInfo, string $dumpPath)
    {
        $command = $streamInfo['command'];
        if (!empty($streamInfo['cleanup'])) {
            $cleanup = 'dump_status=$?; trap - EXIT; rm -f -- '
                . escapeshellarg($streamInfo['cleanup']) . '; exit "$dump_status"';
            $command = 'trap ' . escapeshellarg($cleanup) . ' EXIT; '
                . "trap 'exit 143' TERM; trap 'exit 130' INT; " . $command;
        }

        try {
            $process = proc_open($command, [1 => ['file', $dumpPath, 'wb']], $pipes);
            if (!is_resource($process)) {
                throw new Exception('Unable to start database dump process.');
            }
            return $process;
        } catch (\Throwable $e) {
            $this->cleanupStream($streamInfo);
            throw $e;
        }
    }

    protected function cleanupStream(array $streamInfo): void
    {
        if (!empty($streamInfo['cleanup']) && file_exists($streamInfo['cleanup'])) {
            @unlink($streamInfo['cleanup']);
        }
    }

    // ─── SQLite ─────────────────────────────────────────────────────────

    protected function dumpSqlite(array $config, string $dumpPath): void
    {
        $source = $config['database'] ?? '';
        if (!is_file($source)) {
            throw new Exception("SQLite database file not found: {$source}");
        }
        if (realpath($source) === realpath($dumpPath)) {
            throw new Exception('SQLite snapshot destination must differ from the source database.');
        }

        // VACUUM INTO reads a transactionally consistent view, including rows
        // still in a live WAL. Copying only the main file loses those rows.
        $snapshot = tempnam(dirname($dumpPath), '.capsule_sqlite_');
        if ($snapshot === false) {
            throw new Exception('Unable to create SQLite snapshot file.');
        }

        try {
            chmod($snapshot, config('capsule.security.temp_file_permissions', 0600));
            $database = new \PDO('sqlite:' . $source, null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
            $database->exec('PRAGMA busy_timeout = 5000');
            $database->exec('VACUUM INTO ' . $database->quote($snapshot));
            $database = null;
            DumpValidator::validate($snapshot, 'sqlite');

            if (!rename($snapshot, $dumpPath)) {
                throw new Exception("Unable to move SQLite snapshot to {$dumpPath}");
            }
        } catch (\Throwable $e) {
            throw new Exception('SQLite snapshot failed (requires SQLite 3.27 or newer): ' . $e->getMessage(), 0, $e);
        } finally {
            if (is_file($snapshot)) {
                @unlink($snapshot);
            }
        }
    }

    protected function buildSqliteStreamCommand(array $config): array
    {
        $snapshot = tempnam(sys_get_temp_dir(), 'capsule_sqlite_');
        if ($snapshot === false) {
            throw new Exception('Unable to create SQLite streaming snapshot.');
        }

        try {
            $this->dumpSqlite($config, $snapshot);
            return ['command' => 'cat ' . escapeshellarg($snapshot), 'cleanup' => $snapshot];
        } catch (\Throwable $e) {
            @unlink($snapshot);
            throw $e;
        }
    }

    /**
     * SQLite snapshots are created synchronously even in parallel mode.
     * @return null
     */
    protected function dumpSqliteSynchronously(array $config, string $dumpPath)
    {
        $this->dumpSqlite($config, $dumpPath);
        return null;
    }
}
