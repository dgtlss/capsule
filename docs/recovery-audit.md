# Recovery reliability audit

This document maps the twenty recovery-audit findings to the implementation and regression coverage. The historical `ANALYSIS.md` is retained for context; it does not describe the current test suite.

> **Encrypted streaming recovery requires Capsule.** `--no-local --encrypt` creates `.capsule.enc`, not a standard encrypted ZIP. Retain a compatible Capsule package/installation and dependency lock with the recovery kit, store the password separately, and test an isolated recovery. The standalone `vendor/bin/capsule-decrypt` needs no original Laravel application or backup catalog and produces a verified plaintext ZIP. See [the recovery steps and format specification](streaming-encryption.md).

## Finding-to-change map

| # | Finding | Implementation | Regression coverage |
| --- | --- | --- | --- |
| 1 | Local backup copied onto itself | Unique archives staged outside destination disks | `BackupReliabilityTest` |
| 2 | False storage writes accepted | Check results, rewind retries, verify stored size and SHA-256 before publication | `StreamingStorageTest`, `ChunkedBackupSafetyTest` |
| 3 | Restore traversal and symlinks | Validate every member and destination before any writes/imports | `RestoreCommandTest`, `LegacyArchiveValidationTest` |
| 4 | Streamed file fragments lost | One ZIP entry per complete file with shared root mapping | `StreamingStorageTest`; `BackupRestoreIntegrationTest` |
| 5 | Dump errors hidden by cleanup | Preserve process exit status; validate all dump modes | `DatabaseDumperTest`, `DumpValidatorTest` |
| 6 | Incomplete incremental recovery | Checksum-bound portable base references, changes/deletions, dependency-aware retention | `BackupReliabilityTest`, `RestoreCommandTest`, `RetentionSafetyTest`, `LegacyIncrementalRestoreTest` |
| 7 | SQLite WAL data lost | Consistent SQLite snapshots rather than copying the live file | `SqliteSnapshotTest`; real WAL round trips |
| 8 | Encryption silently disabled | Password/capability checks; native standard AES ZIP, bounded sodium secretstream container, authenticated encrypted remote chunks, explicit Capsule-required recovery | `BackupReliabilityTest`, `EncryptedStreamingBackupTest`, `EncryptedArchiveTest`, `EncryptedChunkCodecTest`, `RestoreAuthenticationTest`, `DecryptCommandTest`, `LegacyMainBackupCompatibilityTest` |
| 9 | Policy identity lost | Scoped policy execution, persisted destination/retention metadata, policy-aware readers/cleanup | `PolicyIsolationTest`, `RetentionSafetyTest`, restore/verify metadata tests |
| 10 | State leaks between runs | Fresh service state; configuration overrides restored in `finally` | Repeated backup and `PolicyIsolationTest` |
| 11 | Colliding roots/private files excluded | Schema 2 distinct file roots; precise output exclusions; safe-name validation | Root collision/private upload tests and two-root round trips |
| 12 | Names and locks collide | Unique run names; shared operation lock; return releasable acquired locks | Repeated backup and policy/locking regressions |
| 13 | Partial restores report success | Full archive verification first; checked writes/imports; PostgreSQL `ON_ERROR_STOP` | `RestoreCommandTest` |
| 14 | Streaming consumes full ZIP/dump resources | Bounded ZIP64 output, streaming database reads, verified storage; SQLite snapshot exception | Streaming resource/failure tests and real recovery |
| 15 | Catalog required for recovery | `--archive`, or `--disk` plus `--path`; optional `--base-archive`; standalone `.capsule.enc` decryption without booting Laravel | Catalog-free full/incremental restore tests; `DecryptCommandTest` |
| 16 | Unhealthy state overwritten | Positive elapsed age and preserved health warning/failure results | `BackupHealthCheckTest`; optional Spatie integration CI row |
| 17 | No recovery/matrix coverage | Declared `ext-zip`, aligned PHPUnit/Testbench, isolated fixtures and seven CI combinations | Standard, streamed, encrypted real ZIP/SQLite recovery |
| 18 | Retention duplicated/unsafe | Shared retention service; catalog retained on storage deletion failure; protected bases | `RetentionSafetyTest`, `CleanupCommandTest` |
| 19 | Dormant promises | Removed unused concurrent-upload, legacy key-management and S3-lifecycle implementations/configuration | No internal callers found; full suite guards remaining paths |
| 20 | Mixed JSON and human output | Structured command results with failure exit codes and separated progress | Command JSON regressions, including restore success/failure |

## Recovery compatibility and behavior changes

