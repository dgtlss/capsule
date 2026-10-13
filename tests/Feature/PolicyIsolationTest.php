<?php

namespace Dgtlss\Capsule\Tests\Feature;

use Dgtlss\Capsule\Facades\Capsule;
use Dgtlss\Capsule\Services\BackupService;
use Dgtlss\Capsule\Services\ChunkedBackupService;
use Dgtlss\Capsule\Services\PolicyRunner;
use Dgtlss\Capsule\Storage\StorageManager;
use Dgtlss\Capsule\Tests\TestCase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Mockery;

class PolicyIsolationTest extends TestCase
{
    public function test_backup_dependencies_are_fresh_per_resolution(): void
    {
        $this->assertNotSame(app(BackupService::class), app(BackupService::class));
        $this->assertNotSame(app(ChunkedBackupService::class), app(ChunkedBackupService::class));
        $this->assertNotSame(app(StorageManager::class), app(StorageManager::class));
    }

    public function test_policy_config_is_restored_after_exception(): void
    {
        config(['capsule.policies' => ['archive' => ['disk' => 'archive', 'incremental' => true, 'files' => false]]]);
        $before = config('capsule');
        try {
            PolicyRunner::withPolicy('archive', function (): void {
                $this->assertSame('archive', config('capsule.default_disk'));
                $this->assertTrue(config('capsule._policy_incremental'));
                throw new \RuntimeException('Stop');
            });
            $this->fail('Exception should propagate.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Stop', $e->getMessage());
        }
        $this->assertSame($before, config('capsule'));
    }

    public function test_command_resolves_storage_after_policy_and_preserves_tag_and_compression(): void
    {
        Storage::fake('policy-disk');
        config(['capsule.policies' => ['nightly' => ['disk' => 'policy-disk', 'tag' => 'nightly-tag', 'compression_level' => 7]]]);
        $before = config('capsule');
        $resolved = false;
        $this->app->bind(BackupService::class, function () use (&$resolved) {
            $resolved = true;
            $this->assertSame('policy-disk', config('capsule.default_disk'));
            $this->assertSame(Storage::disk('policy-disk'), app(StorageManager::class)->getDisk());
            $mock = Mockery::mock(BackupService::class);
            $mock->shouldReceive('setVerbose')->once()->with(false);
            $mock->shouldReceive('setParallel')->once()->with(false);
            $mock->shouldReceive('setTag')->once()->with('nightly-tag');
            $mock->shouldReceive('setCompressionLevel')->once()->with(7);
            $mock->shouldReceive('setEncryption')->once()->with(false);
            $mock->shouldReceive('setVerification')->once()->with(false);
            $mock->shouldReceive('setIncremental')->once()->with(false);
            $mock->shouldReceive('run')->once()->with(false)->andReturnTrue();
            return $mock;
        });
        $code = Artisan::call('capsule:backup', ['--policy' => 'nightly', '--format' => 'json', '--detailed' => true]);
        $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(0, $code);
        $this->assertSame('success', $payload['status']);
        $this->assertTrue($resolved);
        $this->assertSame($before, config('capsule'));
    }

    public function test_facade_overrides_are_restored_and_subsequent_run_is_clean(): void
    {
        config(['capsule.files.enabled' => true, 'capsule.database.enabled' => true]);
        $observed = [];
        $this->app->bind(BackupService::class, function () use (&$observed) {
            $observed[] = [config('capsule.files.enabled'), config('capsule.database.enabled')];
            $mock = Mockery::mock(BackupService::class);
            $mock->shouldReceive('setTag')->with(null);
            $mock->shouldReceive('setIncremental')->with(false);
            $mock->shouldReceive('setEncryption')->with(false);
            $mock->shouldReceive('setVerification')->with(false);
            $mock->shouldReceive('run')->with(false)->andReturnTrue();
            return $mock;
        });
        $this->assertTrue(Capsule::backup(['db_only' => true]));
        $this->assertTrue(Capsule::backup());
        $this->assertSame([[false, true], [true, true]], $observed);
        $this->assertTrue(config('capsule.files.enabled'));
    }

    public function test_facade_restores_config_when_run_throws(): void
    {
        config(['capsule.files.enabled' => true]);
        $this->app->bind(BackupService::class, function () {
            throw new \RuntimeException('Unable to construct service');
        });
        try {
            Capsule::backup(['db_only' => true]);
            $this->fail('Expected failure.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Unable to construct service', $e->getMessage());
        }
        $this->assertTrue(config('capsule.files.enabled'));
    }

    public function test_json_validation_error_contains_only_one_document(): void
    {
        $code = Artisan::call('capsule:backup', ['--db-only' => true, '--files-only' => true, '--format' => 'json']);
        $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(1, $code);
        $this->assertSame('failed', $payload['status']);
        $this->assertStringContainsString('Cannot use', $payload['error']);
    }

    public function test_json_simulation_contains_only_one_document_and_restores_policy(): void
    {
        config(['capsule.policies' => ['empty' => ['database' => false, 'files' => false]]]);
        $before = config('capsule');
        $code = Artisan::call('capsule:backup', ['--simulate' => true, '--policy' => 'empty', '--format' => 'json']);
        $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(0, $code);
        $this->assertArrayHasKey('estimate', $payload);
        $this->assertSame($before, config('capsule'));
    }
}
