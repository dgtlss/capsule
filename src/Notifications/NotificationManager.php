<?php

namespace Dgtlss\Capsule\Notifications;

use Dgtlss\Capsule\Models\BackupLog;
use Dgtlss\Capsule\Notifications\Channels\EmailNotifier;
use Dgtlss\Capsule\Notifications\Channels\SlackNotifier;
use Dgtlss\Capsule\Notifications\Channels\DiscordNotifier;
use Dgtlss\Capsule\Notifications\Channels\TeamsNotifier;
use Dgtlss\Capsule\Notifications\Channels\GoogleChatNotifier;
use Exception;

class NotificationManager
{
    protected array $notifiers = [];

    public function __construct()
    {
        $this->initializeNotifiers();
    }

    public function sendSuccessNotification(BackupLog $backupLog): void
    {
        if (!config('capsule.notifications.enabled', true)) {
            return;
        }

        $message = $this->buildSuccessMessage($backupLog);

        foreach ($this->notifiers as $notifier) {
            try {
                $notifier->sendSuccess($message, $backupLog);
            } catch (Exception $e) {
                logger()->error("Failed to send success notification via " . get_class($notifier) . ": " . $e->getMessage());
            }
        }
    }

    public function sendFailureNotification(BackupLog $backupLog, Exception $exception): void
    {
        if (!config('capsule.notifications.enabled', true)) {
            return;
        }

        $message = $this->buildFailureMessage($backupLog, $exception);

        foreach ($this->notifiers as $notifier) {
            try {
                $notifier->sendFailure($message, $backupLog, $exception);
            } catch (Exception $e) {
                logger()->error("Failed to send failure notification via " . get_class($notifier) . ": " . $e->getMessage());
            }
        }
    }

    protected function initializeNotifiers(): void
    {
        if (config('capsule.notifications.email.enabled', false)) {
            $this->notifiers[] = new EmailNotifier();
        }

        if (config('capsule.notifications.webhooks.slack.enabled', false)) {
            $this->notifiers[] = new SlackNotifier();
        }

        if (config('capsule.notifications.webhooks.discord.enabled', false)) {
            $this->notifiers[] = new DiscordNotifier();
        }

        if (config('capsule.notifications.webhooks.teams.enabled', false)) {
            $this->notifiers[] = new TeamsNotifier();
        }

        if (config('capsule.notifications.webhooks.google_chat.enabled', false)) {
            $this->notifiers[] = new GoogleChatNotifier();
        }
    }

    protected function buildSuccessMessage(BackupLog $backupLog): array
    {
        return [
            'title' => 'Backup Completed Successfully',
            'message' => 'The backup process has completed successfully.',
            'details' => [
                'Started at' => $backupLog->started_at->format('Y-m-d H:i:s'),
                'Completed at' => $backupLog->completed_at->format('Y-m-d H:i:s'),
                'Duration' => $backupLog->started_at->diffForHumans($backupLog->completed_at, true),
                'File size' => $this->formatBytes($backupLog->file_size),
                'Status' => 'Success',
            ],
            'color' => 'good',
            'emoji' => '✅',
        ];
    }

    protected function buildFailureMessage(BackupLog $backupLog, Exception $exception): array
    {
        return [
            'title' => 'Backup Failed',
            'message' => 'The backup process has failed.',
            'details' => [
                'Started at' => $backupLog->started_at->format('Y-m-d H:i:s'),
                'Failed at' => $backupLog->completed_at ? $backupLog->completed_at->format('Y-m-d H:i:s') : 'Unknown',
                'Error' => $exception->getMessage(),
                'Status' => 'Failed',
            ],
            'color' => 'danger',
            'emoji' => '❌',
        ];
    }

    protected function formatBytes(int $bytes): string
    {
        if ($bytes === 0) {
            return '0 B';
        }

        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $pow = floor(log($bytes, 1024));

        return round($bytes / (1024 ** $pow), 2) . ' ' . $units[$pow];
    }
}