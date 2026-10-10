<?php

namespace Dgtlss\Capsule\Tests\Unit;

use Dgtlss\Capsule\Support\ManifestBuilder;
use Dgtlss\Capsule\Tests\TestCase;

class ManifestBuilderTest extends TestCase
{
    public function test_build_returns_correct_schema(): void
    {
        $builder = new ManifestBuilder();
        $manifest = $builder->build(false, 6, false);

        $this->assertEquals(2, $manifest['schema_version']);
        $this->assertArrayHasKey('generated_at', $manifest);
        $this->assertArrayHasKey('app', $manifest);
        $this->assertArrayHasKey('capsule', $manifest);
        $this->assertArrayHasKey('storage', $manifest);
        $this->assertArrayHasKey('database', $manifest);
        $this->assertArrayHasKey('files', $manifest);
        $this->assertArrayHasKey('entries', $manifest);
    }

    public function test_build_reflects_chunked_flag(): void
    {
        $builder = new ManifestBuilder();

        $normal = $builder->build(false, 6, false);
        $this->assertFalse($normal['capsule']['chunked']);

        $chunked = $builder->build(true, 6, false);
        $this->assertTrue($chunked['capsule']['chunked']);
    }

    public function test_build_reflects_encryption_flag(): void
    {
        $builder = new ManifestBuilder();

        $plain = $builder->build(false, 6, false);
        $this->assertFalse($plain['capsule']['encryption_enabled']);

        $encrypted = $builder->build(false, 6, true);
        $this->assertTrue($encrypted['capsule']['encryption_enabled']);
    }

    public function test_add_entry_with_real_file(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'capsule_test_');
        file_put_contents($tmp, 'test content');

        $builder = new ManifestBuilder();
        $builder->addEntry('files/test.txt', $tmp);

        $manifest = $builder->build(false, 6, false);

        $this->assertCount(1, $manifest['entries']);
        $this->assertEquals('files/test.txt', $manifest['entries'][0]['path']);
        $this->assertEquals(12, $manifest['entries'][0]['size']);
        $this->assertNotEmpty($manifest['entries'][0]['sha256']);

        @unlink($tmp);
    }

    public function test_reset_clears_entries(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'capsule_test_');
        file_put_contents($tmp, 'data');

        $builder = new ManifestBuilder();
        $builder->addEntry('files/a.txt', $tmp);
        $builder->reset();

        $manifest = $builder->build(false, 6, false);
        $this->assertEmpty($manifest['entries']);

        @unlink($tmp);
    }
    public function test_manifest_encoder_refuses_archives_that_restore_cannot_read(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('16 MiB');
        ManifestBuilder::encode(['entries' => [], 'padding' => str_repeat('x', \Dgtlss\Capsule\Support\RestoreArchive::MAX_MANIFEST_BYTES)]);
    }

    public function test_stream_entry_preserves_database_format_without_private_source_fields(): void
    {
        $builder = new ManifestBuilder();
        $builder->addStreamEntry('database/main.sqlite', 1, str_repeat('a', 64), [
            'driver' => 'sqlite', 'format' => 'sqlite', 'connection' => 'main', 'source_file' => '/private/source',
        ]);
        $entry = $builder->build(true, 1, false)['entries'][0];
        $this->assertSame('sqlite', $entry['format']);
        $this->assertSame('sqlite', $entry['driver']);
        $this->assertSame('main', $entry['connection']);
        $this->assertArrayNotHasKey('source_file', $entry);
    }

}
