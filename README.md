# Capsule - Laravel Backup Package

A comprehensive Laravel package for backing up databases and files to external storage with notifications and retention policies.

## Features

- **Multi-Database Support**: MySQL, PostgreSQL, SQLite
- **File Backup**: Configurable paths with exclusion patterns
- **Multiple Storage Options**: Local, S3, DigitalOcean Spaces, FTP
- **Notifications**: Email, Slack, Discord, Microsoft Teams, Google Chat
- **Data Retention**: Automatic cleanup based on days/count policies
- **Scheduled Backups**: Automated backup scheduling
- **Artisan Commands**: Backup, cleanup, list, inspect, verify (incl. verify-all)
- **Logging**: Comprehensive backup logs with statistics
- **Chunked Streaming Mode**: No local disk usage, concurrent uploads
- **Integrity & Manifest**: `manifest.json` inside each archive with checksums
- **JSON Output**: Machine-friendly output for backup/cleanup/verify
- **Budget-aware Retention**: Keep usage under a configurable size budget
- **Locking**: Prevent overlapping runs with cache locks
- **Hooks & Extensibility**: Events, file filters, pre/post steps
- **Health Integration**: Health summary + optional Spatie Health check
- **Filament Panel (browse-only)**: List and inspect backups (no restore)

## Installation

Install the package via Composer:

```bash
composer require dgtlss/capsule
```

Publish the configuration file:

```bash
php artisan vendor:publish --tag=capsule-config
```

Run the migrations:

```bash
php artisan migrate
```

## Configuration

Capsule uses a **config-first approach** with sensible defaults and **integrates with Laravel's filesystem configuration**. No need to configure storage twice!

### Storage Integration

Capsule uses your existing `config/filesystems.php` disks - no duplicate configuration needed:

```php
return [
    // Use any disk from your filesystems.php config
    'default_disk' => env('CAPSULE_DEFAULT_DISK', 'local'),
    
    // Path within the disk to store backups
    'backup_path' => env('CAPSULE_BACKUP_PATH', 'backups'),
    
    // Database backups - all settings in config
    'database' => [
        'enabled' => true,
        'connections' => 'default', // string or array
        'compress' => true,
    ],
    
    // File backups - customize paths as needed
    'files' => [
        'enabled' => true,
        'paths' => [
            storage_path('app'),
            public_path(),
        ],
        'exclude_paths' => [
            storage_path('logs'),
            storage_path('framework/cache'),
        ],
    ],
    
    // Retention - days can be overridden via ENV
    'retention' => [
        'days' => env('CAPSULE_RETENTION_DAYS', 30),
        'count' => 10,
        'cleanup_enabled' => true,
    ],
    
    // Notifications - enable in config, URLs via ENV
    'notifications' => [
        'enabled' => true,
        'email' => [
            'enabled' => false, // Enable in config
            'to' => env('CAPSULE_EMAIL_TO'), // Address via ENV
        ],
        'webhooks' => [
            'slack' => [
                'enabled' => false, // Enable in config
                'webhook_url' => env('CAPSULE_SLACK_WEBHOOK_URL'), // URL via ENV
                'channel' => '#general', // Config-based
            ],
        ],
    ],
    
    // Scheduling - all config-based
    'schedule' => [
        'enabled' => true,
        'frequency' => 'daily', // hourly, daily, weekly, monthly
        'time' => '02:00',
    ],
];
```

### Customization

1. **Publish the config**: `php artisan vendor:publish --tag=capsule-config`
2. **Edit directly** in `config/capsule.php` - no ENV variables needed for most settings
3. **Add ENV variables** only for sensitive data (API keys, webhook URLs, email addresses)

## Environment Variables

Capsule integrates with Laravel's filesystem configuration, so **use your existing filesystem disks**! 

### Storage Setup

1. **Configure your disks** in `config/filesystems.php` (as you normally would)
2. **Reference the disk** in Capsule config or ENV

```env
# Use any disk from your filesystems.php config
CAPSULE_DEFAULT_DISK=s3

# Optional: customize backup path within the disk
CAPSULE_BACKUP_PATH=app-backups
```

### Example Filesystem Disks

Your `config/filesystems.php` might already have:

```php
'disks' => [
    's3' => [
        'driver' => 's3',
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION'),
        'bucket' => env('AWS_BUCKET'),
    ],
    
    'do_spaces' => [
        'driver' => 's3',
        'key' => env('DO_SPACES_KEY'),
        'secret' => env('DO_SPACES_SECRET'),
        'region' => env('DO_SPACES_REGION'),
        'bucket' => env('DO_SPACES_BUCKET'),
        'endpoint' => env('DO_SPACES_ENDPOINT'),
    ],
],
```

Just set `CAPSULE_DEFAULT_DISK=s3` or `CAPSULE_DEFAULT_DISK=do_spaces` and you're done!

### Optional Configuration

