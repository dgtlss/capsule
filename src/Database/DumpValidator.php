<?php

namespace Dgtlss\Capsule\Database;

use Exception;

class DumpValidator
{
    /**
     * Validate a completed dump before it gets added to the archive.
     * The caller must also check the dump process exit status: format checks
     * cannot prove that every requested database object was exported.
     */
    public static function validate(string $dumpPath, string $driver): void
    {
        clearstatcache(true, $dumpPath);
        if (!is_file($dumpPath)) {
            throw new Exception("Dump file does not exist: {$dumpPath}");
        }

        $size = filesize($dumpPath);
        if ($size === false || $size === 0) {
            throw new Exception("Dump file is empty or unreadable: {$dumpPath}");
        }

        $handle = fopen($dumpPath, 'rb');
        if ($handle === false) {
            throw new Exception("Cannot open dump file for validation: {$dumpPath}");
        }

        try {
            $header = fread($handle, min(4096, $size));
            if (fseek($handle, max(0, $size - 4096)) !== 0) {
                throw new Exception("Cannot read dump footer: {$dumpPath}");
            }
            $tail = fread($handle, 4096);
            if ($header === false || $tail === false) {
                throw new Exception("Cannot read dump file for validation: {$dumpPath}");
            }
            self::validateContent($header, $tail, $size, $driver);
        } finally {
            fclose($handle);
        }

        if ($driver === 'sqlite') {
            try {
                $database = new \PDO('sqlite:' . $dumpPath, null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
                $result = $database->query('PRAGMA quick_check')->fetchAll(\PDO::FETCH_COLUMN);
                if ($result !== ['ok']) {
                    throw new Exception('SQLite dump failed integrity validation.');
                }
            } catch (\Throwable $e) {
                throw new Exception('SQLite dump failed integrity validation: ' . $e->getMessage(), 0, $e);
            }
        }
    }

    /**
     * Validate bounded first/last 4096-byte samples of a completed stream.
     * SQLite streams originate from a separately integrity-checked snapshot.
     */
    public static function validateContent(#[\SensitiveParameter] string $header, #[\SensitiveParameter] string $tail, int $size, string $driver): void
    {
        if ($size <= 0 || $header === '') {
            throw new Exception('Database dump is empty.');
        }

        match ($driver) {
            'mysql', 'mariadb' => self::validateMysqlContent($header, $tail),
            'pgsql' => self::validatePostgresContent($header, $tail),
            'sqlite' => self::validateSqliteContent($header, $size),
            default => null,
        };
    }

    protected static function validateMysqlContent(#[\SensitiveParameter] string $header, #[\SensitiveParameter] string $tail): void
    {
        if (!self::mysqlCommentsEnabled()) {
            // Explicitly comment-free dumps have no completion marker. Require
            // SQL output and a successful process exit at the call site.
            if (!preg_match('/(?:\/\*![0-9]+\s|^(?:SET|CREATE|INSERT|REPLACE|DROP|LOCK|ALTER|USE)\s)/m', $header)) {
                throw new Exception('MySQL dump appears invalid: missing SQL output.');
            }
            return;
        }

        if (!preg_match('/^-- (?:MySQL dump|MariaDB dump|mysqldump)\b/m', $header)) {
            throw new Exception('MySQL dump appears invalid: missing expected header.');
        }
        if (!preg_match('/^-- Dump completed(?: on .*)?\s*$/m', $tail)) {
            throw new Exception('MySQL dump is incomplete: missing "Dump completed" marker.');
        }
    }

    protected static function mysqlCommentsEnabled(): bool
    {
        $flags = (string) (config('capsule.database.mysqldump_flags') ?? env('CAPSULE_MYSQLDUMP_FLAGS', ''));
        $enabled = true;
        // Preserve option ordering, e.g. --compact --comments re-enables them.
        foreach (preg_split('/\s+/', $flags, -1, PREG_SPLIT_NO_EMPTY) as $flag) {
            $flag = trim($flag, "\"'");
            if (in_array($flag, ['--skip-comments', '--comments=0', '--comments=false', '--compact'], true)) {
                $enabled = false;
            } elseif (in_array($flag, ['--comments', '--comments=1', '--comments=true', '--skip-compact'], true)) {
                $enabled = true;
            }
        }
        return $enabled;
    }

    protected static function validatePostgresContent(#[\SensitiveParameter] string $header, #[\SensitiveParameter] string $tail): void
    {
        if (!preg_match('/^-- PostgreSQL database dump\s*$/m', $header)) {
            throw new Exception('PostgreSQL dump appears invalid: missing expected header.');
        }
        if (!preg_match('/^-- PostgreSQL database dump complete\s*$/m', $tail)) {
            throw new Exception('PostgreSQL dump is incomplete: missing completion marker.');
        }
    }

    protected static function validateSqliteContent(#[\SensitiveParameter] string $header, int $size): void
    {
        if ($size < 100 || strlen($header) < 100 || substr($header, 0, 16) !== "SQLite format 3\0") {
            throw new Exception('SQLite dump does not have a valid SQLite header.');
        }

        $pageSize = unpack('n', substr($header, 16, 2))[1];
        $pageSize = $pageSize === 1 ? 65536 : $pageSize;
        $pages = unpack('N', substr($header, 28, 4))[1];
        if ($pageSize < 512 || $pageSize > 65536 || ($pageSize & ($pageSize - 1)) !== 0
            || $size % $pageSize !== 0 || $size < $pages * $pageSize) {
            throw new Exception('SQLite dump is truncated or has an invalid page size.');
        }
    }
}
