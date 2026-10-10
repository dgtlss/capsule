<?php

namespace Dgtlss\Capsule\Support;

use Dgtlss\Capsule\Contracts\FileFilterInterface;
use RuntimeException;

/** One portable, collision-free file layout shared by every backup mode. */
class FileSelection
{
    public static function roots(): array
    {
        $roots = [];
        foreach ((array) config('capsule.files.paths', []) as $key => $path) {
            $id = is_string($key) ? $key : 'root-' . $key;
            if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/D', $id) || isset($roots[$id])) {
                throw new RuntimeException('File roots need unique, safe names.');
            }
            $roots[$id] = rtrim($path, DIRECTORY_SEPARATOR);
        }
        return $roots;
    }

    public static function excludes(): array
    {
        $excludes = (array) config('capsule.files.exclude_paths', []);
        $disks = array_merge([config('capsule.default_disk', 'local')], (array) config('capsule.additional_disks', []));
        foreach ($disks as $name) {
            $disk = config("filesystems.disks.{$name}", []);
            if (($disk['driver'] ?? null) === 'local' && !empty($disk['root'])) {
                $excludes[] = rtrim($disk['root'], '/') . '/' . trim(config('capsule.backup_path', 'backups'), '/');
            }
        }
        return array_values(array_unique($excludes));
    }

    /** @return array<string, string> Archive path => absolute source path. */
    public static function files(): array
    {
        $files = [];
        $portableNames = [];
        $excludes = self::excludes();
        $filters = [];
        foreach ((array) config('capsule.extensibility.file_filters', []) as $class) {
            $filter = app($class);
            if (!$filter instanceof FileFilterInterface) {
                throw new RuntimeException("Invalid file filter: {$class}");
            }
            $filters[] = $filter;
        }
        $context = new BackupContext('files');
        foreach (self::roots() as $id => $path) {
            $root = realpath($path);
            if ($root === false || !is_readable($root)) {
                throw new RuntimeException("Backup root is missing or unreadable: {$path}");
            }
            if (is_link($path)) {
                throw new RuntimeException("Symbolic link backup roots are not supported: {$path}");
            }
            $candidates = is_file($root) ? [new \SplFileInfo($root)] : new \RecursiveIteratorIterator(
                new \RecursiveCallbackFilterIterator(
                    new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
                    fn ($file) => !$file->isLink() && !self::shouldExclude($file->getPathname(), $excludes)
                )
            );
            foreach ($candidates as $file) {
                $source = $file->getPathname();
                if (!$file->isFile() || $file->isLink() || self::shouldExclude($source, $excludes)) {
                    continue;
                }
                foreach ($filters as $filter) {
                    if (!$filter->shouldInclude($source, $context)) {
                        continue 2;
                    }
                }
                if (!is_readable($source)) {
                    throw new RuntimeException("Backup file is unreadable: {$source}");
                }
                $relative = is_file($root) ? basename($root) : substr($source, strlen($root) + 1);
                $archivePath = 'files/' . $id . '/' . str_replace(DIRECTORY_SEPARATOR, '/', $relative);
                RestoreArchive::assertSafeName($archivePath);
                if (isset($portableNames[strtolower($archivePath)])) {
                    throw new RuntimeException("File names conflict on case-insensitive recovery targets: {$archivePath}");
                }
                $portableNames[strtolower($archivePath)] = true;
                $files[$archivePath] = $source;
            }
        }
        ksort($files);
        return $files;
    }

    public static function shouldExclude(string $path, array $excludes): bool
    {
        $path = rtrim($path, '/');
        foreach ($excludes as $exclude) {
            $exclude = rtrim($exclude, '/');
            if ($exclude === '') {
                continue;
            }
            if ($path === $exclude || str_starts_with($path, $exclude . '/') ||
                (!str_contains($exclude, '/') && basename($path) === $exclude)) {
                return true;
            }
        }
        return false;
    }
}
