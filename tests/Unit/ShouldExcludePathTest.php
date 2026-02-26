<?php

namespace Dgtlss\Capsule\Tests\Unit;

use Dgtlss\Capsule\Services\BackupService;
use Dgtlss\Capsule\Tests\TestCase;

class ShouldExcludePathTest extends TestCase
{
    protected function callShouldExclude(string $path, array $excludePaths): bool
    {
        $service = app(BackupService::class);
        $method = new \ReflectionMethod($service, 'shouldExcludePath');
        $method->setAccessible(true);

        return $method->invoke($service, $path, $excludePaths);
    }

    public function test_exact_path_match(): void
    {
        $this->assertTrue($this->callShouldExclude('/var/www/vendor', ['/var/www/vendor']));
    }

    public function test_prefix_match(): void
    {
        $this->assertTrue($this->callShouldExclude('/var/www/vendor/autoload.php', ['/var/www/vendor']));
    }

    public function test_no_match(): void
    {
        $this->assertFalse($this->callShouldExclude('/var/www/app/Models/User.php', ['/var/www/vendor']));
    }

    public function test_basename_only_pattern_matches_anywhere(): void
    {
        $this->assertTrue($this->callShouldExclude('/var/www/some/dir/.DS_Store', ['.DS_Store']));
    }

    public function test_full_path_does_not_match_basename_elsewhere(): void
    {
        // Excluding /var/www/vendor should NOT exclude /other/path/vendor
        $this->assertFalse($this->callShouldExclude('/other/path/vendor', ['/var/www/vendor']));
    }

    public function test_full_path_does_not_match_file_with_same_basename(): void
    {
        // Excluding /var/www/vendor should NOT exclude /other/path/file/vendor
        $this->assertFalse($this->callShouldExclude('/other/path/file/vendor', ['/var/www/vendor']));
    }
}
