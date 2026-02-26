<?php

namespace Dgtlss\Capsule\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BackupSnapshot extends Model
{
    protected $table = 'backup_snapshots';

    protected $fillable = [
        'backup_log_id',
        'type',
        'base_snapshot_id',
        'file_index',
        'total_files',
        'total_size',
    ];

    protected $casts = [
        'total_files' => 'integer',
        'total_size' => 'integer',
    ];

    public function backupLog(): BelongsTo
    {
        return $this->belongsTo(BackupLog::class);
    }

    public function baseSnapshot(): BelongsTo
    {
        return $this->belongsTo(self::class, 'base_snapshot_id');
    }

    public function getFileIndexArray(): array
    {
        $raw = $this->file_index;
        if (empty($raw)) {
            return [];
        }

        return json_decode($raw, true) ?: [];
    }

    public function setFileIndexFromArray(array $index): void
    {
        $this->file_index = json_encode($index, JSON_UNESCAPED_SLASHES);
    }
}
