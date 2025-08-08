<?php

namespace Dgtlss\Capsule\Notifications\Channels;

use Dgtlss\Capsule\Models\BackupLog;
use Dgtlss\Capsule\Notifications\NotifierInterface;
use GuzzleHttp\Client;
use Exception;

class SlackNotifier implements NotifierInterface
{
    protected Client $client;

    public function __construct()
    {
        $this->client = new Client();
    }

    public function sendSuccess(array $message, BackupLog $backupLog): void
    {
        $webhookUrl = config('capsule.notifications.webhooks.slack.webhook_url');

        if (!$webhookUrl) {
            return;
        }

        $payload = $this->buildSlackPayload($message, 'good');
        $this->sendWebhook($webhookUrl, $payload);
    }

    public function sendFailure(array $message, BackupLog $backupLog, Exception $exception): void
    {
        $webhookUrl = config('capsule.notifications.webhooks.slack.webhook_url');

        if (!$webhookUrl) {
            return;
        }

        $payload = $this->buildSlackPayload($message, 'danger');
        $this->sendWebhook($webhookUrl, $payload);
    }

    protected function buildSlackPayload(array $message, string $color): array
    {
        $fields = [];
        foreach ($message['details'] as $key => $value) {
            $fields[] = [
                'title' => $key,
                'value' => $value,
                'short' => true,
            ];
        }

        $actions = [];
        if (!empty($message['details']['File size'] ?? null)) {
            $actions[] = [
                'type' => 'button',
                'text' => [ 'type' => 'plain_text', 'text' => 'Open Storage' ],
                'url' => config('app.url') ?? ''
            ];
        }

        return [
            'channel' => config('capsule.notifications.webhooks.slack.channel', '#general'),
            'username' => config('capsule.notifications.webhooks.slack.username', 'Capsule'),
            'attachments' => [[
                'color' => $color,
                'title' => $message['emoji'] . ' ' . $message['title'],
                'text' => $message['message'],
                'fields' => $fields,
                'footer' => 'Capsule Backup',
                'ts' => time(),
                'actions' => $actions,
            ]],
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