<?php

namespace Dgtlss\Capsule\Support;

use Illuminate\Support\Facades\Log;

class MemoryMonitor
{
    protected static array $checkpoints = [];
    
    public static function checkpoint(string $name): void
    {
        self::$checkpoints[$name] = [
            'memory_usage' => memory_get_usage(true),
            'peak_memory' => memory_get_peak_usage(true),
            'timestamp' => microtime(true),
        ];
    }
    
    public static function getCheckpoint(string $name): ?array
    {
        return self::$checkpoints[$name] ?? null;
    }
    
    public static function getMemoryUsage(): array
    {
        return [
            'current' => memory_get_usage(true),
            'peak' => memory_get_peak_usage(true),
            'limit' => ini_get('memory_limit'),
        ];
    }
    
    public static function formatBytes(int $bytes): string
    {
        return Helpers::formatBytes($bytes);
    }

    public static function logMemoryUsage(string $context = ''): void
    {
        $usage = self::getMemoryUsage();
        $context = $context ? " ({$context})" : '';

        Log::debug(sprintf(
            "Memory Usage%s: Current: %s, Peak: %s, Limit: %s",
            $context,
            Helpers::formatBytes($usage['current']),
            Helpers::formatBytes($usage['peak']),
            $usage['limit']
        ));
    }
    
    public static function compareCheckpoints(string $from, string $to): array
    {
        $fromCheckpoint = self::getCheckpoint($from);
        $toCheckpoint = self::getCheckpoint($to);
        
        if (!$fromCheckpoint || !$toCheckpoint) {
            return [];
        }
        
        return [
            'memory_delta' => $toCheckpoint['memory_usage'] - $fromCheckpoint['memory_usage'],
            'peak_delta' => $toCheckpoint['peak_memory'] - $fromCheckpoint['peak_memory'],
            'time_delta' => $toCheckpoint['timestamp'] - $fromCheckpoint['timestamp'],
            'formatted' => [
                'memory_delta' => Helpers::formatBytes($toCheckpoint['memory_usage'] - $fromCheckpoint['memory_usage']),
                'peak_delta' => Helpers::formatBytes($toCheckpoint['peak_memory'] - $fromCheckpoint['peak_memory']),
                'time_delta' => round(($toCheckpoint['timestamp'] - $fromCheckpoint['timestamp']) * 1000, 2) . 'ms',
            ],
        ];
    }
}