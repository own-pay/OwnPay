<?php
declare(strict_types=1);

namespace OwnPay\Support;

/**
 * DateHelper — centralized DateTimeImmutable-based date utilities.
 *
 * Replaces scattered date()/strtotime() calls with type-safe,
 * timezone-aware helpers. All methods return strings formatted
 * for MySQL or ISO 8601 as appropriate.
 */
final class DateHelper
{
    /**
     * Current timestamp in MySQL DATETIME format.
     */
    public static function now(): string
    {
        return (new \DateTimeImmutable())->format('Y-m-d H:i:s');
    }

    /**
     * Current timestamp with microseconds (MySQL DATETIME(6)).
     */
    public static function nowMicro(): string
    {
        return (new \DateTimeImmutable())->format('Y-m-d H:i:s.u');
    }

    /**
     * Current timestamp in ISO 8601 format.
     */
    public static function iso(): string
    {
        return (new \DateTimeImmutable())->format('c');
    }

    /**
     * Current date only (Y-m-d).
     */
    public static function today(): string
    {
        return (new \DateTimeImmutable())->format('Y-m-d');
    }

    /**
     * First day of current month (Y-m-01).
     */
    public static function monthStart(): string
    {
        return (new \DateTimeImmutable('first day of this month'))->format('Y-m-d');
    }

    /**
     * Timestamp N seconds in the past.
     */
    public static function ago(int $seconds): string
    {
        return (new \DateTimeImmutable())->modify("-{$seconds} seconds")->format('Y-m-d H:i:s');
    }

    /**
     * Timestamp N seconds in the future.
     */
    public static function future(int $seconds): string
    {
        return (new \DateTimeImmutable())->modify("+{$seconds} seconds")->format('Y-m-d H:i:s');
    }

    /**
     * Check if a datetime string is in the past.
     */
    public static function isPast(string $datetime): bool
    {
        try {
            return (new \DateTimeImmutable($datetime)) < new \DateTimeImmutable();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Current hour (0-23).
     */
    public static function currentHour(): int
    {
        return (int) (new \DateTimeImmutable())->format('G');
    }

    /**
     * Format for backup filenames (Y-m-d_His).
     */
    public static function backupTimestamp(): string
    {
        return (new \DateTimeImmutable())->format('Y-m-d_His');
    }

    /**
     * Current year/month path segment (Y/m).
     */
    public static function yearMonthPath(): string
    {
        return (new \DateTimeImmutable())->format('Y/m');
    }

    /**
     * Normalize any datetime string to MySQL format.
     */
    public static function normalize(string $input, string $fallback = 'now'): string
    {
        try {
            return (new \DateTimeImmutable($input))->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            return (new \DateTimeImmutable($fallback))->format('Y-m-d H:i:s');
        }
    }

    /**
     * Seconds elapsed since a given datetime.
     */
    public static function secondsSince(string $datetime): int
    {
        try {
            $then = new \DateTimeImmutable($datetime);
            $now = new \DateTimeImmutable();
            return max(0, $now->getTimestamp() - $then->getTimestamp());
        } catch (\Throwable) {
            return 0;
        }
    }
}
