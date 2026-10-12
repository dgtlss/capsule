<?php

namespace Dgtlss\Capsule\Tests\Feature;

use Dgtlss\Capsule\Commands\DiagnoseCommand;
use Dgtlss\Capsule\Tests\TestCase;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;

class DiagnoseJsonTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        mkdir($this->testPath('config'), 0700);
        $app->useConfigPath($this->testPath('config'));
    }

    protected function setUp(): void
    {
        parent::setUp();
        file_put_contents(config_path('capsule.php'), '<?php return [];');
        mkdir(storage_path('app'), 0700, true);
        mkdir($this->testPath('source'), 0700);
        file_put_contents($this->testPath('source/file.txt'), 'synthetic file');
        config(['capsule.files.enabled' => true, 'capsule.files.paths' => [$this->testPath('source')]]);
    }

    public function test_success_is_one_complete_json_document(): void
    {
        $this->assertSame(0, Artisan::call('capsule:diagnose', ['--format' => 'json']));
        $report = $this->report();
        $this->assertSame('passed', $report['overall_status']);
        $this->assertSame(['basic', 'storage', 'database', 'files', 'system', 'health'], array_keys($report['checks']));
        $this->assertNotContains(false, $report['checks']);
    }

    public function test_expected_failure_and_detailed_checks_are_json(): void
    {
        config(['capsule.default_disk' => 'missing', 'capsule.files.paths' => [$this->testPath('missing')]]);
        $this->assertSame(1, Artisan::call('capsule:diagnose', ['--format' => 'json', '--detailed' => true]));
        $report = $this->report();
        $this->assertSame('failed', $report['overall_status']);
        $this->assertFalse($report['checks']['storage']);
        $this->assertFalse($report['checks']['files']);
        $this->assertArrayHasKey('backup_history', $report['checks']);
        $this->assertArrayHasKey('security', $report['checks']);
    }

    public function test_fix_publishes_config_without_contaminating_json(): void
    {
        unlink(config_path('capsule.php'));
        $this->assertSame(0, Artisan::call('capsule:diagnose', ['--format' => 'json', '--fix' => true]));
        $this->assertTrue($this->report()['checks']['basic']);
        $this->assertFileExists(config_path('capsule.php'));
    }

    public function test_fix_with_remaining_failure_still_outputs_only_json(): void
    {
        unlink(config_path('capsule.php'));
        config(['capsule.default_disk' => 'missing']);
        $this->assertSame(1, Artisan::call('capsule:diagnose', ['--format' => 'json', '--fix' => true]));
        $report = $this->report();
        $this->assertTrue($report['checks']['basic']);
        $this->assertFalse($report['checks']['storage']);
    }

    public function test_unexpected_check_failure_is_json_and_other_checks_continue(): void
    {
        $this->app->make(Kernel::class)->registerCommand(new class extends DiagnoseCommand {
            protected function checkStorageConfig(bool $fix = false, bool $verbose = true): bool
            {
                throw new \RuntimeException('Synthetic adapter error with confidential diagnostic context');
            }
        });
        $this->assertSame(1, Artisan::call('capsule:diagnose', ['--format' => 'json', '--fix' => true]));
        $output = Artisan::output();
        $report = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        $this->assertFalse($report['checks']['storage']);
        $this->assertTrue($report['checks']['files']);
        $this->assertArrayHasKey('storage', $report['errors']);
        $this->assertStringNotContainsString('confidential', $output);
    }

    public function test_table_output_remains_human_readable(): void
    {
        $this->assertSame(0, Artisan::call('capsule:diagnose'));
        $output = Artisan::output();
        $this->assertStringContainsString('Capsule Configuration Diagnosis', $output);
        $this->assertStringContainsString('Storage disk is writable', $output);
    }

    private function report(): array
    {
        return json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
    }
}
