<?php

namespace Dgtlss\Capsule\Tests\Unit;

use Dgtlss\Capsule\Support\Helpers;
use PHPUnit\Framework\TestCase;

class HelpersTest extends TestCase
{
    public function test_format_bytes_zero(): void
    {
        $this->assertEquals('0 B', Helpers::formatBytes(0));
    }

    public function test_format_bytes_bytes(): void
    {
        $this->assertEquals('500 B', Helpers::formatBytes(500));
    }

    public function test_format_bytes_kilobytes(): void
    {
        $this->assertEquals('1 KB', Helpers::formatBytes(1024));
    }

    public function test_format_bytes_megabytes(): void
    {
        $this->assertEquals('1 MB', Helpers::formatBytes(1048576));
    }

    public function test_format_bytes_gigabytes(): void
    {
        $this->assertEquals('1 GB', Helpers::formatBytes(1073741824));
    }

    public function test_format_bytes_negative(): void
    {
        $this->assertEquals('0 B', Helpers::formatBytes(-1));
    }

    public function test_format_bytes_fractional(): void
    {
        $result = Helpers::formatBytes(1536);
        $this->assertEquals('1.5 KB', $result);
    }
}
