<?php
declare(strict_types=1);

namespace OwnPay\Service\System;

/**
 * Service managing timezone-aware DateTime calculations and string formatting.
 *
 * Implements utilities to generate timezone-local timestamp representations, parse datetime inputs,
 * compute relative elapsed intervals (e.g. "x minutes ago"), and provide static timestamp values for system operations.
 */
final class DateTimeService
{
    /**
     * @var string The default timezone name used for timezone calculations (e.g., 'Asia/Dhaka', 'UTC').
     */
    private string $timezone;

    /**
     * @var string Format token for dates.
     */
    private string $dateFormat;

    /**
     * @var string Format token for times.
     */
    private string $timeFormat;

    /**
     * DateTimeService constructor.
     *
     * Resolves the current system timezone by falling back to environment variables.
     *
     * @param string|null $timezone The name of the timezone (null defaults to 'APP_TIMEZONE' or 'UTC').
     * @param string $dateFormat Format representation for date values.
     * @param string $timeFormat Format representation for time values.
     */
    public function __construct(
        ?string $timezone = null,
        string $dateFormat = 'Y-m-d',
        string $timeFormat = 'H:i:s'
    ) {
        $this->timezone = $timezone ?? (getenv('APP_TIMEZONE') ?: 'UTC');
        $this->dateFormat = $dateFormat;
        $this->timeFormat = $timeFormat;
    }

    /**
     * Instantiates a new DateTimeImmutable representation resolved to the active timezone.
     *
     * @return \DateTimeImmutable Timezone-local DateTimeImmutable instance.
     */
    public function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone($this->timezone));
    }

    /**
     * Formats a DateTimeInterface instance using the active timezone configuration.
     *
     * Converts the DateTime context to the configured timezone before outputting.
     *
     * @param \DateTimeInterface $dt The datetime instance to convert and format.
     * @param string|null $format Optional format overrides; defaults to full datetime string.
     * @return string The formatted timestamp string.
     */
    public function format(\DateTimeInterface $dt, ?string $format = null): string
    {
        $tz = new \DateTimeZone($this->timezone);
        $local = \DateTimeImmutable::createFromInterface($dt)->setTimezone($tz);
        return $local->format($format ?? $this->dateFormat . ' ' . $this->timeFormat);
    }

    /**
     * Formats a DateTimeInterface date segment using the active config.
     *
     * @param \DateTimeInterface $dt The datetime to format.
     * @return string Formatted date string (e.g. Y-m-d).
     */
    public function formatDate(\DateTimeInterface $dt): string
    {
        return $this->format($dt, $this->dateFormat);
    }

    /**
     * Formats a DateTimeInterface time segment using the active config.
     *
     * @param \DateTimeInterface $dt The datetime to format.
     * @return string Formatted time string (e.g. H:i:s).
     */
    public function formatTime(\DateTimeInterface $dt): string
    {
        return $this->format($dt, $this->timeFormat);
    }

    /**
     * Parses a textual datetime string into a DateTimeImmutable instance.
     *
     * @param string $datetime The datetime string description to evaluate.
     * @return \DateTimeImmutable The parsed DateTimeImmutable instance.
     */
    public function parse(string $datetime): \DateTimeImmutable
    {
        return new \DateTimeImmutable($datetime, new \DateTimeZone($this->timezone));
    }

    /**
     * Formats the difference between the given timestamp and now in relative human-readable format.
     *
     * @param \DateTimeInterface $dt The comparison timestamp.
     * @return string Relative elapsed time string (e.g., "3 minutes ago").
     */
    public function ago(\DateTimeInterface $dt): string
    {
        $now = $this->now();
        $diff = $now->getTimestamp() - $dt->getTimestamp();

        if ($diff < 60) {
            return 'just now';
        }
        if ($diff < 3600) {
            $m = intdiv($diff, 60);
            return "{$m} minute" . ($m > 1 ? 's' : '') . ' ago';
        }
        if ($diff < 86400) {
            $h = intdiv($diff, 3600);
            return "{$h} hour" . ($h > 1 ? 's' : '') . ' ago';
        }
        if ($diff < 2592000) {
            $d = intdiv($diff, 86400);
            return "{$d} day" . ($d > 1 ? 's' : '') . ' ago';
        }
        return $this->formatDate($dt);
    }

    /**
     * Generates a static formatted datetime string for the current time.
     *
     * Intended for convenience and cron runners (e.g. SystemUpdateJob).
     *
     * @param string $format The formatting tokens.
     * @return string Current datetime string.
     */
    public static function getCurrentDatetime(string $format = 'Y-m-d H:i:s'): string
    {
        $tz = getenv('APP_TIMEZONE') ?: 'UTC';
        return (new \DateTimeImmutable('now', new \DateTimeZone($tz)))->format($format);
    }
}
