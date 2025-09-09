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
        Mail::html($this->buildHtml($message, $backupLog, true), function ($mail) use ($to, $subject) {
            $mail->to($to)->subject($subject);
        });
    }

    public function sendFailure(array $message, BackupLog $backupLog, Exception $exception): void
    {
        $to = config('capsule.notifications.email.to');
        $subject = config('capsule.notifications.email.subject_failure', 'Backup Failed');

        if (!$to) {
            return;
        }

        Mail::html($this->buildHtml($message, $backupLog, false), function ($mail) use ($to, $subject) {
            $mail->to($to)->subject($subject);
        });
    }

    public function sendCleanup(array $message, int $deletedCount, int $deletedSize): void
    {
        $to = config('capsule.notifications.email.to');
        $subject = config('capsule.notifications.email.subject_cleanup', 'Cleanup Completed');

        if (!$to) {
            return;
        }

        Mail::html($this->buildCleanupHtml($message, $deletedCount, $deletedSize), function ($mail) use ($to, $subject) {
            $mail->to($to)->subject($subject);
        });
    }

    protected function buildEmailContent(array $message): string
    {
        $content = $message['title'] . "\n\n";
        $content .= $message['message'] . "\n\n";
        $content .= "Details:\n";

        foreach ($message['details'] as $key => $value) {
            $content .= "- {$key}: {$value}\n";
        }

        $content .= "\n--\nSent by Capsule Backup Package";

        return $content;
    }

    protected function buildHtml(array $message, BackupLog $backupLog, bool $success): string
    {
        $color = $success ? '#16a34a' : '#dc2626';
        $emoji = $message['emoji'] ?? ($success ? '✅' : '❌');
        $rows = '';
        foreach ($message['details'] as $k => $v) {
            $rows .= '<tr><td style="padding:6px 10px;color:#6b7280;font-size:12px">' . htmlspecialchars((string)$k) . '</td><td style="padding:6px 10px;color:#111827;font-size:12px">' . htmlspecialchars((string)$v) . '</td></tr>';
        }

        return '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width">
        <title>Capsule Backup</title></head>
        <body style="margin:0;background:#f9fafb;font-family:system-ui,-apple-system,Segoe UI,Roboto,Ubuntu,Cantarell,Noto Sans,sans-serif">
          <div style="max-width:640px;margin:24px auto;background:#ffffff;border:1px solid #e5e7eb;border-radius:8px;overflow:hidden">
            <div style="padding:16px 20px;border-bottom:1px solid #e5e7eb;display:flex;align-items:center;gap:10px">
              <div style="font-size:20px">' . $emoji . '</div>
              <div>
                <div style="font-weight:600;color:#111827">' . htmlspecialchars((string)($message['title'] ?? 'Backup')) . '</div>
                <div style="color:#6b7280;font-size:13px">' . htmlspecialchars((string)($message['message'] ?? '')) . '</div>
              </div>
            </div>
            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border-collapse:collapse">
              <tbody>' . $rows . '</tbody>
            </table>
            <div style="padding:12px 16px;border-top:1px solid #e5e7eb;color:#6b7280;font-size:12px">
              Capsule Backup • ' . ($success ? '<span style="color:' . $color . '">Success</span>' : '<span style="color:' . $color . '">Failed</span>') . '
            </div>
          </div>
        </body></html>';
    }

    protected function buildCleanupHtml(array $message, int $deletedCount, int $deletedSize): string
    {
        $color = '#16a34a';
        $emoji = $message['emoji'] ?? '🧹';
        $rows = '';
        foreach ($message['details'] as $k => $v) {
            $rows .= '<tr><td style="padding:8px 16px;border-bottom:1px solid #e5e7eb;color:#6b7280;font-size:13px">' . htmlspecialchars((string)$k) . '</td><td style="padding:8px 16px;border-bottom:1px solid #e5e7eb;color:#111827;font-size:13px">' . htmlspecialchars((string)$v) . '</td></tr>';
        }

        return '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . htmlspecialchars((string)($message['title'] ?? 'Cleanup')) . '</title></head><body style="font-family:ui-sans-serif,system-ui,-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,Helvetica Neue,Arial,Noto Sans,sans-serif;line-height:1.5;color:#111827;background-color:#f9fafb;margin:0;padding:0">
          <div style="max-width:600px;margin:0 auto;background-color:#ffffff;border-radius:8px;overflow:hidden;box-shadow:0 1px 3px 0 rgba(0,0,0,0.1)">
            <div style="padding:16px;border-bottom:1px solid #e5e7eb">
              <div style="display:flex;align-items:center;gap:8px">
                <span style="font-size:20px">' . $emoji . '</span>
                <div style="font-weight:600;color:#111827">' . htmlspecialchars((string)($message['title'] ?? 'Cleanup')) . '</div>
                <div style="color:#6b7280;font-size:13px">' . htmlspecialchars((string)($message['message'] ?? '')) . '</div>
              </div>
            </div>
            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border-collapse:collapse">
              <tbody>' . $rows . '</tbody>
            </table>
            <div style="padding:12px 16px;border-top:1px solid #e5e7eb;color:#6b7280;font-size:12px">
              Capsule Backup • <span style="color:' . $color . '">Cleanup Completed</span>
            </div>
          </div>
        </body></html>';
    }
}