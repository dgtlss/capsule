<?php

namespace Dgtlss\Capsule\Services;

use Dgtlss\Capsule\Models\BackupLog;
use Dgtlss\Capsule\Models\BackupSnapshot;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Collection;

/** Shared retention planner for commands and both backup engines. Call while holding the backup lock. */
class RetentionService
{
    public static function currentScope(): array
    {
        return [
            'policy' => config('capsule._policy_name'),
            'disk' => config('capsule.default_disk', 'local'),
            'backup_path' => trim(config('capsule.backup_path', 'backups'), '/'),
        ];
    }

    /**
     * Options: scope, policy, days, dry_run, failed, storage, output (a callback).
     * No scope means all recorded policies, each with its own storage and retention rules.
     */
    public function cleanup(array $options = []): array
    {
        $result = ['count' => 0, 'size' => 0, 'errors' => []];
        $all = BackupLog::orderByDesc('created_at')->orderByDesc('id')->get();
        $successful = $all->where('status', 'success')->keyBy('id');
        $selected = $successful->filter(fn (BackupLog $backup) => $this->selected($backup, $options));
        $parents = $this->dependencies($all);
        $candidates = [];
        $minimumKeep = [];

        foreach ($selected->groupBy(fn (BackupLog $backup) => $this->scopeKey($this->scope($backup))) as $group) {
            $rules = $this->rules($group->first());
            $days = max(0, (int) ($options['days'] ?? ($rules['days'] ?? 30)));
            $count = array_key_exists('days', $options) && $options['days'] !== null ? 0 : max(0, (int) ($rules['count'] ?? 10));
            $minKeep = max(0, (int) ($rules['min_keep'] ?? 3));
            $keep = max($count, $minKeep);
            $remaining = $group->count();
            $bytes = (int) $group->sum('file_size');
            $cutoff = now()->subDays($days);
            $budget = max(0, (int) ($rules['max_storage_mb'] ?? 0)) * 1024 * 1024;
            $newest = $group->values();
            foreach ($newest->take($minKeep) as $backup) {
                $minimumKeep[$backup->id] = true;
            }

            foreach ($newest as $index => $backup) {
                if ($index >= $keep && $backup->created_at->lt($cutoff)) {
                    $candidates[$backup->id] = $backup;
                    $remaining--;
                    $bytes -= (int) $backup->file_size;
                }
            }
            // Plan budget pruning against the same projected set, including in dry runs.
            foreach ($newest->reverse() as $backup) {
                if ($budget <= 0 || $bytes <= $budget || $remaining <= $minKeep) {
                    break;
                }
                if (isset($candidates[$backup->id])) {
                    continue;
                }
                $candidates[$backup->id] = $backup;
                $remaining--;
                $bytes -= (int) $backup->file_size;
            }
        }

        if (!empty($options['failed'])) {
            $timeout = max(1, (int) config('capsule.lock.timeout_seconds', 900));
            foreach ($all as $backup) {
                if (!$this->selected($backup, $options)) {
                    continue;
                }
                $days = max(0, (int) ($options['days'] ?? ($this->rules($backup)['days'] ?? 30)));
                if (($backup->status === 'failed' && $backup->created_at->lt(now()->subDays($days)))
                    || ($backup->status === 'running' && $backup->started_at && $backup->started_at->lt(now()->subSeconds($timeout)))) {
                    $candidates[$backup->id] = $backup;
                }
            }
        }

        // Preserve every ancestor of every retained backup, including dependencies across policies.
        $protected = [];
        foreach ($all as $backup) {
            if (!isset($candidates[$backup->id])) {
                $this->protectAncestors($backup->id, $parents, $protected);
            }
        }
        foreach (array_keys($protected) as $id) {
            unset($candidates[$id]);
        }

        // A base protected above may leave the projected storage budget unmet. Re-plan
        // against the retained set, only pruning leaves (or bases whose children are
        // already planned) and preserving each scope's newest minimum copies.
        $projected = array_fill_keys($all->modelKeys(), true);
        foreach (array_keys($candidates) as $id) {
            unset($projected[$id]);
        }
        foreach ($selected->groupBy(fn (BackupLog $backup) => $this->scopeKey($this->scope($backup))) as $group) {
            $rules = $this->rules($group->first());
            $budget = max(0, (int) ($rules['max_storage_mb'] ?? 0)) * 1024 * 1024;
            if ($budget <= 0) {
                continue;
            }
            $remaining = $group->filter(fn (BackupLog $backup) => isset($projected[$backup->id]));
            $bytes = (int) $remaining->sum('file_size');
            do {
                $changed = false;
                foreach ($remaining->reverse() as $backup) {
                    if ($bytes <= $budget) {
                        break;
                    }
                    if (isset($minimumKeep[$backup->id]) || $this->hasDependent($backup->id, $parents, $projected)) {
                        continue;
                    }
                    $candidates[$backup->id] = $backup;
                    unset($projected[$backup->id]);
                    $remaining = $remaining->reject(fn (BackupLog $item) => $item->id === $backup->id);
                    $bytes -= (int) $backup->file_size;
                    $changed = true;
                }
            } while ($changed && $bytes > $budget);
        }

        $remainingIds = array_fill_keys($all->modelKeys(), true);
        // Delete children first. A failed child deletion must also preserve its base records/files.
        while ($candidates !== []) {
            $progress = false;
            foreach ($candidates as $id => $backup) {
                if ($this->hasDependent($id, $parents, $remainingIds)) {
                    continue;
                }
                unset($candidates[$id]);
                $progress = true;
                try {
                    if (empty($options['dry_run'])) {
                        $this->deleteBackup($backup);
                    }
                    unset($remainingIds[$id]);
                    $result['count']++;
                    $result['size'] += (int) $backup->file_size;
                    $this->report($options, (!empty($options['dry_run']) ? 'Would delete' : 'Deleted') . " backup #{$id}");
                } catch (\Throwable $e) {
                    $result['errors'][] = "Backup #{$id}: {$e->getMessage()}";
                }
            }
            if (!$progress) {
                break;
            }
        }

        if (!empty($options['storage'])) {
            $this->cleanupOrphans($all, $options, $result);
        }
        return $result;
    }

