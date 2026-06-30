<?php
declare(strict_types=1);

namespace OwnPay\Support;

/**
 * Class DateHelper
 *
 * Provides centralized, type-safe, and timezone-aware date and time utility methods.
 * Ensures consistent serialization of timestamps into standard formats compatible with MySQL 8.x (DATETIME and DATETIME(6))
 * and ISO 8601 specifications. Frequently used in ledger journaling, audit logs, and transaction lock durations.
 *
 * @package OwnPay\Support
 */
final class DateHelper
{
    /**
     * Retrieve the current system time formatted as a MySQL-compliant DATETIME string.
     *
     * @return string Current date and time in 'Y-m-d H:i:s' format.
     */
    public static function now(): string
    {
        return (new \DateTimeImmutable())->format('Y-m-d H:i:s');
    }

    /**
     * Retrieve the current system time with microsecond precision (MySQL DATETIME(6)).
     *
     * Essential for high-frequency transaction logging, audit trails, and precise database sorting.
     *
     * @return string Current date and time in 'Y-m-d H:i:s.u' format.
     */
    public static function nowMicro(): string
    {
        return (new \DateTimeImmutable())->format('Y-m-d H:i:s.u');
    }

    /**
     * Retrieve the current timestamp represented in the standard ISO 8601 specification format.
     *
     * @return string Current timestamp in ISO 8601 'c' format.
     */
    public static function iso(): string
    {
        return (new \DateTimeImmutable())->format('c');
    }

    /**
     * Retrieve the current date in 'Y-m-d' format.
     *
     * @return string Current date string.
     */
    public static function today(): string
    {
        return (new \DateTimeImmutable())->format('Y-m-d');
    }

    /**
     * Retrieve the first day of the current calendar month.
     *
     * @return string First day date string in 'Y-m-d' format.
     */
    public static function monthStart(): string
    {
        return (new \DateTimeImmutable('first day of this month'))->format('Y-m-d');
    }

    /**
     * Retrieve a timestamp representing a point in time N seconds in the past.
     *
     * @param int $seconds The number of seconds to subtract.
     * @return string The past timestamp formatted in 'Y-m-d H:i:s'.
     */
    public static function ago(int $seconds): string
    {
        return (new \DateTimeImmutable())->modify("-{$seconds} seconds")->format('Y-m-d H:i:s');
    }

    /**
     * Retrieve a timestamp representing a point in time N seconds in the future.
     *
     * Often used to establish transaction timeouts, lock windows, or session expirations.
     *
     * @param int $seconds The number of seconds to add.
     * @return string The future timestamp formatted in 'Y-m-d H:i:s'.
     */
    public static function future(int $seconds): string
    {
        return (new \DateTimeImmutable())->modify("+{$seconds} seconds")->format('Y-m-d H:i:s');
    }

    /**
     * Determine if a given datetime string represents a point in time in the past.
     *
     * Returns false if the input string is invalid or cannot be parsed.
     *
     * @param string $datetime The datetime string to evaluate.
     * @return bool True if the datetime is in the past, false otherwise.
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
     * Retrieve the current hour in 24-hour format.
     *
     * @return int The current hour (0-23).
     */
    public static function currentHour(): int
    {
        return (int) (new \DateTimeImmutable())->format('G');
    }

    /**
     * Generate a timestamp string suitable for safe inclusion in backup filenames.
     *
     * @return string The timestamp formatted as 'Y-m-d_His'.
     */
    public static function backupTimestamp(): string
    {
        return (new \DateTimeImmutable())->format('Y-m-d_His');
    }

    /**
     * Retrieve a URL-safe directory segment mapping the current year and month.
     *
     * Useful for file storage partitioning schemes.
     *
     * @return string The path segment formatted as 'Y/m'.
     */
    public static function yearMonthPath(): string
    {
        return (new \DateTimeImmutable())->format('Y/m');
    }

    /**
     * Normalize an arbitrary datetime input string to the standard MySQL DATETIME format.
     *
     * Uses the specified fallback representation (e.g. 'now') if the main input cannot be parsed.
     *
     * @param string $input The raw input date/time string.
     * @param string $fallback The parseable fallback representation if input parsing fails.
     * @return string The normalized MySQL DATETIME string.
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
     * Calculate the absolute number of seconds elapsed since the specified datetime.
     *
     * Returns 0 if the input string is invalid or represents a future time.
     *
     * @param string $datetime The datetime string to calculate elapsed time from.
     * @return int The elapsed duration in seconds.
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