- **Breaking compatibility change:** Laravel 10/11 and PHP 8.1 are no longer supported. Required versions are Laravel 12 with PHP 8.2+, or Laravel 13 with PHP 8.3+. The remaining CI matrix keeps Composer security enforcement enabled; no release/version bump is made by this PR.
- New schema 2 archives contain `files/<root>/...`. Numeric configured roots become `root-0`, `root-1`, etc.; safe explicit names remain named. Restore preserves those root directories below `--target`, or below the application base path by default. Check the plan before restoring over an application.
- Supported schema 1 archives remain readable in their original layout. Recorded SHA-256 values are checked; a narrowly recognized old full-stream database omission is handled with explicit limited-integrity warnings, as detailed below. Missing/invalid manifests, missing or unsupported extra payloads, traversal paths, symlinks, conflicting destinations, and wrong passwords fail before a restore writes destination data.
- New schema 2 incremental archives require their checksum-matching full base. The manifest carries `base_archive` with `disk`, `path` and `sha256`, plus `deleted_files`. A local `--base-archive` override supports disaster recovery without the original catalog or storage connection. Copy and retain both original artifacts. For encrypted streaming, retain the original `.capsule.enc` base; its decrypted ZIP has different bytes and cannot replace the checksum-bound original.
- New producers keep a 16 MiB manifest cap. Readers default to a 16 MiB per-archive budget, configurable with `capsule.restore.max_manifest_bytes`; restore also accepts `--max-manifest-bytes=N`. A positive decimal integer no greater than `PHP_INT_MAX` is required, with no unlimited mode. Inspect/verify use the configuration. Raising the budget does not relax path/schema/checksum validation or the producer cap; bounded payload streaming does not imply unlimited metadata memory.
- Backup and cleanup share a cache lock whose TTL must exceed the longest operation. The lease is not automatically renewed. `--force` explicitly bypasses coordination, including protection against an active cleanup or backup.
- Recorded disk/policy/retention metadata governs each archive after configuration changes. Legacy records fall back to the configured disk.
- Retention `count` protects the newest N from age pruning, rather than imposing a maximum count. Explicit `--days` bypasses that protection; storage-budget pruning can prune it. `min_keep` and required incremental bases remain protected. A storage deletion failure retains its catalog record.
- SQLite recovery requires `ext-sqlite3` and copies the validated snapshot through SQLite's backup API into the target database. It fails closed without the extension; it does not overwrite a live WAL-mode database file directly. SQLite backup and validation require `ext-pdo_sqlite`.
- **Encrypted streaming format change:** `--no-local --encrypt` creates a compressed ZIP inside a version 1 `.capsule.enc` sodium secretstream container. It is not a password-protected ZIP: compatible Capsule and the password are required to decrypt. The outer container hides member names and the manifest, and remote capture chunks are independently authenticated and encrypted. Argon2id13 uses a random salt, two passes, and 64 MiB native working memory. Streaming passwords are 1–4096 bytes, with NUL/CR/LF rejected and no trimming, truncation, or normalization. No Python runtime or `nelexa/zip` is required. Standard `--encrypt` remains a locally staged native AES ZIP and existing standard/legacy AES ZIP recovery remains available. See [format, resource, and dependency details](streaming-encryption.md).
- Preserve a compatible Capsule package/source version, working dependency installation, Composer lock, and format documentation with the recovery kit; keep the password separately. `vendor/bin/capsule-decrypt backup.capsule.enc --output=recovered.zip` runs without the original application/catalog and uses a hidden prompt or `--password-stdin`, with no password argument or environment/config fallback. The equivalent Laravel command is `capsule:decrypt`. Both accept `--format=json`, validate the complete archive before publishing a new plaintext ZIP, and require local space. Perform an isolated recovery drill before enabling the format.
- `.capsule.enc` restore authenticates and decrypts into a private temporary ZIP, then validates all checksums and paths before destination changes. Handled errors remove partial staging; power loss or SIGKILL can leave private plaintext files. Verify and inspect also decrypt privately: budget both the encrypted download and full plaintext ZIP, plus expanded payload staging for restore. The backup catalog remains plaintext and exposes paths, checksums, timing, and recovery metadata; final object size and the format header are also public.
- Streaming bounds payload buffers but metadata grows with entry/chunk count. The stream is read more than once and stored objects are read back for verification, adding storage I/O. SQLite requires temporary disk space for a consistent database snapshot. Filesystem adapters may also buffer internally. Failed final uploads preserve encrypted source-chunk references and their KDF metadata in backup metadata for recovery. The bounded-stream backup does not spool a full archive locally; decryption and restore still require local staging.
- The three removed services had no internal callers. Applications directly instantiating these undocumented classes must remove that usage. Master-key rotation, external KMS integration, package-managed concurrent uploads, and S3 lifecycle transitions are not supported. The new sodium-encrypted archive container remains supported. Configure provider-native lifecycle rules separately when needed.

## Historical backup compatibility

