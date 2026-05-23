<?php
declare(strict_types=1);

namespace OwnPay\Security;

/**
 * Class PiiMasker
 *
 * Provides utility methods to mask Personally Identifiable Information (PII)
 * such as email addresses, phone numbers, credit card numbers, and IP addresses
 * for presentation, logging, and transmission safety.
 *
 * @package OwnPay\Security
 */
final class PiiMasker
{
    /**
     * Masks an email address to protect user identity.
     *
     * Example: converts "user@example.com" to "us***@ex***.com".
     *
     * @param string $email The raw email address.
     * @return string The masked email address.
     */
    public static function email(string $email): string
    {
        $parts = explode('@', $email, 2);
        if (count($parts) !== 2) {
            return '***';
        }
        [$local, $domain] = $parts;
        $maskedLocal = mb_substr($local, 0, 2) . str_repeat('*', max(3, mb_strlen($local) - 2));
        $domParts = explode('.', $domain);
        $domName = $domParts[0];
        $maskedDom = mb_substr($domName, 0, 2) . str_repeat('*', max(3, mb_strlen($domName) - 2));
        $domParts[0] = $maskedDom;
        return $maskedLocal . '@' . implode('.', $domParts);
    }

    /**
     * Masks a phone number string.
     *
     * Example: converts "+8801700000000" to "+880****0000".
     *
     * @param string $phone The raw phone number.
     * @return string The masked phone number.
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
     * Masks a payment card number, retaining only the final four digits.
     *
     * Example: converts "1234567890123456" to "************3456".
     *
     * @param string $number The raw card number.
     * @return string The masked card number.
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
     * Masks an IP address (IPv4 or IPv6) for logging privacy.
     *
     * Example IPv4: "192.168.1.1" to "192.168.***.***".
     * Example IPv6: masks the final four segment blocks.
     *
     * @param string $ip The raw IP address string.
     * @return string The masked IP address string.
     */
    public static function ip(string $ip): string
    {
        $parts = explode('.', $ip);
        if (count($parts) === 4) {
            return $parts[0] . '.' . $parts[1] . '.***.' . '***';
        }
        // Mask the trailing four segment groups for IPv6.
        $parts6 = explode(':', $ip);
        if (count($parts6) >= 4) {
            $visible = array_slice($parts6, 0, 4);
            return implode(':', $visible) . ':****:****:****:****';
        }
        return '***';
    }

    /**
     * Performs a generic masking operation on a string or recursively on an array.
     *
     * @param array<string, mixed>|string $value The raw input string or nested array.
     * @param int $showFirst The number of initial characters to leave visible.
     * @param int $showLast The number of final characters to leave visible.
     * @return array<string, mixed>|string The masked representation matching the input structure.
     */
    public static function mask(array|string $value, int $showFirst = 2, int $showLast = 2): array|string
    {
        if (is_array($value)) {
            $result = [];
            foreach ($value as $k => $v) {
                $lk = strtolower((string)$k);
                if (is_array($v)) {
                    $stringKeyed = [];
                    foreach ($v as $subK => $subV) {
                        $stringKeyed[(string) $subK] = $subV;
                    }
                    $result[$k] = self::mask($stringKeyed, $showFirst, $showLast);
                } elseif (is_string($v)) {
                    if ($lk === 'email') {
                        $result[$k] = self::email($v);
                    } elseif ($lk === 'phone') {
                        $result[$k] = self::phone($v);
                    } elseif ($lk === 'name') {
                        $result[$k] = self::mask($v, 1, 1);
                    } else {
                        $result[$k] = $v;
                    }
                } else {
                    $result[$k] = $v;
                }
            }
            return $result;
        }

        $len = mb_strlen($value);
        if ($len <= $showFirst + $showLast) {
            return str_repeat('*', $len);
        }
        return mb_substr($value, 0, $showFirst)
            . str_repeat('*', $len - $showFirst - $showLast)
            . mb_substr($value, -$showLast);
    }

    /**
     * Alias method for email masking operations.
     *
     * @param string $email The raw email address.
     * @return string The masked email address.
     */
    public static function maskEmail(string $email): string
    {
        return self::email($email);
    }

    /**
     * Alias method for phone masking operations.
     *
     * @param string $phone The raw phone number.
     * @return string The masked phone number.
     */
    public static function maskPhone(string $phone): string
    {
        return self::phone($phone);
    }
}
