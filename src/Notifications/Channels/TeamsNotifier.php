<?php

namespace Dgtlss\Capsule\Notifications\Channels;

use Dgtlss\Capsule\Models\BackupLog;
use Dgtlss\Capsule\Notifications\NotifierInterface;
use GuzzleHttp\Client;
use Exception;

class TeamsNotifier implements NotifierInterface
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
        $webhookUrl = config('capsule.notifications.webhooks.teams.webhook_url');
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

        $statusStyle = match ($type) {
            'success' => 'good',
            'failure' => 'attention',
            'cleanup' => 'accent',
            default => 'default',
        };

        $body = [];

        $body[] = [
            'type' => 'ColumnSet',
            'columns' => [
                [
                    'type' => 'Column',
                    'width' => 'auto',
                    'items' => [
                        ['type' => 'TextBlock', 'text' => $emoji, 'size' => 'large'],
                    ],
                ],
                [
                    'type' => 'Column',
                    'width' => 'stretch',
                    'items' => [
                        ['type' => 'TextBlock', 'text' => $title, 'weight' => 'bolder', 'size' => 'medium', 'wrap' => true],
                        ['type' => 'TextBlock', 'text' => $subtitle, 'spacing' => 'none', 'isSubtle' => true, 'wrap' => true],
                    ],
                ],
            ],
        ];

        $factSet = ['type' => 'FactSet', 'facts' => []];
        $errorValue = null;

        foreach ($details as $key => $value) {
            if (strtolower($key) === 'error' || strtolower($key) === 'recommended action') {
                $errorValue = $errorValue ? $errorValue . "\n{$key}: {$value}" : "{$key}: {$value}";
                continue;
            }
            $factSet['facts'][] = ['title' => $key, 'value' => (string) $value];
        }

        if (!empty($factSet['facts'])) {
            $body[] = $factSet;
        }

        if ($errorValue) {
            $body[] = [
                'type' => 'Container',
                'style' => 'attention',
                'items' => [
                    ['type' => 'TextBlock', 'text' => 'Error Details', 'weight' => 'bolder', 'color' => 'attention'],
                    ['type' => 'TextBlock', 'text' => $errorValue, 'wrap' => true, 'fontType' => 'monospace', 'size' => 'small'],
                ],
            ];
        }

        $contextLine = implode(' | ', array_filter([
            $ctx['app_name'] ?? null,
            $ctx['app_env'] ?? null,
            $ctx['hostname'] ?? null,
            now()->format('M d, Y H:i T'),
        ]));

        $body[] = [
            'type' => 'TextBlock',
            'text' => $contextLine,
            'isSubtle' => true,
            'size' => 'small',
            'spacing' => 'medium',
            'separator' => true,
        ];

        return [
            'type' => 'message',
            'attachments' => [
                [
                    'contentType' => 'application/vnd.microsoft.card.adaptive',
                    'contentUrl' => null,
                    'content' => [
                        '$schema' => 'http://adaptivecards.io/schemas/adaptive-card.json',
                        'type' => 'AdaptiveCard',
                        'version' => '1.4',
                        'body' => $body,
                    ],
                ],
            ],
        ];
    }
}
