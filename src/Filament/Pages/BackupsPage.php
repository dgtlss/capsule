<?php

namespace Dgtlss\Capsule\Filament\Pages;

use Dgtlss\Capsule\Models\BackupLog;
use Filament\Pages\Page;

class BackupsPage extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-cloud-arrow-down';
    protected static string $view = 'capsule::filament.backups-page';
    protected static ?string $navigationLabel = 'Capsule Backups';
    protected static ?string $navigationGroup = 'Maintenance';

    public static function shouldRegisterNavigation(): bool
    {
        return class_exists(Page::class);
    }
}
