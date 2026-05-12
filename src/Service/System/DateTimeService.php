<?php
declare(strict_types=1);

namespace OwnPay\Service\System;

/**
 * DateTime service â€” timezone-aware date formatting.
 */
final class DateTimeService
{
    private string $timezone;
    private string $dateFormat;
    private string $timeFormat;

    public function __construct(
        ?string $timezone = null,
        string $dateFormat = 'Y-m-d',
        string $timeFormat = 'H:i:s'
    ) {
        $this->timezone = $timezone ?? (getenv('APP_TIMEZONE') ?: 'UTC');
        $this->dateFormat = $dateFormat;
        $this->timeFormat = $timeFormat;
    }

    public function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone($this->timezone));
    }

    public function format(\DateTimeInterface $dt, ?string $format = null): string
    {
        $tz = new \DateTimeZone($this->timezone);
        $local = \DateTimeImmutable::createFromInterface($dt)->setTimezone($tz);
        return $local->format($format ?? $this->dateFormat . ' ' . $this->timeFormat);
    }

    public function formatDate(\DateTimeInterface $dt): string
    {
        return $this->format($dt, $this->dateFormat);
    }

    public function formatTime(\DateTimeInterface $dt): string
    {
        return $this->format($dt, $this->timeFormat);
    }

    /**
     * Parse string to DateTimeImmutable.
     */
    public function parse(string $datetime): \DateTimeImmutable
    {
        return new \DateTimeImmutable($datetime, new \DateTimeZone($this->timezone));
    }

    /**
     * Human-readable relative time (e.g. "3 minutes ago").
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
}
