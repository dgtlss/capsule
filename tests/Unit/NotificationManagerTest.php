<?php

namespace Dgtlss\Capsule\Tests\Unit;

use Dgtlss\Capsule\Models\BackupLog;
use Dgtlss\Capsule\Notifications\NotificationManager;
use Dgtlss\Capsule\Tests\TestCase;

class NotificationManagerTest extends TestCase
{
    protected function makeSuccessLog(): BackupLog
    {
        return new BackupLog([
            'started_at' => now()->subMinutes(5),
            'completed_at' => now(),
            'status' => 'success',
            'file_path' => 'backups/backup_2024.zip',
            'file_size' => 1048576,
            'tag' => 'nightly',
        ]);
    }

    protected function makeFailedLog(): BackupLog
    {
        return new BackupLog([
            'started_at' => now()->subMinutes(2),
            'completed_at' => now(),
            'status' => 'failed',
            'error_message' => 'Disk full',
            'tag' => 'pre-deploy',
        ]);
    }

    public function test_success_message_contains_app_context(): void
    {
        config(['app.name' => 'TestApp']);

        $manager = new NotificationManager();
        $method = new \ReflectionMethod($manager, 'buildSuccessMessage');
        $method->setAccessible(true);

        $msg = $method->invoke($manager, $this->makeSuccessLog());

        $this->assertEquals('success', $msg['type']);
        $this->assertStringContains('TestApp', $msg['message']);
        $this->assertEquals('TestApp', $msg['details']['Application']);
        $this->assertNotEmpty($msg['details']['Host']);
        $this->assertEquals('nightly', $msg['details']['Tag']);
        $this->assertEquals('1 MB', $msg['details']['File size']);
        $this->assertArrayHasKey('context', $msg);
        $this->assertEquals('TestApp', $msg['context']['app_name']);
    }

    public function test_failure_message_contains_error_and_context(): void
    {
        config(['app.name' => 'MyApp']);

        $manager = new NotificationManager();
        $method = new \ReflectionMethod($manager, 'buildFailureMessage');
        $method->setAccessible(true);

        $exception = new \Exception('Connection timed out');
        $msg = $method->invoke($manager, $this->makeFailedLog(), $exception);

        $this->assertEquals('failure', $msg['type']);
        $this->assertStringContains('MyApp', $msg['message']);
        $this->assertEquals('Connection timed out', $msg['details']['Error']);
        $this->assertEquals('pre-deploy', $msg['details']['Tag']);
    }

    public function test_failure_message_detects_preflight_db_failure(): void
    {
        $log = new BackupLog([
            'started_at' => now(),
            'completed_at' => now(),
            'status' => 'failed',
            'metadata' => [
                'failure_stage' => 'preflight',
                'failed_connections' => [['connection' => 'mysql']],
            ],
        ]);

        $manager = new NotificationManager();
        $method = new \ReflectionMethod($manager, 'buildFailureMessage');
        $method->setAccessible(true);

        $msg = $method->invoke($manager, $log, new \Exception('Database unreachable for connection(s): mysql'));

        $this->assertStringContains('Database Unreachable', $msg['title']);
        $this->assertEquals('mysql', $msg['details']['Affected connections']);
        $this->assertArrayHasKey('Recommended action', $msg['details']);
    }

    public function test_cleanup_message_contains_context(): void
    {
        config(['app.name' => 'CleanApp']);

        $manager = new NotificationManager();
        $method = new \ReflectionMethod($manager, 'buildCleanupMessage');
        $method->setAccessible(true);

        $msg = $method->invoke($manager, 5, 52428800);

        $this->assertEquals('cleanup', $msg['type']);
        $this->assertStringContains('5', $msg['message']);
        $this->assertStringContains('CleanApp', $msg['message']);
        $this->assertEquals('50 MB', $msg['details']['Space freed']);
        $this->assertEquals('5', $msg['details']['Backups deleted']);
    }

    public function test_should_notify_returns_true_when_null(): void
    {
        $manager = new NotificationManager();
        $method = new \ReflectionMethod($manager, 'shouldNotify');
        $method->setAccessible(true);

        config(['capsule.notifications.email.notify_on' => null]);

        $this->assertTrue($method->invoke($manager, 'capsule.notifications.email', 'success'));
        $this->assertTrue($method->invoke($manager, 'capsule.notifications.email', 'failure'));
        $this->assertTrue($method->invoke($manager, 'capsule.notifications.email', 'cleanup'));
    }

    public function test_should_notify_filters_by_event(): void
    {
        $manager = new NotificationManager();
        $method = new \ReflectionMethod($manager, 'shouldNotify');
        $method->setAccessible(true);

        config(['capsule.notifications.email.notify_on' => ['failure']]);

        $this->assertFalse($method->invoke($manager, 'capsule.notifications.email', 'success'));
        $this->assertTrue($method->invoke($manager, 'capsule.notifications.email', 'failure'));
        $this->assertFalse($method->invoke($manager, 'capsule.notifications.email', 'cleanup'));
    }

    public function test_should_notify_handles_csv_string(): void
    {
        $manager = new NotificationManager();
        $method = new \ReflectionMethod($manager, 'shouldNotify');
        $method->setAccessible(true);

        config(['capsule.notifications.email.notify_on' => 'success,failure']);

        $this->assertTrue($method->invoke($manager, 'capsule.notifications.email', 'success'));
        $this->assertTrue($method->invoke($manager, 'capsule.notifications.email', 'failure'));
        $this->assertFalse($method->invoke($manager, 'capsule.notifications.email', 'cleanup'));
    }

    protected static function assertStringContains(string $needle, string $haystack): void
    {
        static::assertStringContainsString($needle, $haystack);
    }
}
