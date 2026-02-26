<?php

namespace Dgtlss\Capsule\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BackupMetric extends Model
{
    protected $table = 'backup_metrics';

    protected $fillable = [
        'backup_log_id',
        'raw_size',
        'compressed_size',
        'file_count',
        'directory_count',
        'db_dump_count',
        'db_raw_size',
        'files_raw_size',
        'duration_seconds',
        'compression_ratio',
        'throughput_bytes_per_sec',
        'extension_breakdown',
    ];

    protected $casts = [
        'raw_size' => 'integer',
        'compressed_size' => 'integer',
        'file_count' => 'integer',
        'directory_count' => 'integer',
        'db_dump_count' => 'integer',
        'db_raw_size' => 'integer',
        'files_raw_size' => 'integer',
        'duration_seconds' => 'float',
        'compression_ratio' => 'float',
        'throughput_bytes_per_sec' => 'float',
        'extension_breakdown' => 'array',
    ];

    public function backupLog(): BelongsTo
    {
        return $this->belongsTo(BackupLog::class);
    }
}
