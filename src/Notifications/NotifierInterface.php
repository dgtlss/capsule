<?php

namespace Dgtlss\Capsule\Notifications;

use Dgtlss\Capsule\Models\BackupLog;
use Dgtlss\Capsule\Support\BackupContext;
use Exception;
use Throwable;

interface NotifierInterface
{
    public function sendSuccess(array $message, BackupLog $backupLog): void;

    public function sendFailure(array $message, BackupLog $backupLog, Exception $exception): void;

    public function sendCleanup(array $message, int $deletedCount, int $deletedSize): void;

    public function sendRestoreSuccess(array $message, BackupLog $backupLog, BackupContext $context): void;

    public function sendRestoreFailure(array $message, BackupLog $backupLog, Throwable $exception): void;
}