```env
# Override default storage (local, s3, digitalocean, ftp)
CAPSULE_DEFAULT_STORAGE=s3

# Override retention days (defaults to 30 days)
CAPSULE_RETENTION_DAYS=14

# Email notifications
CAPSULE_EMAIL_TO=admin@yoursite.com

# Webhook URLs (only if using notifications)
CAPSULE_SLACK_WEBHOOK_URL=https://hooks.slack.com/your-webhook-url
CAPSULE_DISCORD_WEBHOOK_URL=https://discord.com/api/webhooks/your-webhook-url
CAPSULE_TEAMS_WEBHOOK_URL=https://outlook.office.com/webhook/your-webhook-url
CAPSULE_GOOGLE_CHAT_WEBHOOK_URL=https://chat.googleapis.com/v1/spaces/your-webhook-url
```

## Usage

### Commands overview

```bash
# Create a backup (preflight DB check, with locking)
php artisan capsule:backup [--no-local] [--parallel] [--compress=1] [--encrypt] [--verify]

# JSON output (for CI/automation)
php artisan capsule:backup --format=json

# List and inspect
php artisan capsule:list [--limit=50] [--format=json]
php artisan capsule:inspect {id} [--format=json]

# Verify integrity of latest or specific backup(s)
php artisan capsule:verify            # latest
php artisan capsule:verify --id=123   # specific
php artisan capsule:verify --all      # all successful backups
php artisan capsule:verify --format=json

# Cleanup with retention + budget-aware pruning
php artisan capsule:cleanup [--days=] [--dry-run] [--format=json]

# Diagnose configuration and health
php artisan capsule:diagnose [--detailed]

# Health snapshot (JSON)
php artisan capsule:health --format=json
```

### Troubleshooting

If backups are failing, start with the diagnostic command:

```bash
php artisan capsule:diagnose
```

This will check your configuration, storage setup, database connections, and system requirements.

For detailed error information when running backups:

```bash
php artisan capsule:backup --v
```

### Manual Backup

Run a backup manually:

```bash
php artisan capsule:backup
```

Run a chunked backup without using local disk space (streams directly to external storage):

```bash
php artisan capsule:backup --no-local
```

Force a backup even if another is running:

```bash
php artisan capsule:backup --force
```

### Cleanup Old Backups

Clean up old backups according to retention policy:

```bash
php artisan capsule:cleanup
```

Preview what would be deleted:

```bash
php artisan capsule:cleanup --dry-run
```

Override retention days (ignores count-based retention):

```bash
php artisan capsule:cleanup --days=7
```

Combine options:

```bash
php artisan capsule:cleanup --days=14 --dry-run
```

### Programmatic Usage

```php
use Dgtlss\Capsule\Services\BackupService;

$backupService = app(BackupService::class);
$success = $backupService->run();

if ($success) {
    echo "Backup completed successfully!";
} else {
    echo "Backup failed!";
}
```

### Verify Latest Backup

Re-download the latest successful backup, verify ZIP integrity and manifest checksums, then delete the local temp file:

```bash
php artisan capsule:verify
```

Options:

- `--id=` verify a specific `backup_logs.id`
- `--keep` keep the downloaded file instead of deleting
- `--v` verbose mismatch details

## Storage Integration Benefits

✅ **No Duplicate Configuration** - Use your existing filesystem disks  
✅ **Laravel Native** - Leverages Laravel's filesystem contracts  
✅ **All Drivers Supported** - Local, S3, FTP, SFTP, and any custom filesystem drivers  
✅ **Consistent API** - Same backup functionality across all storage types  
✅ **Easy Switching** - Change storage with a single environment variable

## Notifications

### Slack

```env
CAPSULE_SLACK_ENABLED=true
CAPSULE_SLACK_WEBHOOK_URL=https://hooks.slack.com/services/YOUR/SLACK/WEBHOOK
CAPSULE_SLACK_CHANNEL=#general
CAPSULE_SLACK_USERNAME=Capsule
```

### Discord

```env
CAPSULE_DISCORD_ENABLED=true
CAPSULE_DISCORD_WEBHOOK_URL=https://discord.com/api/webhooks/YOUR/DISCORD/WEBHOOK
```

### Microsoft Teams

```env
CAPSULE_TEAMS_ENABLED=true
CAPSULE_TEAMS_WEBHOOK_URL=https://outlook.office.com/webhook/YOUR/TEAMS/WEBHOOK
```

### Google Chat

```env
CAPSULE_GOOGLE_CHAT_ENABLED=true
CAPSULE_GOOGLE_CHAT_WEBHOOK_URL=https://chat.googleapis.com/v1/spaces/YOUR/WEBHOOK
```

### HTML Email Styling

Email notifications are sent as styled HTML for readability. Configure the recipient in `capsule.notifications.email.to` and optional subjects. Webhook payloads include structured details.

## Scheduled Backups

Capsule automatically registers scheduled backups based on your configuration:

```php
'schedule' => [
    'enabled' => env('CAPSULE_SCHEDULE_ENABLED', true),
    'frequency' => env('CAPSULE_SCHEDULE_FREQUENCY', 'daily'), // hourly, daily, weekly, monthly
    'time' => env('CAPSULE_SCHEDULE_TIME', '02:00'),
],
```

