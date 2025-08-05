<?php

namespace Dgtlss\Capsule\Notifications\Channels;

use Dgtlss\Capsule\Models\BackupLog;
use Dgtlss\Capsule\Notifications\NotifierInterface;
use GuzzleHttp\Client;
use Exception;

class TeamsNotifier implements NotifierInterface
{
    protected Client $client;

    public function __construct()
    {
        $this->client = new Client();
    }

    public function sendSuccess(array $message, BackupLog $backupLog): void
    {
        $webhookUrl = config('capsule.notifications.webhooks.teams.webhook_url');

        if (!$webhookUrl) {
            return;
        }

        $payload = $this->buildTeamsPayload($message, 'Good');
        $this->sendWebhook($webhookUrl, $payload);
    }

    public function sendFailure(array $message, BackupLog $backupLog, Exception $exception): void
    {
        $webhookUrl = config('capsule.notifications.webhooks.teams.webhook_url');

        if (!$webhookUrl) {
            return;
        }

        $payload = $this->buildTeamsPayload($message, 'Attention');
        $this->sendWebhook($webhookUrl, $payload);
    }

    protected function buildTeamsPayload(array $message, string $themeColor): array
    {
        $facts = [];
        foreach ($message['details'] as $key => $value) {
            $facts[] = [
                'name' => $key,
                'value' => $value,
            ];
        }

        return [
            '@type' => 'MessageCard',
            '@context' => 'https://schema.org/extensions',
            'summary' => $message['title'],
            'themeColor' => $themeColor === 'Good' ? '00ff00' : 'ff0000',
            'sections' => [
                [
                    'activityTitle' => $message['emoji'] . ' ' . $message['title'],
                    'activitySubtitle' => $message['message'],
                    'facts' => $facts,
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