<?php

namespace Dgtlss\Capsule\Notifications\Channels;

use Dgtlss\Capsule\Models\BackupLog;
use Dgtlss\Capsule\Notifications\NotifierInterface;
use GuzzleHttp\Client;
use Exception;

class DiscordNotifier implements NotifierInterface
{
    protected Client $client;

    public function __construct()
    {
        $this->client = new Client();
    }

    public function sendSuccess(array $message, BackupLog $backupLog): void
    {
        $webhookUrl = config('capsule.notifications.webhooks.discord.webhook_url');

        if (!$webhookUrl) {
            return;
        }

        $payload = $this->buildDiscordPayload($message, 0x00ff00); // Green
        $this->sendWebhook($webhookUrl, $payload);
    }

    public function sendFailure(array $message, BackupLog $backupLog, Exception $exception): void
    {
        $webhookUrl = config('capsule.notifications.webhooks.discord.webhook_url');

        if (!$webhookUrl) {
            return;
        }

        $payload = $this->buildDiscordPayload($message, 0xff0000); // Red
        $this->sendWebhook($webhookUrl, $payload);
    }

    protected function buildDiscordPayload(array $message, int $color): array
    {
        $fields = [];
        foreach ($message['details'] as $key => $value) {
            $fields[] = [
                'name' => $key,
                'value' => $value,
                'inline' => true,
            ];
        }

        return [
            'username' => config('capsule.notifications.webhooks.discord.username', 'Capsule'),
            'embeds' => [
                [
                    'title' => $message['emoji'] . ' ' . $message['title'],
                    'description' => $message['message'],
                    'color' => $color,
                    'fields' => $fields,
                    'footer' => [
                        'text' => 'Capsule Backup',
                    ],
                    'timestamp' => now()->toISOString(),
                ],
            ],
        ];
    }

    protected function sendWebhook(string $url, array $payload): void
    {
        $this->client->post($url, [
            'json' => $payload,
            'timeout' => 10,
        ]);
    }
}