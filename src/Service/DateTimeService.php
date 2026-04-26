<?php
declare(strict_types=1);

namespace OwnPay\Service;

use DateTime;
use DateTimeZone;

class DateTimeService
{
    public static function timeAgo(string $datetime): string
    {
        global $global_response_brand;

        // Determine user timezone or default to Dhaka
        $userTimezone = !empty($global_response_brand['response'][0]['timezone'])
            ? $global_response_brand['response'][0]['timezone']
            : 'Asia/Dhaka';

        // Create DateTime objects in the user's timezone
        $tz = new DateTimeZone($userTimezone);

        // Convert the input datetime (assumed UTC) to user's timezone
        $past = new DateTime($datetime, new DateTimeZone('UTC'));
        $past->setTimezone($tz);

        // Get current time in user's timezone
        $now = new DateTime('now', $tz);

        // Calculate difference
        $diff = $now->diff($past);

        if ($diff->y > 0) {
            return $diff->y . ' year' . ($diff->y > 1 ? 's' : '') . ' ago';
        } elseif ($diff->m > 0) {
            return $diff->m . ' month' . ($diff->m > 1 ? 's' : '') . ' ago';
        } elseif ($diff->d > 0) {
            return $diff->d . ' day' . ($diff->d > 1 ? 's' : '') . ' ago';
        } elseif ($diff->h > 0) {
            return $diff->h . ' hour' . ($diff->h > 1 ? 's' : '') . ' ago';
        } elseif ($diff->i > 0) {
            return $diff->i . ' minute' . ($diff->i > 1 ? 's' : '') . ' ago';
        } else {
            return 'Just now';
        }
    }

    public static function getCurrentDatetime(string $format = 'Y-m-d H:i:s'): string
    {
        $currentDatetime = new DateTime();
        return $currentDatetime->format($format);
    }

    public static function dateformat(string $date, string $format = 'd/m/Y'): bool
    {
        $d = DateTime::createFromFormat($format, $date);
        return $d && $d->format($format) === $date;
    }

    public static function convertUTCtoUserTZ(string $utc_time, string $user_tz = 'UTC', string $format = 'Y-m-d H:i:s'): string
    {
        $dt = new DateTime($utc_time, new DateTimeZone('UTC'));
        $dt->setTimezone(new DateTimeZone($user_tz));
        return $dt->format($format);
    }

    public static function isExpired(string $expires_at): bool
    {
        if (empty($expires_at)) {
            return false;
        }

        $timestamp = strtotime($expires_at);

        if ($timestamp === false) {
            return true;
        }

        if (preg_match('/^\d{1,4}[-\/]\d{1,2}[-\/]\d{1,4}$/', $expires_at)) {
            $timestamp = strtotime(date('Y-m-d 23:59:59', $timestamp));
        }

        return time() > $timestamp;
    }
}
