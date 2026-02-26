<?php

namespace Dgtlss\Capsule\Tests\Feature;

use Dgtlss\Capsule\Models\BackupLog;
use Dgtlss\Capsule\Tests\TestCase;

class CleanupCommandTest extends TestCase
{
    public function test_cleanup_dry_run_does_not_delete(): void
    {
        BackupLog::create([
            'started_at' => now()->subDays(60),
            'completed_at' => now()->subDays(60),
            'status' => 'success',
            'file_path' => 'backups/old.zip',
            'file_size' => 1024,
            'created_at' => now()->subDays(60),
        ]);

        $this->artisan('capsule:cleanup', ['--dry-run' => true, '--days' => 30])
            ->assertExitCode(0);

        $this->assertDatabaseCount('backup_logs', 1);
    }

    public function test_cleanup_respects_retention_days(): void
    {
        $recent = BackupLog::create([
            'started_at' => now(),
            'completed_at' => now(),
            'status' => 'success',
            'file_path' => 'backups/recent.zip',
            'file_size' => 1024,
        ]);

        $this->artisan('capsule:cleanup', ['--days' => 30])
            ->assertExitCode(0);

        $this->assertDatabaseHas('backup_logs', ['id' => $recent->id]);
    }

    public function test_cleanup_json_output(): void
    {
        $this->artisan('capsule:cleanup', ['--format' => 'json'])
            ->assertExitCode(0)
            ->expectsOutputToContain('"deleted_count"');
    }
}
