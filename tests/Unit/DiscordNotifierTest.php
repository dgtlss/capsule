<?php

namespace Dgtlss\Capsule\Tests\Unit;

use Dgtlss\Capsule\Notifications\Channels\DiscordNotifier;
use Dgtlss\Capsule\Tests\TestCase;

class DiscordNotifierTest extends TestCase
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
                'Environment' => 'testing',
                'Host' => 'web-01',
                'Duration' => '2 minutes',
                'File size' => '50 MB',
            ],
            'color' => '#16a34a',
            'color_int' => 0x16a34a,
            'emoji' => "\u{2705}",
        ];
    }

    public function test_embed_has_author_and_footer(): void
    {
        config(['capsule.notifications.webhooks.discord.username' => 'TestBot']);

        $notifier = new DiscordNotifier();
        $method = new \ReflectionMethod($notifier, 'buildPayload');
        $method->setAccessible(true);

        $payload = $method->invoke($notifier, $this->buildMessage());

        $this->assertEquals('TestBot', $payload['username']);
        $this->assertCount(1, $payload['embeds']);

        $embed = $payload['embeds'][0];
        $this->assertEquals('TestApp (testing)', $embed['author']['name']);
        $this->assertStringContainsString('web-01', $embed['footer']['text']);
        $this->assertEquals(0x16a34a, $embed['color']);
        $this->assertNotEmpty($embed['timestamp']);
    }

    public function test_failure_embeds_error_in_description(): void
    {
        $msg = $this->buildMessage('failure');
        $msg['details']['Error'] = 'Something went wrong';

        $notifier = new DiscordNotifier();
        $method = new \ReflectionMethod($notifier, 'buildPayload');
        $method->setAccessible(true);

        $payload = $method->invoke($notifier, $msg);
        $embed = $payload['embeds'][0];

        $this->assertStringContainsString('Something went wrong', $embed['description']);
        $this->assertStringContainsString('```', $embed['description']);

        $fieldNames = array_column($embed['fields'], 'name');
        $this->assertNotContains('Error', $fieldNames);
    }

    public function test_inline_fields_are_set_correctly(): void
    {
        $notifier = new DiscordNotifier();
        $method = new \ReflectionMethod($notifier, 'buildPayload');
        $method->setAccessible(true);

        $payload = $method->invoke($notifier, $this->buildMessage());
        $embed = $payload['embeds'][0];

        foreach ($embed['fields'] as $field) {
            if (in_array($field['name'], ['Application', 'Environment', 'Host', 'Duration', 'File size'])) {
                $this->assertTrue($field['inline'], "{$field['name']} should be inline");
            }
        }
    }
}
