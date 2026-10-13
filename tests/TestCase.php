<?php

namespace Dgtlss\Capsule\Tests;

use Dgtlss\Capsule\CapsuleServiceProvider;
use Orchestra\Testbench\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected ?string $testDirectory = null;

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
        $this->testDirectory = sys_get_temp_dir() . '/capsule-tests-' . bin2hex(random_bytes(8));
        mkdir($this->testDirectory, 0700, true);
        $app->useStoragePath($this->testDirectory . '/storage');
        mkdir($app->storagePath('framework/cache'), 0700, true);
        mkdir($app->storagePath('logs'), 0700, true);

        $app['config']->set('cache.default', 'array');
        $app['config']->set('app.key', 'base64:' . base64_encode(str_repeat('a', 32)));
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        $app['config']->set('filesystems.disks.capsule-test', [
            'driver' => 'local',
            'root' => $this->testDirectory . '/disk',
            'throw' => true,
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
        try {
            parent::tearDown();
        } finally {
            if ($this->testDirectory !== null && is_dir($this->testDirectory)) {
                $this->removeDirectory($this->testDirectory);
            }
        }
    }

    protected function testPath(string $path = ''): string
    {
        return $this->testDirectory . ($path === '' ? '' : '/' . ltrim($path, '/'));
    }

    protected function removeDirectory(string $dir): void
    {
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            if ($item->isDir() && !$item->isLink()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }

        rmdir($dir);
    }
}
