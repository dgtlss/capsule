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

        foreach ($this->notifiers as $entry) {
            if (!$this->shouldNotify($entry['config_key'], 'success')) {
                continue;
            }
            try {
                $entry['notifier']->sendSuccess($message, $backupLog);
            } catch (Exception $e) {
                logger()->error("Failed to send success notification via " . get_class($entry['notifier']) . ": " . $e->getMessage());
            }
        }
    }

    public function sendFailureNotification(BackupLog $backupLog, Exception $exception): void
    {
        if (!config('capsule.notifications.enabled', true)) {
            return;
        }

        $message = $this->buildFailureMessage($backupLog, $exception);

        foreach ($this->notifiers as $entry) {
            if (!$this->shouldNotify($entry['config_key'], 'failure')) {
                continue;
            }
            try {
                $entry['notifier']->sendFailure($message, $backupLog, $exception);
            } catch (Exception $e) {
                logger()->error("Failed to send failure notification via " . get_class($entry['notifier']) . ": " . $e->getMessage());
            }
        }
    }

    public function sendCleanupNotification(int $deletedCount, int $deletedSize): void
    {
        if (!config('capsule.notifications.enabled', true)) {
            return;
        }

        $message = $this->buildCleanupMessage($deletedCount, $deletedSize);

        foreach ($this->notifiers as $entry) {
            if (!$this->shouldNotify($entry['config_key'], 'cleanup')) {
                continue;
            }
            try {
                $entry['notifier']->sendCleanup($message, $deletedCount, $deletedSize);
            } catch (Exception $e) {
                logger()->error("Failed to send cleanup notification via " . get_class($entry['notifier']) . ": " . $e->getMessage());
            }
        }
    }

    protected function shouldNotify(string $configKey, string $event): bool
    {
        $notifyOn = config("{$configKey}.notify_on");

        if ($notifyOn === null) {
            return true;
        }

        $events = is_array($notifyOn) ? $notifyOn : explode(',', (string) $notifyOn);
        $events = array_map('trim', $events);

        return in_array($event, $events, true);
    }

    protected function initializeNotifiers(): void
    {
        if (config('capsule.notifications.email.enabled', false)) {
            $this->notifiers[] = [
                'notifier' => new EmailNotifier(),
                'config_key' => 'capsule.notifications.email',
            ];
        }

        $webhookChannels = [
            'slack' => SlackNotifier::class,
            'discord' => DiscordNotifier::class,
            'teams' => TeamsNotifier::class,
            'google_chat' => GoogleChatNotifier::class,
        ];

        foreach ($webhookChannels as $key => $class) {
            if (config("capsule.notifications.webhooks.{$key}.enabled", false)) {
                $this->notifiers[] = [
                    'notifier' => new $class(),
                    'config_key' => "capsule.notifications.webhooks.{$key}",
                ];
            }
        }
    }

    protected function buildContext(): array
    {
        return [
            'app_name' => config('app.name', 'Laravel'),
            'app_env' => app()->environment(),
            'hostname' => gethostname() ?: 'unknown',
            'disk' => config('capsule.default_disk', 'local'),
            'backup_path' => config('capsule.backup_path', 'backups'),
            'app_url' => config('app.url'),
        ];
    }

    protected function buildSuccessMessage(BackupLog $backupLog): array
    {
        $ctx = $this->buildContext();
        $duration = $backupLog->started_at && $backupLog->completed_at
            ? $backupLog->started_at->diffForHumans($backupLog->completed_at, true)
            : 'unknown';

        return [
            'type' => 'success',
            'title' => 'Backup Completed Successfully',
            'message' => "A backup of {$ctx['app_name']} ({$ctx['app_env']}) completed successfully.",
            'context' => $ctx,
            'details' => [
                'Application' => $ctx['app_name'],
                'Environment' => $ctx['app_env'],
                'Host' => $ctx['hostname'],
                'Started at' => $backupLog->started_at ? $backupLog->started_at->format('Y-m-d H:i:s T') : 'Unknown',
                'Completed at' => $backupLog->completed_at ? $backupLog->completed_at->format('Y-m-d H:i:s T') : 'Unknown',
                'Duration' => $duration,
                'File size' => Helpers::formatBytes($backupLog->file_size ?? 0),
                'Storage disk' => $ctx['disk'],
                'Backup path' => $backupLog->file_path ?? $ctx['backup_path'],
                'Tag' => $backupLog->tag ?? '-',
            ],
            'color' => '#16a34a',
            'color_int' => 0x16a34a,
            'color_name' => 'good',
            'emoji' => "\u{2705}",
        ];
    }

    protected function buildFailureMessage(BackupLog $backupLog, Exception $exception): array
    {
        $ctx = $this->buildContext();
        $isPreflightDb = str_contains(strtolower($exception->getMessage()), 'database unreachable')
            || (is_array($backupLog->metadata ?? null) && ($backupLog->metadata['failure_stage'] ?? '') === 'preflight');

        $title = $isPreflightDb ? 'Backup Failed - Database Unreachable' : 'Backup Failed';
        $message = $isPreflightDb
            ? "Capsule could not reach the database for {$ctx['app_name']} ({$ctx['app_env']}). No backup was produced."
            : "The backup of {$ctx['app_name']} ({$ctx['app_env']}) has failed.";

        $details = [
            'Application' => $ctx['app_name'],
            'Environment' => $ctx['app_env'],
            'Host' => $ctx['hostname'],
            'Started at' => $backupLog->started_at ? $backupLog->started_at->format('Y-m-d H:i:s T') : 'Unknown',
            'Failed at' => $backupLog->completed_at ? $backupLog->completed_at->format('Y-m-d H:i:s T') : 'Unknown',
            'Error' => $exception->getMessage(),
        ];

        if ($backupLog->tag) {
            $details['Tag'] = $backupLog->tag;
        }

        if ($isPreflightDb && is_array($backupLog->metadata ?? null) && !empty($backupLog->metadata['failed_connections'] ?? [])) {
            $failed = collect($backupLog->metadata['failed_connections'])->pluck('connection')->implode(', ');
            $details['Affected connections'] = $failed;
            $details['Recommended action'] = 'Verify DB host/port/credentials and that the database service is reachable.';
        }

        return [
            'type' => 'failure',
            'title' => $title,
            'message' => $message,
            'context' => $ctx,
            'details' => $details,
            'color' => '#dc2626',
            'color_int' => 0xdc2626,
            'color_name' => 'danger',
            'emoji' => "\u{274C}",
        ];
    }

    protected function buildCleanupMessage(int $deletedCount, int $deletedSize): array
    {
        $ctx = $this->buildContext();

        return [
            'type' => 'cleanup',
            'title' => 'Backup Cleanup Completed',
            'message' => "{$deletedCount} old backup(s) removed from {$ctx['app_name']} ({$ctx['app_env']}).",
            'context' => $ctx,
            'details' => [
                'Application' => $ctx['app_name'],
                'Environment' => $ctx['app_env'],
                'Host' => $ctx['hostname'],
                'Backups deleted' => (string) $deletedCount,
                'Space freed' => Helpers::formatBytes($deletedSize),
                'Storage disk' => $ctx['disk'],
                'Completed at' => now()->format('Y-m-d H:i:s T'),
            ],
            'color' => '#2563eb',
            'color_int' => 0x2563eb,
            'color_name' => 'warning',
            'emoji' => "\u{1F9F9}",
        ];
    }
}
