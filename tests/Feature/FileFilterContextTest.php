<?php

namespace Dgtlss\Capsule\Tests\Feature;

use Dgtlss\Capsule\Contracts\FileFilterInterface;
use Dgtlss\Capsule\Contracts\StepInterface;
use Dgtlss\Capsule\Events\BackupStarting;
use Dgtlss\Capsule\Events\FilesCollectStarting;
use Dgtlss\Capsule\Models\BackupLog;
use Dgtlss\Capsule\Services\BackupService;
use Dgtlss\Capsule\Services\ChunkedBackupService;
use Dgtlss\Capsule\Services\IncrementalService;
use Dgtlss\Capsule\Support\BackupContext;
use Dgtlss\Capsule\Support\BackupDecryption;
use Dgtlss\Capsule\Support\FileSelection;
use Dgtlss\Capsule\Tests\TestCase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use ZipArchive;

class FileFilterContextTest extends TestCase
{
    private ContextMatchingFileFilter $filter;

    protected function setUp(): void
    {
        parent::setUp();
        mkdir($this->testPath('source'), 0700, true);
        file_put_contents($this->testPath('source/hello.txt'), 'hello');
        config([
            'capsule.files.enabled' => true,
            'capsule.files.paths' => ['source' => $this->testPath('source')],
            'capsule.files.exclude_paths' => [],
            'capsule.retention.cleanup_enabled' => false,
            'capsule.audit.enabled' => false,
            'capsule.performance.memory_limit' => null,
            'capsule.security.backup_password' => 'filter-context-test-password',
            'capsule.extensibility.file_filters' => [ContextMatchingFileFilter::class],
        ]);
        $this->filter = new ContextMatchingFileFilter();
        $this->app->instance(ContextMatchingFileFilter::class, $this->filter);
    }

    public static function operationModes(): array
    {
        return [['normal'], ['chunked'], ['incremental'], ['simulation']];
    }

    #[DataProvider('operationModes')]
    public function test_selection_preserves_the_supplied_context(string $mode): void
    {
        $context = new BackupContext($mode, ['selection' => 'custom']);
        $context->verbose = true;
        $context->compressionLevel = 7;
        $context->encryption = true;
        $context->verification = true;
        $this->filter->expected = $context;

        $this->assertSame(
            ['files/source/hello.txt' => $this->testPath('source/hello.txt')],
            FileSelection::files($context)
        );
        $this->assertSame([$context], $this->filter->seen);
    }

    public function test_direct_incremental_indexing_uses_its_existing_mode(): void
    {
        $this->filter->expectedMode = 'incremental';
        $index = (new IncrementalService())->buildCurrentIndex(true);

        $this->assertArrayHasKey('files/source/hello.txt', $index);
        $this->assertSame('incremental', $this->filter->seen[0]->mode);
    }

    public function test_incremental_indexing_preserves_an_explicit_operation_context(): void
    {
        $context = new BackupContext('normal', ['scope' => 'full-backup']);
        $context->verbose = true;
        $this->filter->expected = $context;
        $index = (new IncrementalService())->buildCurrentIndex(true, $context);

        $this->assertArrayHasKey('files/source/hello.txt', $index);
        $this->assertSame([$context], $this->filter->seen);
    }

    public static function backupModes(): array
    {
        return [
            'normal' => [BackupService::class, 'normal', false],
            'normal encrypted' => [BackupService::class, 'normal', true],
            'chunked' => [ChunkedBackupService::class, 'chunked', false],
            'chunked encrypted' => [ChunkedBackupService::class, 'chunked', true],
        ];
    }

