# Recovery reliability audit

This document maps the twenty recovery-audit findings to the implementation and regression coverage. The historical `ANALYSIS.md` is retained for context; it does not describe the current test suite.

## Finding-to-change map

| # | Finding | Implementation | Regression coverage |
| --- | --- | --- | --- |
| 1 | Local backup copied onto itself | Unique archives staged outside destination disks | `BackupReliabilityTest` |
| 2 | False storage writes accepted | Check results, rewind retries, verify stored size and SHA-256 before publication | `StreamingStorageTest`, `ChunkedBackupSafetyTest` |
| 3 | Restore traversal and symlinks | Validate every member and destination before any writes/imports | `RestoreCommandTest` |
| 4 | Streamed file fragments lost | One ZIP entry per complete file with shared root mapping | `StreamingStorageTest`; `BackupRestoreIntegrationTest` |
| 5 | Dump errors hidden by cleanup | Preserve process exit status; validate all dump modes | `DatabaseDumperTest`, `DumpValidatorTest` |
| 6 | Incomplete incremental recovery | Checksum-bound portable base references, changes/deletions, dependency-aware retention | `BackupReliabilityTest`, `RestoreCommandTest`, `RetentionSafetyTest` |
| 7 | SQLite WAL data lost | Consistent SQLite snapshots rather than copying the live file | `SqliteSnapshotTest`; real WAL round trips |
| 8 | Encryption silently disabled | Password/capability checks and checked encryption operations; fail closed for streaming | `BackupReliabilityTest`, encrypted round trip, restore password failures |
| 9 | Policy identity lost | Scoped policy execution, persisted destination/retention metadata, policy-aware readers/cleanup | `PolicyIsolationTest`, `RetentionSafetyTest`, restore/verify metadata tests |
| 10 | State leaks between runs | Fresh service state; configuration overrides restored in `finally` | Repeated backup and `PolicyIsolationTest` |
| 11 | Colliding roots/private files excluded | Schema 2 distinct file roots; precise output exclusions; safe-name validation | Root collision/private upload tests and two-root round trips |
| 12 | Names and locks collide | Unique run names; shared operation lock; return releasable acquired locks | Repeated backup and policy/locking regressions |
| 13 | Partial restores report success | Full archive verification first; checked writes/imports; PostgreSQL `ON_ERROR_STOP` | `RestoreCommandTest` |
| 14 | Streaming consumes full ZIP/dump resources | Bounded ZIP64 output, streaming database reads, verified storage; SQLite snapshot exception | Streaming resource/failure tests and real recovery |
| 15 | Catalog required for recovery | `--archive`, or `--disk` plus `--path`; optional `--base-archive` | Catalog-free full/incremental restore tests |
| 16 | Unhealthy state overwritten | Positive elapsed age and preserved health warning/failure results | `BackupHealthCheckTest`; optional Spatie integration CI row |
| 17 | No recovery/matrix coverage | Declared `ext-zip`, aligned PHPUnit/Testbench, isolated fixtures and seven CI combinations | Standard, streamed, encrypted real ZIP/SQLite recovery |
| 18 | Retention duplicated/unsafe | Shared retention service; catalog retained on storage deletion failure; protected bases | `RetentionSafetyTest`, `CleanupCommandTest` |
| 19 | Dormant promises | Removed unused concurrent-upload, envelope-encryption and S3-lifecycle implementations/configuration | No internal callers found; full suite guards remaining paths |
| 20 | Mixed JSON and human output | Structured command results with failure exit codes and separated progress | Command JSON regressions, including restore success/failure |

## Recovery compatibility and behavior changes

