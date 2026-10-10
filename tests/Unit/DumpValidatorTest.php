<?php

namespace Dgtlss\Capsule\Tests\Unit;

use Dgtlss\Capsule\Database\DumpValidator;
use Dgtlss\Capsule\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class DumpValidatorTest extends TestCase
{
    public static function invalidSqlDumps(): array
    {
        return [
            'mysql error only' => ['mysql', 'mysqldump: access denied', 'missing expected header'],
            'mysql generic comment' => ['mysql', "-- error: permission denied\n", 'missing expected header'],
            'mysql truncated small dump' => ['mysql', "-- MySQL dump 10.13\nCREATE TABLE x (id INT);\n", 'incomplete'],
            'mysql truncated large dump' => ['mysql', "-- MySQL dump 10.13\n" . str_repeat('x', 9000), 'incomplete'],
            'postgres error only' => ['pgsql', 'pg_dump: error: permission denied', 'missing expected header'],
            'postgres generic comment' => ['pgsql', "-- error: connection failed\n", 'missing expected header'],
            'postgres header only' => ['pgsql', "-- PostgreSQL database dump\n", 'incomplete'],
            'postgres SET only' => ['pgsql', "SET client_encoding = 'UTF8';\n", 'missing expected header'],
        ];
    }

    #[DataProvider('invalidSqlDumps')]
    public function test_rejects_error_output_and_incomplete_dumps(string $driver, string $content, string $error): void
    {
        $this->expectExceptionMessage($error);
        DumpValidator::validateContent(substr($content, 0, 4096), substr($content, -4096), strlen($content), $driver);
    }

    public static function validSqlDumps(): array
    {
        return [
            ['mysql', "-- MySQL dump 10.13\nCREATE TABLE x (id INT);\n-- Dump completed on 2026-10-10\n"],
            ['mariadb', "/*M!999999\\- enable the sandbox mode */\n-- MariaDB dump 10.19\nCREATE TABLE x (id INT);\n-- Dump completed\n"],
            ['pgsql', "--\n-- PostgreSQL database dump\n--\nSET client_encoding = 'UTF8';\n-- PostgreSQL database dump complete\n\n\\unrestrict example\n"],
        ];
    }

    #[DataProvider('validSqlDumps')]
    public function test_accepts_complete_dump_files_and_stream_samples(string $driver, string $content): void
    {
        $path = tempnam(sys_get_temp_dir(), 'capsule-validation-');
        try {
            file_put_contents($path, $content);
            DumpValidator::validate($path, $driver);
            DumpValidator::validateContent(substr($content, 0, 4096), substr($content, -4096), strlen($content), $driver);
            $this->addToAssertionCount(1);
        } finally {
            unlink($path);
        }
    }

    public function test_explicit_comment_free_mysql_output_is_supported(): void
    {
        config()->set('capsule.database.mysqldump_flags', '--compact');
        $sql = "CREATE TABLE x (id INT);\n";
        DumpValidator::validateContent($sql, $sql, strlen($sql), 'mysql');
        $this->addToAssertionCount(1);
    }

    public function test_comment_free_mysql_error_output_is_rejected(): void
    {
        config()->set('capsule.database.mysqldump_flags', '--skip-comments');
        $this->expectExceptionMessage('missing SQL output');
        DumpValidator::validateContent('mysqldump: error', 'mysqldump: error', 16, 'mysql');
    }

    public function test_reenabled_comments_require_completion_marker(): void
    {
        config()->set('capsule.database.mysqldump_flags', '--compact --comments');
        $content = "-- MySQL dump 10.13\nCREATE TABLE x (id INT);\n";
        $this->expectExceptionMessage('incomplete');
        DumpValidator::validateContent($content, $content, strlen($content), 'mysql');
    }

    public function test_rejects_empty_dump(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'capsule-validation-');
        try {
            $this->expectExceptionMessage('empty');
            DumpValidator::validate($path, 'mysql');
        } finally {
            unlink($path);
        }
    }

    public function test_sqlite_checks_database_pages_not_only_magic_header(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'capsule-validation-');
        try {
            $database = new \PDO('sqlite:' . $path);
            $database->exec('CREATE TABLE records (id INTEGER PRIMARY KEY, value TEXT)');
            $database->exec("INSERT INTO records (value) VALUES ('saved')");
            $database = null;
            DumpValidator::validate($path, 'sqlite');
            $contents = file_get_contents($path);
            // Keep the valid file header/page count and file size, but destroy
            // the table b-tree page. Header-only validation used to accept it.
            file_put_contents($path, substr($contents, 0, 4096) . str_repeat("\0", strlen($contents) - 4096));
            $this->expectExceptionMessage('integrity validation');
            DumpValidator::validate($path, 'sqlite');
        } finally {
            unlink($path);
        }
    }

    public function test_sqlite_rejects_truncated_page(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'capsule-validation-');
        try {
            $database = new \PDO('sqlite:' . $path);
            $database->exec('CREATE TABLE records (id INTEGER PRIMARY KEY)');
            $database = null;
            file_put_contents($path, substr(file_get_contents($path), 0, 4097));
            $this->expectExceptionMessage('truncated');
            DumpValidator::validate($path, 'sqlite');
        } finally {
            unlink($path);
        }
    }
}