    #[DataProvider('backupModes')]
    public function test_backup_filters_receive_the_active_context_and_do_not_silently_omit_files(
        string $serviceClass,
        string $mode,
        bool $encrypted
    ): void {
        $contexts = $this->captureRunContexts();
        $service = app($serviceClass);
        $service->setVerbose(true);
        $service->setCompressionLevel(7);
        $service->setEncryption($encrypted);
        $service->setVerification(true);

        $this->assertTrue($service->run(), $service->getLastError() ?? 'Backup failed.');
        $this->assertSame('hello', $this->readLatestFile($encrypted));
        $this->assertSame([$contexts->starting[0]], $this->filter->seen);
        $this->assertSame($contexts->starting, $contexts->collecting);
        $seen = $this->filter->snapshots[0];
        $this->assertSame($mode, $seen['mode']);
        $this->assertTrue($seen['verbose']);
        $this->assertSame(7, $seen['compressionLevel']);
        $this->assertSame($encrypted, $seen['encryption']);
        $this->assertTrue($seen['verification']);
        $this->assertSame(['pre_step' => 'retained'], $seen['metadata']);
    }

    public function test_full_and_incremental_runs_use_the_same_operation_context_as_their_steps(): void
    {
        $contexts = $this->captureRunContexts();
        $service = app(BackupService::class);
        $this->assertTrue($service->run(), $service->getLastError() ?? 'Full backup failed.');
        $this->assertSame('hello', $this->readLatestFile());
        file_put_contents($this->testPath('source/hello.txt'), 'world');
        clearstatcache();
        $service->setIncremental(true);

        $this->assertTrue($service->run(), $service->getLastError() ?? 'Incremental backup failed.');
        $this->assertSame('world', $this->readLatestFile());
        $this->assertSame('incremental', BackupLog::successful()->latest('id')->firstOrFail()->metadata['backup_type']);
        $this->assertCount(2, $this->filter->seen);
        $this->assertSame($contexts->starting, $this->filter->seen);
        $this->assertSame($contexts->starting, $contexts->collecting);
        $this->assertNotSame($this->filter->seen[0], $this->filter->seen[1]);
        foreach ($this->filter->snapshots as $seen) {
            $this->assertSame('normal', $seen['mode']);
            $this->assertSame(['pre_step' => 'retained'], $seen['metadata']);
        }
    }

    private function captureRunContexts(): object
    {
        $contexts = (object) ['starting' => [], 'collecting' => []];
        Event::listen(BackupStarting::class, function (BackupStarting $event) use ($contexts): void {
            $contexts->starting[] = $event->context;
            $this->filter->expected = $event->context;
        });
        Event::listen(FilesCollectStarting::class, function (FilesCollectStarting $event) use ($contexts): void {
            $contexts->collecting[] = $event->context;
        });
        $step = new class implements StepInterface {
            public function handle(BackupContext $context): void
            {
                $context->metadata['pre_step'] = 'retained';
            }
        };
        $this->app->instance('context-test-step', $step);
        config(['capsule.extensibility.pre_steps' => ['context-test-step']]);
        return $contexts;
    }

    private function readLatestFile(bool $encrypted = false): string|false
    {
        $backup = BackupLog::successful()->latest('id')->firstOrFail();
        $path = Storage::disk('capsule-test')->path($backup->file_path);
        if (str_ends_with($path, '.capsule.enc')) {
            $decrypted = $this->testPath('decrypted-' . $backup->id . '.zip');
            BackupDecryption::decryptToFile($path, $decrypted, config('capsule.security.backup_password'));
            $path = $decrypted;
        }
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($path));
        try {
            if ($encrypted) {
                $zip->setPassword(config('capsule.security.backup_password'));
            }
            return $zip->getFromName('files/source/hello.txt');
        } finally {
            $zip->close();
        }
    }
}

/** A real custom filter that excludes files unless its operation context matches. */
class ContextMatchingFileFilter implements FileFilterInterface
{
    public ?BackupContext $expected = null;
    public ?string $expectedMode = null;
    public array $seen = [];
    public array $snapshots = [];

    public function shouldInclude(string $absolutePath, BackupContext $context): bool
    {
        $this->seen[] = $context;
        $this->snapshots[] = get_object_vars($context);
        return $this->expected !== null
            ? $context === $this->expected
            : $context->mode === $this->expectedMode;
    }
}
