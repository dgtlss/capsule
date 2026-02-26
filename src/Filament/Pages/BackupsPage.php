<?php

namespace Dgtlss\Capsule\Filament\Pages;

use Dgtlss\Capsule\Health\Checks\BackupHealthCheck;
use Dgtlss\Capsule\Models\BackupLog;
use Dgtlss\Capsule\Support\Helpers;
use Filament\Pages\Page;

class BackupsPage extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-cloud-arrow-down';
    protected static string $view = 'capsule::filament.backups-page';
    protected static ?string $navigationLabel = 'Capsule Backups';
    protected static ?string $navigationGroup = 'Maintenance';

    public int $perPage = 25;
    public string $statusFilter = '';
    public string $search = '';

    public static function shouldRegisterNavigation(): bool
    {
        return class_exists(Page::class);
    }

    public function getBackupsProperty()
    {
        $query = BackupLog::orderByDesc('created_at');

        if ($this->statusFilter) {
            $query->where('status', $this->statusFilter);
        }

        if ($this->search) {
            $query->where(function ($q) {
                $q->where('file_path', 'like', "%{$this->search}%")
                  ->orWhere('tag', 'like', "%{$this->search}%");
            });
        }

        return $query->paginate($this->perPage);
    }

    public function getHealthProperty(): array
    {
        return [
            'age' => BackupHealthCheck::lastSuccessAgeDays(),
            'failures' => BackupHealthCheck::recentFailuresCount(),
            'usage' => Helpers::formatBytes(BackupHealthCheck::storageUsageBytes()),
        ];
    }
}
