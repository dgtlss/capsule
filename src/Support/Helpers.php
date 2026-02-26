<?php

namespace Dgtlss\Capsule\Support;

class Helpers
{
    public static function formatBytes(int $bytes): string
    {
        if ($bytes <= 0) {
            return '0 B';
        }

        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $power = floor(log($bytes, 1024));
        $power = min($power, count($units) - 1);

        return round($bytes / (1024 ** $power), 2) . ' ' . $units[$power];
    }

    public static function commandExists(string $command): bool
    {
        $output = [];
        $returnCode = 0;
        exec('which ' . escapeshellarg($command) . ' 2>/dev/null', $output, $returnCode);

        return $returnCode === 0;
    }
}
