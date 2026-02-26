<?php

namespace Dgtlss\Capsule\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class BackupLog extends Model
{
    protected $fillable = [
        'started_at',
        'completed_at',
        'status',
        'tag',
        'file_path',
        'file_size',
        'error_message',
        'metadata',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'metadata' => 'array',
        'file_size' => 'integer',
    ];

    public function duration(): Attribute
    {
        return Attribute::make(
            get: function () {
                if (!$this->started_at || !$this->completed_at) {
                    return null;
                }

                return $this->started_at->diffInSeconds($this->completed_at);
            }
        );
    }

    public function formattedFileSize(): Attribute
    {
        return Attribute::make(
            get: function () {
                if (!$this->file_size) {
                    return '0 B';
                }

                $units = ['B', 'KB', 'MB', 'GB', 'TB'];
                $pow = floor(log($this->file_size, 1024));

                return round($this->file_size / (1024 ** $pow), 2) . ' ' . $units[$pow];
            }
        );
    }

    public function scopeSuccessful($query)
    {
        return $query->where('status', 'success');
    }

    public function scopeFailed($query)
    {
        return $query->where('status', 'failed');
    }

    public function scopeRunning($query)
    {
        return $query->where('status', 'running');
    }

    public function scopeRecent($query, int $days = 30)
    {
        return $query->where('created_at', '>=', now()->subDays($days));
    }

    public function isSuccessful(): bool
    {
        return $this->status === 'success';
    }

    public function isFailed(): bool
    {
        return $this->status === 'failed';
    }

    public function isRunning(): bool
    {
        return $this->status === 'running';
    }

    public function metric(): HasOne
    {
        return $this->hasOne(BackupMetric::class);
    }

    public function verificationLogs(): HasMany
    {
        return $this->hasMany(VerificationLog::class);
    }
}