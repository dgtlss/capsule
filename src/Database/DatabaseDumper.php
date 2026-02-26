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

        if ($connections === null) {
            return [config('database.default')];
        }

        $connections = is_string($connections) ? [$connections] : $connections;

        return array_map(function ($c) {
            return $c === 'default' ? config('database.default') : $c;
        }, $connections);
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
            'sqlite' => ['command' => sprintf('cat %s', escapeshellarg($config['database'])), 'cleanup' => null],
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
        $dumpCommand = $this->getMysqlDumpCommand();
        $configFile = $this->buildMysqlConfigFile($config, $dumpCommand);

        try {
            $safeFlags = implode(' ', $this->buildMysqlFlags());
            $tableArgs = $this->buildMysqlTableArgs($config['database']);

            if ($tableArgs['useInclude']) {
                $command = sprintf('%s --defaults-extra-file=%s %s %s %s %s > %s 2>&1', $dumpCommand, escapeshellarg($configFile), $safeFlags, $tableArgs['extraFlags'], escapeshellarg($config['database']), $tableArgs['tablesArg'], escapeshellarg($dumpPath));
            } else {
                $command = sprintf('%s --defaults-extra-file=%s %s %s %s %s > %s 2>&1', $dumpCommand, escapeshellarg($configFile), $safeFlags, $tableArgs['extraFlags'], $tableArgs['ignoreFlags'], escapeshellarg($config['database']), escapeshellarg($dumpPath));
            }

            $output = [];
            $returnCode = 0;
            exec($command, $output, $returnCode);

            if ($returnCode !== 0) {
                $outputText = trim(implode("\n", $output));
                $retried = $this->retryMysqlWithoutRoutines($dumpCommand, $configFile, $config, $tableArgs, $dumpPath);

                if (!$retried) {
                    throw new Exception("MySQL dump failed with return code: {$returnCode}" . (!empty($outputText) ? "\nCommand output:\n{$outputText}" : ''));
                }
            }
        } finally {
            if (file_exists($configFile)) {
                unlink($configFile);
            }
        }
    }

    protected function retryMysqlWithoutRoutines(string $dumpCommand, string $configFile, array $config, array $tableArgs, string $dumpPath): bool
    {
        $includeTriggers = (bool) config('capsule.database.include_triggers', true);
        $includeRoutines = (bool) config('capsule.database.include_routines', false);

        if (!$includeTriggers && !$includeRoutines) {
            return false;
        }

        $fallbackFlags = implode(' ', ['--single-transaction', '--hex-blob', '--skip-triggers']);

        if ($tableArgs['useInclude']) {
            $retryCommand = sprintf('%s --defaults-extra-file=%s %s %s %s %s > %s 2>&1', $dumpCommand, escapeshellarg($configFile), $fallbackFlags, $tableArgs['extraFlags'], escapeshellarg($config['database']), $tableArgs['tablesArg'], escapeshellarg($dumpPath));
        } else {
            $retryCommand = sprintf('%s --defaults-extra-file=%s %s %s %s %s > %s 2>&1', $dumpCommand, escapeshellarg($configFile), $fallbackFlags, $tableArgs['extraFlags'], $tableArgs['ignoreFlags'], escapeshellarg($config['database']), escapeshellarg($dumpPath));
        }

        $retryOutput = [];
        $retryCode = 0;
        exec($retryCommand, $retryOutput, $retryCode);

        if ($retryCode === 0) {
            \Illuminate\Support\Facades\Log::warning('MySQL dump succeeded after disabling routines/triggers', [
                'database' => $config['database'] ?? null,
            ]);
            return true;
        }

        return false;
    }

    protected function startMysqlDumpProcess(array $config, string $dumpPath)
    {
        $dumpCommand = $this->getMysqlDumpCommand();
        $configFile = $this->buildMysqlConfigFile($config, $dumpCommand);
        $safeFlags = implode(' ', $this->buildMysqlFlags());
        $tableArgs = $this->buildMysqlTableArgs($config['database']);
        $extraFlags = $tableArgs['extraFlags'];

        $allFlags = trim($safeFlags . ' ' . $extraFlags);

        $command = sprintf(
            '%s --defaults-extra-file=%s %s %s > %s 2>&1; rm %s',
            $dumpCommand,
            escapeshellarg($configFile),
            $allFlags,
            escapeshellarg($config['database']),
            escapeshellarg($dumpPath),
            escapeshellarg($configFile)
        );

        return proc_open($command, [], $pipes);
    }

    protected function buildMysqlStreamCommand(array $config): array
    {
        $dumpCommand = $this->getMysqlDumpCommand();
        $configFile = $this->buildMysqlConfigFile($config, $dumpCommand);
        $safeFlags = implode(' ', $this->buildMysqlFlags());
        $tableArgs = $this->buildMysqlTableArgs($config['database']);

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
        $pgpassFile = $this->buildPgpassFile($config);

        try {
            $tableFlags = $this->buildPostgresTableFlags();
            $command = sprintf(
                'PGPASSFILE=%s pg_dump --host=%s --port=%s --username=%s --dbname=%s --no-owner --no-privileges --format=plain %s > %s 2>&1',
                escapeshellarg($pgpassFile),
                escapeshellarg($config['host']),
                escapeshellarg($config['port'] ?? 5432),
                escapeshellarg($config['username']),
                escapeshellarg($config['database']),
                $tableFlags,
                escapeshellarg($dumpPath)
            );

            exec($command, $output, $returnCode);

            if ($returnCode !== 0) {
                throw new Exception("PostgreSQL dump failed with return code: {$returnCode}" . (!empty($output) ? "\nCommand output: " . implode("\n", $output) : ''));
            }
        } finally {
            if (file_exists($pgpassFile)) {
                unlink($pgpassFile);
            }
        }
    }

    protected function startPostgresDumpProcess(array $config, string $dumpPath)
    {
        $pgpassFile = $this->buildPgpassFile($config);

        $command = sprintf(
            'PGPASSFILE=%s pg_dump --host=%s --port=%s --username=%s --dbname=%s > %s 2>&1; rm %s',
            escapeshellarg($pgpassFile),
            escapeshellarg($config['host']),
            escapeshellarg($config['port'] ?? 5432),
            escapeshellarg($config['username']),
            escapeshellarg($config['database']),
            escapeshellarg($dumpPath),
            escapeshellarg($pgpassFile)
        );

        return proc_open($command, [], $pipes);
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

    // ─── SQLite ─────────────────────────────────────────────────────────

    protected function dumpSqlite(array $config, string $dumpPath): void
    {
        if (!file_exists($config['database'])) {
            throw new Exception("SQLite database file not found: {$config['database']}");
        }

        if (!copy($config['database'], $dumpPath)) {
            throw new Exception("Failed to copy SQLite database from {$config['database']} to {$dumpPath}");
        }
    }

    /**
     * SQLite is just a file copy -- handled synchronously even in parallel mode.
     * @return null
     */
    protected function dumpSqliteSynchronously(array $config, string $dumpPath)
    {
        $this->dumpSqlite($config, $dumpPath);
        return null;
    }
}
