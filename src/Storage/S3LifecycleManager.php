<?php

namespace Dgtlss\Capsule\Storage;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class S3LifecycleManager
{
    /**
     * Apply storage class tagging to an S3 object based on its age.
     * Capsule manages this at the application level, complementing
     * native S3 lifecycle policies.
     */
    public static function applyStorageClass(string $diskName, string $remotePath, ?string $storageClass = null): bool
    {
        $disk = Storage::disk($diskName);
        $adapter = method_exists($disk, 'getAdapter') ? $disk->getAdapter() : null;

        if (!$adapter || !class_exists('\League\Flysystem\AwsS3V3\AwsS3V3Adapter') || !$adapter instanceof \League\Flysystem\AwsS3V3\AwsS3V3Adapter) {
            return false;
        }

        $storageClass = $storageClass ?? self::resolveStorageClass();
        if (!$storageClass) {
            return false;
        }

        try {
            $client = $adapter->getClient();
            $bucket = $adapter->getBucket();

            $client->copyObject([
                'Bucket' => $bucket,
                'CopySource' => $bucket . '/' . $remotePath,
                'Key' => $remotePath,
                'StorageClass' => $storageClass,
                'MetadataDirective' => 'COPY',
            ]);

            Log::info("Capsule: Applied storage class {$storageClass} to {$remotePath}");
            return true;
        } catch (\Throwable $e) {
            Log::warning("Capsule: Failed to apply storage class to {$remotePath}: {$e->getMessage()}");
            return false;
        }
    }

    /**
     * Tag an S3 object with metadata for lifecycle policy matching.
     */
    public static function tagObject(string $diskName, string $remotePath, array $tags): bool
    {
        $disk = Storage::disk($diskName);
        $adapter = method_exists($disk, 'getAdapter') ? $disk->getAdapter() : null;

        if (!$adapter || !class_exists('\League\Flysystem\AwsS3V3\AwsS3V3Adapter') || !$adapter instanceof \League\Flysystem\AwsS3V3\AwsS3V3Adapter) {
            return false;
        }

        try {
            $client = $adapter->getClient();
            $bucket = $adapter->getBucket();

            $tagSet = [];
            foreach ($tags as $key => $value) {
                $tagSet[] = ['Key' => $key, 'Value' => (string) $value];
            }

            $client->putObjectTagging([
                'Bucket' => $bucket,
                'Key' => $remotePath,
                'Tagging' => ['TagSet' => $tagSet],
            ]);

            return true;
        } catch (\Throwable $e) {
            Log::warning("Capsule: Failed to tag S3 object {$remotePath}: {$e->getMessage()}");
            return false;
        }
    }

    /**
     * Apply Capsule-specific tags to a newly uploaded backup.
     */
    public static function tagBackup(string $diskName, string $remotePath, array $metadata = []): void
    {
        if (!config('capsule.s3_lifecycle.tagging_enabled', false)) {
            return;
        }

        $tags = array_merge([
            'capsule' => 'true',
            'capsule-type' => 'backup',
            'capsule-env' => app()->environment(),
            'capsule-app' => config('app.name', 'Laravel'),
        ], $metadata);

        self::tagObject($diskName, $remotePath, $tags);
    }

    /**
     * Transition older backups to a cheaper storage class.
     */
    public static function transitionOldBackups(): int
    {
        if (!config('capsule.s3_lifecycle.transition_enabled', false)) {
            return 0;
        }

        $diskName = config('capsule.default_disk', 'local');
        $rules = config('capsule.s3_lifecycle.transitions', []);
        $transitioned = 0;

        $backups = \Dgtlss\Capsule\Models\BackupLog::successful()
            ->whereNotNull('file_path')
            ->orderBy('created_at')
            ->get();

        foreach ($rules as $rule) {
            $daysOld = $rule['after_days'] ?? null;
            $targetClass = $rule['storage_class'] ?? null;
            if (!$daysOld || !$targetClass) {
                continue;
            }

            $cutoff = now()->subDays($daysOld);

            foreach ($backups as $backup) {
                if ($backup->created_at->greaterThan($cutoff)) {
                    continue;
                }

                $currentClass = $backup->metadata['storage_class'] ?? 'STANDARD';
                if ($currentClass === $targetClass) {
                    continue;
                }

                if (self::applyStorageClass($diskName, $backup->file_path, $targetClass)) {
                    $backup->metadata = array_merge($backup->metadata ?? [], [
                        'storage_class' => $targetClass,
                        'transitioned_at' => now()->toISOString(),
                    ]);
                    $backup->save();
                    $transitioned++;
                }
            }
        }

        return $transitioned;
    }

    protected static function resolveStorageClass(): ?string
    {
        return config('capsule.s3_lifecycle.default_storage_class');
    }
}
