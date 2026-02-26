<?php

namespace Dgtlss\Capsule\Tests;

use Dgtlss\Capsule\CapsuleServiceProvider;
use Orchestra\Testbench\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function getPackageProviders($app): array
    {
        return [
            CapsuleServiceProvider::class,
        ];
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        $app['config']->set('filesystems.disks.capsule-test', [
            'driver' => 'local',
            'root' => sys_get_temp_dir() . '/capsule-tests',
        ]);

        $app['config']->set('capsule.default_disk', 'capsule-test');
        $app['config']->set('capsule.backup_path', 'backups');
        $app['config']->set('capsule.database.enabled', false);
        $app['config']->set('capsule.files.enabled', false);
        $app['config']->set('capsule.notifications.enabled', false);
        $app['config']->set('capsule.schedule.enabled', false);
    }

    protected function tearDown(): void
    {
        $testDir = sys_get_temp_dir() . '/capsule-tests';
        if (is_dir($testDir)) {
            $this->removeDirectory($testDir);
        }

        parent::tearDown();
    }

    protected function removeDirectory(string $dir): void
    {
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            if ($item->isDir()) {
                rmdir($item->getRealPath());
            } else {
                unlink($item->getRealPath());
            }
        }

        rmdir($dir);
    }
}
