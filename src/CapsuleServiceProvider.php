<?php

namespace Dgtlss\Capsule;

use Dgtlss\Capsule\Commands\AdvisorCommand;
use Dgtlss\Capsule\Commands\BackupCommand;
use Dgtlss\Capsule\Commands\CleanupCommand;
use Dgtlss\Capsule\Commands\DiagnoseCommand;
use Dgtlss\Capsule\Commands\VerifyCommand;
use Dgtlss\Capsule\Commands\VerifyScheduledCommand;
use Dgtlss\Capsule\Commands\ListCommand;
use Dgtlss\Capsule\Commands\InspectCommand;
use Dgtlss\Capsule\Commands\DownloadCommand;
use Dgtlss\Capsule\Commands\HealthCommand;
use Dgtlss\Capsule\Commands\RestoreCommand;
use Dgtlss\Capsule\Database\DatabaseDumper;
use Dgtlss\Capsule\Notifications\NotificationManager;
use Dgtlss\Capsule\Services\BackupService;
use Dgtlss\Capsule\Services\ChunkedBackupService;
use Dgtlss\Capsule\Storage\StorageManager;
use Dgtlss\Capsule\Support\ManifestBuilder;
use Illuminate\Support\ServiceProvider;
use Illuminate\Console\Scheduling\Schedule;

class CapsuleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/capsule.php', 'capsule');

        $this->app->singleton(StorageManager::class);
        $this->app->singleton(NotificationManager::class);
        $this->app->singleton(DatabaseDumper::class);

        $this->app->singleton(BackupService::class, function ($app) {
            return new BackupService(
                $app,
                $app->make(StorageManager::class),
                $app->make(NotificationManager::class),
                $app->make(DatabaseDumper::class),
                new ManifestBuilder(),
            );
        });

        $this->app->singleton(ChunkedBackupService::class, function ($app) {
            return new ChunkedBackupService(
                $app,
                $app->make(StorageManager::class),
                $app->make(NotificationManager::class),
                $app->make(DatabaseDumper::class),
                new ManifestBuilder(),
            );
        });
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/../config/capsule.php' => config_path('capsule.php'),
        ], 'capsule-config');

        // Views for Filament panel (optional in host app)
        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'capsule');

        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

        if ($this->app->runningInConsole()) {
            $this->commands([
                AdvisorCommand::class,
                BackupCommand::class,
                CleanupCommand::class,
                DiagnoseCommand::class,
                VerifyCommand::class,
                ListCommand::class,
                InspectCommand::class,
                HealthCommand::class,
                RestoreCommand::class,
                DownloadCommand::class,
                VerifyScheduledCommand::class,
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

            if (config('capsule.verification.schedule_enabled', true)) {
                $verifyFreq = config('capsule.verification.frequency', 'daily');
                $verifyTime = config('capsule.verification.time', '04:00');
                $verifyCmd = $schedule->command('capsule:verify-scheduled');

                match ($verifyFreq) {
                    'hourly' => $verifyCmd->hourly(),
                    'daily' => $verifyCmd->dailyAt($verifyTime),
                    'weekly' => $verifyCmd->weeklyOn(0, $verifyTime),
                    default => $verifyCmd->dailyAt($verifyTime),
                };
            }

            $command = $schedule->command('capsule:backup');

            match ($frequency) {
                'hourly' => $command->hourly(),
                'daily' => $command->dailyAt($time),
                'twiceDaily' => $command->twiceDaily(1, 13),
                'everyFourHours' => $command->everyFourHours(),
                'everySixHours' => $command->everySixHours(),
                'weekly' => $command->weeklyOn(0, $time),
                'monthly' => $command->monthlyOn(1, $time),
                default => str_contains($frequency, ' ')
                    ? $command->cron($frequency)
                    : $command->dailyAt($time),
            };
        });
    }
}