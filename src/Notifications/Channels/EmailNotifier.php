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

        Mail::raw($this->buildEmailContent($message), function ($mail) use ($to, $subject) {
            $mail->to($to)
                 ->subject($subject);
        });
    }

    public function sendFailure(array $message, BackupLog $backupLog, Exception $exception): void
    {
        $to = config('capsule.notifications.email.to');
        $subject = config('capsule.notifications.email.subject_failure', 'Backup Failed');

        if (!$to) {
            return;
        }

        Mail::raw($this->buildEmailContent($message), function ($mail) use ($to, $subject) {
            $mail->to($to)
                 ->subject($subject);
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
}