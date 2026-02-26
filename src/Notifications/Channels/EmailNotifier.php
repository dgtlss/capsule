<?php

namespace Dgtlss\Capsule\Notifications\Channels;

use Dgtlss\Capsule\Models\BackupLog;
use Dgtlss\Capsule\Notifications\NotifierInterface;
use Illuminate\Support\Facades\Mail;
use Exception;

class EmailNotifier implements NotifierInterface
{
    public function sendSuccess(array $message, BackupLog $backupLog): void
    {
        $to = config('capsule.notifications.email.to');
        $subject = config('capsule.notifications.email.subject_success', 'Backup Completed Successfully');

        if (!$to) {
            return;
        }

        $appName = $message['context']['app_name'] ?? 'Laravel';
        $env = $message['context']['app_env'] ?? '';
        $subject = "[{$appName}] {$subject}" . ($env ? " ({$env})" : '');

        $this->send($to, $subject, $message);
    }

    public function sendFailure(array $message, BackupLog $backupLog, Exception $exception): void
    {
        $to = config('capsule.notifications.email.to');
        $subject = config('capsule.notifications.email.subject_failure', 'Backup Failed');

        if (!$to) {
            return;
        }

        $appName = $message['context']['app_name'] ?? 'Laravel';
        $env = $message['context']['app_env'] ?? '';
        $subject = "[{$appName}] {$subject}" . ($env ? " ({$env})" : '');

        $this->send($to, $subject, $message);
    }

    public function sendCleanup(array $message, int $deletedCount, int $deletedSize): void
    {
        $to = config('capsule.notifications.email.to');
        $subject = config('capsule.notifications.email.subject_cleanup', 'Backup Cleanup Completed');

        if (!$to) {
            return;
        }

        $appName = $message['context']['app_name'] ?? 'Laravel';
        $subject = "[{$appName}] {$subject}";

        $this->send($to, $subject, $message);
    }

    protected function send(string $to, string $subject, array $message): void
    {
        $html = $this->buildHtml($message);
        $from = config('capsule.notifications.email.from');

        Mail::html($html, function ($mail) use ($to, $subject, $from) {
            $mail->to($to)->subject($subject);
            if ($from) {
                $mail->from($from);
            }
        });
    }

    protected function buildHtml(array $message): string
    {
        $type = $message['type'] ?? 'success';
        $color = $message['color'] ?? '#6b7280';
        $emoji = $message['emoji'] ?? '';
        $title = htmlspecialchars((string) ($message['title'] ?? 'Capsule Backup'));
        $subtitle = htmlspecialchars((string) ($message['message'] ?? ''));
        $appName = htmlspecialchars((string) ($message['context']['app_name'] ?? 'Laravel'));
        $env = htmlspecialchars((string) ($message['context']['app_env'] ?? ''));
        $hostname = htmlspecialchars((string) ($message['context']['hostname'] ?? ''));

        $statusLabel = match ($type) {
            'success' => 'Success',
            'failure' => 'Failed',
            'cleanup' => 'Cleanup Complete',
            default => ucfirst($type),
        };

        $detailRows = '';
        foreach ($message['details'] ?? [] as $key => $value) {
            $k = htmlspecialchars((string) $key);
            $v = htmlspecialchars((string) $value);
            $isError = strtolower($key) === 'error' || strtolower($key) === 'recommended action';
            $valueStyle = $isError
                ? 'padding:10px 16px;color:#991b1b;font-size:13px;font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,monospace;word-break:break-all;background:#fef2f2'
                : 'padding:10px 16px;color:#111827;font-size:13px';

            $detailRows .= <<<ROW
            <tr>
              <td style="padding:10px 16px;color:#6b7280;font-size:12px;font-weight:500;white-space:nowrap;vertical-align:top;width:140px">{$k}</td>
              <td style="{$valueStyle}">{$v}</td>
            </tr>
ROW;
        }

        $timestamp = now()->format('M d, Y \a\t H:i T');

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>{$title}</title>
</head>
<body style="margin:0;padding:0;background-color:#f3f4f6;font-family:ui-sans-serif,system-ui,-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,'Helvetica Neue',Arial,sans-serif;-webkit-font-smoothing:antialiased">
  <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background-color:#f3f4f6">
    <tr>
      <td align="center" style="padding:32px 16px">
        <table role="presentation" width="600" cellspacing="0" cellpadding="0" style="max-width:600px;width:100%;background-color:#ffffff;border-radius:12px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,0.1)">

          <!-- Status Banner -->
          <tr>
            <td style="background-color:{$color};padding:20px 24px">
              <table role="presentation" width="100%" cellspacing="0" cellpadding="0">
                <tr>
                  <td style="font-size:28px;line-height:1;padding-right:12px;vertical-align:middle" width="40">{$emoji}</td>
                  <td style="vertical-align:middle">
                    <div style="color:#ffffff;font-size:18px;font-weight:700;line-height:1.3">{$title}</div>
                    <div style="color:rgba(255,255,255,0.85);font-size:13px;line-height:1.4;margin-top:2px">{$subtitle}</div>
                  </td>
                </tr>
              </table>
            </td>
          </tr>

          <!-- Detail Rows -->
          <tr>
            <td style="padding:8px 0">
              <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border-collapse:collapse">
                {$detailRows}
              </table>
            </td>
          </tr>

          <!-- Footer -->
          <tr>
            <td style="padding:16px 24px;border-top:1px solid #e5e7eb;background-color:#f9fafb">
              <table role="presentation" width="100%" cellspacing="0" cellpadding="0">
                <tr>
                  <td style="color:#9ca3af;font-size:11px;line-height:1.5">
                    Capsule Backup &middot; {$appName} &middot; {$env} &middot; {$hostname}<br>
                    {$timestamp}
                  </td>
                  <td align="right" style="color:{$color};font-size:12px;font-weight:600">
                    {$statusLabel}
                  </td>
                </tr>
              </table>
            </td>
          </tr>

        </table>
      </td>
    </tr>
  </table>
</body>
</html>
HTML;
    }
}
