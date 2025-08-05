# Capsule - Laravel Backup Package

A comprehensive Laravel package for backing up databases and files to external storage with notifications and retention policies.

## Features

- **Multi-Database Support**: MySQL, PostgreSQL, SQLite
- **File Backup**: Configurable paths with exclusion patterns  
- **Storage Options**: Local, S3, DigitalOcean Spaces, FTP
- **Notifications**: Email, Slack, Discord, Microsoft Teams, Google Chat
- **Smart Retention**: Automatic cleanup based on days/count policies
- **Scheduled Backups**: Automated backup scheduling
- **Chunked Backups**: Stream directly to cloud storage without local disk usage

## Quick Start

```bash
# Install
composer require dgtlss/capsule

# Publish config and run migrations
php artisan vendor:publish --tag=capsule-config
php artisan migrate

# Test your setup
php artisan capsule:diagnose

# Run backup
php artisan capsule:backup
```

## Configuration

Capsule integrates with Laravel's existing filesystem configuration - no duplicate setup needed!

### Basic Setup

```php
// config/capsule.php
return [
    'default_disk' => env('CAPSULE_DEFAULT_DISK', 'local'),
    'backup_path' => env('CAPSULE_BACKUP_PATH', 'backups'),
    
    'database' => [
        'enabled' => true,
        'connections' => 'default',
        'compress' => true,
    ],
    
    'files' => [
        'enabled' => true,
        'paths' => [storage_path('app'), public_path()],
        'exclude_paths' => [storage_path('logs')],
    ],
    
    'retention' => [
        'days' => env('CAPSULE_RETENTION_DAYS', 30),
        'count' => 10,
    ],
];
```

### Environment Variables

```env
# Storage (use any disk from config/filesystems.php)
CAPSULE_DEFAULT_DISK=s3
CAPSULE_BACKUP_PATH=app-backups
CAPSULE_RETENTION_DAYS=14

# Notifications
CAPSULE_EMAIL_TO=admin@yoursite.com
CAPSULE_SLACK_WEBHOOK_URL=https://hooks.slack.com/your-webhook-url
```

## Commands

### Backup Commands

```bash
# Run backup
php artisan capsule:backup

# Chunked backup (no local disk usage)
php artisan capsule:backup --no-local

# Force backup (ignore running backups)
php artisan capsule:backup --force

# Verbose output for debugging
php artisan capsule:backup -v
```

### Cleanup Commands

```bash
# Clean old backups
php artisan capsule:cleanup

# Preview deletions
php artisan capsule:cleanup --dry-run

# Override retention days
php artisan capsule:cleanup --days=7

# Also clean failed backup records and orphaned files
php artisan capsule:cleanup --failed --storage

# Verbose output
php artisan capsule:cleanup --dry-run --failed --storage -v
```

### Troubleshooting

```bash
# Check configuration and requirements
php artisan capsule:diagnose
```

## Advanced Features

### Chunked Backups

Stream directly to external storage without using local disk space:

```bash
php artisan capsule:backup --no-local
```

Perfect for large databases, Docker containers, or cloud environments with limited storage.

### Scheduled Backups

Configure automatic backups in `config/capsule.php`:

```php
'schedule' => [
    'enabled' => true,
    'frequency' => 'daily', // hourly, daily, weekly, monthly
    'time' => '02:00',
],
```

### Notifications

Enable notifications for backup status:

```env
# Email
CAPSULE_EMAIL_TO=admin@yoursite.com

# Webhooks
CAPSULE_SLACK_WEBHOOK_URL=https://hooks.slack.com/your-webhook
CAPSULE_DISCORD_WEBHOOK_URL=https://discord.com/api/webhooks/your-webhook
CAPSULE_TEAMS_WEBHOOK_URL=https://outlook.office.com/webhook/your-webhook
```

### Programmatic Usage

```php
use Dgtlss\Capsule\Services\BackupService;

$success = app(BackupService::class)->run();
```

## Requirements

- PHP 8.1+
- Laravel 10.0+
- Database tools (mysqldump, pg_dump for PostgreSQL)
- Required PHP extensions: zip, ftp (if using FTP storage)

## License

This package is open-sourced software licensed under the [MIT license](LICENSE.md).