<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Storage Disk
    |--------------------------------------------------------------------------
    |
    | This option controls the default filesystem disk that will be used to
    | store backup files. This should reference a disk configured in your
    | config/filesystems.php file. No need to configure storage twice!
    |
    | Examples: "local", "s3", "public", or any custom disk you've defined
    |
    */

    'default_disk' => env('CAPSULE_DEFAULT_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Additional Storage Disks
    |--------------------------------------------------------------------------
    |
    | Backups will also be copied to these disks for redundancy. Each entry
    | should be a disk name from config/filesystems.php. The primary backup
    | is always stored on `default_disk`; these are additional copies.
    |
    */

    'additional_disks' => [
        // 's3-secondary',
        // 'local-archive',
    ],

    /*
    |--------------------------------------------------------------------------
    | Backup Path
    |--------------------------------------------------------------------------
    |
    | The directory path within the storage disk where backups will be stored.
    | This path will be created automatically if it doesn't exist.
    |
    */

    'backup_path' => env('CAPSULE_BACKUP_PATH', 'backups'),

    /*
    |--------------------------------------------------------------------------
    | Storage Upload Reliability
    |--------------------------------------------------------------------------
    | Configure retry/backoff for storage operations (upload/delete). Useful
    | for transient network issues with cloud providers.
    */
    'storage' => [
        'retries' => env('CAPSULE_STORAGE_RETRIES', 3),
        'backoff_ms' => env('CAPSULE_STORAGE_BACKOFF_MS', 500),
        'max_backoff_ms' => env('CAPSULE_STORAGE_MAX_BACKOFF_MS', 5000),
    ],

    /*
    |--------------------------------------------------------------------------
    | Database Backup
    |--------------------------------------------------------------------------
    |
    | Configure database backup settings. You can specify which database
    | connections to backup, exclude specific tables, or only include
    | certain tables. The backup will be compressed by default.
    |
    */

    'database' => [
        'enabled' => true,

        // Database connection(s) to backup. 
        // Set to null to auto-backup only the current default database,
        // or specify specific connections: ['mysql', 'pgsql']
        // Example: null (auto-detect current database) or ['mysql'] or 'mysql'
        'connections' => null,

        // Tables to exclude from backup (applies to all connections)
        'exclude_tables' => [
            // 'sessions',
            // 'cache',
        ],

        // If specified, only these tables will be backed up (overrides exclude_tables)
        'include_tables' => [
            // 'users',
            // 'posts',
        ],

        // Include triggers and stored routines in MySQL dumps
        'include_triggers' => env('CAPSULE_INCLUDE_TRIGGERS', true),
        'include_routines' => env('CAPSULE_INCLUDE_ROUTINES', false),

        // Extra flags to pass to mysqldump/pg_dump. Example: '--set-gtid-purged=OFF'
        'mysqldump_flags' => env('CAPSULE_MYSQLDUMP_FLAGS', ''),

        // Compress database dumps using gzip
        'compress' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | File Backup
    |--------------------------------------------------------------------------
    |
    | Configure which files and directories should be included in backups.
    | You can specify paths to include and paths to exclude. All paths
    | should be absolute paths or use Laravel helper functions.
    |
    */

    'files' => [
        'enabled' => true,

        // Paths to include in backup
        'paths' => [
            base_path(),
        ],

        // Paths to exclude from backup (even if included in paths above)
        'exclude_paths' => [
            base_path('.env'),
            base_path('.env.local'),
            base_path('.env.production'),
            base_path('.env.staging'),
            base_path('node_modules'),
            base_path('vendor'),
            base_path('.git'),
            base_path('.gitignore'),
            base_path('storage/app/backups'),
            storage_path('app/backups'),
            storage_path('app/private'),
            storage_path('logs'),
            storage_path('framework/cache'),
            storage_path('framework/sessions'),
            storage_path('framework/views'),
            base_path('bootstrap/cache'),
            base_path('.DS_Store'),
            base_path('Thumbs.db'),
            // public_path('uploads/temp'),
        ],

        // Compress files in the backup archive
        'compress' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Backup Performance
    |--------------------------------------------------------------------------
    |
    | Configure backup performance settings to optimize speed and resource usage.
    | Compression level: 1 (fastest) to 9 (best compression). Default is 6.
    | Lower values prioritize speed, higher values prioritize compression ratio.
    |
    */

    'backup' => [
        // ZIP compression level (1-9): 1 = fastest, 9 = best compression
        'compression_level' => env('CAPSULE_COMPRESSION_LEVEL', 6),
    ],

    /*
    |--------------------------------------------------------------------------
    | Backup Retention
    |--------------------------------------------------------------------------
    |
    | Configure how long backups should be kept. Backups older than the
    | specified number of days will be automatically deleted. You can
    | also set a maximum number of backups to keep regardless of age.
    |
    */

    'retention' => [
        // Delete backups older than this many days (can be overridden via CAPSULE_RETENTION_DAYS env var)
        'days' => env('CAPSULE_RETENTION_DAYS', 30),

        // Maximum number of successful backups to keep (newest are preserved)
        'count' => 10,

        // Optional: total storage budget for backups on the target disk (in MB)
        // When exceeded, oldest backups will be pruned until within budget, while
        // still respecting the 'count' minimum to keep.
        'max_storage_mb' => env('CAPSULE_RETENTION_MAX_STORAGE_MB', null),

        // Minimum number of backups to always keep, even when over budget
        'min_keep' => env('CAPSULE_RETENTION_MIN_KEEP', 3),

        // Enable automatic cleanup of old backups
        'cleanup_enabled' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Chunked Backup (Streaming)
    |--------------------------------------------------------------------------
    |
    | Configure settings for chunked backups that stream directly to external
    | storage without using local disk space. Useful for large backups or
    | environments with limited local storage.
    |
    */

    'chunked_backup' => [
        // Size of each chunk in bytes (10MB default)
        'chunk_size' => 10485760,

        // Prefix for temporary chunk files
        'temp_prefix' => 'capsule_chunk_',

        // Maximum number of concurrent chunk uploads (improves upload speed)
        'max_concurrent_uploads' => 3,
    ],

    /*
    |--------------------------------------------------------------------------
    | Notifications
    |--------------------------------------------------------------------------
    |
    | Configure backup notifications. You can enable email notifications
    | and webhook notifications for various chat platforms. Notifications
    | are sent for both successful and failed backups.
    |
    */

    'notifications' => [
        // Master switch for all notifications
        'enabled' => true,

        'email' => [
            // Enable email notifications
            'enabled' => false,

            // Email address to send notifications to
            'to' => env('CAPSULE_EMAIL_TO'),

            // Optional: sender address (falls back to Laravel default)
            'from' => env('CAPSULE_EMAIL_FROM'),

            // Email subject lines (app name and env are prepended automatically)
            'subject_success' => 'Backup Completed Successfully',
            'subject_failure' => 'Backup Failed',
            'subject_cleanup' => 'Backup Cleanup Completed',

            // Which events trigger this channel: ['success', 'failure', 'cleanup']
            // Set to null to receive all events
            'notify_on' => null,
        ],

        'webhooks' => [

            'slack' => [
                // Enable Slack notifications
                'enabled' => false,

                // Slack webhook URL (get from Slack app settings)
                'webhook_url' => env('CAPSULE_SLACK_WEBHOOK_URL'),

                // Channel to post to (include #)
                'channel' => '#general',

                // Username and emoji to post as
                'username' => 'Capsule',
                'icon_emoji' => ':package:',

                // Which events trigger this channel: ['success', 'failure', 'cleanup']
                'notify_on' => null,
            ],

            'discord' => [
                // Enable Discord notifications
                'enabled' => false,

                // Discord webhook URL (get from Discord server settings)
                'webhook_url' => env('CAPSULE_DISCORD_WEBHOOK_URL'),

                // Username and avatar
                'username' => 'Capsule',
                'avatar_url' => null,

                // Which events trigger this channel
                'notify_on' => null,
            ],

            'teams' => [
                // Enable Microsoft Teams notifications
                'enabled' => false,

                // Teams webhook URL (get from Teams channel connectors)
                'webhook_url' => env('CAPSULE_TEAMS_WEBHOOK_URL'),

                // Which events trigger this channel
                'notify_on' => null,
            ],

            'google_chat' => [
                // Enable Google Chat notifications
                'enabled' => false,

                // Google Chat webhook URL (get from Google Chat space settings)
                'webhook_url' => env('CAPSULE_GOOGLE_CHAT_WEBHOOK_URL'),

                // Which events trigger this channel
                'notify_on' => null,
            ],

        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Backup Scheduling
    |--------------------------------------------------------------------------
    |
    | Configure automatic backup scheduling. Backups will be automatically
    | triggered based on the frequency and time specified below. This uses
    | Laravel's task scheduler, so make sure your cron is configured.
    |
    */

    'schedule' => [
        // Enable automatic scheduled backups
        'enabled' => true,

        // How often to run backups
        // Supported: "hourly", "daily", "twiceDaily", "everyFourHours",
        //            "everySixHours", "weekly", "monthly"
        // Or provide a custom cron expression: "0 3 * * 1-5"
        'frequency' => 'daily',

        // Time to run daily backups (24-hour format)
        // Only used when frequency is "daily"
        'time' => '02:00',
    ],

    /*
    |--------------------------------------------------------------------------
    | Security Settings
    |--------------------------------------------------------------------------
    |
    | Configure security settings for backup archives and processes.
    | Enable encryption, set secure file permissions, and configure
    | access controls for your backup files.
    |
    */

    'security' => [
        // Enable backup encryption (requires CAPSULE_BACKUP_PASSWORD)
        'encrypt_backups' => env('CAPSULE_ENCRYPT_BACKUPS', false),

        // Backup encryption password
        'backup_password' => env('CAPSULE_BACKUP_PASSWORD'),

        // Encryption algorithm for backups (AES-256 recommended)
        'encryption_method' => 'AES-256-CBC',

        // Enable integrity verification of backup files
        'verify_integrity' => env('CAPSULE_VERIFY_INTEGRITY', true),

        // Secure temporary file permissions (octal)
        'temp_file_permissions' => 0600,

        // Maximum backup file age before warning (days)
        'max_backup_age_warning' => 7,
    ],

    /*
    |--------------------------------------------------------------------------
    | Performance Optimization
    |--------------------------------------------------------------------------
    |
    | Configure performance settings to optimize backup speed and resource usage.
    | Adjust these settings based on your server capabilities and requirements.
    |
    */

    'performance' => [
        // Enable parallel database processing
        'parallel_databases' => env('CAPSULE_PARALLEL_DATABASES', false),

        // Memory limit for backup operations (MB)
        'memory_limit' => env('CAPSULE_MEMORY_LIMIT', 512),

        // Maximum execution time for backups (seconds, 0 = no limit)
        'max_execution_time' => env('CAPSULE_MAX_EXECUTION_TIME', 0),

        // Enable file streaming for large backups
        'enable_streaming' => env('CAPSULE_ENABLE_STREAMING', false),

        // Chunk size for file processing (bytes)
        'file_chunk_size' => 8192,
    ],

    /*
    |--------------------------------------------------------------------------
    | Locking
    |--------------------------------------------------------------------------
    |
    | Prevent overlapping runs of backup/cleanup/verify using cache locks.
    | Configure the lock store (null = default) and timeouts.
    |
    */

    'lock' => [
        'store' => env('CAPSULE_LOCK_STORE', null),
        'timeout_seconds' => env('CAPSULE_LOCK_TIMEOUT', 900), // 15 minutes
        'wait_seconds' => env('CAPSULE_LOCK_WAIT', 0),
    ],

    /*
    |--------------------------------------------------------------------------
    | Extensibility: Custom Filters and Steps
    |--------------------------------------------------------------------------
    |
    | Register fully-qualified class names that implement
    | - Dgtlss\Capsule\Contracts\FileFilterInterface for file inclusion
    | - Dgtlss\Capsule\Contracts\StepInterface for pipeline steps
    |
    */

    'extensibility' => [
        'file_filters' => [
            // Example: App\Backup\Filters\ExcludeLargeFiles::class
        ],
        'pre_steps' => [
            // Example: App\Backup\Steps\EnterMaintenanceMode::class
        ],
        'post_steps' => [
            // Example: App\Backup\Steps\ExitMaintenanceMode::class
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Health Integration (Spatie Laravel Health)
    |--------------------------------------------------------------------------
    */

    /*
    |--------------------------------------------------------------------------
    | Automated Integrity Verification
    |--------------------------------------------------------------------------
    |
    | Capsule can automatically verify backup integrity on a schedule.
    | Each run picks an unverified (or stale) backup, downloads it,
    | and validates the ZIP structure and manifest checksums.
    |
    */

    'verification' => [
        'schedule_enabled' => env('CAPSULE_VERIFY_SCHEDULE_ENABLED', true),
        'frequency' => 'daily',
        'time' => '04:00',
        'recheck_days' => 7,
    ],

    'health' => [
        'enabled' => env('CAPSULE_HEALTH_ENABLED', true),
        // Warn when last success older than N days
        'max_last_success_age_days' => env('CAPSULE_HEALTH_MAX_LAST_SUCCESS_DAYS', 2),
        // Fail when recent failures exceed N in the last 7 days
        'max_recent_failures' => env('CAPSULE_HEALTH_MAX_RECENT_FAILURES', 0),
        // Warn when storage usage exceeds this percent of budget (if budget set)
        'warn_storage_percent' => env('CAPSULE_HEALTH_WARN_STORAGE_PERCENT', 90),
    ],

];