- **Breaking compatibility change:** Laravel 10/11 and PHP 8.1 are no longer supported. Required versions are Laravel 12 with PHP 8.2+, or Laravel 13 with PHP 8.3+. The remaining CI matrix keeps Composer security enforcement enabled; no release/version bump is made by this PR.
- New schema 2 archives contain `files/<root>/...`. Numeric configured roots become `root-0`, `root-1`, etc.; safe explicit names remain named. Restore preserves those root directories below `--target`, or below the application base path by default. Check the plan before restoring over an application.
- Safe schema 1 archives with valid SHA-256 manifests remain readable in their original layout. Missing/invalid manifests, missing/extra payloads, traversal paths, symlinks, duplicate destinations and wrong passwords fail before a restore writes data.
- Incremental archives require their checksum-matching full base. The manifest carries `base_archive` with `disk`, `path` and `sha256`, plus `deleted_files`. A local `--base-archive` override supports disaster recovery without the original catalog or storage connection. Copy and retain both artifacts.
- Both producers and readers enforce a 16 MiB manifest limit. Excessive metadata fails closed; bounded payload streaming does not imply unlimited file counts.
- Backup and cleanup share a cache lock whose TTL must exceed the longest operation. The lease is not automatically renewed. `--force` explicitly bypasses coordination, including protection against an active cleanup or backup.
- Recorded disk/policy/retention metadata governs each archive after configuration changes. Legacy records fall back to the configured disk.
- Retention `count` protects the newest N from age pruning, rather than imposing a maximum count. Explicit `--days` bypasses that protection; storage-budget pruning can prune it. `min_keep` and required incremental bases remain protected. A storage deletion failure retains its catalog record.
- SQLite recovery requires `ext-sqlite3` and copies the validated snapshot through SQLite's backup API into the target database. It fails closed without the extension; it does not overwrite a live WAL-mode database file directly. SQLite backup and validation require `ext-pdo_sqlite`.
- Standard AES ZIP encryption needs a password and a compatible libzip build. The streamed mode rejects encryption; it never silently writes plaintext when encryption was requested.
- Streaming bounds payload buffers but metadata grows with entry/chunk count. The stream is read more than once and stored objects are read back for verification, adding storage I/O. SQLite requires temporary disk space for a consistent database snapshot. Filesystem adapters may also buffer internally. Failed final uploads preserve source-chunk references in backup metadata for recovery.
- The three removed services had no internal callers. Applications directly instantiating these undocumented classes must remove that usage. Envelope encryption/key rotation, package-managed concurrent uploads and S3 lifecycle transitions are not supported. Configure provider-native lifecycle rules separately when needed.

## Verification

- Before changes: the clean repository suite passed **66 tests / 164 assertions** on PHP 8.4.24, PHPUnit 10.5.66 and Laravel 11.
- The new end-to-end tests exercise real local storage, binary ZIP entries, two roots sharing a basename, a live WAL-mode SQLite database, and successful recovery through the public restore command. Standard, streamed and encrypted runs pass on the locally installed Laravel 13 stack. A fourth round trip generates a full backup plus an incremental, hides the backup catalog, and verifies unchanged/changed/added/deleted files after recovery.
- `.github/workflows/tests.yml` declares seven supported Laravel/PHP combinations. CI resolves actual releases (`minimum-stability: stable`), rather than accepting a development branch to satisfy compatibility. The Laravel 13 / PHP 8.4 row also installs the optional Spatie Health integration.
- A workflow definition is not a passing CI run. Review checks on the PR's exact commit before release. Local runtime coverage is PHP 8.4; PHP 8.2/8.3/8.5 execution is delegated to CI. [Composer dependency resolution blocks Laravel 10/11 because of framework security advisories](compatibility-resolution.md); the supported matrix excludes those releases and no security checks have been disabled.
- Tests use synthetic MySQL/PostgreSQL client processes for exit status/validation behavior. Live MySQL/PostgreSQL recovery, real cloud storage/adapter behavior, notification delivery and Filament runtime are not established by this suite. Perform deployment-specific restore drills.

## Final local validation

The complete suite passed on PHP 8.4.24 / Laravel 13.35.0 / PHPUnit 11.5.57: **213 tests, 947 assertions, one skipped optional Spatie test**. The optional Spatie integration was exercised separately with the package installed (three tests, three assertions, no skip). PHP syntax and whitespace checks passed. Composer configuration validates; the existing explicit package-version field produces a recommendation warning.

Additional streaming checks verified empty files, Unicode names, a real 4 GiB+1 entry, and ZIP64 compressed-size overflow with Java, Python and InfoZip readers. The generator used 4 MiB peak memory in those isolated probes; this is not a total application/adapter memory guarantee. Permanent tests include a 96 MiB incompressible collation case and corruption/truncation/retry regressions. Final CI results must be checked on the published commit.

## Dependency references

- [Laravel supported PHP versions](https://laravel.com/docs/13.x/releases)
- Official Testbench dependency metadata: [8.x](https://github.com/orchestral/testbench/blob/8.x/composer.json), [9.x](https://github.com/orchestral/testbench/blob/9.x/composer.json), [10.x](https://github.com/orchestral/testbench/blob/10.x/composer.json), [11.x](https://github.com/orchestral/testbench/blob/11.x/composer.json)
- [SQLite online backups](https://www.sqlite.org/backup.html)
- [PostgreSQL psql error behavior](https://www.postgresql.org/docs/current/app-psql.html)