## Chunked Backup (No Local Storage)

The `--no-local` flag enables a special chunked backup mode that streams data directly to external storage without using local disk space. This is particularly useful for:

- **Large databases/files**: Avoid running out of local disk space
- **Limited storage environments**: Docker containers, serverless functions
- **Cloud-first workflows**: Stream directly to S3, DigitalOcean Spaces, etc.

### How It Works

1. **Streaming**: Database dumps and files are streamed in configurable chunks
2. **Concurrent Upload**: Multiple chunks are uploaded simultaneously for speed
3. **Direct Storage**: Each chunk is uploaded immediately to external storage
4. **Collation**: Chunks are downloaded and reassembled into a final ZIP file
5. **Cleanup**: Temporary chunks are automatically removed

### Configuration

All chunked backup settings are configured directly in `config/capsule.php`:

```php
'chunked_backup' => [
    'chunk_size' => 10485760, // 10MB chunks - adjust as needed
    'temp_prefix' => 'capsule_chunk_',
    'max_concurrent_uploads' => 3, // Upload multiple chunks simultaneously
],
```

### Performance Benefits

- ⚡ **Concurrent Uploads**: Up to 3 chunks uploaded simultaneously by default
- 📊 **Upload Statistics**: Tracks success rates and performance metrics
- 🔄 **Fault Tolerance**: Continues if some chunks fail (up to 50% failure threshold)
- 🎯 **Smart Throttling**: Automatically manages upload queue and concurrency

**No environment variables needed** - customize all settings directly in the config file.

### Usage Examples

```bash
# Regular backup (uses local disk)
php artisan capsule:backup

# Chunked backup (no local disk usage)
php artisan capsule:backup --no-local

# Chunked backup with dry run cleanup
php artisan capsule:backup --no-local && php artisan capsule:cleanup --dry-run
```

## Requirements

- PHP 8.1+
- Laravel 10.0+
- Database tools (mysqldump, pg_dump for PostgreSQL)
- Required PHP extensions: zip, ftp (if using FTP storage)

## JSON Output

All major commands support `--format=json` for easy consumption in CI or monitoring:

```bash
php artisan capsule:backup --format=json
php artisan capsule:cleanup --dry-run --format=json
php artisan capsule:verify --all --format=json
```

Sample backup output:

```json
{
  "status": "success",
  "duration_seconds": 12.34
}
```

## Locking

Capsule prevents overlapping runs using cache locks. Configure in `config/capsule.php`:

```php
'lock' => [
    'store' => env('CAPSULE_LOCK_STORE', null),
    'timeout_seconds' => 900,
    'wait_seconds' => 0,
],
```

Use `--force` to bypass the lock for `capsule:backup`.

## Budget-aware Retention

In addition to age/count policies, Capsule can enforce a storage budget:

```php
'retention' => [
    'max_storage_mb' => 10240, // 10 GB
    'min_keep' => 3,
],
```

When `max_storage_mb` is set, `capsule:cleanup` prunes oldest backups until usage is under budget (never dropping below `min_keep`).

## Hooks & Extensibility

- Events: `BackupStarting`, `DatabaseDumpStarting/Completed`, `FilesCollectStarting/Completed`, `ArchiveFinalizing`, `BackupUploaded`, `BackupSucceeded/Failed`.
- File filters: implement `Dgtlss\Capsule\Contracts\FileFilterInterface` and register in `capsule.extensibility.file_filters`.
- Steps: implement `Dgtlss\Capsule\Contracts\StepInterface` and register in `capsule.extensibility.pre_steps` / `post_steps`. Steps run before/after the backup; failures abort the run.

## Health Integration

Capsule provides a simple health summary in `capsule:diagnose` and a check for Spatie Laravel Health.

```php
// app/Providers/HealthServiceProvider.php (host app)
use Spatie\Health\Facades\Health;
use Dgtlss\Capsule\Health\CapsuleBackupCheck;

public function boot(): void
{
    Health::checks([
        CapsuleBackupCheck::new(),
        // other checks...
    ]);
}
```

Configure thresholds in `capsule.health`:

```php
'health' => [
    'max_last_success_age_days' => 2,
    'max_recent_failures' => 0,
    'warn_storage_percent' => 90,
],
```

## Filament Panel (browse only)

Capsule ships a simple browse/inspect panel (no restore) you can add to your Filament admin:

- Views are auto-loaded from `resources/views` under namespace `capsule`.
- Page class: `Dgtlss\Capsule\Filament\Pages\BackupsPage` (add to your panel if desired).

The page shows the latest backups, quick health stats, and an Inspect hint per backup.

## Behavior Notes

- Preflight DB check: if any configured DB connection is unreachable, Capsule aborts the backup and sends a clear failure notification (no archive is produced).
- Each archive contains a `manifest.json` with metadata and per-entry SHA-256 checksums.
- `capsule:verify` uses the manifest to validate entries and, for S3, best-effort remote checksum (ETag) comparison for single-part uploads.

## License

This package is open-sourced software licensed under the [MIT license](LICENSE.md).