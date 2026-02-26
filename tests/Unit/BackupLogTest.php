<?php

namespace Dgtlss\Capsule\Tests\Unit;

use Dgtlss\Capsule\Models\BackupLog;
use Dgtlss\Capsule\Tests\TestCase;

class BackupLogTest extends TestCase
{
    public function test_create_backup_log(): void
    {
        $log = BackupLog::create([
            'started_at' => now(),
            'status' => 'running',
        ]);

        $this->assertDatabaseHas('backup_logs', ['id' => $log->id, 'status' => 'running']);
    }

    public function test_scopes(): void
    {
        BackupLog::create(['started_at' => now(), 'status' => 'success', 'completed_at' => now()]);
        BackupLog::create(['started_at' => now(), 'status' => 'failed', 'completed_at' => now()]);
        BackupLog::create(['started_at' => now(), 'status' => 'running']);

        $this->assertEquals(1, BackupLog::successful()->count());
        $this->assertEquals(1, BackupLog::failed()->count());
        $this->assertEquals(1, BackupLog::running()->count());
    }

    public function test_is_status_methods(): void
    {
        $success = new BackupLog(['status' => 'success']);
        $failed = new BackupLog(['status' => 'failed']);
        $running = new BackupLog(['status' => 'running']);

        $this->assertTrue($success->isSuccessful());
        $this->assertFalse($success->isFailed());

        $this->assertTrue($failed->isFailed());
        $this->assertFalse($failed->isSuccessful());

        $this->assertTrue($running->isRunning());
    }

    public function test_formatted_file_size(): void
    {
        $log = new BackupLog(['file_size' => 1048576]);
        $this->assertEquals('1 MB', $log->formattedFileSize);
    }

    public function test_duration(): void
    {
        $log = new BackupLog([
            'started_at' => now()->subSeconds(45),
            'completed_at' => now(),
        ]);

        $this->assertEquals(45, $log->duration);
    }

    public function test_duration_null_when_incomplete(): void
    {
        $log = new BackupLog(['started_at' => now()]);
        $this->assertNull($log->duration);
    }

    public function test_tag_is_fillable(): void
    {
        $log = BackupLog::create([
            'started_at' => now(),
            'status' => 'success',
            'completed_at' => now(),
            'tag' => 'pre-deploy',
        ]);

        $this->assertEquals('pre-deploy', $log->fresh()->tag);
    }

    public function test_recent_scope(): void
    {
        BackupLog::create(['started_at' => now(), 'status' => 'success', 'completed_at' => now()]);

        $old = BackupLog::create(['started_at' => now()->subDays(60), 'status' => 'success', 'completed_at' => now()->subDays(60)]);
        $old->created_at = now()->subDays(60);
        $old->save();

        $this->assertEquals(1, BackupLog::recent(30)->count());
    }
}
