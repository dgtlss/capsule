# Capsule Package Analysis

## Package Summary

**Capsule** (v1.1.0) is a Laravel backup package that backs up databases and files to external storage on a schedule. It supports MySQL/MariaDB, PostgreSQL, and SQLite; stores to any Laravel filesystem disk (local, S3, FTP, etc.); sends notifications via Email/Slack/Discord/Teams/Google Chat; and provides retention policies, verification, a Filament panel, and Spatie Health integration.

### Architecture Overview

| Area | Files |
|---|---|
| Service layer | `BackupService`, `ChunkedBackupService`, `ConcurrentUploadManager` |
| Storage | `StorageManager` (wraps Laravel filesystem) |
| Notifications | `NotificationManager` + 5 channel classes |
| Commands | 7 artisan commands (backup, cleanup, verify, list, inspect, diagnose, health) |
| Extensibility | `StepInterface`, `FileFilterInterface`, 9 event classes |
| Health | `BackupHealthCheck`, `CapsuleBackupCheck` (Spatie) |
| UI | Filament `BackupsPage` + Blade view |
| Model | `BackupLog` |

---

## Proposed Changes

### 1. Add a Test Suite

**Priority: Critical**

There are zero test files. For a package that handles critical backup data, this is the single most important gap. At minimum, the package needs:

- Unit tests for `StorageManager`, `NotificationManager`, `BackupLog` model, `Lock`, `MemoryMonitor`, `BackupHealthCheck`.
- Integration tests for `BackupService::run()` and `ChunkedBackupService::run()` using SQLite and the `local` disk.
- Command tests for all 7 artisan commands verifying exit codes, output, and side effects.
- Tests for edge cases: empty database, missing paths, unreachable storage, concurrent lock contention.

Orchestra Testbench is already in `require-dev` but unused.

---

### 2. Fix the Retention Cleanup Logic Bug

**Priority: Critical**

`BackupService::cleanup()` (lines 1038-1069) uses `orWhereNotIn` inside the `where` callback, which means it deletes backups that are either older than N days **OR** not in the latest N count -- whichever matches first. The intended behavior should be: delete backups that are older than N days **AND** not protected by the count-based keep list. The current logic is overly aggressive and can delete recent backups that exceed the count threshold even if they're well within the retention window.

The same pattern appears in `CleanupCommand::cleanupSuccessfulBackups()`.

---

### 3. Extract Shared Logic from BackupService and ChunkedBackupService

**Priority: High**

These two classes duplicate large amounts of code:

- MySQL dump construction (config file creation, flag building, SSL handling) -- ~100 lines duplicated.
- PostgreSQL dump construction -- ~40 lines duplicated.
- `getMysqlDumpCommand()` and `commandExists()` -- identical methods in both classes.
- `buildManifest()` -- nearly identical.
- `shouldExcludePath()` -- duplicated with slightly different logic (the chunked version uses `strpos` prefix matching only, the standard version also matches basenames).
- `formatBytes()` -- duplicated in 6 different classes.

Proposed extraction:
- A `DatabaseDumper` class (or per-driver strategy classes) for dump logic.
- A `ManifestBuilder` class.
- A shared `Helpers::formatBytes()` utility or a trait.
- A `BackupServiceInterface` that both services implement, enabling polymorphic usage.

---

### 4. Use Dependency Injection Instead of Direct Instantiation

**Priority: High**

`BackupService` and `ChunkedBackupService` both `new` up `StorageManager` and `NotificationManager` directly in their constructors. `CleanupCommand` also creates `new StorageManager()` and `new NotificationManager()` inline. This makes the classes impossible to unit test with mocks and bypasses the service container.

These should be constructor-injected dependencies, resolved from the container.

---

### 5. Fix Lock Lifecycle (Acquire/Release)

**Priority: High**

In `BackupCommand::handle()`, the lock is acquired but never explicitly released. If the backup throws an exception, the lock remains held until the cache TTL expires (default 15 minutes). The lock should be released in a `finally` block.

Additionally, the lock object returned by `Lock::acquire()` is not stored in a variable that would allow later release -- the `Lock` class returns the lock object but the command doesn't call `->release()` on it.

---

### 6. Add a Restore Command

**Priority: High**

A backup package without any restore capability forces users to manually download and extract archives. Even a basic `capsule:restore {id} [--db-only] [--files-only] [--target=]` that downloads, extracts, and optionally re-imports database dumps would dramatically improve usability.

---

### 7. Fix composer.json PHP Version Mismatch

**Priority: Medium**

`composer.json` requires `php: ^8.3` but the README states "PHP 8.1+". One of these is wrong. Given that the code doesn't use any PHP 8.3-specific features, the `composer.json` constraint should likely be relaxed to `^8.1`.

---

### 8. Make S3 and FTP Flysystem Adapters Optional

**Priority: Medium**

`league/flysystem-aws-s3-v3` and `league/flysystem-ftp` are hard `require` dependencies. Since Capsule uses Laravel's filesystem abstraction and the user's own disk config, these adapters should be `suggest`ed rather than required. Users who only back up to local disk shouldn't need the AWS SDK installed.