    private function scope(BackupLog $backup): array
    {
        $metadata = $backup->metadata ?? [];
        $storage = $metadata['storage'] ?? [];
        return [
            'policy' => $metadata['policy'] ?? null,
            'disk' => $storage['disk'] ?? $metadata['disk'] ?? config('capsule.default_disk', 'local'),
            'backup_path' => trim($storage['backup_path'] ?? config('capsule.backup_path', 'backups'), '/'),
        ];
    }

    private function scopeKey(array $scope): string
    {
        return json_encode([$scope['policy'] ?? null, $scope['disk'], trim($scope['backup_path'], '/')]);
    }

    private function selected(BackupLog $backup, array $options): bool
    {
        $scope = $this->scope($backup);
        if (isset($options['scope']) && $this->scopeKey($scope) !== $this->scopeKey($options['scope'])) {
            return false;
        }
        return !isset($options['policy']) || $scope['policy'] === $options['policy'];
    }

    private function rules(BackupLog $backup): array
    {
        $metadata = $backup->metadata ?? [];
        // Saved rules avoid applying one policy's settings to a different policy or disk.
        if (isset($metadata['retention']) && is_array($metadata['retention'])) {
            return $metadata['retention'];
        }
        $rules = config('capsule.retention', []);
        $policy = $metadata['policy'] ?? null;
        $policies = config('capsule.policies', []);
        return $policy !== null ? array_replace($rules, $policies[$policy]['retention'] ?? []) : $rules;
    }

