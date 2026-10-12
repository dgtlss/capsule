```
  ██████╗  █████╗  ██████╗  ███████╗ ██╗   ██╗ ██╗      ███████╗
 ██╔════╝ ██╔══██╗ ██╔══██╗ ██╔════╝ ██║   ██║ ██║      ██╔════╝
 ██║      ███████║ ██████╔╝ ███████╗ ██║   ██║ ██║      █████╗
 ██║      ██╔══██║ ██╔═══╝  ╚════██║ ██║   ██║ ██║      ██╔══╝
 ╚██████╗ ██║  ██║ ██║      ███████║ ╚██████╔╝ ███████╗ ███████╗
  ╚═════╝ ╚═╝  ╚═╝ ╚═╝      ╚══════╝  ╚═════╝  ╚══════╝ ╚══════╝
```
A comprehensive Laravel backup package. Back up your database and files, store them anywhere, get notified, and restore with confidence.

Capsule supports MySQL, PostgreSQL, and SQLite. It stores backups on any Laravel filesystem disk (local, S3, SFTP, FTP, DigitalOcean Spaces, etc.) and notifies you via Email, Slack, Discord, Microsoft Teams, or Google Chat.

> **Recovery requirement for encrypted streaming:** `--no-local --encrypt` creates a `.capsule.enc` file, not a password-protected ZIP. **You need a compatible Capsule package and the backup password to decrypt it.** Keep a tested Capsule installation and its dependency lock with your recovery kit, and store the password separately. The [standalone decrypt command](#decrypt--disaster-recovery) works without your original Laravel application or backup catalog. Standard `--encrypt` still creates an AES-encrypted ZIP using local staging.

## Table of Contents

- [Installation](#installation)
- [Quick Start](#quick-start)
- [Commands](#commands)
- [Backup](#backup)
- [Restore](#restore)
- [Decrypt & Disaster Recovery](#decrypt--disaster-recovery)
- [Compatibility with Older Backups](#compatibility-with-older-backups)
- [List & Inspect](#list--inspect)
- [Verify](#verify)
- [Cleanup](#cleanup)
- [Diagnose](#diagnose)
- [Health](#health)
- [Advisor](#advisor)
- [Download](#download)
- [Backup If Stale](#backup-if-stale)
- [Features](#features)
- [Incremental Backups](#incremental-backups)
- [Backup Simulation](#backup-simulation)
- [Backup Policies](#backup-policies)
- [Multi-Disk Storage](#multi-disk-storage)
- [Chunked Streaming](#chunked-streaming)
- [Encryption](#encryption)
- [Programmatic API](#programmatic-api)
- [Configuration](#configuration)
- [Storage](#storage)
- [Database](#database)
- [Files](#files)
- [Retention](#retention)
- [Scheduling](#scheduling)
- [Notifications](#notifications)
- [Extensibility](#extensibility)
- [Integrations](#integrations)
- [Requirements](#requirements)
- [Testing and Compatibility](#testing-and-compatibility)
- [Recovery Audit](docs/recovery-audit.md)
- [License](#license)

---

## Installation

```bash
composer require dgtlss/capsule
```

Publish the config file:

```bash
php artisan vendor:publish --tag=capsule-config
```

Run the migrations:

```bash
php artisan migrate
```

That's it. Capsule auto-discovers via Laravel's package discovery.

---

## Quick Start

Run your first backup:

```bash
php artisan capsule:backup
```

You'll see a summary report when it finishes:

```
┌───────────────────────────────────────────────────────┐
│  ✅  Backup #1 completed successfully                  │
├───────────────────────────────────────────────────────┤
│  Duration        2.4s                                   │
│  Archive         backup_2026-02-26_02-00-00.zip         │
│  Size            14.2 MB (from 89.5 MB)                 │
│  Compression     0.16x                                  │
│  Files           2,847 files in 312 dirs                │
│  Database        1 dump(s) - 45.2 MB                    │
│  Storage         s3                                     │
│  Throughput      37.3 MB/s                              │
│  Tag             nightly                                │
│  Next run        Tomorrow at 02:00                      │
└───────────────────────────────────────────────────────┘
```

Check your backup status:

```bash
php artisan capsule:health
```

If something goes wrong, start here:

```bash
php artisan capsule:diagnose
```

---

## Commands

### Backup

```bash
# Standard backup
php artisan capsule:backup

# Database only
php artisan capsule:backup --db-only

# Files only
php artisan capsule:backup --files-only

# Incremental (only changed files since last full backup)
php artisan capsule:backup --incremental

# Tag a backup for easy identification
php artisan capsule:backup --tag=pre-deploy

# Simulate without running (estimates size and duration)
php artisan capsule:backup --simulate

# Use a named policy
php artisan capsule:backup --policy=database-hourly

# Stream ZIP output to storage (bounded buffers)
php artisan capsule:backup --no-local

# Encrypt a standard locally staged AES ZIP
php artisan capsule:backup --encrypt

# Stream an encrypted .capsule.enc file (Capsule required to decrypt)
php artisan capsule:backup --no-local --encrypt

# Verify integrity after creation
php artisan capsule:backup --verify

# Max compression
php artisan capsule:backup --compress=9

# Force run even if another backup is in progress
php artisan capsule:backup --force

# Verbose output
php artisan capsule:backup --detailed

# JSON output (for CI/automation)
php artisan capsule:backup --format=json
```

### Restore

```bash
# Restore the latest backup
php artisan capsule:restore

# Restore a specific backup
php artisan capsule:restore 42

# Recover without the application database or backup catalog
php artisan capsule:restore --archive=/safe/backup.zip --target=/tmp/recovered
php artisan capsule:restore --disk=s3 --path=backups/backup.zip --target=/tmp/recovered

# Restore an encrypted stream with compatible Capsule and the configured password
php artisan capsule:restore --archive=/safe/backup.capsule.enc --target=/tmp/recovered

# Browse what's inside before restoring
php artisan capsule:restore --list

# Restore specific files only (schema 2 preserves named or numbered roots)
php artisan capsule:restore --only=root-0/config/database.php --only=root-0/config/app.php

# Restore using glob patterns
php artisan capsule:restore --only='*.php'

# Database only
php artisan capsule:restore --db-only

# Files only
php artisan capsule:restore --files-only

# Restore files to a different directory
php artisan capsule:restore --files-only --target=/tmp/restored

# Restore to a different database connection
php artisan capsule:restore --db-only --connection=mysql_secondary

# Preview the restore plan (legacy filesystem checks may use temporary probes)
php artisan capsule:restore --dry-run

# Skip confirmation
php artisan capsule:restore --force
```

For `.capsule.enc`, restoration requires compatible Capsule and the password. It first authenticates and decrypts into a private temporary ZIP, then validates the full archive manifest, paths, and payload checksums before changing files or importing databases. This requires local space for the decrypted ZIP and restore staging; `--no-local` is a backup option, not a disk-free recovery guarantee. Temporary plaintext is removed on handled failures. Standard and legacy AES ZIPs remain readable through the existing restore path. Invalid paths, symlink destinations, unreadable encrypted entries, and failed database imports return a nonzero exit code. JSON mode requires `--force` for a restore that changes data.

Restoring over an existing file preserves its ordinary read/write/execute permissions, but strips set-id and sticky bits. New files use `0666 & ~umask` (normally `0644`); archive attributes do not grant execute or special permissions. Plaintext staging stays private until atomic publication. Ownership and ACLs are not restored from the archive. Recorded incremental case-only renames are supported on a probed case-sensitive target filesystem; simultaneous conflicting names still fail validation before destination changes.

Schema 2 archives keep each configured file root separate: `files/root-0/...`, `files/root-1/...`, or `files/<configured-name>/...`. `--target` receives these root directories; without it, they are restored beneath the application base path. Review `--list` and `--dry-run` before moving restored files into production. New backups retain a 16 MiB manifest cap. Readers default to the same budget; an explicit [larger historical manifest budget](#larger-historical-manifests) can be configured for trusted older archives without relaxing validation. Supported legacy schema 1 archives retain their original unnamespaced layout. Their recorded SHA-256 values are still checked, with narrowly scoped, warned compatibility handling for old streamed database members that never had historical checksums. Archives without a valid manifest are rejected. See [older-backup compatibility](#compatibility-with-older-backups) for integrity and incremental limitations.

### Compatibility with Older Backups

Upgrading does not require converting your existing ZIP backups to `.capsule.enc`. The recovery suite includes real archives produced and restored by unmodified historical main commit `543a2ea9534eaf6df6d0e5710e972f518adc2306`, with reproducible [fixture provenance and generation instructions](tests/Fixtures/LegacyMain/README.md). Keep the original backup files and credentials until you have tested recovery with the new version.

| Historical backup | Recovery behavior and limits |
| --- | --- |
| Regular or old `--no-local` plain ZIP | Supported through `capsule:restore` with the catalog, `--archive`, or `--disk` plus `--path`; original schema 1 paths are preserved. |
| Regular or old `--no-local` AES ZIP | Supported through the same restore routes with an AES-capable libzip and the effective original password. It remains a ZIP and does not need `capsule:decrypt`. |
| Old configured password `"0"` | The historical writer treated this value as empty and created plaintext ZIPs, even when metadata indicated encryption. Existing plaintext archives remain readable; do not assume they were encrypted. |
| Old password containing NUL | Native libzip used only the prefix before the first NUL. Explicitly configure that effective prefix to read the old AES ZIP. Current password inputs reject NUL; Capsule does not silently truncate them. |
| Old streamed database without a manifest SHA-256 | Only the recognized schema 1 full-stream shape is accepted. The entire member is checked through EOF using ZIP CRC and native AES authentication where applicable, with a warning that no historical SHA-256 exists. This is not SHA-256 verification of that database dump. |
| Old incremental with `base_snapshot_id` | Use the original full base from its catalog or supply `--base-archive`. The old format did not bind the base with a historical SHA-256 or record deletions. Recovery warns and overlays changes: files deleted after the full backup may remain, so the result is not an exact point-in-time snapshot. |
| Safe legacy Unix filenames | Supported schema 1 spelling, including literal backslashes, colons, trailing dots/spaces, and case-distinct names, is retained on a compatible filesystem. On Unix these are literal filename bytes, not alternate path separators or drive syntax. Capsule does not silently rename files for portability. Incompatible destinations fail before restoring data. |

The old streamed database exception does not permit arbitrary unlisted members or relax schema 2's complete SHA-256 coverage. Inspect/verify results mark `checksums_complete: false` and include warnings for these archives; a successful legacy read does not mean every payload had a historical SHA-256. Timestamp-suffixed database members are mapped back to their recorded source connection; historical `default` aliases produce an additional warning to confirm the destination connection. Review the restore plan or specify the intended `--connection`.

Filesystem compatibility is checked before destination data writes. When legacy case-distinct names require it, Capsule creates and removes private probe files in the nearest existing destination directory, including during `--dry-run`. Traversal, symlinks, ambiguous or conflicting destinations, malformed manifests, wrong passwords, and corrupted payloads remain errors. Compatibility does not make incomplete, damaged, or previously unusable archives recoverable, or supply integrity/deletion evidence the old writer never stored.

When only historical snapshot state is available, the next incremental backup after upgrade starts a new full base instead of silently treating the old snapshot as a portable new-format base. Keep the old full and incremental pair together for historical recovery. See the [detailed compatibility audit](docs/recovery-audit.md#historical-backup-compatibility).

#### Larger Historical Manifests

If a trusted older backup has a manifest larger than the default 16 MiB reader budget, provision enough memory and raise the budget explicitly:

```bash
# Preview recovery with a 32 MiB manifest budget for each archive, including its base
php artisan capsule:restore --archive=/safe/legacy.zip --target=/tmp/recovered --max-manifest-bytes=33554432 --dry-run
```

`--max-manifest-bytes=N` overrides `capsule.restore.max_manifest_bytes` for that restore. Inspect and verify use the configuration value. The budget must be a positive decimal integer no greater than `PHP_INT_MAX`; there is no unlimited setting. It applies separately to every archive in an incremental chain, including a historical full base.

Raise it only for a trusted historical archive on a recovery host with enough memory: decoded JSON arrays and validation metadata can require several times the encoded manifest size, in addition to other buffers and cryptographic memory. Paths, schemas, and recorded checksums are still validated. The new-backup producer cap remains 16 MiB.

### Decrypt & Disaster Recovery

> **Capsule is required to decrypt `.capsule.enc`.** An ordinary ZIP utility cannot open the encrypted file, even with the password. After Capsule decrypts and verifies it, the output is an ordinary, unencrypted ZIP.

The Composer package includes a standalone command. It loads its installed dependencies but does **not** boot Laravel or need the original application, application database, backup catalog, or storage configuration. On a recovery machine, use the compatible Capsule package and dependencies you retained and tested:

```bash
# Hidden password prompt; output must be a new local file
vendor/bin/capsule-decrypt /safe/backup.capsule.enc --output=/private/recovered.zip

# Automation: read one password line from a protected file, never a literal argument
vendor/bin/capsule-decrypt /safe/backup.capsule.enc --output=/private/recovered.zip --password-stdin < /secure/backup-password

# Equivalent command inside an available Laravel application
php artisan capsule:decrypt /safe/backup.capsule.enc --output=/private/recovered.zip
```

Both decrypt commands support `--format=json`. They take a single-line password of 1–4096 bytes only through a hidden terminal prompt or explicit `--password-stdin`; they do not use a password argument, environment variable, or application configuration. NUL, CR, and LF bytes are not allowed within the password; stdin removes the final line ending. Configure the password separately for ordinary Laravel backup/restore commands as described under [Encryption](#encryption).

The output directory must exist and have enough space for a complete plaintext ZIP. The command authenticates the full stream, verifies the manifest, paths, and checksums, and only then publishes a new owner-only output file. It refuses to overwrite an existing destination or follow symlink paths. On handled errors, partial plaintext staging is removed; a power loss or forced process kill can leave private temporary files that must be cleaned up. Protect the recovered ZIP and remove it when finished.

For every disaster-recovery kit:

1. Retain the backup, the compatible Capsule package/source version, this format documentation, and a working installation with its Composer dependency lock. A lockfile alone does not preserve dependency downloads for an offline recovery.
2. Store the backup password separately and ensure it is available to the people authorized to recover. Capsule cannot recover a lost password.
3. Retain any matching full base required by an incremental backup, including the original encrypted artifact. Its manifest checksum binds the original stored bytes; a decrypted copy is not a checksum-equivalent `--base-archive` replacement. Decrypting an incremental does not make it a complete backup by itself.
4. Perform an isolated recovery drill without the original application or catalog: decrypt, inspect the recovered ZIP, then test file/database recovery in a safe environment. Repeat before upgrading Capsule or changing backup format or passwords.

See [streaming encryption and its versioned format](docs/streaming-encryption.md) for resource requirements and failed-upload recovery.

### List & Inspect

```bash
# List recent backups
php artisan capsule:list

# Limit results
php artisan capsule:list --limit=10

# JSON output
php artisan capsule:list --format=json

# Inspect a specific backup (shows manifest with checksums)
php artisan capsule:inspect 42
```

### Verify

```bash
# Verify the latest backup (downloads, checks ZIP + checksums)
php artisan capsule:verify

# Verify a specific backup
php artisan capsule:verify --id=42

# Verify all successful backups
php artisan capsule:verify --all

# Keep the downloaded file after verification
php artisan capsule:verify --keep
```

`capsule:verify` and `capsule:inspect` automatically decrypt `.capsule.enc` through the same private staging path as restore. Allow local space for both the downloaded encrypted archive and its complete decrypted ZIP, even for verification or inspection. Restore additionally needs expanded payload staging. Handled errors clean up temporary plaintext; power loss or SIGKILL may leave private staging files.

Capsule also runs automated verification on a schedule. See [Integrity Monitoring](#integrity-monitoring).

### Cleanup

```bash
# Clean up based on retention policy
php artisan capsule:cleanup

# Preview what would be deleted
php artisan capsule:cleanup --dry-run

# Override retention days
php artisan capsule:cleanup --days=7

# Also clean up failed backup records
php artisan capsule:cleanup --failed

# Clean up orphaned storage files
php artisan capsule:cleanup --storage
```

### Diagnose

```bash
# Check config, storage, database, file paths, system requirements
php artisan capsule:diagnose

# Include performance, security, and backup history analysis
php artisan capsule:diagnose --detailed

# Attempt to fix common issues (e.g., publish missing config)
php artisan capsule:diagnose --fix

# One JSON document, including detailed checks, fixes and failure results
php artisan capsule:diagnose --format=json --detailed --fix
```

JSON includes boolean `checks`, `overall_status`, and sanitized `errors` for checks that could not complete. The exit code is nonzero if any check fails; repair commands do not print progress into the JSON output.

### Health

```bash
# JSON health snapshot
php artisan capsule:health
```

Returns:

```json
{
    "last_success_age_days": 0,
    "recent_failures_7d": 0,
    "storage_usage_bytes": 14892032
}
```

### Advisor

```bash
# Analyze trends and get scheduling recommendations
php artisan capsule:advisor
```

The advisor examines your backup history and reports on size growth, duration trends, compression efficiency, failure rates, and gives actionable recommendations.

### Download

```bash
# Download the latest backup to local disk
php artisan capsule:download

# Download a specific backup
php artisan capsule:download 42

# Download to a custom path
php artisan capsule:download --path=/tmp/backups
```

### Backup If Stale

Only run a backup if the last successful one is older than a threshold. Useful for redundant scheduling or deploy hooks where you don't want to double-backup.

```bash
# Backup only if last success is older than 24 hours (default)
php artisan capsule:backup-if-stale

# Custom threshold
php artisan capsule:backup-if-stale --hours=6

# With a specific policy
php artisan capsule:backup-if-stale --hours=12 --policy=database-hourly
```

---

## Features

### Incremental Backups

Instead of backing up all files every time, incremental mode only includes files that changed since the last full backup. Capsule compares file sizes, modification times, and SHA-256 hashes to detect changes, including edits that preserve size and timestamps.

```bash
php artisan capsule:backup --incremental
```

If no compatible previous full backup exists, Capsule automatically runs a full backup instead. Each new incremental manifest records its base archive disk, object path, SHA-256 checksum, and deleted file paths. Historical schema 1 incrementals have weaker metadata; their [legacy recovery warnings and base requirements](#compatibility-with-older-backups) still apply. Restore verifies the base, applies its files, overlays changed files, then applies deletions. Retention protects bases required by retained incremental backups.

For disaster recovery with downloaded archives, explicitly supply the matching base:

```bash
php artisan capsule:restore --archive=/safe/incremental.zip --base-archive=/safe/full.zip --target=/tmp/recovered
```

Keep both original archives, including their encrypted `.capsule.enc` files when applicable. An incremental binds its base by the original object’s SHA-256; a standalone-decrypted copy has different bytes and is not a matching base replacement. A missing or mismatched base fails before restoration starts.

A typical workflow:

```bash
# Weekly full backup
php artisan capsule:backup --tag=weekly-full

# Daily incremental
php artisan capsule:backup --incremental --tag=daily-incremental
```

### Backup Simulation

Estimate backup size and duration before committing:

```bash
php artisan capsule:backup --simulate
```

This scans all configured paths and databases, calculates totals, estimates compression from historical data, and reports:

- Raw data size and estimated archive size
- Estimated duration based on past throughput
- Top file extensions by size
- Largest files
- Historical comparison against recent backups
- Warnings for low disk space or large datasets

### Backup Policies

Define named backup strategies for different needs:

```php
// config/capsule.php
'policies' => [
    'database-hourly' => [
        'database' => true,
        'files' => false,
        'disk' => 's3',
        'frequency' => 'hourly',
        'retention' => ['days' => 7, 'count' => 168],
    ],
    'full-weekly' => [
        'database' => true,
        'files' => true,
        'disk' => 'glacier',
        'frequency' => 'weekly',
        'time' => '03:00',
        'retention' => ['days' => 365, 'count' => 52],
    ],
    'incremental-daily' => [
        'database' => true,
        'files' => true,
        'incremental' => true,
        'frequency' => 'daily',
        'time' => '02:00',
    ],
],
```

Each policy runs on its own schedule automatically. Run a specific policy manually:

```bash
php artisan capsule:backup --policy=database-hourly
```

When no policies are defined, Capsule uses the global config as a single default policy.

### Multi-Disk Storage

Back up to multiple destinations for redundancy:

```php
'default_disk' => 's3',
'additional_disks' => ['local-archive', 's3-secondary'],
```

The primary backup goes to `default_disk`. Every configured additional copy must also be written and verified successfully; a replication failure fails the run and preserves its recovery source. Recorded destinations are retained with the backup so cleanup can remove expired replicas even after configuration changes.

### Chunked Streaming

For large backups or limited local storage, stream directly to cloud storage:

```bash
php artisan capsule:backup --no-local
```

ZIP output and payloads use bounded streams; Capsule does not assemble a complete local ZIP or load the whole archive into memory. SQLite requires a consistent temporary on-disk database snapshot, which can be as large as the database. Budget temporary space for that snapshot and any filesystem adapter buffering. Chunk buffers accept 1 byte through 64 MiB (10 MiB by default); metadata grows with file/chunk count. Upload verification reads back stored objects to check their sizes and SHA-256 hashes, so allow for additional storage read traffic. If final publication fails, recovery chunk references remain in the failed backup log.

> **Adding `--encrypt` changes the output to `.capsule.enc`, which requires compatible Capsule and the password to decrypt. It is not a standard encrypted ZIP.** The compressed ZIP is encrypted inside a native sodium secretstream container; filenames and the manifest are hidden. Temporary remote capture chunks are also authenticated and encrypted before upload. There is no plaintext fallback and no full-archive local spool for this backup path. Recovery still requires local plaintext staging. See [standalone disaster recovery](#decrypt--disaster-recovery) and [streaming encryption details](docs/streaming-encryption.md).

```php
'chunked_backup' => [
    'chunk_size' => 10485760,        // 10 MB chunks
    'temp_prefix' => 'capsule_chunk_',
],
```

### Encryption

Choose the format with your recovery plan in mind:

- `php artisan capsule:backup --encrypt` creates a standard password-protected AES-256 ZIP using native `ZipArchive`/libzip and local archive staging. Manual extraction needs an AES-capable ZIP reader; member names and sizes remain visible.
- `php artisan capsule:backup --no-local --encrypt` creates a compressed ZIP inside a version 1 `.capsule.enc` container using native sodium XChaCha20-Poly1305 secretstream and Argon2id13. **It requires a compatible Capsule package and the password to decrypt. Standard ZIP tools cannot open it directly.** Filenames, the manifest, and all ZIP contents are encrypted. Temporary remote capture chunks are encrypted too.

For Laravel backup/restore commands, set the password through secret configuration:

```env
CAPSULE_BACKUP_PASSWORD=your-generated-high-entropy-password
```

Use a high-entropy generated password and keep it separately from the backups. The `.capsule.enc` password KDF uses a random salt, two Argon2id13 passes, and 64 MiB of native working memory per derivation. Chunk and final-archive keys are derived separately in sequence; PHP memory metrics may omit this native allocation. This does not change the legacy password derivation used by standard AES ZIPs. Missing passwords, unsupported encryption, or failed encryption calls abort the backup without downgrading to plaintext. Streaming passwords must contain 1–4096 bytes and no NUL, CR, or LF bytes. Capsule does not trim, truncate, or normalize them. Standard/legacy ZIP passwords keep their existing behavior, with NUL bytes rejected.

The standalone and Artisan decrypt commands use a hidden prompt or explicit `--password-stdin` instead of the application's password configuration. They produce a verified **plaintext ZIP** and require local disk space. Keep the compatible Capsule package, working installation, and dependency lock as part of your [disaster-recovery kit](#decrypt--disaster-recovery), and test recovery before relying on it.

Encryption does not hide final object size or the public format header. The application backup catalog remains plaintext and can contain paths, checksums, timing, and recovery metadata; protect the catalog, logs, and storage access. There is no master-key rotation or external key-management service. See the [format and operational details](docs/streaming-encryption.md).

### Programmatic API

Use Capsule from your application code without going through artisan:

```php
use Dgtlss\Capsule\Facades\Capsule;

// Run a backup
$success = Capsule::backup();

// With options
$success = Capsule::backup([
    'tag' => 'pre-deploy',
    'db_only' => true,
    'incremental' => true,
]);

// Simulate
$estimate = Capsule::simulate();

// List backups
$backups = Capsule::list(10);

// Get the latest successful backup
$latest = Capsule::latest();

// Health check
if (!Capsule::isHealthy()) {
    // alert
}
```

### Integrity Monitoring

Capsule continuously verifies your backups are intact:

```php
'verification' => [
    'schedule_enabled' => true,
    'frequency' => 'daily',
    'time' => '04:00',
    'recheck_days' => 7,    // Re-verify after 7 days
],
```

Each scheduled run picks an unverified backup, downloads it, validates the ZIP structure and SHA-256 checksums for every entry, and logs the result. Failed verifications trigger notifications.

```bash
# Run manually
php artisan capsule:verify-scheduled
```

### Anomaly Detection

After each backup, Capsule compares the result against the rolling average:

- **Size anomalies**: flags backups that are >200% larger or smaller than average
- **Duration anomalies**: flags backups taking >300% longer than average
- **File count anomalies**: flags unexpected changes in file count
- **Compression anomalies**: flags drops in compression efficiency

Anomalies appear in the post-backup summary and are included in notifications.

```php
'anomaly' => [
    'size_deviation_percent' => 200,
    'duration_deviation_percent' => 300,
],
```

### Database Dump Validation

Before adding a dump to the archive, Capsule validates it:

- **MySQL/MariaDB**: checks for expected header comments and the "Dump completed" end marker
- **PostgreSQL**: validates the dump header format
- **SQLite**: verifies the magic header bytes and minimum file size

A corrupt or empty dump aborts the backup with a clear error.

### Audit Log

Every backup operation is recorded in an immutable audit trail:

```php
'audit' => [
    'enabled' => true,
],
```

Tracks: action (backup/restore/cleanup), trigger (artisan/scheduler/api), actor (system user or authenticated user), status, and full details. Stored in the `backup_audit_logs` table.


---

## Configuration

After publishing the config (`php artisan vendor:publish --tag=capsule-config`), all settings live in `config/capsule.php`.

### Storage

Capsule uses your existing Laravel filesystem disks. No duplicate storage configuration needed.

```php
'default_disk' => env('CAPSULE_DEFAULT_DISK', 'local'),
'backup_path' => env('CAPSULE_BACKUP_PATH', 'backups'),
```

Point `CAPSULE_DEFAULT_DISK` at any disk in your `config/filesystems.php`:

```env
CAPSULE_DEFAULT_DISK=s3
```

Upload reliability:

```php
'storage' => [
    'retries' => 3,
    'backoff_ms' => 500,
    'max_backoff_ms' => 5000,
],
```

### Database

```php
'database' => [
    'enabled' => true,
    'connections' => null,          // null = auto-detect default connection
    'exclude_tables' => [],
    'include_tables' => [],
    'include_triggers' => true,
    'include_routines' => false,
    'mysqldump_flags' => '',
    'compress' => true,
],
```

Set `connections` to an array to back up multiple databases:

```php
'connections' => ['mysql', 'pgsql'],
```

### Files

```php
'files' => [
    'enabled' => true,
    'paths' => [base_path()],
    'exclude_paths' => [
        base_path('.env'),
        base_path('node_modules'),
        base_path('vendor'),
        base_path('.git'),
        storage_path('logs'),
        storage_path('framework/cache'),
    ],
    'compress' => true,
],
```

### Retention

```php
'retention' => [
    'days' => 30,               // Delete backups older than this
    'count' => 10,              // Protect latest N from age-based pruning
    'max_storage_mb' => null,   // Optional storage budget
    'min_keep' => 3,            // Never drop below this count
    'cleanup_enabled' => true,
],
```

Age pruning protects the newest `count` backups; it is not a maximum archive count. An explicit `capsule:cleanup --days=...` bypasses that count protection. Budget pruning may exceed it, but both `min_keep` and bases required by retained incrementals remain protected. Failed storage deletions keep their catalog records for retry. Policies and original storage destinations are respected throughout cleanup.

Backup and cleanup share a cache lock. Set `capsule.lock.timeout_seconds` longer than the longest expected backup or cleanup operation: the lease is not renewed automatically. `--force` intentionally bypasses this coordination and can overlap an active operation; avoid it in routine scheduled runs.

### Scheduling

```php
'schedule' => [
    'enabled' => true,
    'frequency' => 'daily',     // hourly, daily, twiceDaily, weekly, monthly, or cron
    'time' => '02:00',
],
```

Custom cron expression:

```php
'frequency' => '0 3 * * 1-5',  // Weekdays at 3 AM
```

Make sure your server's cron is configured to run Laravel's scheduler:

```
* * * * * cd /path-to-your-project && php artisan schedule:run >> /dev/null 2>&1
```

### Notifications

```php
'notifications' => [
    'enabled' => true,
    'webhook_retries' => 3,
    'webhook_backoff_ms' => 1000,

    'email' => [
        'enabled' => false,
        'to' => env('CAPSULE_EMAIL_TO'),
        'from' => env('CAPSULE_EMAIL_FROM'),
        'notify_on' => null,            // null = all events, or ['failure']
    ],

    'webhooks' => [
        'slack' => [
            'enabled' => false,
            'webhook_url' => env('CAPSULE_SLACK_WEBHOOK_URL'),
            'channel' => '#general',
            'username' => 'Capsule',
            'icon_emoji' => ':package:',
            'notify_on' => null,
        ],
        'discord' => [
            'enabled' => false,
            'webhook_url' => env('CAPSULE_DISCORD_WEBHOOK_URL'),
            'username' => 'Capsule',
            'notify_on' => null,
        ],
        'teams' => [
            'enabled' => false,
            'webhook_url' => env('CAPSULE_TEAMS_WEBHOOK_URL'),
            'notify_on' => null,
        ],
        'google_chat' => [
            'enabled' => false,
            'webhook_url' => env('CAPSULE_GOOGLE_CHAT_WEBHOOK_URL'),
            'notify_on' => null,
        ],
    ],
],
```

The `notify_on` option controls which events trigger each channel. Set to `['failure']` to only get alerted on failures, or `null` to receive everything.

All notifications include: app name, environment, hostname, backup size, duration, storage disk, and error details (for failures).

Webhook channels use Block Kit (Slack), Embeds (Discord), Adaptive Cards (Teams), and Card v2 (Google Chat).

### Extensibility

Register custom file filters and pipeline steps:

```php
'extensibility' => [
    'file_filters' => [
        // \App\Backup\Filters\ExcludeLargeFiles::class,
    ],
    'pre_steps' => [
        // \App\Backup\Steps\EnterMaintenanceMode::class,
    ],
    'post_steps' => [
        // \App\Backup\Steps\ExitMaintenanceMode::class,
    ],
],
```

**File filters** implement `Dgtlss\Capsule\Contracts\FileFilterInterface`:

```php
public function shouldInclude(string $absolutePath, BackupContext $context): bool;
```

Filters and lifecycle events receive the same operation context used by pipeline steps, including option flags and pre-step metadata. Its mode is `normal` for regular/full and regular/incremental backups, or `chunked` for `--no-local`. Direct `IncrementalService::buildCurrentIndex()` calls without a supplied context use `incremental`.

Built-in filters: `MaxFileSizeFilter`, `ExtensionFilter`, `PatternFilter`.

**Steps** implement `Dgtlss\Capsule\Contracts\StepInterface`:

```php
public function handle(BackupContext $context): void;
```

**Events** dispatched during backup:

`BackupStarting`, `DatabaseDumpStarting`, `DatabaseDumpCompleted`, `FilesCollectStarting`, `FilesCollectCompleted`, `ArchiveFinalizing`, `BackupUploaded`, `BackupSucceeded`, `BackupFailed`

---

## Integrations

### Spatie Laravel Health

```php
use Spatie\Health\Facades\Health;
use Dgtlss\Capsule\Health\CapsuleBackupCheck;

Health::checks([
    CapsuleBackupCheck::new(),
]);
```

Configure thresholds:

```php
'health' => [
    'max_last_success_age_days' => 2,
    'max_recent_failures' => 0,
    'warn_storage_percent' => 90,
],
```

### Filament

Capsule ships a browse-only Filament page with filtering, pagination, status badges, and health stats. Add to your panel:

```php
use Dgtlss\Capsule\Filament\Pages\BackupsPage;
```

### JSON / CI

All commands support `--format=json` for machine-readable output:

```bash
php artisan capsule:backup --format=json
php artisan capsule:list --format=json
php artisan capsule:verify --all --format=json
php artisan capsule:cleanup --dry-run --format=json
php artisan capsule:health
php artisan capsule:advisor --format=json
```

---

## Requirements

- PHP 8.2+ for Laravel 12; PHP 8.3+ for Laravel 13
- Laravel 12 or 13
- `zip`, `sodium`, and `zlib` PHP extensions; sodium must support Argon2id13 and secretstream
- AES-capable libzip for standard encrypted ZIP creation and legacy AES ZIP recovery; no Python runtime or PHP ZIP-encryption package is needed for `.capsule.enc`
- Database tools: `mysqldump` (MySQL/MariaDB), `pg_dump` (PostgreSQL)
- `mysql` / `mariadb` or `psql` client for the corresponding database restore
- `pdo_sqlite` for SQLite snapshots/validation; `sqlite3` for SQLite restoration (including live WAL databases)

---

## Testing and Compatibility

```bash
composer install
composer test
```

Tests use isolated temporary storage, actual ZIP archives, and SQLite databases. The recovery suite covers standard, streamed, and encrypted backup/restore round trips, plus failure-path regressions. No cloud credentials are required.

The GitHub Actions workflow resolves and runs the complete suite for these combinations:

| Laravel | PHP versions | Testbench | PHPUnit |
| --- | --- | --- | --- |
| 12 | 8.2, 8.3, 8.4, 8.5 | 10 | 11.5 |
| 13 | 8.3, 8.4, 8.5 | 11 | 11.5 |

The matrix follows [Laravel's supported PHP versions](https://laravel.com/docs/13.x/releases) and the official [Testbench 10](https://github.com/orchestral/testbench/blob/10.x/composer.json) and [11](https://github.com/orchestral/testbench/blob/11.x/composer.json) dependency constraints. Breaking compatibility change: Laravel 10/11 and PHP 8.1 support have been removed. Those framework versions are upstream end-of-life and Composer blocks the released dependency sets because of security advisories; see [the compatibility evidence](docs/compatibility-resolution.md). Upgrade the host application to a supported Laravel/PHP combination before adopting these changes. A configured CI row is not evidence of a passing run: check the workflow results on your commit.

Live MySQL/PostgreSQL recovery and real cloud adapters still require deployment-specific integration tests. Always perform a restore drill on the infrastructure you rely on.

The independent security review of these encryption/recovery changes is **incomplete**. A format-dispatch issue identified during review was fixed with focused regression coverage, but that does not establish completion of the review. Complete a manual security review before merge or release; neither these tests nor the partial review are a professional cryptographic audit.

---

## License

MIT. See [LICENSE.md](LICENSE.md).
