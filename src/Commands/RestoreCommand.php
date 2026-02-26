<?php

namespace Dgtlss\Capsule\Commands;

use Dgtlss\Capsule\Database\DatabaseDumper;
use Dgtlss\Capsule\Models\BackupLog;
use Dgtlss\Capsule\Support\Helpers;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use ZipArchive;

class RestoreCommand extends Command
{
    protected $signature = 'capsule:restore
        {id? : Backup log ID to restore (defaults to latest)}
        {--db-only : Only restore database dumps}
        {--files-only : Only restore files}
        {--target= : Target directory for file restoration (default: original paths)}
        {--connection= : Database connection to restore to (default: from backup)}
        {--only=* : Only restore specific files (glob patterns, e.g. config/*.php)}
        {--list : List archive contents without restoring}
        {--dry-run : Show what would be restored without making changes}
        {--force : Skip confirmation prompt}
        {--detailed : Verbose output}
        {--format=table : Output format (table|json)}';

    protected $description = 'Restore a backup (database and/or files)';

    public function handle(): int
    {
        $backup = $this->resolveBackup();
        if (!$backup) {
            $this->error('No successful backup found to restore.');
            return self::FAILURE;
        }

        $zip = $this->downloadAndOpen($backup);
        if (!$zip) {
            return self::FAILURE;
        }

        try {
            if ($this->option('list')) {
                return $this->listContents($zip, $backup);
            }

            return $this->performRestore($zip, $backup);
        } finally {
            $tmpPath = $zip->filename;
            $zip->close();
            @unlink($tmpPath);
        }
    }

    protected function listContents(ZipArchive $zip, BackupLog $backup): int
    {
        $format = $this->option('format');
        $entries = [];

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            $stat = $zip->statIndex($i);
            $entries[] = [
                'path' => $name,
                'size' => $stat['size'] ?? 0,
                'size_formatted' => Helpers::formatBytes($stat['size'] ?? 0),
                'compressed' => $stat['comp_size'] ?? 0,
                'type' => str_starts_with($name, 'database/') ? 'database' : (str_starts_with($name, 'files/') ? 'file' : 'meta'),
            ];
        }

        if ($format === 'json') {
            $this->line(json_encode([
                'backup_id' => $backup->id,
                'total_entries' => count($entries),
                'entries' => $entries,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return self::SUCCESS;
        }

        $this->info("Backup #{$backup->id} - {$zip->numFiles} entries");
        $this->newLine();

        $dbEntries = array_filter($entries, fn($e) => $e['type'] === 'database');
        $fileEntries = array_filter($entries, fn($e) => $e['type'] === 'file');
        $metaEntries = array_filter($entries, fn($e) => $e['type'] === 'meta');

        if (!empty($dbEntries)) {
            $this->info('Database dumps (' . count($dbEntries) . ')');
            foreach ($dbEntries as $e) {
                $this->line("  {$e['size_formatted']}  {$e['path']}");
            }
            $this->newLine();
        }

        if (!empty($fileEntries)) {
            $this->info('Files (' . count($fileEntries) . ')');
            $shown = 0;
            foreach ($fileEntries as $e) {
                if ($shown < 50 || $this->option('detailed')) {
                    $this->line("  {$e['size_formatted']}  {$e['path']}");
                }
                $shown++;
            }
            if ($shown > 50 && !$this->option('detailed')) {
                $this->comment("  ... and " . ($shown - 50) . " more (use --detailed to show all)");
            }
            $this->newLine();
        }

        if (!empty($metaEntries)) {
            $this->comment('Metadata');
            foreach ($metaEntries as $e) {
                $this->line("  {$e['size_formatted']}  {$e['path']}");
            }
        }

        $totalSize = array_sum(array_column($entries, 'size'));
        $this->newLine();
        $this->info('Total: ' . count($entries) . ' entries, ' . Helpers::formatBytes($totalSize));

        return self::SUCCESS;
    }

    protected function performRestore(ZipArchive $zip, BackupLog $backup): int
    {
        $verbose = (bool) $this->option('detailed');
        $dryRun = (bool) $this->option('dry-run');
        $dbOnly = (bool) $this->option('db-only');
        $filesOnly = (bool) $this->option('files-only');
        $onlyPatterns = $this->option('only');

        $this->info("Backup #{$backup->id} | {$backup->created_at} | " . Helpers::formatBytes($backup->file_size ?? 0));

        if ($dryRun) {
            $this->warn('DRY RUN -- no changes will be made.');
        }

        if (!$dryRun && !$this->option('force')) {
            if (!$this->confirm('This will overwrite existing data. Continue?')) {
                $this->info('Restore cancelled.');
                return self::SUCCESS;
            }
        }

        $restoredDb = 0;
        $restoredFiles = 0;

        if (!$filesOnly && empty($onlyPatterns)) {
            $restoredDb = $this->restoreDatabase($zip, $dryRun, $verbose);
        }

        if (!$dbOnly) {
            $restoredFiles = $this->restoreFiles($zip, $dryRun, $verbose, $onlyPatterns);
        }

        $this->newLine();
        $action = $dryRun ? 'Would restore' : 'Restored';
        $this->info("{$action}: {$restoredDb} database dump(s), {$restoredFiles} file(s).");

        return self::SUCCESS;
    }

    protected function downloadAndOpen(BackupLog $backup): ?ZipArchive
    {
        $diskName = config('capsule.default_disk', 'local');
        $disk = Storage::disk($diskName);

        if (!$backup->file_path || !$disk->exists($backup->file_path)) {
            $this->error("Backup file not found on disk '{$diskName}': {$backup->file_path}");
            return null;
        }

        $tmpPath = tempnam(sys_get_temp_dir(), 'capsule_restore_');
        $this->info('Downloading backup archive...');

        $stream = $disk->readStream($backup->file_path);
        if ($stream === false) {
            $this->error('Failed to open remote file stream.');
            return null;
        }

        $out = fopen($tmpPath, 'wb');
        stream_copy_to_stream($stream, $out);
        fclose($out);
        if (is_resource($stream)) {
            fclose($stream);
        }

        $zip = new ZipArchive();
        $password = config('capsule.security.backup_password') ?? env('CAPSULE_BACKUP_PASSWORD');

        if ($zip->open($tmpPath) !== true) {
            @unlink($tmpPath);
            $this->error('Failed to open backup archive.');
            return null;
        }

        if (!empty($password)) {
            @$zip->setPassword($password);
        }

        return $zip;
    }

    protected function resolveBackup(): ?BackupLog
    {
        $id = $this->argument('id');
        if ($id) {
            return BackupLog::where('id', (int) $id)->where('status', 'success')->first();
        }

        return BackupLog::where('status', 'success')->orderByDesc('created_at')->first();
    }

    protected function restoreDatabase(ZipArchive $zip, bool $dryRun, bool $verbose): int
    {
        $this->info('Restoring database...');
        $count = 0;

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (!str_starts_with($name, 'database/') || !str_ends_with($name, '.sql')) {
                continue;
            }

            $connectionName = basename($name, '.sql');
            $targetConnection = $this->option('connection') ?? $connectionName;

            $this->line("  {$name} -> connection '{$targetConnection}'");

            if ($dryRun) {
                $count++;
                continue;
            }

            $tmpSql = tempnam(sys_get_temp_dir(), 'capsule_sql_');
            $sqlStream = $zip->getStream($name);
            if ($sqlStream === false) {
                $this->warn("  Skipping {$name}: unable to read from archive.");
                continue;
            }

            $outFile = fopen($tmpSql, 'wb');
            stream_copy_to_stream($sqlStream, $outFile);
            fclose($outFile);
            fclose($sqlStream);

            try {
                $this->importSqlDump($targetConnection, $tmpSql, $verbose);
                $count++;
            } catch (\Exception $e) {
                $this->error("  Failed to restore {$name}: {$e->getMessage()}");
            } finally {
                @unlink($tmpSql);
            }
        }

        return $count;
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
                'PGPASSFILE=%s psql --host=%s --port=%s --username=%s --dbname=%s -f %s 2>&1',
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

    protected function importSqlite(array $config, string $sqlPath, bool $verbose): void
    {
        $dbPath = $config['database'];
        if (!copy($sqlPath, $dbPath)) {
            throw new \Exception("Failed to copy SQLite dump to {$dbPath}");
        }

        if ($verbose) {
            $this->info("  SQLite restore complete: {$dbPath}");
        }
    }

    protected function restoreFiles(ZipArchive $zip, bool $dryRun, bool $verbose, array $onlyPatterns = []): int
    {
        $this->info('Restoring files...');
        $targetBase = $this->option('target') ?? base_path();
        $count = 0;
        $skipped = 0;

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (!str_starts_with($name, 'files/')) {
                continue;
            }

            $relativePath = substr($name, strlen('files/'));
            if (empty($relativePath) || str_ends_with($name, '/')) {
                continue;
            }

            if (!empty($onlyPatterns) && !$this->matchesPatterns($relativePath, $onlyPatterns)) {
                $skipped++;
                continue;
            }

            $targetPath = rtrim($targetBase, '/') . '/' . $relativePath;

            if ($verbose) {
                $this->line("  {$relativePath}");
            }

            if ($dryRun) {
                $count++;
                continue;
            }

            $targetDir = dirname($targetPath);
            if (!is_dir($targetDir)) {
                mkdir($targetDir, 0755, true);
            }

            $entryStream = $zip->getStream($name);
            if ($entryStream === false) {
                $this->warn("  Skipping {$name}: unable to read from archive.");
                continue;
            }

            $outFile = fopen($targetPath, 'wb');
            stream_copy_to_stream($entryStream, $outFile);
            fclose($outFile);
            fclose($entryStream);
            $count++;
        }

        if (!empty($onlyPatterns) && $skipped > 0) {
            $this->comment("  Skipped {$skipped} file(s) not matching --only patterns");
        }

        return $count;
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
}
