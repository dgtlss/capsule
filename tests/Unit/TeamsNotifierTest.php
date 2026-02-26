<?php

namespace Dgtlss\Capsule\Tests\Unit;

use Dgtlss\Capsule\Notifications\Channels\TeamsNotifier;
use Dgtlss\Capsule\Tests\TestCase;

class TeamsNotifierTest extends TestCase
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
            ],
            'details' => [
                'Application' => 'TestApp',
                'Duration' => '2 minutes',
                'File size' => '50 MB',
            ],
            'color' => '#16a34a',
            'color_int' => 0x16a34a,
            'emoji' => "\u{2705}",
        ];
    }

    public function test_uses_adaptive_card_format(): void
    {
        $notifier = new TeamsNotifier();
        $method = new \ReflectionMethod($notifier, 'buildPayload');
        $method->setAccessible(true);

        $payload = $method->invoke($notifier, $this->buildMessage());

        $this->assertEquals('message', $payload['type']);
        $this->assertCount(1, $payload['attachments']);

        $card = $payload['attachments'][0];
        $this->assertEquals('application/vnd.microsoft.card.adaptive', $card['contentType']);
        $this->assertEquals('AdaptiveCard', $card['content']['type']);
        $this->assertEquals('1.4', $card['content']['version']);
    }

    public function test_does_not_use_legacy_message_card(): void
    {
        $notifier = new TeamsNotifier();
        $method = new \ReflectionMethod($notifier, 'buildPayload');
        $method->setAccessible(true);

        $payload = $method->invoke($notifier, $this->buildMessage());

        $this->assertArrayNotHasKey('@type', $payload);
        $this->assertArrayNotHasKey('@context', $payload);
    }

    public function test_error_shown_in_attention_container(): void
    {
        $msg = $this->buildMessage('failure');
        $msg['details']['Error'] = 'Database connection refused';

        $notifier = new TeamsNotifier();
        $method = new \ReflectionMethod($notifier, 'buildPayload');
        $method->setAccessible(true);

        $payload = $method->invoke($notifier, $msg);
        $body = $payload['attachments'][0]['content']['body'];

        $attentionContainers = array_filter($body, fn($b) => ($b['type'] ?? '') === 'Container' && ($b['style'] ?? '') === 'attention');
        $this->assertNotEmpty($attentionContainers, 'Error should be in an attention container');
    }
}
