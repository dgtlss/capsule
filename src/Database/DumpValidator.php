<?php

namespace Dgtlss\Capsule\Database;

use Exception;

class DumpValidator
{
    /**
     * Validate a database dump file before it gets added to the archive.
     *
     * @throws Exception if validation fails
     */
    public static function validate(string $dumpPath, string $driver): void
    {
        if (!file_exists($dumpPath)) {
            throw new Exception("Dump file does not exist: {$dumpPath}");
        }

        $size = filesize($dumpPath);
        if ($size === 0) {
            throw new Exception("Dump file is empty (0 bytes): {$dumpPath}");
        }

        match ($driver) {
            'mysql', 'mariadb' => self::validateMysqlDump($dumpPath, $size),
            'pgsql' => self::validatePostgresDump($dumpPath, $size),
            'sqlite' => self::validateSqliteDump($dumpPath, $size),
            default => null,
        };
    }

    protected static function validateMysqlDump(string $path, int $size): void
    {
        $handle = fopen($path, 'r');
        if (!$handle) {
            throw new Exception("Cannot open dump file for validation: {$path}");
        }

        $header = fread($handle, min(4096, $size));

        $validHeaders = ['-- MySQL dump', '-- MariaDB dump', '-- mysqldump', '-- Server version', '/*!'];
        $foundHeader = false;
        foreach ($validHeaders as $marker) {
            if (str_contains($header, $marker)) {
                $foundHeader = true;
                break;
            }
        }

        if (!$foundHeader && !str_starts_with(trim($header), '--')) {
            fclose($handle);
            throw new Exception('MySQL dump appears invalid: missing expected header comments.');
        }

        fseek($handle, max(0, $size - 1024));
        $tail = fread($handle, 1024);
        fclose($handle);

        if ($size > 1024 && !str_contains($tail, 'Dump completed') && !str_contains($tail, '-- Dump completed')) {
            \Illuminate\Support\Facades\Log::warning('MySQL dump may be incomplete: missing "Dump completed" marker at end of file.', [
                'file' => $path,
                'size' => $size,
            ]);
        }
    }

    protected static function validatePostgresDump(string $path, int $size): void
    {
        $handle = fopen($path, 'r');
        if (!$handle) {
            throw new Exception("Cannot open dump file for validation: {$path}");
        }

        $header = fread($handle, min(4096, $size));
        fclose($handle);

        if (!str_contains($header, 'PostgreSQL database dump') && !str_starts_with(trim($header), '--') && !str_starts_with(trim($header), 'SET ')) {
            throw new Exception('PostgreSQL dump appears invalid: missing expected header.');
        }
    }

    protected static function validateSqliteDump(string $path, int $size): void
    {
        if ($size < 100) {
            throw new Exception("SQLite dump file is suspiciously small ({$size} bytes).");
        }

        $handle = fopen($path, 'r');
        $header = fread($handle, 16);
        fclose($handle);

        if ($header !== 'SQLite format 3' . "\0") {
            throw new Exception('SQLite dump does not have a valid SQLite header.');
        }
    }
}
