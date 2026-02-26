<?php

namespace Dgtlss\Capsule\Tests\Unit;

use Dgtlss\Capsule\Notifications\Channels\GoogleChatNotifier;
use Dgtlss\Capsule\Tests\TestCase;

class GoogleChatNotifierTest extends TestCase
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

    public function test_uses_cards_v2_format(): void
    {
        $notifier = new GoogleChatNotifier();
        $method = new \ReflectionMethod($notifier, 'buildPayload');
        $method->setAccessible(true);

        $payload = $method->invoke($notifier, $this->buildMessage());

        $this->assertArrayHasKey('cardsV2', $payload);
        $this->assertArrayNotHasKey('text', $payload);

        $card = $payload['cardsV2'][0]['card'];
        $this->assertArrayHasKey('header', $card);
        $this->assertArrayHasKey('sections', $card);
        $this->assertStringContainsString('Backup Completed', $card['header']['title']);
        $this->assertStringContainsString('TestApp', $card['header']['subtitle']);
    }

    public function test_does_not_use_plain_text(): void
    {
        $notifier = new GoogleChatNotifier();
        $method = new \ReflectionMethod($notifier, 'buildPayload');
        $method->setAccessible(true);

        $payload = $method->invoke($notifier, $this->buildMessage());

        $this->assertArrayNotHasKey('text', $payload);
    }

    public function test_error_displayed_with_color(): void
    {
        $msg = $this->buildMessage('failure');
        $msg['details']['Error'] = 'Connection refused';

        $notifier = new GoogleChatNotifier();
        $method = new \ReflectionMethod($notifier, 'buildPayload');
        $method->setAccessible(true);

        $payload = $method->invoke($notifier, $msg);

        $widgets = $payload['cardsV2'][0]['card']['sections'][0]['widgets'];
        $hasError = false;
        foreach ($widgets as $widget) {
            if (isset($widget['decoratedText']['topLabel']) && $widget['decoratedText']['topLabel'] === 'Error') {
                $hasError = true;
                $this->assertStringContainsString('Connection refused', $widget['decoratedText']['text']);
                break;
            }
        }
        $this->assertTrue($hasError, 'Error should be present in widgets');
    }
}
