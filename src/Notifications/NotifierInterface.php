<?php

namespace Dgtlss\Capsule\Notifications;

use Dgtlss\Capsule\Models\BackupLog;
use Exception;

interface NotifierInterface
{
    public function sendSuccess(array $message, BackupLog $backupLog): void;
    
    public function sendFailure(array $message, BackupLog $backupLog, Exception $exception): void;
    
    public function sendCleanup(array $message, int $deletedCount, int $deletedSize): void;
}