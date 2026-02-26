<?php

namespace Dgtlss\Capsule\Tests\Unit;

use Dgtlss\Capsule\Notifications\Channels\SlackNotifier;
use Dgtlss\Capsule\Tests\TestCase;

class SlackNotifierTest extends TestCase
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
                'Duration' => '2 minutes',
                'File size' => '50 MB',
                'Storage disk' => 'local',
                'Tag' => 'nightly',
            ],
            'color' => '#16a34a',
            'color_int' => 0x16a34a,
            'color_name' => 'good',
            'emoji' => "\u{2705}",
        ];
    }

    protected function buildFailureMessage(): array
    {
        $msg = $this->buildMessage('failure');
        $msg['title'] = 'Backup Failed';
        $msg['message'] = 'The backup of TestApp (testing) has failed.';
        $msg['details']['Error'] = 'mysqldump: Got error: 2002: Connection refused';
        $msg['color'] = '#dc2626';
        $msg['emoji'] = "\u{274C}";
        return $msg;
    }

    public function test_success_payload_uses_block_kit(): void
    {
        $notifier = new SlackNotifier();
        $method = new \ReflectionMethod($notifier, 'buildPayload');
        $method->setAccessible(true);

        $payload = $method->invoke($notifier, $this->buildMessage());

        $this->assertArrayHasKey('blocks', $payload);
        $this->assertArrayNotHasKey('attachments', $payload);

        $blockTypes = array_column($payload['blocks'], 'type');
        $this->assertContains('header', $blockTypes);
        $this->assertContains('section', $blockTypes);
        $this->assertContains('divider', $blockTypes);
        $this->assertContains('context', $blockTypes);
    }

    public function test_failure_payload_includes_error_in_code_block(): void
    {
        $notifier = new SlackNotifier();
        $method = new \ReflectionMethod($notifier, 'buildPayload');
        $method->setAccessible(true);

        $payload = $method->invoke($notifier, $this->buildFailureMessage());
        $blocks = $payload['blocks'];

        $hasCodeBlock = false;
        foreach ($blocks as $block) {
            if (isset($block['text']['text']) && str_contains($block['text']['text'], '```')) {
                $hasCodeBlock = true;
                $this->assertStringContainsString('Connection refused', $block['text']['text']);
                break;
            }
        }
        $this->assertTrue($hasCodeBlock, 'Error should be displayed in a code block');
    }

    public function test_context_block_shows_app_info(): void
    {
        $notifier = new SlackNotifier();
        $method = new \ReflectionMethod($notifier, 'buildPayload');
        $method->setAccessible(true);

        $payload = $method->invoke($notifier, $this->buildMessage());

        $contextBlocks = array_filter($payload['blocks'], fn($b) => ($b['type'] ?? '') === 'context');
        $contextBlock = array_values($contextBlocks)[0] ?? null;

        $this->assertNotNull($contextBlock);
        $text = $contextBlock['elements'][0]['text'] ?? '';
        $this->assertStringContainsString('TestApp', $text);
        $this->assertStringContainsString('testing', $text);
        $this->assertStringContainsString('web-01', $text);
    }

    public function test_field_pairs_group_short_fields(): void
    {
        $notifier = new SlackNotifier();
        $method = new \ReflectionMethod($notifier, 'buildFieldPairs');
        $method->setAccessible(true);

        $details = [
            'Application' => 'TestApp',
            'Environment' => 'testing',
            'Host' => 'web-01',
            'Backup path' => 'backups/backup_2024-01-01.zip',
        ];

        $pairs = $method->invoke($notifier, $details, 'success');

        // Application + Environment should be paired, Host is short but Backup path is long
        $this->assertNotEmpty($pairs);
        // First pair should have 2 items (Application + Environment)
        $this->assertCount(2, $pairs[0]);
    }
}
