<?php
declare(strict_types=1);

namespace OwnPay\Security;

/**
 * PII masker — mask sensitive data for display/logging.
 *
 * Per pci-compliance + security skills: never log raw PII.
 */
final class PiiMasker
{
    /**
     * Mask email: us**@ex****.com
     */
    public static function email(string $email): string
    {
        $parts = explode('@', $email, 2);
        if (count($parts) !== 2) {
            return '***';
        }
        [$local, $domain] = $parts;
        $maskedLocal = mb_substr($local, 0, 2) . str_repeat('*', max(2, mb_strlen($local) - 2));
        $domParts = explode('.', $domain);
        $domName = $domParts[0];
        $maskedDom = mb_substr($domName, 0, 2) . str_repeat('*', max(2, mb_strlen($domName) - 2));
        $domParts[0] = $maskedDom;
        return $maskedLocal . '@' . implode('.', $domParts);
    }

    /**
     * Mask phone: +880****1234
     */
    public static function phone(string $phone): string
    {
        $len = mb_strlen($phone);
        if ($len <= 4) {
            return str_repeat('*', $len);
        }
        $visible = 4;
        $prefix = mb_substr($phone, 0, max(1, $len - $visible - 4));
        $suffix = mb_substr($phone, -$visible);
        $masked = str_repeat('*', $len - mb_strlen($prefix) - $visible);
        return $prefix . $masked . $suffix;
    }

    /**
     * Mask card number: ****1234
     */
    public static function card(string $number): string
    {
        $clean = preg_replace('/\D/', '', $number) ?? '';
        if (strlen($clean) < 4) {
            return '****';
        }
        return str_repeat('*', strlen($clean) - 4) . substr($clean, -4);
    }

    /**
     * Mask IP: 192.168.***.***
     */
    public static function ip(string $ip): string
    {
        $parts = explode('.', $ip);
        if (count($parts) === 4) {
            return $parts[0] . '.' . $parts[1] . '.***.' . '***';
        }
        // IPv6 — mask last 4 groups
        $parts6 = explode(':', $ip);
        if (count($parts6) >= 4) {
            $visible = array_slice($parts6, 0, 4);
            return implode(':', $visible) . ':****:****:****:****';
        }
        return '***';
    }

    /**
     * Generic mask: show first N and last M chars.
     */
    public static function mask(string $value, int $showFirst = 2, int $showLast = 2): string
    {
        $len = mb_strlen($value);
        if ($len <= $showFirst + $showLast) {
            return str_repeat('*', $len);
        }
        return mb_substr($value, 0, $showFirst)
            . str_repeat('*', $len - $showFirst - $showLast)
            . mb_substr($value, -$showLast);
    }

    /**
     * Alias for email() — called by CustomerPiiService.
     */
    public static function maskEmail(string $email): string
    {
        return self::email($email);
    }

    /**
     * Alias for phone() — called by CustomerPiiService.
     */
    public static function maskPhone(string $phone): string
    {
        return self::phone($phone);
    }
}