Compatibility evidence comes from archives generated by **unmodified main commit `543a2ea9534eaf6df6d0e5710e972f518adc2306`**, using that revision's actual `BackupService` and `ChunkedBackupService`, rather than hand-authored lookalike manifests. The historical restore command checks recovered file bytes and SQLite rows before fixtures are accepted. The [fixture README](../tests/Fixtures/LegacyMain/README.md), generator, catalog, and ZIP files retain the provenance and reproduction steps. The generator uses isolated synthetic test data and the checkout's installed development dependencies. Its archive bytes may vary because of AES salts, temporary paths, and host metadata.

| Historical case | Compatibility contract | Important limitation |
| --- | --- | --- |
| Regular and old `--no-local` plain ZIPs | Read via the catalog, a local `--archive`, or explicit `--disk`/`--path`; preserve schema 1 layout. | Invalid or incomplete old archives do not become valid through compatibility handling. |
| Regular and old `--no-local` native AES ZIPs | Read with AES-capable libzip and the effective original password. Native authentication is checked through EOF. | These are ZIPs, not `.capsule.enc`; no standalone decrypt step is needed. |
| Configured password `"0"` | Old plaintext ZIP output remains readable even if the historical manifest's encryption flag was set. | Historical `!empty()` checks omitted encryption. Metadata alone is not proof of confidentiality. |
| Configured password containing NUL | Configure the exact prefix before the first NUL, which historical libzip used as its effective AES password. | Current inputs reject NUL and do not silently shorten secrets; new encrypted streaming also rejects CR/LF and enforces 1–4096 bytes. |
| Full streamed schema 1 database members absent from `entries` | Only the recognized old manifest shape and nonempty timestamped `database/<connection>_YYYY-MM-DD_HH-MM-SS.sql` members are eligible, one per connection. Read to EOF and check native ZIP CRC/AES authentication. | No historical SHA-256 exists for these members. Report `integrity: legacy-zip`, `sha256: null`, and an explicit warning; inspect/verify report `checksums_complete: false`. Never call these members SHA-256 verified. |
| Old literal `default` database alias | Resolve one source connection name from the historical timestamped member and warn to confirm the restore target. | The reader does not invent the source identity from the current application's configuration. Review the plan or use an explicit restore connection. |
| Incremental schema 1 with `base_snapshot_id` | Resolve the original successful full base through its snapshot/catalog, or use explicit `--base-archive` without the catalog. Validate the base and delta before destination writes. | No historical base SHA binding or deletion records exist. Warn that base identity cannot be verified against the delta and removed files may remain. This is not exact point-in-time recovery. |
| Safe nonportable Unix names in schema 1 | Preserve spelling on a compatible filesystem, including literal backslashes, colons (including drive-like components), trailing dots/spaces, reserved Unix-valid names, and case-distinct pairs. | Do not silently rename. Check destination compatibility before data writes; incompatible filesystems and unsafe/conflicting paths are rejected. |

All ordinary schema 1 entries with a recorded SHA-256 remain strictly checked. Schema 2 still requires complete checksum coverage and portable names. The legacy streamed database exception does not permit arbitrary missing checksums, unexpected extra members, or duplicate connection dumps. A manifest matching that historical shape is not proof of its origin and cannot supply missing historical SHA-256 evidence. ZIP CRC detects accidental corruption; it is not a cryptographic integrity guarantee. Native AES authentication applies only to encrypted members.

Legacy incremental recovery overlays recorded changes on the selected full base. It does not infer deletions from the catalog file index or present a newly computed base hash as historical evidence. Missing/malformed bases, a non-full legacy base, cycles, invalid schema, and inconsistent metadata fail closed. After an upgrade, an incremental backup with only old snapshot state available starts a new full base; old artifacts and snapshots are not rewritten.

On Unix, schema 1 preserves literal backslashes and colons as filename bytes inside the archive namespace; it does not reinterpret them as separators or drive syntax. Schema 2 and Windows retain the stricter portability rules. Legacy filename handling does not relax slash-segment traversal, leading-slash, control/NUL-byte, invalid-UTF-8, symlink/special-file, exact-duplicate, or file/directory-conflict protection. Case-distinct names require an actual case-sensitivity probe in the nearest existing target parent. Probe files are private and removed afterward; even `--dry-run` may briefly create these temporary files. Restored names are not normalized or silently renamed. Test on the intended recovery filesystem rather than assuming every Unix or Linux mount preserves the same names.

Larger valid historical manifests can be recovered with an explicit reader budget, for example:

```bash
php artisan capsule:restore --archive=/safe/legacy.zip --target=/tmp/recovered --max-manifest-bytes=33554432 --dry-run
```

