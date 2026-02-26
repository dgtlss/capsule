<?php

namespace Dgtlss\Capsule\Tests\Unit;

use Dgtlss\Capsule\Filters\ExtensionFilter;
use Dgtlss\Capsule\Filters\MaxFileSizeFilter;
use Dgtlss\Capsule\Filters\PatternFilter;
use Dgtlss\Capsule\Support\BackupContext;
use Dgtlss\Capsule\Tests\TestCase;

class FiltersTest extends TestCase
{
    protected BackupContext $context;

    protected function setUp(): void
    {
        parent::setUp();
        $this->context = new BackupContext('normal');
    }

    public function test_max_file_size_filter_includes_small_files(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'capsule_test_');
        file_put_contents($tmp, 'small');

        config(['capsule.filters.max_file_size_bytes' => 1024]);
        $filter = new MaxFileSizeFilter();

        $this->assertTrue($filter->shouldInclude($tmp, $this->context));
        @unlink($tmp);
    }

    public function test_max_file_size_filter_excludes_large_files(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'capsule_test_');
        file_put_contents($tmp, str_repeat('x', 2048));

        config(['capsule.filters.max_file_size_bytes' => 1024]);
        $filter = new MaxFileSizeFilter();

        $this->assertFalse($filter->shouldInclude($tmp, $this->context));
        @unlink($tmp);
    }

    public function test_extension_filter_exclude(): void
    {
        config(['capsule.filters.exclude_extensions' => ['log', 'tmp']]);
        config(['capsule.filters.include_extensions' => []]);
        $filter = new ExtensionFilter();

        $this->assertFalse($filter->shouldInclude('/var/log/app.log', $this->context));
        $this->assertTrue($filter->shouldInclude('/var/www/app.php', $this->context));
    }

    public function test_extension_filter_include(): void
    {
        config(['capsule.filters.include_extensions' => ['php', 'env']]);
        config(['capsule.filters.exclude_extensions' => []]);
        $filter = new ExtensionFilter();

        $this->assertTrue($filter->shouldInclude('/var/www/app.php', $this->context));
        $this->assertFalse($filter->shouldInclude('/var/www/image.png', $this->context));
    }

    public function test_pattern_filter_exclude(): void
    {
        config(['capsule.filters.exclude_patterns' => ['*.log', '*.cache']]);
        config(['capsule.filters.include_patterns' => []]);
        $filter = new PatternFilter();

        $this->assertFalse($filter->shouldInclude('/var/log/error.log', $this->context));
        $this->assertTrue($filter->shouldInclude('/var/www/app.php', $this->context));
    }

    public function test_pattern_filter_include(): void
    {
        config(['capsule.filters.include_patterns' => ['*.php']]);
        config(['capsule.filters.exclude_patterns' => []]);
        $filter = new PatternFilter();

        $this->assertTrue($filter->shouldInclude('/var/www/app.php', $this->context));
        $this->assertFalse($filter->shouldInclude('/var/www/style.css', $this->context));
    }
}
