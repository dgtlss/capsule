# Historical Capsule compatibility fixtures

These ZIPs were created by **unmodified Capsule main commit
`543a2ea9534eaf6df6d0e5710e972f518adc2306`**. They are actual output of its
`BackupService` and `ChunkedBackupService`, not synthetic ZIP manifests.
Only test inputs are used: small text/binary files and an isolated SQLite database.
The generator also restores every full backup using that historical commit and
checks recovered bytes and a SQLite query before accepting a fixture.

## Reproduction

From the repository root, with the normal development dependencies and PHP ZIP,
PDO SQLite, and SQLite3 extensions available:

```sh
bash tests/Fixtures/LegacyMain/regenerate.sh
php vendor/bin/phpunit tests/Feature/LegacyMainBackupCompatibilityTest.php
```

The script exports the pinned git commit into a temporary directory and overrides
the Composer PSR-4 mapping before any Capsule class is loaded. It does not change
production code or the current checkout. It uses the checkout's installed Laravel
and Testbench development dependencies. The input application, storage disk, and
database are isolated temporary paths. Those paths appear in historical manifests
and snapshot metadata because that is how the old format was written.

The fixture catalog records the source revision, SHA-256 of each archive, and
unmodified old catalog rows/snapshots. Regeneration replaces the ZIPs and catalog.
AES salts, temporary paths, and host metadata make archive bytes nondeterministic;
the backup/restore scenarios and expected recovered data are reproducible.

## Cases

- Regular and `--no-local`/streamed old output, each with files or files + SQLite
- Plain ZIP and native AES-256 ZIP using `legacy-fixture-password`
- Configured password `"0"`: old `!empty()` checks silently omitted encryption;
  these fixtures are intentionally plaintext despite the historical manifest flag
- Configured password `"legacy-prefix\0suffix"`: historical native libzip used
  `legacy-prefix` as the effective AES password. Recovery with that prefix is
  explicitly tested. This is a migration path, not approval of NUL in new passwords
- Literal `default` database connection aliases resolved to real connection names
- Valid POSIX colon/backslash/trailing-dot names and case-distinct files and directories
- Old full + incremental pair and genuine `backup_snapshots` state
- Catalog, explicit local archive, and explicit storage-object restore routes
- Nested files, empty files, byte-exact binary content, separate source directories,
  and regular-mode standalone file roots; old `files/` paths stay unchanged

All historical manifests are schema 1. Old streamed database payloads were not
included in the checksum entries and had timestamp-suffixed connection names.
Their ZIP payloads were nevertheless recoverable by the old restore command.
Compatibility handling must disclose the weaker legacy integrity evidence rather
than treating an absent historical checksum as a SHA-256 verification.

Old incremental manifests have `base_snapshot_id`, without a portable base hash
or deletion records. A legacy recovery can combine a catalog-resolved or explicit
base with its delta, but cannot retroactively prove base identity or infer deletions.
These limitations predate the upgrade. Starting a new incremental backup after an
upgrade must safely fall back to a new full backup when only old snapshot state
exists, without changing the old archive or snapshot.
