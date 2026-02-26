<?php

namespace Dgtlss\Capsule\Notifications\Channels;

use Dgtlss\Capsule\Models\BackupLog;
use Dgtlss\Capsule\Notifications\NotifierInterface;
use Dgtlss\Capsule\Notifications\WebhookDispatcher;
use GuzzleHttp\Client;
use Exception;

class DiscordNotifier implements NotifierInterface
{
    protected WebhookDispatcher $dispatcher;

    public function __construct(?WebhookDispatcher $dispatcher = null)
    {
        $this->dispatcher = $dispatcher ?? new WebhookDispatcher();
    }

    public function sendSuccess(array $message, BackupLog $backupLog): void
    {
        $this->send($message);
    }

    public function sendFailure(array $message, BackupLog $backupLog, Exception $exception): void
    {
        $this->send($message);
    }

    public function sendCleanup(array $message, int $deletedCount, int $deletedSize): void
    {
        $this->send($message);
    }

    protected function send(array $message): void
    {
        $webhookUrl = config('capsule.notifications.webhooks.discord.webhook_url');
        if (!$webhookUrl) {
            return;
        }

        $payload = $this->buildPayload($message);
        $this->dispatcher->dispatch($webhookUrl, $payload, 'discord');
    }

    protected function buildPayload(array $message): array
    {
        $type = $message['type'] ?? 'success';
        $emoji = $message['emoji'] ?? '';
        $colorInt = $message['color_int'] ?? 0x6b7280;
        $title = $message['title'] ?? 'Capsule Backup';
        $subtitle = $message['message'] ?? '';
        $ctx = $message['context'] ?? [];
        $details = $message['details'] ?? [];

        $inlineKeys = ['Application', 'Environment', 'Host', 'Duration', 'File size', 'Storage disk', 'Tag', 'Backups deleted', 'Space freed'];

        $fields = [];
        $errorValue = null;

        foreach ($details as $key => $value) {
            if (strtolower($key) === 'error') {
                $errorValue = $value;
                continue;
            }

            $fields[] = [
                'name' => $key,
                'value' => strlen((string) $value) > 200 ? substr((string) $value, 0, 200) . '...' : (string) $value,
                'inline' => in_array($key, $inlineKeys),
            ];
        }

        $description = $subtitle;
        if ($errorValue) {
            $errorDisplay = strlen($errorValue) > 1000 ? substr($errorValue, 0, 1000) . '...' : $errorValue;
            $description .= "\n\n**Error**\n```\n{$errorDisplay}\n```";
        }

        $embed = [
            'title' => "{$emoji}  {$title}",
            'description' => $description,
            'color' => $colorInt,
            'fields' => $fields,
            'footer' => [
                'text' => 'Capsule Backup' . (isset($ctx['hostname']) ? " | {$ctx['hostname']}" : ''),
            ],
            'timestamp' => now()->toISOString(),
        ];

        if (isset($ctx['app_name'])) {
            $embed['author'] = [
                'name' => $ctx['app_name'] . (isset($ctx['app_env']) ? " ({$ctx['app_env']})" : ''),
            ];
        }

        return [
            'username' => config('capsule.notifications.webhooks.discord.username', 'Capsule'),
            'avatar_url' => config('capsule.notifications.webhooks.discord.avatar_url'),
            'embeds' => [$embed],
        ];
    }
}
