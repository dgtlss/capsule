<?php

namespace Dgtlss\Capsule\Tests\Unit;

use Dgtlss\Capsule\Health\CapsuleBackupCheck;
use Dgtlss\Capsule\Health\Checks\BackupHealthCheck;
use Dgtlss\Capsule\Models\BackupLog;
use Dgtlss\Capsule\Tests\TestCase;

class BackupHealthCheckTest extends TestCase
{
    public function test_past_backup_age_is_positive_with_carbon_three(): void
    {
        $this->travelTo(now()->startOfDay());
        $backup = BackupLog::create(['started_at' => now()->subDays(5), 'status' => 'success']);
        $backup->forceFill(['created_at' => now()->subDays(5)])->save();
        $this->assertSame(5, BackupHealthCheck::lastSuccessAgeDays());
    }

    public function test_future_clock_skew_does_not_produce_a_negative_age(): void
    {
        $backup = BackupLog::create(['started_at' => now(), 'status' => 'success']);
        $backup->forceFill(['created_at' => now()->addDays(2)])->save();
        $this->assertSame(0, BackupHealthCheck::lastSuccessAgeDays());
    }

    public function test_spatie_warning_is_not_overwritten_by_ok(): void
    {
        if (!class_exists(\Spatie\Health\Checks\Check::class)) {
            $this->markTestSkipped('Optional spatie/laravel-health integration is not installed.');
        }
        $backup = BackupLog::create(['started_at' => now()->subDays(5), 'status' => 'success']);
        $backup->forceFill(['created_at' => now()->subDays(5)])->save();
        $result = CapsuleBackupCheck::new()->run();
        $this->assertSame('warning', $result->status->value);
    }
}
