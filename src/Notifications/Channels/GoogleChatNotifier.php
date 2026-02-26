<?php

namespace Dgtlss\Capsule\Notifications\Channels;

use Dgtlss\Capsule\Models\BackupLog;
use Dgtlss\Capsule\Notifications\NotifierInterface;
use Dgtlss\Capsule\Notifications\WebhookDispatcher;
use GuzzleHttp\Client;
use Exception;

class GoogleChatNotifier implements NotifierInterface
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
        $webhookUrl = config('capsule.notifications.webhooks.google_chat.webhook_url');
        if (!$webhookUrl) {
            return;
        }

        $payload = $this->buildPayload($message);
        $this->dispatcher->dispatch($webhookUrl, $payload, 'google_chat');
    }

    protected function buildPayload(array $message): array
    {
        $type = $message['type'] ?? 'success';
        $emoji = $message['emoji'] ?? '';
        $title = $message['title'] ?? 'Capsule Backup';
        $subtitle = $message['message'] ?? '';
        $ctx = $message['context'] ?? [];
        $details = $message['details'] ?? [];

        $widgets = [];

        $widgets[] = [
            'decoratedText' => [
                'topLabel' => ($ctx['app_name'] ?? 'App') . ' (' . ($ctx['app_env'] ?? '') . ')',
                'text' => "<b>{$title}</b>",
                'bottomLabel' => $subtitle,
            ],
        ];

        $errorValue = null;
        $detailWidgets = [];

        foreach ($details as $key => $value) {
            if (strtolower($key) === 'error') {
                $errorValue = $value;
                continue;
            }

            $detailWidgets[] = [
                'decoratedText' => [
                    'topLabel' => $key,
                    'text' => (string) $value,
                ],
            ];
        }

        // Group detail widgets into columns of two
        for ($i = 0; $i < count($detailWidgets); $i += 2) {
            if (isset($detailWidgets[$i + 1])) {
                $widgets[] = [
                    'columns' => [
                        'columnItems' => [
                            ['horizontalSizeStyle' => 'FILL_AVAILABLE_SPACE', 'horizontalAlignment' => 'START', 'verticalAlignment' => 'CENTER', 'widgets' => [$detailWidgets[$i]]],
                            ['horizontalSizeStyle' => 'FILL_AVAILABLE_SPACE', 'horizontalAlignment' => 'START', 'verticalAlignment' => 'CENTER', 'widgets' => [$detailWidgets[$i + 1]]],
                        ],
                    ],
                ];
            } else {
                $widgets[] = $detailWidgets[$i];
            }
        }

        if ($errorValue) {
            $widgets[] = ['divider' => new \stdClass()];
            $errorDisplay = strlen($errorValue) > 2000 ? substr($errorValue, 0, 2000) . '...' : $errorValue;
            $widgets[] = [
                'decoratedText' => [
                    'topLabel' => 'Error',
                    'text' => "<font color=\"#dc2626\">{$errorDisplay}</font>",
                    'wrapText' => true,
                ],
            ];
        }

        $contextLine = implode(' | ', array_filter([
            $ctx['hostname'] ?? null,
            now()->format('M d, Y H:i T'),
        ]));

        $widgets[] = ['divider' => new \stdClass()];
        $widgets[] = [
            'decoratedText' => [
                'text' => "<font color=\"#9ca3af\">Capsule Backup | {$contextLine}</font>",
            ],
        ];

        $headerSubtitle = ($ctx['app_name'] ?? '') . (isset($ctx['app_env']) ? " ({$ctx['app_env']})" : '');

        return [
            'cardsV2' => [
                [
                    'cardId' => 'capsule-' . $type . '-' . time(),
                    'card' => [
                        'header' => [
                            'title' => "{$emoji}  {$title}",
                            'subtitle' => $headerSubtitle,
                        ],
                        'sections' => [
                            ['widgets' => $widgets],
                        ],
                    ],
                ],
            ],
        ];
    }
}
