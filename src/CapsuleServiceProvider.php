<?php

namespace Dgtlss\Capsule;

use Dgtlss\Capsule\Commands\BackupCommand;
use Dgtlss\Capsule\Commands\RestoreCommand;
use Dgtlss\Capsule\Commands\CleanupCommand;
use Dgtlss\Capsule\Commands\DiagnoseCommand;
use Dgtlss\Capsule\Commands\VerifyCommand;
use Dgtlss\Capsule\Commands\ListCommand;
use Dgtlss\Capsule\Commands\InspectCommand;
use Dgtlss\Capsule\Commands\HealthCommand;
use Dgtlss\Capsule\Services\BackupService;
use Dgtlss\Capsule\Services\ChunkedBackupService;
use Dgtlss\Capsule\Services\RestoreService;
use Illuminate\Support\ServiceProvider;
use Illuminate\Console\Scheduling\Schedule;

class CapsuleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/capsule.php', 'capsule');

        $this->app->singleton(BackupService::class, function ($app) {
            return new BackupService($app);
        });

        $this->app->singleton(ChunkedBackupService::class, function ($app) {
            return new ChunkedBackupService($app);
        });

        $this->app->singleton(RestoreService::class, function ($app) {
            return new RestoreService();
        });
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/../config/capsule.php' => config_path('capsule.php'),
        ], 'capsule-config');

        // Register facade alias
        $this->app->alias('Capsule', \Dgtlss\Capsule\Facades\Capsule::class);

        // Views for Filament panel (optional in host app)
        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'capsule');

        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

        if ($this->app->runningInConsole()) {
            $this->commands([
                BackupCommand::class,
                RestoreCommand::class,
                CleanupCommand::class,
                DiagnoseCommand::class,
                VerifyCommand::class,
                ListCommand::class,
                InspectCommand::class,
                HealthCommand::class,
            ]);
        }

        $this->scheduleBackups();
    }

    protected function scheduleBackups(): void
    {
        if (!config('capsule.schedule.enabled')) {
            return;
        }

        $this->app->booted(function () {
            $schedule = $this->app->make(Schedule::class);
            $frequency = config('capsule.schedule.frequency', 'daily');
            $time = config('capsule.schedule.time', '02:00');

            $command = $schedule->command('capsule:backup');

            match ($frequency) {
                'hourly' => $command->hourly(),
                'daily' => $command->dailyAt($time),
                'weekly' => $command->weekly(),
                'monthly' => $command->monthly(),
                default => $command->dailyAt($time),
            };
        });
    }
}