    private function path(BackupLog $backup): ?string
    {
        if (!$backup->file_path) {
            return null;
        }
        $path = str_replace('\\', '/', $backup->file_path);
        if (str_starts_with($path, '/') || in_array('..', explode('/', $path), true)) {
            throw new \RuntimeException('Refusing an unsafe storage path.');
        }
        $prefix = $this->scope($backup)['backup_path'];
        return $prefix === '' || str_starts_with($path, $prefix . '/') ? $path : $prefix . '/' . $path;
    }

    private function deleteBackup(BackupLog $backup): void
    {
        $disk = Storage::disk($this->scope($backup)['disk']);
        $chunks = $this->recoveryChunkPaths($backup);
        $path = $this->path($backup);
        foreach ($chunks as $chunk) {
            if (!$this->referencedElsewhere($backup, $chunk) && !$disk->delete($chunk)) {
                throw new \RuntimeException("Storage refused to delete recovery chunk {$chunk}; backup record preserved.");
            }
        }
        foreach ($this->archiveDisks($backup) as $diskName) {
            if ($path !== null && !$this->referencedElsewhere($backup, $path, $diskName) && !Storage::disk($diskName)->delete($path)) {
                throw new \RuntimeException("Storage refused to delete {$diskName}:{$path}; backup record preserved.");
            }
        }
        if (!$backup->delete()) {
            throw new \RuntimeException('Could not delete the backup record.');
        }
    }

    private function archiveDisks(BackupLog $backup): array
    {
        $additional = $backup->metadata['storage']['additional_disks'] ?? [];
        if (!is_array($additional)) {
            throw new \RuntimeException('Invalid recorded replica disks.');
        }
        $disks = array_merge([$this->scope($backup)['disk']], $additional);
        foreach ($disks as $name) {
            if (!is_string($name) || $name === '') {
                throw new \RuntimeException('Invalid recorded replica disk name.');
            }
        }
        return array_values(array_unique($disks));
    }

    private function referencedElsewhere(BackupLog $backup, string $path, ?string $disk = null): bool
    {
        $disk ??= $this->scope($backup)['disk'];
        foreach (BackupLog::where('id', '!=', $backup->id)->get() as $other) {
            if (in_array($disk, $this->archiveDisks($other), true) && $this->path($other) === $path) {
                return true;
            }
            if ($this->scope($other)['disk'] === $disk && in_array($path, $this->recoveryChunkPaths($other), true)) {
                return true;
            }
        }
        return false;
    }

    /** Validate and resolve recoverable temporary objects before any catalog deletion. */
    private function recoveryChunkPaths(BackupLog $backup): array
    {
        $chunks = $backup->metadata['recovery_chunks'] ?? [];
        if (!is_array($chunks)) {
            throw new \RuntimeException('Invalid recorded recovery chunk list.');
        }
        $prefix = $this->scope($backup)['backup_path'];
        $paths = [];
        foreach ($chunks as $chunk) {
            $name = is_array($chunk) ? ($chunk['name'] ?? null) : null;
            if (!is_string($name)) {
                throw new \RuntimeException('Invalid recorded recovery chunk name.');
            }
            \Dgtlss\Capsule\Support\RestoreArchive::assertSafeName($name);
            $path = $prefix === '' || str_starts_with($name, $prefix . '/') ? $name : $prefix . '/' . $name;
            \Dgtlss\Capsule\Support\RestoreArchive::assertSafeName($path);
            $paths[] = $path;
        }
        return array_values(array_unique($paths));
    }

