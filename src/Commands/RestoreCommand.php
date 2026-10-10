<?php

namespace Dgtlss\Capsule\Commands;

use Dgtlss\Capsule\Database\DumpValidator;
use Dgtlss\Capsule\Models\BackupLog;
use Dgtlss\Capsule\Services\AuditLogger;
use Dgtlss\Capsule\Support\Helpers;
use Dgtlss\Capsule\Support\RestoreArchive;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class RestoreCommand extends Command
{
    protected $signature = 'capsule:restore
        {id? : Backup log ID to restore (defaults to latest)}
        {--archive= : Explicit local ZIP archive; no backup log is required}
        {--disk= : Storage disk for an explicit archive object}
        {--path= : Storage object path; no backup log is required}
        {--base-archive= : Local base archive for a portable incremental restore}
        {--db-only : Only restore database dumps}
        {--files-only : Only restore files}
        {--target= : Target directory for files (default: application base path)}
        {--connection= : Database connection to restore to (default: from backup)}
        {--only=* : Only restore specific files (glob patterns)}
        {--list : List verified archive contents without restoring}
        {--dry-run : Verify and show the restore plan without making changes}
        {--force : Skip confirmation prompt}
        {--detailed : Verbose output}
        {--format=table : Output format (table|json)}';

    protected $description = 'Restore a verified full or incremental backup';

    /** @var RestoreArchive[] */
    protected array $archives = [];
    protected array $temporaryArchives = [];
    protected array $seenArchives = [];

    public function handle(): int
    {
        $this->archives = $this->temporaryArchives = $this->seenArchives = [];
        $backup = null;
        try {
            $this->validateOptions();
            [$localPath, $backup, $source] = $this->resolveSource();
            $chain = $this->loadArchiveChain($localPath, true);
            $plan = $this->buildPlan($chain);
            if ($this->option('list')) {
                return $this->showContents($chain, $backup, $source);
            }
            // Verify every archive and every destination before any import, overwrite,
            // directory creation or deletion. This also applies to dry runs.
            $this->validatePlan($plan);
            if (!$this->option('dry-run') && !$this->option('force')) {
                if ($this->isJson()) {
                    throw new RuntimeException('Use --force to authorize a non-interactive JSON restore, or --dry-run to preview it.');
                }
                if (!$this->confirm('This will overwrite existing data and apply recorded deletions. Continue?')) {
                    $this->result(['status' => 'cancelled', 'backup_id' => $backup?->id], 'Restore cancelled.');
                    return self::SUCCESS;
                }
            }
            $counts = $this->executePlan($plan);
            $result = ['status' => 'success', 'backup_id' => $backup?->id, 'source' => $source,
                'dry_run' => (bool) $this->option('dry-run'), 'archives_verified' => count($chain)] + $counts;
            if (!$this->option('dry-run')) {
                AuditLogger::log('backup.restored', [
                    'backup_log_id' => $backup?->id,
                    'status' => 'completed',
                    'details' => $counts + ['source' => $source, 'target' => $this->option('target') ?? base_path()],
                ]);
            }
            $action = $this->option('dry-run') ? 'Would restore' : 'Restored';
            $this->result($result, "{$action}: {$counts['databases_restored']} database dump(s), {$counts['files_restored']} file(s); {$counts['files_deleted']} deletion(s).");
            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->result(['status' => 'failed', 'backup_id' => $backup?->id, 'error' => $e->getMessage()], $e->getMessage(), true);
            return self::FAILURE;
        } finally {
            foreach ($this->archives as $archive) {
                $archive->close();
            }
            foreach ($this->temporaryArchives as $path) {
                @unlink($path);
            }
            $this->archives = $this->temporaryArchives = $this->seenArchives = [];
        }
    }

    protected function validateOptions(): void
    {
        if (!in_array($this->option('format'), ['table', 'json'], true)) {
            throw new RuntimeException('Output format must be table or json.');
        }
        if ($this->option('db-only') && ($this->option('files-only') || $this->option('only'))) {
            throw new RuntimeException('--db-only cannot be combined with --files-only or --only.');
        }
        if ($this->option('archive') && ($this->option('path') || $this->option('disk') || $this->argument('id'))) {
            throw new RuntimeException('--archive cannot be combined with a backup ID, --disk or --path.');
        }
        if ($this->option('path') && $this->argument('id')) {
            throw new RuntimeException('--path cannot be combined with a backup ID.');
        }
        if ($this->option('disk') && !$this->option('path')) {
            throw new RuntimeException('--disk requires --path.');
        }
    }

    protected function resolveSource(): array
    {
        if ($path = $this->option('archive')) {
            if (!is_file($path) || !is_readable($path)) {
                throw new RuntimeException("Local archive is not readable: {$path}");
            }
            return [$path, null, ['archive' => $path]];
        }
        if ($path = $this->option('path')) {
            $disk = $this->option('disk') ?: config('capsule.default_disk', 'local');
            return [$this->downloadArchive($disk, $path), null, ['disk' => $disk, 'path' => $path]];
        }
        $backup = $this->resolveBackup();
        if (!$backup) {
            throw new RuntimeException('No successful backup found to restore.');
        }
        $metadata = $backup->metadata ?? [];
        $disk = $metadata['storage']['disk'] ?? $metadata['disk'] ?? config('capsule.default_disk', 'local');
        $path = $metadata['storage']['path'] ?? $backup->file_path;
        if (!is_string($disk) || !is_string($path) || $path === '') {
            throw new RuntimeException('Backup has no usable storage reference.');
        }
        return [$this->downloadArchive($disk, $path), $backup, ['disk' => $disk, 'path' => $path]];
    }

    protected function resolveBackup(): ?BackupLog
    {
        $id = $this->argument('id');
        if ($id !== null && (!ctype_digit((string) $id) || (int) $id < 1)) {
            throw new RuntimeException('Backup ID must be a positive integer; use --archive for a local ZIP.');
        }
        return $id !== null
            ? BackupLog::where('id', (int) $id)->where('status', 'success')->first()
            : BackupLog::where('status', 'success')->orderByDesc('created_at')->first();
    }

    protected function downloadArchive(string $diskName, string $path): string
    {
        RestoreArchive::assertSafeName($path);
        $disk = Storage::disk($diskName);
        if (!$disk->exists($path)) {
            throw new RuntimeException("Backup file not found on disk '{$diskName}': {$path}");
        }
        $this->progress("Reading backup archive from {$diskName}...");
        $input = $disk->readStream($path);
        if (!is_resource($input)) {
            throw new RuntimeException('Failed to open backup storage stream.');
        }
        $temporary = tempnam(sys_get_temp_dir(), 'capsule_restore_');
        if ($temporary === false) {
            fclose($input);
            throw new RuntimeException('Failed to create archive staging file.');
        }
        $this->temporaryArchives[] = $temporary;
        $output = fopen($temporary, 'wb');
        if ($output === false) {
            fclose($input);
            throw new RuntimeException('Failed to open archive staging file.');
        }
        try {
            $copied = stream_copy_to_stream($input, $output);
            if ($copied === false || !fflush($output) || $copied !== $disk->size($path)) {
                throw new RuntimeException('Failed to download the complete backup archive.');
            }
        } finally {
            fclose($input);
            fclose($output);
        }
        return $temporary;
    }

    /** @return RestoreArchive[] Base first, then each incremental layer. */
    protected function loadArchiveChain(string $path, bool $topLevel = false): array
    {
        $digest = @hash_file('sha256', $path);
        if ($digest === false || isset($this->seenArchives[$digest]) || count($this->seenArchives) >= 64) {
            throw new RuntimeException('Invalid, cyclic or excessively deep incremental archive chain.');
        }
        $this->seenArchives[$digest] = true;
        $password = config('capsule.security.backup_password') ?? env('CAPSULE_BACKUP_PASSWORD');
        $archive = new RestoreArchive($path, $password);
        $this->archives[] = $archive;
        $archive->verifyAndStage();
        $manifest = $archive->manifest();
        $type = $manifest['backup_type'] ?? 'full';
        if (!in_array($type, ['full', 'incremental'], true)) {
            throw new RuntimeException('Unsupported backup type in archive manifest.');
        }
        if ($type === 'full') {
            if ($archive->deletions() || isset($manifest['base_archive'])) {
                throw new RuntimeException('Full archive cannot contain an incremental base or deletion records.');
            }
            return [$archive];
        }
        $base = $manifest['base_archive'] ?? null;
        if (!is_array($base)) {
            throw new RuntimeException('Incremental archive has no portable base_archive reference.');
        }
        $expected = $base['sha256'] ?? null;
        if (!is_string($expected) || !preg_match('/^[a-f0-9]{64}$/iD', $expected)) {
            throw new RuntimeException('Incremental base reference has no valid SHA-256 checksum.');
        }
        if ($topLevel && $this->option('base-archive')) {
            $basePath = $this->option('base-archive');
            if (!is_file($basePath) || !is_readable($basePath)) {
                throw new RuntimeException('The supplied base archive is not readable.');
            }
        } else {
            if (!is_string($base['disk'] ?? null) || $base['disk'] === '' || !is_string($base['path'] ?? null)) {
                throw new RuntimeException('Incremental base reference needs a storage disk and object path.');
            }
            $basePath = $this->downloadArchive($base['disk'], $base['path']);
        }
        $actual = @hash_file('sha256', $basePath);
        if ($actual === false || !hash_equals(strtolower($expected), $actual)) {
            throw new RuntimeException('Incremental base archive checksum mismatch.');
        }
        return array_merge($this->loadArchiveChain($basePath), [$archive]);
    }

    protected function buildPlan(array $chain): array
    {
        $files = $databases = $deletions = [];
        foreach ($chain as $archive) {
            foreach ($archive->deletions() as $name) {
                unset($files[$name]);
                $deletions[$name] = $name;
            }
            foreach ($archive->entries() as $name => $entry) {
                if (str_starts_with($name, 'files/')) {
                    $files[$name] = $entry;
                    unset($deletions[$name]);
                } elseif (preg_match('#^database/([^/]+)\.(sql|sqlite)$#D', $name, $match)) {
                    $entry['connection'] = $this->option('connection') ?: ($entry['connection'] ?? $match[1]);
                    $entry['format'] = $entry['format'] ?? ($match[2] === 'sqlite' ? 'sqlite' : null);
                    $databases[$name] = $entry;
                }
            }
        }
        if ($this->option('files-only') || $this->option('only')) {
            $databases = [];
        }
        if ($this->option('db-only')) {
            $files = $deletions = [];
        }
        $patterns = $this->option('only');
        if ($patterns) {
            $files = array_filter($files, fn ($entry) => $this->matchesPatterns(substr($entry['path'], 6), $patterns));
            $deletions = array_filter($deletions, fn ($name) => $this->matchesPatterns(substr($name, 6), $patterns));
        }
        return compact('files', 'databases', 'deletions');
    }

    protected function validatePlan(array $plan): void
    {
        $writes = $deletions = $portablePaths = [];
        foreach ($plan['files'] as $name => $entry) {
            $writes[$this->targetPath($name)] = true;
        }
        foreach ($plan['deletions'] as $name) {
            $deletions[$this->targetPath($name)] = true;
        }
        foreach (array_merge(array_keys($writes), array_keys($deletions)) as $path) {
            $key = strtolower($path);
            if (isset($portablePaths[$key])) {
                throw new RuntimeException("Conflicting restore destinations: {$path}");
            }
            $portablePaths[$key] = true;
        }
        // Historical deletions may be parents/children of new files. Only writes
        // describe the final tree, so validate their mutual conflicts separately.
        $portableWrites = array_change_key_case($writes, CASE_LOWER);
        foreach (array_keys($writes) as $path) {
            for ($parent = dirname(strtolower($path)); $parent !== dirname($parent); $parent = dirname($parent)) {
                if (isset($portableWrites[$parent])) {
                    throw new RuntimeException('Restore plan contains conflicting file and directory destinations.');
                }
            }
            $this->assertPlannedDestination($path, false, $deletions);
            if (is_dir($path)) {
                $this->assertDirectoryCanBeReplaced($path, $deletions);
            }
        }
        foreach (array_keys($deletions) as $path) {
            $this->assertPlannedDestination($path, true, $deletions);
        }
        $databaseTargets = [];
        foreach ($plan['databases'] as $entry) {
            if (!is_string($entry['connection']) || !preg_match('/^[A-Za-z0-9_-]+$/D', $entry['connection'])) {
                throw new RuntimeException('Invalid database connection name in manifest.');
            }
            $config = config('database.connections.' . $entry['connection']);
            if (!is_array($config) || !in_array($config['driver'] ?? null, ['mysql', 'mariadb', 'pgsql', 'sqlite'], true)) {
                throw new RuntimeException("Missing or unsupported database connection: {$entry['connection']}");
            }
            $driver = $config['driver'];
            $compatibleDrivers = ['mysql' => 'mysql', 'mariadb' => 'mysql'];
            if (isset($entry['driver']) && ($compatibleDrivers[$entry['driver']] ?? $entry['driver']) !== ($compatibleDrivers[$driver] ?? $driver)) {
                throw new RuntimeException("Database driver mismatch for {$entry['connection']}.");
            }
            if (isset($entry['format']) && (!in_array($entry['format'], ['sql', 'sqlite'], true)
                || ($entry['format'] === 'sqlite') !== ($driver === 'sqlite'))) {
                throw new RuntimeException('Database dump format is incompatible with the target connection.');
            }
            if ($driver === 'sqlite') {
                $this->validateSqliteRestore($config, $entry['staged_path']);
                $identity = 'sqlite:' . $this->absoluteDatabasePath($config['database']);
            } else {
                $identity = json_encode([
                    $compatibleDrivers[$driver] ?? $driver, $config['unix_socket'] ?? $config['host'] ?? 'localhost',
                    $config['port'] ?? ($driver === 'pgsql' ? 5432 : 3306), $config['database'] ?? '',
                ]);
            }
            if (isset($databaseTargets[$identity])) {
                throw new RuntimeException('Multiple database dumps resolve to the same restore target.');
            }
            $databaseTargets[$identity] = true;
        }
    }

    protected function targetPath(string $name): string
    {
        RestoreArchive::assertSafeName($name);
        if (!str_starts_with($name, 'files/') || strlen($name) <= 6) {
            throw new RuntimeException("Invalid restore file path: {$name}");
        }
        $base = str_replace('\\', '/', $this->option('target') ?: base_path());
        if (!str_starts_with($base, '/') && !preg_match('/^[A-Za-z]:\//', $base)) {
            $base = getcwd() . '/' . $base;
        }
        // Canonicalize only the user-supplied target, never names from an archive.
        $prefix = str_starts_with($base, '/') ? '/' : substr($base, 0, 3);
        $components = [];
        foreach (explode('/', substr($base, strlen($prefix))) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                array_pop($components);
            } else {
                $components[] = $part;
            }
        }
        return rtrim($prefix . implode('/', $components), '/') . '/' . substr($name, 6);
    }

    protected function assertSafeDestination(string $path): void
    {
        clearstatcache();
        $current = $path;
        $leaf = true;
        while (true) {
            if (is_link($current)) {
                throw new RuntimeException("Restore destination contains a symbolic link: {$current}");
            }
            if (file_exists($current)) {
                if ($leaf && !is_file($current)) {
                    throw new RuntimeException("Restore destination is not a regular file: {$current}");
                }
                if (!$leaf && !is_dir($current)) {
                    throw new RuntimeException("Restore parent is not a directory: {$current}");
                }
            }
            $parent = dirname($current);
            if ($parent === $current) {
                break;
            }
            $current = $parent;
            $leaf = false;
        }
    }

    protected function assertPlannedDestination(string $path, bool $deletion, array $deletions = []): void
    {
        clearstatcache();
        $current = $path;
        $leaf = true;
        while (true) {
            if (is_link($current)) {
                throw new RuntimeException("Restore destination contains a symbolic link: {$current}");
            }
            if (file_exists($current) && !is_file($current) && !is_dir($current)) {
                throw new RuntimeException("Restore destination contains a special file: {$current}");
            }
            if (!$leaf && is_file($current) && !$deletion && !isset($deletions[$current])) {
                throw new RuntimeException("Restore parent is not a directory or a recorded deletion: {$current}");
            }
            $parent = dirname($current);
            if ($parent === $current) {
                break;
            }
            $current = $parent;
            $leaf = false;
        }
    }

    protected function assertDirectoryCanBeReplaced(string $directory, array $deletions): void
    {
        $children = scandir($directory);
        if ($children === false) {
            throw new RuntimeException("Cannot inspect directory being replaced: {$directory}");
        }
        foreach ($children as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $path = $directory . '/' . $name;
            if (is_link($path)) {
                throw new RuntimeException("Restore destination contains a symbolic link: {$path}");
            }
            if (is_dir($path)) {
                $this->assertDirectoryCanBeReplaced($path, $deletions);
            } elseif (!is_file($path) || !isset($deletions[$path])) {
                throw new RuntimeException("Directory replacement would remove an unrelated file: {$path}");
            }
        }
    }

    /** Remove only empty directories, never recursively remove file contents. */
    protected function removeEmptyDirectories(string $directory): void
    {
        if (is_link($directory)) {
            throw new RuntimeException("Restore destination contains a symbolic link: {$directory}");
        }
        $children = scandir($directory);
        if ($children === false) {
            throw new RuntimeException("Cannot inspect directory being replaced: {$directory}");
        }
        foreach ($children as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $path = $directory . '/' . $name;
            if (is_link($path) || !is_dir($path)) {
                throw new RuntimeException("Directory replacement would remove an unrelated file: {$path}");
            }
            $this->removeEmptyDirectories($path);
        }
        if (!@rmdir($directory)) {
            throw new RuntimeException("Cannot remove empty restore directory: {$directory}");
        }
    }

    protected function executePlan(array $plan): array
    {
        $counts = ['databases_restored' => 0, 'files_restored' => 0, 'files_deleted' => 0];
        $dryRun = (bool) $this->option('dry-run');
        $verbose = (bool) $this->option('detailed') && !$this->isJson();
        foreach ($plan['databases'] as $entry) {
            $this->progress("Database {$entry['path']} -> {$entry['connection']}");
            if (!$dryRun) {
                $this->importSqlDump($entry['connection'], $entry['staged_path'], $verbose);
            }
            $counts['databases_restored']++;
        }
        // Apply explicit historical file deletions before writes so file/directory
        // transitions work. Existing directories are never recursively deleted.
        foreach ($plan['deletions'] as $name) {
            $path = $this->targetPath($name);
            if (!$dryRun) {
                $this->assertPlannedDestination($path, true);
                if (is_file($path) && !@unlink($path)) {
                    throw new RuntimeException("Failed applying deletion: {$name}");
                }
            }
            $counts['files_deleted']++;
        }
        foreach ($plan['files'] as $name => $entry) {
            $path = $this->targetPath($name);
            if ($verbose) {
                $this->progress("File {$name}");
            }
            if (!$dryRun) {
                $this->assertPlannedDestination($path, false);
                if (is_dir($path)) {
                    $this->removeEmptyDirectories($path);
                }
                $this->assertSafeDestination($path);
                $directory = dirname($path);
                if (!is_dir($directory) && !@mkdir($directory, 0755, true) && !is_dir($directory)) {
                    throw new RuntimeException("Failed creating restore directory: {$directory}");
                }
                $this->assertSafeDestination($path);
                $temporary = tempnam($directory, '.capsule_');
                if ($temporary === false) {
                    throw new RuntimeException("Unable to stage restored file: {$name}");
                }
                try {
                    if (!@copy($entry['staged_path'], $temporary)) {
                        throw new RuntimeException("Failed writing restored file: {$name}");
                    }
                    $this->assertSafeDestination($path);
                    if (!@rename($temporary, $path)) {
                        throw new RuntimeException("Failed replacing restored file: {$name}");
                    }
                } finally {
                    if (file_exists($temporary)) {
                        @unlink($temporary);
                    }
                }
            }
            $counts['files_restored']++;
        }
        return $counts;
    }

    protected function showContents(array $chain, ?BackupLog $backup, array $source): int
    {
        $entries = [];
        foreach ($chain as $index => $archive) {
            foreach ($archive->entries() as $entry) {
                unset($entry['staged_path']);
                $entries[] = $entry + ['archive_index' => $index];
            }
        }
        $result = ['status' => 'success', 'backup_id' => $backup?->id, 'source' => $source,
            'archives_verified' => count($chain), 'total_entries' => count($entries), 'entries' => $entries,
            'deleted_files' => array_values($this->buildPlan($chain)['deletions'])];
        if ($this->isJson()) {
            $this->result($result);
        } else {
            foreach ($entries as $entry) {
                $this->line(Helpers::formatBytes($entry['size']) . '  ' . $entry['path']);
            }
            foreach ($result['deleted_files'] as $name) {
                $this->line('DELETE  ' . $name);
            }
            $this->info(count($entries) . ' verified entries in ' . count($chain) . ' archive(s).');
        }
        return self::SUCCESS;
    }

    protected function isJson(): bool
    {
        return $this->option('format') === 'json';
    }

    protected function progress(string $message): void
    {
        if (!$this->isJson()) {
            $this->line($message);
        }
    }

    protected function result(array $result, ?string $message = null, bool $error = false): void
    {
        if ($this->isJson()) {
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR));
        } elseif ($message !== null) {
            $error ? $this->error($message) : $this->info($message);
        }
    }

    protected function matchesPatterns(string $path, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if (fnmatch($pattern, $path) || fnmatch($pattern, basename($path))) {
                return true;
            }
        }
        return false;
    }
    protected function importSqlDump(string $connection, string $sqlPath, bool $verbose): void
    {
        $config = config("database.connections.{$connection}");
        if (!$config) {
            throw new \Exception("Database connection '{$connection}' not found.");
        }

        $driver = $config['driver'] ?? 'unknown';

        match ($driver) {
            'mysql', 'mariadb' => $this->importMysql($config, $sqlPath, $verbose),
            'pgsql' => $this->importPostgres($config, $sqlPath, $verbose),
            'sqlite' => $this->importSqlite($config, $sqlPath, $verbose),
            default => throw new \Exception("Unsupported driver for restore: {$driver}"),
        };
    }

    protected function importMysql(array $config, string $sqlPath, bool $verbose): void
    {
        $mysqlCmd = Helpers::commandExists('mysql') ? 'mysql' : (Helpers::commandExists('mariadb') ? 'mariadb' : null);
        if (!$mysqlCmd) {
            throw new \Exception('Neither mysql nor mariadb client found.');
        }

        $configFile = tempnam(sys_get_temp_dir(), 'mysql_config_');
        $escape = fn($v) => '"' . str_replace(["\\", "\n", "\r", '"'], ["\\\\", "\\n", "\\r", '\\"'], (string) $v) . '"';

        $content = sprintf("[client]\nuser=%s\npassword=%s\n", $escape($config['username'] ?? ''), $escape($config['password'] ?? ''));
        if (!empty($config['unix_socket'])) {
            $content .= 'socket=' . $escape($config['unix_socket']) . "\n";
        } else {
            $content .= sprintf("host=%s\nport=%s\n", $escape($config['host'] ?? 'localhost'), $escape($config['port'] ?? 3306));
        }

        file_put_contents($configFile, $content);
        chmod($configFile, 0600);

        try {
            $command = sprintf(
                '%s --defaults-extra-file=%s %s < %s 2>&1',
                $mysqlCmd,
                escapeshellarg($configFile),
                escapeshellarg($config['database']),
                escapeshellarg($sqlPath)
            );

            exec($command, $output, $returnCode);
            if ($returnCode !== 0) {
                throw new \Exception("MySQL import failed (code {$returnCode}): " . implode("\n", $output));
            }

            if ($verbose) {
                $this->info("  MySQL import complete for '{$config['database']}'");
            }
        } finally {
            @unlink($configFile);
        }
    }

    protected function importPostgres(array $config, string $sqlPath, bool $verbose): void
    {
        $pgpassFile = tempnam(sys_get_temp_dir(), 'pgpass_');
        $escape = fn($v) => str_replace(['\\', ':'], ['\\\\', '\\:'], (string) $v);

        file_put_contents($pgpassFile, sprintf(
            "%s:%s:%s:%s:%s\n",
            $escape($config['host']),
            $escape($config['port'] ?? 5432),
            $escape($config['database']),
            $escape($config['username']),
            $escape($config['password'])
        ));
        chmod($pgpassFile, 0600);

        try {
            $command = sprintf(
                'PGPASSFILE=%s psql --no-psqlrc --set=ON_ERROR_STOP=1 --host=%s --port=%s --username=%s --dbname=%s -f %s 2>&1',
                escapeshellarg($pgpassFile),
                escapeshellarg($config['host']),
                escapeshellarg($config['port'] ?? 5432),
                escapeshellarg($config['username']),
                escapeshellarg($config['database']),
                escapeshellarg($sqlPath)
            );

            exec($command, $output, $returnCode);
            if ($returnCode !== 0) {
                throw new \Exception("PostgreSQL import failed (code {$returnCode}): " . implode("\n", $output));
            }

            if ($verbose) {
                $this->info("  PostgreSQL import complete for '{$config['database']}'");
            }
        } finally {
            @unlink($pgpassFile);
        }
    }

    protected function absoluteDatabasePath(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        if (!str_starts_with($path, '/') && !preg_match('/^[A-Za-z]:\//', $path)) {
            $path = getcwd() . '/' . $path;
        }
        $prefix = str_starts_with($path, '/') ? '/' : substr($path, 0, 3);
        $components = [];
        foreach (explode('/', substr($path, strlen($prefix))) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                array_pop($components);
            } else {
                $components[] = $part;
            }
        }
        return $prefix . implode('/', $components);
    }

    protected function validateSqliteRestore(array $config, string $sqlPath): void
    {
        if (!class_exists(\SQLite3::class)) {
            throw new RuntimeException('Safe SQLite restore requires the ext-sqlite3 PHP extension.');
        }
        DumpValidator::validate($sqlPath, 'sqlite');
        $dbPath = $config['database'] ?? null;
        if (!is_string($dbPath) || $dbPath === ':memory:' || $dbPath === '') {
            throw new RuntimeException('SQLite restore requires a persistent database file.');
        }
        $dbPath = $this->absoluteDatabasePath($dbPath);
        $this->assertSafeDestination($dbPath);
        if (!is_dir(dirname($dbPath)) || !is_writable(dirname($dbPath))) {
            throw new RuntimeException('SQLite restore target directory must exist and be writable.');
        }
        if (is_file($dbPath) && filesize($dbPath) > 0) {
            $source = $target = null;
            try {
                $source = new \SQLite3($sqlPath, SQLITE3_OPEN_READONLY);
                $target = new \SQLite3($dbPath, SQLITE3_OPEN_READONLY);
                $source->enableExceptions(true);
                $target->enableExceptions(true);
                if ($target->querySingle('PRAGMA quick_check') !== 'ok') {
                    throw new RuntimeException('Existing SQLite restore target failed integrity validation.');
                }
                if (strtolower($target->querySingle('PRAGMA journal_mode')) === 'wal'
                    && $target->querySingle('PRAGMA page_size') !== $source->querySingle('PRAGMA page_size')) {
                    throw new RuntimeException('SQLite WAL target and backup must have the same page size.');
                }
            } finally {
                $target?->close();
                $source?->close();
            }
        }
    }

    protected function importSqlite(array $config, string $sqlPath, bool $verbose): void
    {
        $this->validateSqliteRestore($config, $sqlPath);
        $dbPath = $this->absoluteDatabasePath($config['database']);
        $source = $destination = null;
        try {
            $source = new \SQLite3($sqlPath, SQLITE3_OPEN_READONLY);
            $source->enableExceptions(true);
            $destination = new \SQLite3($dbPath, SQLITE3_OPEN_READWRITE | SQLITE3_OPEN_CREATE);
            $destination->enableExceptions(true);
            $destination->busyTimeout(5000);
            // SQLite's online backup API replaces the database inside a write
            // transaction, including WAL-backed targets. Copy/rename can leave
            // live WAL pages overriding restored contents and is never safe here.
            if (!@$source->backup($destination)) {
                throw new RuntimeException('SQLite restore failed; the target may be locked by an active transaction.');
            }
        } catch (\Throwable $e) {
            throw new RuntimeException('SQLite restore failed: ' . $e->getMessage(), 0, $e);
        } finally {
            $destination?->close();
            $source?->close();
        }
        if ($verbose) {
            $this->info("  SQLite restore complete: {$dbPath}");
        }
    }
}
