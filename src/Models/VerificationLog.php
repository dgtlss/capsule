<?php

namespace Dgtlss\Capsule\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VerificationLog extends Model
{
    protected $table = 'backup_verification_logs';

    protected $fillable = [
        'backup_log_id',
        'status',
        'entries_checked',
        'entries_failed',
        'error_message',
        'duration_seconds',
        'trigger',
    ];

    protected $casts = [
        'entries_checked' => 'integer',
        'entries_failed' => 'integer',
        'duration_seconds' => 'float',
    ];

    public function backupLog(): BelongsTo
    {
        return $this->belongsTo(BackupLog::class);
    }

    public function scopePassed($query)
    {
        return $query->where('status', 'passed');
    }

    public function scopeFailed($query)
    {
        return $query->where('status', 'failed');
    }
}
