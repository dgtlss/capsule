<?php

namespace Dgtlss\Capsule\Tests\Feature;

use Dgtlss\Capsule\Models\BackupLog;
use Dgtlss\Capsule\Tests\TestCase;

class ListCommandTest extends TestCase
{
    public function test_list_command_shows_table(): void
    {
        BackupLog::create([
            'started_at' => now(),
            'completed_at' => now(),
            'status' => 'success',
            'file_path' => 'backups/backup_2024.zip',
            'file_size' => 1024,
        ]);

        $this->artisan('capsule:list')
            ->assertExitCode(0);
    }

    public function test_list_command_json_format(): void
    {
        BackupLog::create([
            'started_at' => now(),
            'completed_at' => now(),
            'status' => 'success',
            'file_path' => 'backups/backup_2024.zip',
            'file_size' => 2048,
            'tag' => 'test-tag',
        ]);

        $this->artisan('capsule:list', ['--format' => 'json'])
            ->assertExitCode(0)
            ->expectsOutputToContain('test-tag');
    }

    public function test_list_command_empty(): void
    {
        $this->artisan('capsule:list')
            ->assertExitCode(0);
    }
}
