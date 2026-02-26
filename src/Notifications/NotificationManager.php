<?php

namespace Dgtlss\Capsule\Notifications;

use Dgtlss\Capsule\Models\BackupLog;
use Dgtlss\Capsule\Notifications\Channels\EmailNotifier;
use Dgtlss\Capsule\Notifications\Channels\SlackNotifier;
use Dgtlss\Capsule\Notifications\Channels\DiscordNotifier;
use Dgtlss\Capsule\Notifications\Channels\TeamsNotifier;
use Dgtlss\Capsule\Notifications\Channels\GoogleChatNotifier;
use Dgtlss\Capsule\Support\Helpers;
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

    public function sendCleanupNotification(int $deletedCount, int $deletedSize): void
    {
        if (!config('capsule.notifications.enabled', true)) {
            return;
        }

        $message = $this->buildCleanupMessage($deletedCount, $deletedSize);

        foreach ($this->notifiers as $notifier) {
            try {
                $notifier->sendCleanup($message, $deletedCount, $deletedSize);
            } catch (Exception $e) {
                logger()->error("Failed to send cleanup notification via " . get_class($notifier) . ": " . $e->getMessage());
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
                'File size' => Helpers::formatBytes($backupLog->file_size),
                'Status' => 'Success',
            ],
            'color' => 'good',
            'emoji' => '✅',
        ];
    }

    protected function buildFailureMessage(BackupLog $backupLog, Exception $exception): array
    {
        $isPreflightDb = str_contains(strtolower($exception->getMessage()), 'database unreachable')
            || (is_array($backupLog->metadata ?? null) && ($backupLog->metadata['failure_stage'] ?? '') === 'preflight');

        $title = $isPreflightDb ? 'Backup Failed – Database Unreachable' : 'Backup Failed';
        $message = $isPreflightDb
            ? 'Capsule could not reach the configured database connection(s). No backup was performed.'
            : 'The backup process has failed.';

        $details = [
            'Started at' => $backupLog->started_at ? $backupLog->started_at->format('Y-m-d H:i:s') : 'Unknown',
            'Failed at' => $backupLog->completed_at ? $backupLog->completed_at->format('Y-m-d H:i:s') : 'Unknown',
            'Error' => $exception->getMessage(),
        ];

        if ($isPreflightDb && is_array($backupLog->metadata ?? null) && !empty($backupLog->metadata['failed_connections'] ?? [])) {
            $failed = collect($backupLog->metadata['failed_connections'])->pluck('connection')->implode(', ');
            $details['Affected connections'] = $failed;
            $details['Action'] = 'Verify DB host/port/credentials and that the database service is reachable from the application environment.';
        }

        $details['Status'] = 'Failed';

        return [
            'title' => $title,
            'message' => $message,
            'details' => $details,
            'color' => 'danger',
            'emoji' => '❌',
        ];
    }

    protected function buildCleanupMessage(int $deletedCount, int $deletedSize): array
    {
        return [
            'title' => 'Cleanup Completed',
            'message' => "{$deletedCount} items were removed.",
            'details' => [
                'Items deleted' => $deletedCount,
                'Space freed' => Helpers::formatBytes($deletedSize),
                'Completed at' => now()->format('Y-m-d H:i:s'),
                'Status' => 'Success',
            ],
            'color' => 'good',
            'emoji' => '🧹',
        ];
    }

}