    private function dependencies(Collection $all): array
    {
        $parents = [];
        // Retention needs dependency edges, never the historical file indexes.
        $snapshots = BackupSnapshot::get(['id', 'backup_log_id', 'base_snapshot_id'])->keyBy('id');
        foreach ($snapshots as $snapshot) {
            if ($snapshot->base_snapshot_id && ($base = $snapshots->get($snapshot->base_snapshot_id))) {
                $parents[$snapshot->backup_log_id][] = $base->backup_log_id;
            }
        }
        $archives = [];
        foreach ($all as $backup) {
            try {
                $path = $this->path($backup);
                if ($path !== null) {
                    $archives[$this->scope($backup)['disk'] . ':' . $path] = $backup->id;
                }
            } catch (\Throwable $e) {
                // The unsafe record will fail deletion rather than blocking all other retention.
            }
        }
        foreach ($all as $backup) {
            $metadata = $backup->metadata ?? [];
            $base = $metadata['base_archive'] ?? ($metadata['snapshot']['base_archive'] ?? null);
            if (is_array($base) && isset($base['disk'], $base['path'])) {
                $id = $archives[$base['disk'] . ':' . $base['path']] ?? null;
                if ($id !== null) {
                    $parents[$backup->id][] = $id;
                }
            }
        }
        return $parents;
    }

    private function protectAncestors(int $id, array $parents, array &$protected): void
    {
        foreach ($parents[$id] ?? [] as $parent) {
            if (!isset($protected[$parent])) {
                $protected[$parent] = true;
                $this->protectAncestors($parent, $parents, $protected);
            }
        }
    }

    private function hasDependent(int $id, array $parents, array $remaining): bool
    {
        foreach ($parents as $child => $bases) {
            if (isset($remaining[$child]) && in_array($id, $bases, true)) {
                return true;
            }
        }
        return false;
    }

    private function cleanupOrphans(Collection $all, array $options, array &$result): void
    {
        $known = [];
        $scopes = [];
        foreach ($all as $backup) {
            $scope = $this->scope($backup);
            try {
                foreach ($this->archiveDisks($backup) as $diskName) {
                    if ($this->selected($backup, $options)) {
                        $scopes[$diskName . ':' . $scope['backup_path']] = array_replace($scope, ['disk' => $diskName]);
                    }
                    if (($archivePath = $this->path($backup)) !== null) {
                        $known[$diskName . ':' . $archivePath] = true;
                    }
                }
                foreach ($this->recoveryChunkPaths($backup) as $chunkPath) {
                    $known[$scope['disk'] . ':' . $chunkPath] = true;
                }
                if (($path = $this->path($backup)) !== null) {
                    $known[$scope['disk'] . ':' . $path] = true;
                }
            } catch (\Throwable $e) {
                $result['errors'][] = "Backup #{$backup->id}: {$e->getMessage()}";
                return; // Never infer that an unresolvable record's archive is orphaned.
            }
            $metadata = $backup->metadata ?? [];
            $base = $metadata['base_archive'] ?? ($metadata['snapshot']['base_archive'] ?? null);
            if (is_array($base) && isset($base['disk'], $base['path'])) {
                $known[$base['disk'] . ':' . $base['path']] = true;
            }
        }
        if (!isset($options['policy'])) {
            $scope = $options['scope'] ?? self::currentScope();
            $scopes[$scope['disk'] . ':' . $scope['backup_path']] = $scope;
        }
        foreach ($scopes as $scope) {
            try {
                $disk = Storage::disk($scope['disk']);
                foreach ($disk->files($scope['backup_path']) as $path) {
                    if (isset($known[$scope['disk'] . ':' . $path])) {
                        continue;
                    }
                    $size = (int) $disk->size($path);
                    if (empty($options['dry_run']) && !$disk->delete($path)) {
                        throw new \RuntimeException("Storage refused to delete {$path}.");
                    }
                    $result['count']++;
                    $result['size'] += $size;
                    $this->report($options, (!empty($options['dry_run']) ? 'Would delete' : 'Deleted') . " orphan {$path}");
                }
            } catch (\Throwable $e) {
                $result['errors'][] = "Storage {$scope['disk']}: {$e->getMessage()}";
            }
        }
    }

    private function report(array $options, string $message): void
    {
        if (isset($options['output'])) {
            ($options['output'])($message);
        }
    }
}
