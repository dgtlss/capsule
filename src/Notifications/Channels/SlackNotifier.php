<?php

namespace Dgtlss\Capsule\Notifications\Channels;

use Dgtlss\Capsule\Models\BackupLog;
use Dgtlss\Capsule\Notifications\NotifierInterface;
use GuzzleHttp\Client;
use Exception;

class SlackNotifier implements NotifierInterface
{
    protected Client $client;

    public function __construct(?Client $client = null)
    {
        $this->client = $client ?? new Client();
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
        $webhookUrl = config('capsule.notifications.webhooks.slack.webhook_url');
        if (!$webhookUrl) {
            return;
        }

        $payload = $this->buildPayload($message);

        $this->client->post($webhookUrl, [
            'json' => $payload,
            'timeout' => 10,
        ]);
    }

    protected function buildPayload(array $message): array
    {
        $type = $message['type'] ?? 'success';
        $emoji = $message['emoji'] ?? '';
        $color = $message['color'] ?? '#6b7280';
        $title = $message['title'] ?? 'Capsule Backup';
        $subtitle = $message['message'] ?? '';
        $ctx = $message['context'] ?? [];
        $details = $message['details'] ?? [];

        $blocks = [];

        $blocks[] = [
            'type' => 'header',
            'text' => ['type' => 'plain_text', 'text' => "{$emoji}  {$title}", 'emoji' => true],
        ];

        $blocks[] = [
            'type' => 'section',
            'text' => ['type' => 'mrkdwn', 'text' => $subtitle],
        ];

        $blocks[] = ['type' => 'divider'];

        $fieldPairs = $this->buildFieldPairs($details, $type);
        foreach ($fieldPairs as $pair) {
            $block = ['type' => 'section', 'fields' => []];
            foreach ($pair as $field) {
                $block['fields'][] = ['type' => 'mrkdwn', 'text' => $field];
            }
            $blocks[] = $block;
        }

        if (isset($details['Error'])) {
            $blocks[] = ['type' => 'divider'];
            $errorText = strlen($details['Error']) > 2900
                ? substr($details['Error'], 0, 2900) . '...'
                : $details['Error'];
            $blocks[] = [
                'type' => 'section',
                'text' => ['type' => 'mrkdwn', 'text' => "*Error*\n```{$errorText}```"],
            ];
        }

        $contextParts = array_filter([
            $ctx['app_name'] ?? null,
            $ctx['app_env'] ?? null,
            $ctx['hostname'] ?? null,
        ]);
        if ($contextParts) {
            $blocks[] = [
                'type' => 'context',
                'elements' => [
                    ['type' => 'mrkdwn', 'text' => implode(' | ', $contextParts) . ' | ' . now()->format('M d, Y H:i T')],
                ],
            ];
        }

        return [
            'channel' => config('capsule.notifications.webhooks.slack.channel', '#general'),
            'username' => config('capsule.notifications.webhooks.slack.username', 'Capsule'),
            'icon_emoji' => config('capsule.notifications.webhooks.slack.icon_emoji', ':package:'),
            'blocks' => $blocks,
        ];
    }

    protected function buildFieldPairs(array $details, string $type): array
    {
        $shortKeys = ['Application', 'Environment', 'Host', 'Duration', 'File size', 'Storage disk', 'Tag', 'Backups deleted', 'Space freed'];
        $skipKeys = ['Error'];

        $fields = [];
        foreach ($details as $key => $value) {
            if (in_array($key, $skipKeys)) {
                continue;
            }

            $isShort = in_array($key, $shortKeys);
            $fields[] = ['text' => "*{$key}*\n{$value}", 'short' => $isShort];
        }

        $pairs = [];
        $currentPair = [];
        foreach ($fields as $field) {
            if ($field['short'] && count($currentPair) < 2) {
                $currentPair[] = $field['text'];
            } else {
                if (!empty($currentPair)) {
                    $pairs[] = $currentPair;
                    $currentPair = [];
                }
                if ($field['short']) {
                    $currentPair[] = $field['text'];
                } else {
                    $pairs[] = [$field['text']];
                }
            }
        }
        if (!empty($currentPair)) {
            $pairs[] = $currentPair;
        }

        return $pairs;
    }
}
