<?php

namespace Dgtlss\Capsule\Services\Concerns;

use Exception;

trait HasDatabaseBackup
{
    /**
     * Get the MySQL dump command to use (mysqldump or mariadb-dump)
     */
    abstract protected function getMysqlDumpCommand(): string;

    /**
     * Check if a command exists in the system
     */
    protected function commandExists(string $command): bool
    {
        $result = shell_exec("which {$command}");
        return !empty($result);
    }

    /**
     * Copy SQLite database file to backup location
     */
    protected function copySqliteDatabase(array $config, string $dumpPath): void
    {
        if (!file_exists($config['database'])) {
            $this->lastError = "SQLite database file not found: {$config['database']}";
            throw new Exception($this->lastError);
        }

        if (!copy($config['database'], $dumpPath)) {
            $this->lastError = "Failed to copy SQLite database from {$config['database']} to {$dumpPath}";
            if ($this->verbose) {
                $this->lastError .= "\nCheck file permissions and disk space.";
            }
            throw new Exception($this->lastError);
        }
    }

    /**
     * Build MySQL configuration file content with credentials and SSL settings
     * Returns array with [content, escape_function]
     */
    protected function buildMysqlConfig(array $config): array
    {
        $escape = function ($value) {
            $value = (string) $value;
            $value = str_replace(["\\", "\n", "\r", '"'], ["\\\\", "\\n", "\\r", '\\"'], $value);
            return '"' . $value . '"';
        };

        $dumpCommand = $this->getMysqlDumpCommand();

        $configContent = sprintf(
            "[%s]\nuser=%s\npassword=%s\n",
            $dumpCommand,
            $escape($config['username'] ?? ''),
            $escape($config['password'] ?? '')
        );

        if (!empty($config['unix_socket'])) {
            $configContent .= 'socket=' . $escape($config['unix_socket']) . "\n";
        } else {
            $configContent .= sprintf(
                "host=%s\nport=%s\n",
                $escape($config['host'] ?? 'localhost'),
                $escape($config['port'] ?? 3306)
            );
        }

        $sslMode = config('capsule.database.ssl.mode');
        $sslCa = config('capsule.database.ssl.ca');
        $sslCert = config('capsule.database.ssl.cert');
        $sslKey = config('capsule.database.ssl.key');

        if (!empty($sslMode)) {
            $configContent .= 'ssl-mode=' . $escape($sslMode) . "\n";
        }
        if (!empty($sslCa)) {
            $configContent .= 'ssl-ca=' . $escape($sslCa) . "\n";
        }
        if (!empty($sslCert)) {
            $configContent .= 'ssl-cert=' . $escape($sslCert) . "\n";
        }
        if (!empty($sslKey)) {
            $configContent .= 'ssl-key=' . $escape($sslKey) . "\n";
        }

        return [$configContent, $escape];
    }

    /**
     * Build PostgreSQL pgpass file content with credentials
     * Returns array with [content, escape_function]
     */
    protected function buildPostgresConfig(array $config): array
    {
        $escape = function ($value) {
            $value = (string) $value;
            return str_replace(['\\', ':'], ['\\\\', '\\:'], $value);
        };

        $pgpassContent = sprintf(
            "%s:%s:%s:%s:%s\n",
            $escape($config['host']),
            $escape($config['port'] ?? 5432),
            $escape($config['database']),
            $escape($config['username']),
            $escape($config['password'])
        );

        return [$pgpassContent, $escape];
    }

    /**
     * Start MySQL dump process for chunked backup (used by ChunkedBackupService)
     */
    protected function startMysqlDumpProcess(array $config, string $dumpPath)
    {
        $configFile = tempnam(sys_get_temp_dir(), 'mysql_config_');
        [$configContent, $escape] = $this->buildMysqlConfig($config);

        file_put_contents($configFile, $configContent);
        chmod($configFile, config('capsule.security.temp_file_permissions', 0600));

        $includeTriggers = (bool) (config('capsule.database.include_triggers', true));
        $includeRoutines = (bool) (config('capsule.database.include_routines', false));
        $safeFlags = ['--single-transaction', '--hex-blob'];
        if ($includeTriggers) {
            $safeFlags[] = '--triggers';
        } else {
            $safeFlags[] = '--skip-triggers';
        }
        if ($includeRoutines) {
            $safeFlags[] = '--routines';
        }
        $extraFlags = trim((string) (config('capsule.database.mysqldump_flags') ?? env('CAPSULE_MYSQLDUMP_FLAGS', '')));
        $allFlags = trim(implode(' ', $safeFlags) . ' ' . $extraFlags);

        $dumpCommand = $this->getMysqlDumpCommand();
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

    /**
     * Start PostgreSQL dump process for chunked backup (used by ChunkedBackupService)
     */
    protected function startPostgresDumpProcess(array $config, string $dumpPath)
    {
        $pgpassFile = tempnam(sys_get_temp_dir(), 'pgpass_');
        [$pgpassContent, $escape] = $this->buildPostgresConfig($config);

        file_put_contents($pgpassFile, $pgpassContent);
        chmod($pgpassFile, config('capsule.security.temp_file_permissions', 0600));

        $command = sprintf(
            'PGPASSFILE=%s pg_dump --host=%s --port=%s --username=%s --dbname=%s --no-owner --no-privileges --format=plain --no-password > %s 2>&1; rm %s',
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
}