---

### 9. Bring ChunkedBackupService to Feature Parity

**Priority: Medium**

`ChunkedBackupService` is missing several features present in `BackupService`:

- No preflight database connection probe (the standard service aborts early with a clear error if the DB is unreachable).
- No `validateConfiguration()` call.
- No event dispatching (`BackupStarting`, `DatabaseDumpStarting`, etc.).
- No pre/post step execution.
- No file filter support.
- No graceful degradation when the `backup_logs` table is unavailable.
- Hardcodes `--routines --triggers` for MySQL dumps instead of respecting config values for `include_triggers` and `include_routines`.
- Uses `PGPASSWORD` environment variable in the command line for PostgreSQL (visible in `ps` output) instead of the `.pgpass` file approach used by `BackupService`.

---

### 10. Use Standard Verbose Flag Convention

**Priority: Medium**

All commands use `--v` instead of the standard `--verbose` / `-v` convention from Symfony Console (which Laravel inherits). This is confusing because `-v` already exists as a built-in Symfony verbosity flag. The custom `--v` option shadows/conflicts with it. Switch to using the built-in verbosity levels or a properly named `--detailed` flag.

---

### 11. Add --db-only and --files-only Flags

**Priority: Medium**

Currently, the only way to back up just the database or just files is to edit the config. Adding `--db-only` and `--files-only` flags to `capsule:backup` would allow ad-hoc partial backups without config changes.

---

### 12. Support Cron Expressions for Scheduling

**Priority: Medium**

The scheduler only supports four fixed frequencies: `hourly`, `daily`, `weekly`, `monthly`. This doesn't cover common needs like `twiceDaily`, `everyFourHours`, `weeklyOn(1, '03:00')`, or arbitrary cron expressions. Add support for a `cron` option in the schedule config.

---

### 13. Add Multi-Disk Backup Support

**Priority: Medium**

`default_disk` only supports a single destination. Many backup strategies require writing to multiple locations (e.g., local + S3) for redundancy. Support an array of disks or a `disks` config key alongside `default_disk`.

---

### 14. Add Backup Tagging/Naming

**Priority: Low**

Allow users to label backups with a `--tag=pre-deploy` or `--name=` option. This would populate a field in `BackupLog` and make it easier to identify specific backups in `capsule:list` output.

---

### 15. Add a Download Command

**Priority: Low**

Add `capsule:download {id} [--path=]` to download a backup from remote storage to a local path. Currently there's no CLI way to retrieve a stored backup.

---

### 16. Improve Filament Integration

**Priority: Low**

The Filament page is minimal:

- The "Inspect" button shows a JavaScript `alert()` telling the user to use the CLI.
- No filtering, pagination, search, or status badges.
- No ability to trigger a backup or cleanup from the UI.
- No download links.
- The `CapsuleServiceProvider` in the Filament namespace is an empty placeholder.

Upgrade to a proper Filament resource with a table, filters, actions (backup, cleanup, download), and status indicators.

---

### 17. Add Built-in File Filter Implementations

**Priority: Low**

The `FileFilterInterface` exists but there are no built-in implementations. Ship common filters:

- `MaxFileSizeFilter` -- exclude files above a configurable size.
- `ExtensionFilter` -- include/exclude by file extension.
- `PatternFilter` -- glob/regex pattern matching.

These can be registered in the config and would cover the most common customization needs without users writing custom classes.

---

### 18. Fix `shouldExcludePath` Basename Matching

**Priority: Low**

`BackupService::shouldExcludePath()` has a basename comparison that causes any file matching the basename of an exclude path to be excluded, regardless of its directory. For example, excluding `base_path('vendor')` would also exclude a file named `vendor` in any other directory. This logic should be tightened or made opt-in via glob patterns.

---

### 19. Replace `error_log()` with Laravel's Log Facade in MemoryMonitor

**Priority: Low**

`MemoryMonitor::logMemoryUsage()` writes directly to `error_log()` instead of using Laravel's `Log` facade. This bypasses the application's configured logging channels.

---

### 20. Improve CleanupCommand Memory Usage

**Priority: Low**

`CleanupCommand::cleanupSuccessfulBackups()` calls `->get()` which loads all matching backup records into memory. For applications with thousands of backups, this could be a problem. Use `eachById()` or `cursor()` for iteration (note: `ChunkedBackupService::cleanup()` already correctly uses `eachById`).

---

## Unresolved Questions

1. Is the `orWhereNotIn` retention logic intentional (delete anything outside the top N regardless of age), or is it a bug? The `CleanupCommand` implements it differently when `--days` is passed vs. not, suggesting confusion about the intended behavior.
2. Is PHP 8.3 actually the minimum, or should it be 8.1 as the README states?
3. Is the `ConcurrentUploadManager` actually used anywhere? It doesn't appear to be called from either backup service -- both services upload chunks inline. This may be dead code.
4. Should the chunked backup's lack of events/steps/filters be considered a bug or an intentional simplification?
