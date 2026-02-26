<?php

namespace Dgtlss\Capsule\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditLog extends Model
{
    protected $table = 'backup_audit_logs';

    protected $fillable = [
        'action',
        'trigger',
        'actor',
        'backup_log_id',
        'policy',
        'status',
        'details',
    ];

    protected $casts = [
        'details' => 'array',
    ];

    public function backupLog(): BelongsTo
    {
        return $this->belongsTo(BackupLog::class);
    }
}
