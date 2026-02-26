<?php

namespace Dgtlss\Capsule\Tests\Unit;

use Dgtlss\Capsule\Notifications\Channels\EmailNotifier;
use Dgtlss\Capsule\Tests\TestCase;

class EmailNotifierTest extends TestCase
{
    protected function buildMessage(string $type = 'success'): array
    {
        return [
            'type' => $type,
            'title' => 'Backup Completed Successfully',
            'message' => 'A backup of TestApp (testing) completed successfully.',
            'context' => [
                'app_name' => 'TestApp',
                'app_env' => 'testing',
                'hostname' => 'web-01',
                'disk' => 'local',
                'backup_path' => 'backups',
            ],
            'details' => [
                'Application' => 'TestApp',
                'Environment' => 'testing',
                'Host' => 'web-01',
                'Started at' => '2024-01-01 02:00:00 UTC',
                'Completed at' => '2024-01-01 02:05:00 UTC',
                'Duration' => '5 minutes',
                'File size' => '50 MB',
                'Storage disk' => 'local',
                'Backup path' => 'backups/backup_2024.zip',
                'Tag' => 'nightly',
            ],
            'color' => '#16a34a',
            'emoji' => "\u{2705}",
        ];
    }

    public function test_html_contains_status_banner_with_color(): void
    {
        $notifier = new EmailNotifier();
        $method = new \ReflectionMethod($notifier, 'buildHtml');
        $method->setAccessible(true);

        $html = $method->invoke($notifier, $this->buildMessage());

        $this->assertStringContainsString('#16a34a', $html);
        $this->assertStringContainsString('Backup Completed Successfully', $html);
    }

    public function test_html_contains_all_detail_rows(): void
    {
        $notifier = new EmailNotifier();
        $method = new \ReflectionMethod($notifier, 'buildHtml');
        $method->setAccessible(true);

        $html = $method->invoke($notifier, $this->buildMessage());

        $this->assertStringContainsString('TestApp', $html);
        $this->assertStringContainsString('testing', $html);
        $this->assertStringContainsString('web-01', $html);
        $this->assertStringContainsString('50 MB', $html);
        $this->assertStringContainsString('nightly', $html);
    }

    public function test_html_contains_footer_with_context(): void
    {
        $notifier = new EmailNotifier();
        $method = new \ReflectionMethod($notifier, 'buildHtml');
        $method->setAccessible(true);

        $html = $method->invoke($notifier, $this->buildMessage());

        $this->assertStringContainsString('Capsule Backup', $html);
        $this->assertStringContainsString('TestApp', $html);
        $this->assertStringContainsString('Success', $html);
    }

    public function test_failure_html_highlights_errors(): void
    {
        $msg = $this->buildMessage('failure');
        $msg['color'] = '#dc2626';
        $msg['details']['Error'] = 'mysqldump: Got error 2002';

        $notifier = new EmailNotifier();
        $method = new \ReflectionMethod($notifier, 'buildHtml');
        $method->setAccessible(true);

        $html = $method->invoke($notifier, $msg);

        $this->assertStringContainsString('#dc2626', $html);
        $this->assertStringContainsString('mysqldump', $html);
        $this->assertStringContainsString('monospace', $html);
        $this->assertStringContainsString('Failed', $html);
    }

    public function test_cleanup_html_rendered_correctly(): void
    {
        $msg = [
            'type' => 'cleanup',
            'title' => 'Backup Cleanup Completed',
            'message' => '5 old backup(s) removed.',
            'context' => ['app_name' => 'TestApp', 'app_env' => 'testing', 'hostname' => 'web-01'],
            'details' => ['Backups deleted' => '5', 'Space freed' => '200 MB'],
            'color' => '#2563eb',
            'emoji' => "\u{1F9F9}",
        ];

        $notifier = new EmailNotifier();
        $method = new \ReflectionMethod($notifier, 'buildHtml');
        $method->setAccessible(true);

        $html = $method->invoke($notifier, $msg);

        $this->assertStringContainsString('Cleanup', $html);
        $this->assertStringContainsString('200 MB', $html);
        $this->assertStringContainsString('#2563eb', $html);
    }

    public function test_html_is_valid_structure(): void
    {
        $notifier = new EmailNotifier();
        $method = new \ReflectionMethod($notifier, 'buildHtml');
        $method->setAccessible(true);

        $html = $method->invoke($notifier, $this->buildMessage());

        $this->assertStringContainsString('<!DOCTYPE html>', $html);
        $this->assertStringContainsString('<html', $html);
        $this->assertStringContainsString('</html>', $html);
        $this->assertStringContainsString('meta charset="utf-8"', $html);
        $this->assertStringContainsString('meta name="viewport"', $html);
    }
}
