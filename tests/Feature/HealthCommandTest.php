<?php

namespace Dgtlss\Capsule\Tests\Feature;

use Dgtlss\Capsule\Models\BackupLog;
use Dgtlss\Capsule\Tests\TestCase;

class HealthCommandTest extends TestCase
{
    public function test_health_command_returns_json(): void
    {
        BackupLog::create([
            'started_at' => now(),
            'completed_at' => now(),
            'status' => 'success',
            'file_size' => 1024,
        ]);

        $this->artisan('capsule:health')
            ->assertExitCode(0)
            ->expectsOutputToContain('last_success_age_days');
    }

    public function test_health_command_with_no_backups(): void
    {
        $this->artisan('capsule:health')
            ->assertExitCode(0)
            ->expectsOutputToContain('"last_success_age_days": null');
    }
}
