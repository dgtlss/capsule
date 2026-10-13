<?php

namespace Dgtlss\Capsule\Tests\Feature;

use Dgtlss\Capsule\Commands\RestoreCommand;
use Dgtlss\Capsule\Support\RestoreArchive;
use Dgtlss\Capsule\Tests\TestCase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use ZipArchive;

class RestoreFileTransitionsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['capsule.audit.enabled' => false]);
    }

    #[DataProvider('existingModes')]
    public function test_replacement_preserves_ordinary_permissions_without_special_bits(int $existing, int $expected): void
    {
        $this->requireUnix();
        $path = $this->testPath('target/root/file');
        mkdir(dirname($path), 0700, true);
        file_put_contents($path, 'existing');
        chmod($path, $existing);
        $archive = $this->archive(['files/root/file' => 'restored']);
        $mask = umask(0077);
        try {
            [$code, $result] = $this->restore($archive);
        } finally {
            umask($mask);
        }
        $this->assertSame(0, $code, json_encode($result));
        $this->assertSame('restored', file_get_contents($path));
        $this->assertSame($expected, $this->mode($path));
    }

    public static function existingModes(): array
    {
        return [
            'web-readable' => [0644, 0644], 'group-readable' => [0640, 0640],
            'executable' => [0755, 0755], 'private-executable' => [0700, 0700],
            'set-id-bits-stripped' => [06755, 0755], 'sticky-bit-stripped' => [01750, 0750],
        ];
    }

    #[DataProvider('newFileModes')]
    public function test_new_files_use_non_executable_defaults_and_respect_umask(int $mask, int $expected): void
    {
        $this->requireUnix();
        $archive = $this->archive(['files/root/file' => 'restored']);
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($archive));
        $zip->setExternalAttributesName('files/root/file', ZipArchive::OPSYS_UNIX, 0107777 << 16);
        $this->assertTrue($zip->close());
        $oldMask = umask($mask);
        try {
            [$code, $result] = $this->restore($archive);
        } finally {
            umask($oldMask);
        }
        $this->assertSame(0, $code, json_encode($result));
        $this->assertSame($expected, $this->mode($this->testPath('target/root/file')));
        $this->assertSame([], glob($this->testPath('target/root/.capsule_*')));
    }

    public static function newFileModes(): array
    {
        return ['standard' => [0022, 0644], 'group' => [0027, 0640], 'private' => [0077, 0600], 'open' => [0000, 0666]];
    }

    public function test_archive_staging_stays_private_even_with_an_open_umask(): void
    {
        $this->requireUnix();
        $archive = new RestoreArchive($this->archive(['files/root/file' => 'private plaintext']));
        $mask = umask(0000);
        try {
            $archive->verifyAndStage();
            $path = $archive->entries()['files/root/file']['staged_path'];
            $this->assertSame(0600, $this->mode($path));
            $this->assertSame(0700, $this->mode(dirname($path)));
        } finally {
            umask($mask);
            $archive->close();
        }
    }

    public function test_failed_copy_preserves_existing_bytes_and_permissions_and_cleans_private_staging(): void
    {
        $this->requireUnix();
        $path = $this->testPath('target/root/file');
        mkdir(dirname($path), 0700, true);
        file_put_contents($path, 'existing');
        chmod($path, 0751);
        $command = new MissingStagedFileRestoreCommand();
        $command->setLaravel($this->app);
        $this->app['Illuminate\Contracts\Console\Kernel']->registerCommand($command);
        [$code, $result] = $this->restore($this->archive(['files/root/file' => 'replacement']));
        $this->assertSame(1, $code);
        $this->assertStringContainsString('Failed writing restored file', $result['error']);
        $this->assertSame('existing', file_get_contents($path));
        $this->assertSame(0751, $this->mode($path));
        $this->assertSame([], glob($this->testPath('target/root/.capsule_*')));
    }

    public function test_publication_keeps_plaintext_private_until_atomic_replacement(): void
    {
        $this->requireUnix();
        $path = $this->testPath('target/root/file');
        mkdir(dirname($path), 0700, true);
        file_put_contents($path, 'existing');
        chmod($path, 0751);
        $command = new InspectingPublicationRestoreCommand();
        $command->setLaravel($this->app);
        $this->app['Illuminate\Contracts\Console\Kernel']->registerCommand($command);
        [$code, $result] = $this->restore($this->archive(['files/root/file' => 'replacement']));
        $this->assertSame(0, $code, json_encode($result));
        $this->assertSame([
            ['directory' => 0700, 'file' => 0600, 'old_bytes' => 'existing', 'new_bytes' => 'replacement'],
            ['directory' => 0700, 'file' => 0751, 'old_bytes' => 'existing', 'new_bytes' => 'replacement'],
        ], $command->stagingObservations);
        $this->assertSame('replacement', file_get_contents($path));
        $this->assertSame(0751, $this->mode($path));
        $this->assertSame([], glob($this->testPath('target/root/.capsule_*')));
    }

    public function test_permission_setup_failure_preserves_existing_file_and_cleans_staging(): void
    {
        $this->requireUnix();
        $path = $this->testPath('target/root/file');
        mkdir(dirname($path), 0700, true);
        file_put_contents($path, 'existing');
        chmod($path, 0751);
        $command = new InspectingPublicationRestoreCommand();
        $command->removeBeforeChmod = true;
        $command->setLaravel($this->app);
        $this->app['Illuminate\Contracts\Console\Kernel']->registerCommand($command);
        [$code, $result] = $this->restore($this->archive(['files/root/file' => 'replacement']));
        $this->assertSame(1, $code);
        $this->assertStringContainsString('Failed setting restored file permissions', $result['error']);
        $this->assertSame('existing', file_get_contents($path));
        $this->assertSame(0751, $this->mode($path));
        $this->assertSame([], glob($this->testPath('target/root/.capsule_*')));
    }

    public function test_checksum_failure_preserves_existing_file_permissions_and_deletions(): void
    {
        $this->requireUnix();
        $base = $this->archive(['files/Name.txt' => 'old']);
        $delta = $this->delta($base, ['files/name.txt' => 'new'], ['files/Name.txt']);
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($delta));
        $zip->addFromString('files/name.txt', 'bad');
        $this->assertTrue($zip->close());
        mkdir($this->testPath('target'), 0700, true);
        $path = $this->testPath('target/Name.txt');
        file_put_contents($path, 'existing');
        chmod($path, 0751);
        [$code, $result] = $this->restore($delta, ['--base-archive' => $base]);
        $this->assertSame(1, $code);
        $this->assertStringContainsString('checksum', $result['error']);
        $this->assertSame('existing', file_get_contents($path));
        $this->assertSame(0751, $this->mode($path));
        $this->assertFileDoesNotExist($this->testPath('target/name.txt'));
    }

    #[DataProvider('caseRenames')]
    public function test_recorded_case_only_rename_restores_with_and_without_existing_base(string $old, string $new): void
    {
        $this->requireUnix();
        $base = $this->archive([$old => 'old bytes']);
        $delta = $this->delta($base, [$new => 'new bytes'], [$old]);
        foreach ([false, true] as $existing) {
            $target = $this->testPath($existing ? 'existing-target' : 'empty-target');
            if ($existing) {
                $oldPath = $target . '/' . substr($old, 6);
                mkdir(dirname($oldPath), 0700, true);
                file_put_contents($oldPath, 'old bytes');
            }
            [$code, $result] = $this->restore($delta, ['--base-archive' => $base, '--target' => $target]);
            $this->assertSame(0, $code, json_encode($result));
            $this->assertSame(1, $result['files_restored']);
            $this->assertSame(1, $result['files_deleted']);
            $this->assertSame('new bytes', file_get_contents($target . '/' . substr($new, 6)));
            $this->assertFileDoesNotExist($target . '/' . substr($old, 6));
        }
    }

    public static function caseRenames(): array
    {
        return [
            'root-leaf' => ['files/Name.txt', 'files/name.txt'],
            'nested-leaf' => ['files/root/Dir/Name.txt', 'files/root/Dir/name.txt'],
            'root-directory' => ['files/Root/file.txt', 'files/root/file.txt'],
            'nested-directory' => ['files/root/Dir/file.txt', 'files/root/dir/file.txt'],
            'file-to-directory' => ['files/root/Item', 'files/root/item/child.txt'],
            'directory-to-file' => ['files/root/Item/child.txt', 'files/root/item'],
        ];
    }

    public function test_multiple_case_only_rename_layers_are_applied_in_order(): void
    {
        $this->requireUnix();
        $base = $this->archive(['files/root/NAME.txt' => 'base']);
        Storage::disk('capsule-test')->put('chain/base.zip', file_get_contents($base));
        $middle = $this->delta($base, ['files/root/Name.txt' => 'middle'], ['files/root/NAME.txt'], 'chain/base.zip');
        $latest = $this->delta($middle, ['files/root/name.txt' => 'latest'], ['files/root/Name.txt']);
        mkdir($this->testPath('target/root'), 0700, true);
        file_put_contents($this->testPath('target/root/NAME.txt'), 'existing base');
        [$code, $result] = $this->restore($latest, ['--base-archive' => $middle]);
        $this->assertSame(0, $code, json_encode($result));
        $this->assertSame(3, $result['archives_verified']);
        $this->assertSame('latest', file_get_contents($this->testPath('target/root/name.txt')));
        $this->assertFileDoesNotExist($this->testPath('target/root/NAME.txt'));
        $this->assertFileDoesNotExist($this->testPath('target/root/Name.txt'));
    }

    #[DataProvider('caseRenames')]
    public function test_case_alias_without_recorded_deletion_is_rejected_before_changes(string $old, string $new): void
    {
        $base = $this->archive([$old => 'base']);
        $delta = $this->delta($base, [$new => 'new'], []);
        $oldPath = $this->testPath('target/' . substr($old, 6));
        mkdir(dirname($oldPath), 0700, true);
        file_put_contents($oldPath, 'existing');
        chmod($oldPath, 0751);
        [$code, $result] = $this->restore($delta, ['--base-archive' => $base]);
        $this->assertSame(1, $code);
        $this->assertStringContainsString('Conflicting restore destinations', $result['error']);
        $this->assertSame('existing', file_get_contents($oldPath));
        if (PHP_OS_FAMILY !== 'Windows') {
            $this->assertSame(0751, $this->mode($oldPath));
        }
    }

    public function test_remaining_case_alias_in_base_cannot_be_hidden_by_a_different_deletion(): void
    {
        $base = $this->archive(['files/Root/old.txt' => 'old', 'files/Root/keep.txt' => 'keep']);
        $delta = $this->delta($base, ['files/root/new.txt' => 'new'], ['files/Root/old.txt']);
        mkdir($this->testPath('target/Root'), 0700, true);
        file_put_contents($this->testPath('target/Root/old.txt'), 'existing old');
        file_put_contents($this->testPath('target/Root/keep.txt'), 'existing keep');
        [$code, $result] = $this->restore($delta, ['--base-archive' => $base]);
        $this->assertSame(1, $code);
        $this->assertStringContainsString('Conflicting restore destinations', $result['error']);
        $this->assertSame('existing old', file_get_contents($this->testPath('target/Root/old.txt')));
        $this->assertSame('existing keep', file_get_contents($this->testPath('target/Root/keep.txt')));
        $this->assertDirectoryDoesNotExist($this->testPath('target/root'));
    }

    public static function unicodeNames(): array
    {
        return ['case' => ['Å.txt', 'å.txt'], 'normalization' => ['é.txt', "e\u{0301}.txt"]];
    }

    #[DataProvider('unicodeNames')]
    public function test_legacy_unicode_names_require_a_filesystem_that_preserves_both_spellings(string $first, string $second): void
    {
        $this->requireUnix();
        $probe = $this->testPath('unicode-probe');
        mkdir($probe, 0700);
        $stream = fopen($probe . '/' . $first, 'xb');
        fclose($stream);
        $stream = @fopen($probe . '/' . $second, 'xb');
        $distinct = $stream !== false;
        if ($distinct) {
            fclose($stream);
        }
        $archive = $this->archive(['files/' . $first => 'first bytes', 'files/' . $second => 'second bytes'], ['schema_version' => 1]);
        $target = realpath($this->testDirectory) . '/unicode-target';
        mkdir($target, 0700);
        file_put_contents($target . '/untouched', 'existing');
        [$code, $result] = $this->restore($archive, ['--target' => $target]);
        $this->assertSame('existing', file_get_contents($target . '/untouched'));
        if ($distinct) {
            $this->assertSame(0, $code, json_encode($result));
            $this->assertSame('first bytes', file_get_contents($target . '/' . $first));
            $this->assertSame('second bytes', file_get_contents($target . '/' . $second));
        } else {
            $this->assertSame(1, $code, json_encode($result));
            $this->assertSame(['.', '..', 'untouched'], scandir($target));
        }
        $this->assertSame([], glob($target . '/.capsule_*'));
    }

    public function test_case_transition_rejects_incompatible_filesystem_before_deletion(): void
    {
        $base = $this->archive(['files/Name.txt' => 'base']);
        $delta = $this->delta($base, ['files/name.txt' => 'new'], ['files/Name.txt']);
        mkdir($this->testPath('target'), 0700, true);
        file_put_contents($this->testPath('target/Name.txt'), 'existing');
        $command = new CaseInsensitiveRestoreCommand();
        $command->setLaravel($this->app);
        $this->app['Illuminate\Contracts\Console\Kernel']->registerCommand($command);
        [$code, $result] = $this->restore($delta, ['--base-archive' => $base]);
        $this->assertSame(1, $code);
        $this->assertStringContainsString('case-sensitive target filesystem', $result['error']);
        $this->assertSame('existing', file_get_contents($this->testPath('target/Name.txt')));
        $this->assertFileDoesNotExist($this->testPath('target/name.txt'));
    }

    public function test_case_transition_does_not_follow_an_existing_symlink(): void
    {
        $this->requireUnix();
        $base = $this->archive(['files/Root/file.txt' => 'base']);
        $delta = $this->delta($base, ['files/root/file.txt' => 'new'], ['files/Root/file.txt']);
        mkdir($this->testPath('outside'), 0700, true);
        mkdir($this->testPath('target/Root'), 0700, true);
        file_put_contents($this->testPath('outside/file.txt'), 'outside');
        file_put_contents($this->testPath('target/Root/file.txt'), 'existing');
        symlink($this->testPath('outside'), $this->testPath('target/root'));
        [$code, $result] = $this->restore($delta, ['--base-archive' => $base]);
        $this->assertSame(1, $code);
        $this->assertStringContainsString('symbolic link', $result['error']);
        $this->assertSame('outside', file_get_contents($this->testPath('outside/file.txt')));
        $this->assertSame('existing', file_get_contents($this->testPath('target/Root/file.txt')));
    }

    protected function delta(string $base, array $files, array $deletions, ?string $storedBase = null): string
    {
        $reference = ['sha256' => hash_file('sha256', $base)];
        if ($storedBase !== null) {
            $reference += ['disk' => 'capsule-test', 'path' => $storedBase];
        }
        return $this->archive($files, [
            'backup_type' => 'incremental', 'base_archive' => $reference, 'deleted_files' => $deletions,
        ]);
    }

    protected function restore(string $archive, array $options = []): array
    {
        $code = Artisan::call('capsule:restore', array_replace([
            '--archive' => $archive, '--target' => $this->testPath('target'), '--force' => true, '--format' => 'json',
        ], $options));
        return [$code, json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR)];
    }

    protected function archive(array $files, array $manifest = []): string
    {
        $path = $this->testPath(bin2hex(random_bytes(8)) . '.zip');
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($path, ZipArchive::CREATE));
        $entries = [];
        foreach ($files as $name => $contents) {
            $zip->addFromString($name, $contents);
            $entries[] = ['path' => $name, 'size' => strlen($contents), 'sha256' => hash('sha256', $contents)];
        }
        $zip->addFromString('manifest.json', json_encode(array_replace([
            'schema_version' => 2, 'backup_type' => 'full', 'entries' => $entries,
        ], $manifest), JSON_THROW_ON_ERROR));
        $this->assertTrue($zip->close());
        return $path;
    }

    protected function mode(string $path): int
    {
        clearstatcache(true, $path);
        return fileperms($path) & 07777;
    }

    protected function requireUnix(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('Unix file permissions and a case-sensitive target filesystem are required.');
        }
    }
}

class MissingStagedFileRestoreCommand extends RestoreCommand
{
    protected function buildPlan(array $chain): array
    {
        $plan = parent::buildPlan($chain);
        foreach ($plan['files'] as &$entry) {
            $entry['staged_path'] .= '.missing';
        }
        return $plan;
    }
}

class CaseInsensitiveRestoreCommand extends RestoreCommand
{
    protected function assertCaseSensitiveFilesystem(string $path): void
    {
        throw new \RuntimeException('Case-distinct filenames require a case-sensitive target filesystem.');
    }
}

class InspectingPublicationRestoreCommand extends RestoreCommand
{
    public array $stagingObservations = [];
    public bool $removeBeforeChmod = false;

    protected function assertSafeDestination(string $path): void
    {
        parent::assertSafeDestination($path);
        foreach (glob(dirname($path) . '/.capsule_*/file_*') as $file) {
            clearstatcache();
            $this->stagingObservations[] = [
                'directory' => fileperms(dirname($file)) & 07777,
                'file' => fileperms($file) & 07777,
                'old_bytes' => file_get_contents($path),
                'new_bytes' => file_get_contents($file),
            ];
            if ($this->removeBeforeChmod) {
                unlink($file);
            }
        }
    }
}
