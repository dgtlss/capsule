<?php

namespace Dgtlss\Capsule\Notifications\Channels;

use Dgtlss\Capsule\Models\BackupLog;
use Dgtlss\Capsule\Notifications\NotifierInterface;
use GuzzleHttp\Client;
use Exception;

class GoogleChatNotifier implements NotifierInterface
{
    protected Client $client;

    public function __construct()
    {
        $this->client = new Client();
    }

    public function sendSuccess(array $message, BackupLog $backupLog): void
    {
        $webhookUrl = config('capsule.notifications.webhooks.google_chat.webhook_url');

        if (!$webhookUrl) {
            return;
        }

        $payload = $this->buildGoogleChatPayload($message);
        $this->sendWebhook($webhookUrl, $payload);
    }

    public function sendFailure(array $message, BackupLog $backupLog, Exception $exception): void
    {
        $webhookUrl = config('capsule.notifications.webhooks.google_chat.webhook_url');

        if (!$webhookUrl) {
            return;
        }

        $payload = $this->buildGoogleChatPayload($message);
        $this->sendWebhook($webhookUrl, $payload);
    }

    public function sendCleanup(array $message, int $deletedCount, int $deletedSize): void
    {
        $webhookUrl = config('capsule.notifications.webhooks.google_chat.webhook_url');

        if (!$webhookUrl) {
            return;
        }

        $payload = $this->buildGoogleChatPayload($message);
        $this->sendWebhook($webhookUrl, $payload);
    }

    protected function buildGoogleChatPayload(array $message): array
    {
        $text = $message['emoji'] . ' *' . $message['title'] . '*' . "\n\n";
        $text .= $message['message'] . "\n\n";
        
        foreach ($message['details'] as $key => $value) {
            $text .= "*{$key}:* {$value}\n";
        }

        return [
            'text' => $text,
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