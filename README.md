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

## Table of Contents

- [Installation](#installation)
- [Quick Start](#quick-start)
- [Commands](#commands)
- [Backup](#backup)
- [Restore](#restore)
- [List & Inspect](#list--inspect)
- [Verify](#verify)
- [Cleanup](#cleanup)
- [Diagnose](#diagnose)
- [Health](#health)
- [Advisor](#advisor)
- [Download](#download)
- [Features](#features)
- [Incremental Backups](#incremental-backups)
- [Backup Simulation](#backup-simulation)
- [Backup Policies](#backup-policies)
- [Multi-Disk Storage](#multi-disk-storage)
- [Chunked Streaming](#chunked-streaming)
- [Encryption](#encryption)
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

# Stream directly to cloud (no local disk usage)
php artisan capsule:backup --no-local

# Encrypt the backup
php artisan capsule:backup --encrypt

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

# Browse what's inside before restoring
php artisan capsule:restore --list

# Restore specific files only
php artisan capsule:restore --only=config/database.php --only=config/app.php

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

# Preview without making changes
php artisan capsule:restore --dry-run

# Skip confirmation
php artisan capsule:restore --force
```

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
```

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

---

## Features

### Incremental Backups

Instead of backing up all files every time, incremental mode only includes files that changed since the last full backup. Capsule tracks file sizes and modification times to detect changes.

```bash
php artisan capsule:backup --incremental
```

If no previous full backup exists, Capsule automatically runs a full backup instead.

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

The primary backup goes to `default_disk`. Copies are replicated to each additional disk. Replication failures are logged but don't fail the backup.

### Chunked Streaming

For large backups or limited local storage, stream directly to cloud storage:

```bash
php artisan capsule:backup --no-local
```

Data is streamed in configurable chunks, uploaded directly to storage, then collated into a final ZIP archive. No local disk space required beyond small temporary buffers.

```php
'chunked_backup' => [
    'chunk_size' => 10485760,        // 10 MB chunks
    'temp_prefix' => 'capsule_chunk_',
    'max_concurrent_uploads' => 3,
],
```

### Encryption

Capsule supports two encryption approaches:

**ZIP-level encryption** (simple, compatible with standard ZIP tools):

```bash
php artisan capsule:backup --encrypt
```

Set the password via environment variable:

```env
CAPSULE_BACKUP_PASSWORD=your-secret-key
```

**Envelope encryption** (advanced, supports key rotation):

Each backup is encrypted with a unique random data key (DEK), which is then wrapped with your master key. The key ID is stored in the manifest, enabling you to rotate the master key while old backups remain decryptable with their original key.

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

### S3 Lifecycle Management

For S3-compatible storage, Capsule can tag objects and transition them to cheaper storage classes:

```php
's3_lifecycle' => [
    'tagging_enabled' => true,
    'transition_enabled' => true,
    'transitions' => [
        ['after_days' => 30,  'storage_class' => 'STANDARD_IA'],
        ['after_days' => 90,  'storage_class' => 'GLACIER'],
    ],
],
```

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
    'count' => 10,              // Always keep the latest N
    'max_storage_mb' => null,   // Optional storage budget
    'min_keep' => 3,            // Never drop below this count
    'cleanup_enabled' => true,
],
```

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

- PHP 8.1+
- Laravel 10, 11, or 12
- `zip` PHP extension
- Database tools: `mysqldump` (MySQL/MariaDB), `pg_dump` (PostgreSQL)
- Optional: `mysql` / `psql` for restore

---

## License

MIT. See [LICENSE.md](LICENSE.md).
