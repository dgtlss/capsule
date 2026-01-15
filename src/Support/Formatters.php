<?php

namespace Dgtlss\Capsule\Support;

class Formatters
{
    public static function bytes(int $bytes): string
    {
        if ($bytes === 0) {
            return '0 B';
        }

        $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
        $power = floor(log($bytes, 1024));
        
        return round($bytes / (1024 ** $power), 2) . ' ' . $units[$power];
    }

    public static function duration(int $seconds): string
    {
        if ($seconds < 60) {
            return $seconds . 's';
        }

        $minutes = floor($seconds / 60);
        $remainingSeconds = $seconds % 60;

        if ($minutes < 60) {
            return $remainingSeconds > 0 
                ? $minutes . 'm ' . $remainingSeconds . 's'
                : $minutes . 'm';
        }

        $hours = floor($minutes / 60);
        $remainingMinutes = $minutes % 60;

        if ($hours < 24) {
            return $remainingMinutes > 0
                ? $hours . 'h ' . $remainingMinutes . 'm'
                : $hours . 'h';
        }

        $days = floor($hours / 24);
        $remainingHours = $hours % 24;

        return $remainingHours > 0
            ? $days . 'd ' . $remainingHours . 'h'
            : $days . 'd';
    }

    public static function durationPrecise(int $seconds, int $precision = 2): string
    {
        if ($seconds < 1) {
            return round($seconds * 1000, $precision) . 'ms';
        }

        $units = [
            'd' => 86400,
            'h' => 3600,
            'm' => 60,
            's' => 1,
        ];

        $result = '';
        $remaining = $seconds;
        $count = 0;

        foreach ($units as $label => $divisor) {
            if ($remaining >= $divisor) {
                $value = floor($remaining / $divisor);
                $remaining = $remaining % $divisor;
                
                $result .= $value . $label . ' ';
                $count++;
                
                if ($count >= $precision) {
                    break;
                }
            }
        }

        return trim($result) ?: '0s';
    }

    public static function percentage(float $part, float $total, int $decimals = 1): string
    {
        if ($total == 0) {
            return '0%';
        }

        $percentage = ($part / $total) * 100;
        return round($percentage, $decimals) . '%';
    }

    public static function datetimeFriendly(\DateTimeInterface $date): string
    {
        $now = now();
        $diff = $now->diffInSeconds($date);

        if ($diff < 60) {
            return $diff <= 1 ? 'just now' : $diff . 's ago';
        }

        if ($diff < 3600) {
            return floor($diff / 60) . 'm ago';
        }

        if ($diff < 86400) {
            return floor($diff / 3600) . 'h ago';
        }

        if ($diff < 604800) {
            return floor($diff / 86400) . 'd ago';
        }

        return $date->format('M j, Y');
    }

    public static function number(int|float $number, int $decimals = 0): string
    {
        return number_format($number, $decimals);
    }

    public static function bool(bool $value): string
    {
        return $value ? '✓' : '✗';
    }

    public static function list(array $items, string $conjunction = 'and', int $limit = 3): string
    {
        $count = count($items);

        if ($count === 0) {
            return '';
        }

        if ($count === 1) {
            return $items[0];
        }

        if ($count <= $limit) {
            $last = array_pop($items);
            return implode(', ', $items) . " {$conjunction} {$last}";
        }

        $visible = array_slice($items, 0, $limit);
        $remaining = $count - $limit;
        return implode(', ', $visible) . ", and {$remaining} more";
    }

    public static function speed(int $bytes, int $seconds): string
    {
        if ($seconds === 0) {
            return '0 B/s';
        }

        $bytesPerSecond = $bytes / $seconds;
        return self::bytes((int) $bytesPerSecond) . '/s';
    }

    public static function bitrate(int $bytes, int $seconds): string
    {
        if ($seconds === 0) {
            return '0 Mbps';
        }

        $bitsPerSecond = ($bytes * 8) / $seconds;
        $mbps = $bitsPerSecond / 1000000;
        
        return round($mbps, 2) . ' Mbps';
    }

    public static function memory(bool $peak = false): string
    {
        $usage = $peak 
            ? memory_get_peak_usage(true) 
            : memory_get_usage(true);

        return self::bytes($usage);
    }
}