This sets a 32 MiB manifest JSON budget for each archive, including the legacy full base. The option overrides `capsule.restore.max_manifest_bytes` for this restore; inspect/verify use that configuration directly. Only increase it for a trusted historical archive on a host provisioned for the larger allocation: decoded JSON arrays and validation structures may consume several times the encoded size. All path, schema, and recorded checksum checks remain in force, and newly produced manifests remain capped at 16 MiB.

These compatibility paths preserve documented, recoverable historical outputs; they do not recover lost passwords, reconstruct missing payloads, repair arbitrary malformed archives, prove a missing historical checksum, or discover unrecorded deletions. Keep original historical artifacts and perform an isolated restore drill before replacing a recovery installation.

## Current-change verification status

- **Final local suite:** PHP 8.4.24 / Laravel 13.35.0 / PHPUnit 11.5.57 passed **648 tests / 10,265 assertions**, with two skips: optional Spatie Health is not installed locally, and compiled-in sodium cannot be unloaded for the missing-extension subprocess test. Disabled-function failure checks still run. PHP syntax, whitespace, Composer validation and installed-platform checks pass; Composer audit reports no advisories or abandoned packages. The existing explicit package-version field produces a recommendation warning.
- **Exact-commit CI:** Consult the [PR checks](https://github.com/dgtlss/capsule/pull/4/checks) and final PR validation report for the published commit across all seven combinations. The local results above are not a claim that CI has passed.
- **Independent security review: incomplete.** The review identified a format-dispatch gap, which was fixed and received focused regression coverage. The review stopped before completion; those checks do not establish that all encryption/recovery paths were reviewed. A manual security review is required before merge or release. No professional cryptographic audit is claimed.
- **Deployment-specific recovery:** Live MySQL/PostgreSQL, real storage adapters, and the intended recovery filesystem still need their own drills.

## Verification

- Before changes: the clean repository suite passed **66 tests / 164 assertions** on PHP 8.4.24, PHPUnit 10.5.66 and Laravel 11.
- The end-to-end regression suite exercises real local storage, binary ZIP entries, two roots sharing a basename, a live WAL-mode SQLite database, and recovery through the public restore command. Scenarios cover standard, streamed, encrypted-standard, and encrypted-streamed backups. A further round trip generates a full backup plus an incremental, hides the backup catalog, and checks unchanged/changed/added/deleted files after recovery. The current local result is recorded above; check the final PR validation report and exact-commit CI results for other supported runtimes.
- `.github/workflows/tests.yml` declares seven supported Laravel/PHP combinations. CI resolves actual releases (`minimum-stability: stable`), rather than accepting a development branch to satisfy compatibility. The Laravel 13 / PHP 8.4 row also installs the optional Spatie Health integration.
- A workflow definition is not a passing CI run. Review checks on the PR's exact commit before release. Local runtime coverage is PHP 8.4; PHP 8.2/8.3/8.5 execution is delegated to CI. [Composer dependency resolution blocks Laravel 10/11 because of framework security advisories](compatibility-resolution.md); the supported matrix excludes those releases and no security checks have been disabled.
- Tests use synthetic MySQL/PostgreSQL client processes for exit status/validation behavior. Live MySQL/PostgreSQL recovery, real cloud storage/adapter behavior, notification delivery and Filament runtime are not established by this suite. Perform deployment-specific restore drills.

## Original audit local validation (historical)

These results predate the `.capsule.enc` format and standalone decryption command and are not a test result for those changes.

The original audit suite passed on PHP 8.4.24 / Laravel 13.35.0 / PHPUnit 11.5.57: **213 tests, 947 assertions, one skipped optional Spatie test**. The optional Spatie integration was exercised separately with the package installed (three tests, three assertions, no skip). PHP syntax and whitespace checks passed. Composer configuration validates; the existing explicit package-version field produces a recommendation warning.

Additional streaming checks verified empty files, Unicode names, a real 4 GiB+1 entry, and ZIP64 compressed-size overflow with Java, Python and InfoZip readers. The generator used 4 MiB peak memory in those isolated probes; this is not a total application/adapter memory guarantee. Permanent tests include a 96 MiB incompressible collation case and corruption/truncation/retry regressions. Final CI results must be checked on the published commit.

## Dependency references

- [Laravel supported PHP versions](https://laravel.com/docs/13.x/releases)
- Official Testbench dependency metadata: [8.x](https://github.com/orchestral/testbench/blob/8.x/composer.json), [9.x](https://github.com/orchestral/testbench/blob/9.x/composer.json), [10.x](https://github.com/orchestral/testbench/blob/10.x/composer.json), [11.x](https://github.com/orchestral/testbench/blob/11.x/composer.json)
- [SQLite online backups](https://www.sqlite.org/backup.html)
- [PostgreSQL psql error behavior](https://www.postgresql.org/docs/current/app-psql.html)
