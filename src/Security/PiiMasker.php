<?php

declare(strict_types=1);

namespace OwnPay\Security;

/**
 * PiiMasker — masks PII in API response data.
 *
 * Masking rules:
 *   email:  john@example.com    → j***@e***.com
 *   phone:  +8801712345678      → +880***5678
 *   name:   John Doe            → J*** D***
 *   card:   4111111111111111    → ****1111
 *   ip:     192.168.1.100       → 192.168.*.*
 */
final class PiiMasker
{
    /**
     * Default fields to mask and their masking type.
     */
    private const DEFAULT_RULES = [
        'email' => 'email',
        'customer_email' => 'email',
        'phone' => 'phone',
        'customer_phone' => 'phone',
        'mobile' => 'phone',
        'name' => 'name',
        'customer_name' => 'name',
        'first_name' => 'name',
        'last_name' => 'name',
        'card_number' => 'card',
        'pan' => 'card',
        'ip' => 'ip',
        'ip_address' => 'ip',
        'last_used_ip' => 'ip',
        'source_ip' => 'ip',
    ];

    /** @var array<string, string> Field name → mask type */
    private array $rules;

    /**
     * @param array<string, string> $additionalRules Extra masking rules to merge
     */
    public function __construct(array $additionalRules = [])
    {
        $this->rules = array_merge(self::DEFAULT_RULES, $additionalRules);
    }

    /**
     * Mask PII fields in a single associative array.
     *
     * @param array $data The data to mask
     * @return array Masked data
     */
    public function mask(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = $this->mask($value); // Recurse
                continue;
            }

            if (!is_string($value) || $value === '') {
                continue;
            }

            $maskType = $this->rules[$key] ?? null;
            if ($maskType !== null) {
                $data[$key] = $this->applyMask($value, $maskType);
            }
        }

        return $data;
    }

    /**
     * Mask an array of records (e.g. paginated list).
     */
    public function maskArray(array $records): array
    {
        return array_map(fn(array $record) => $this->mask($record), $records);
    }

    /**
     * Mask a single value by type.
     */
    public function maskValue(string $value, string $type): string
    {
        return $this->applyMask($value, $type);
    }

    /**
     * Apply masking based on type.
     */
    private function applyMask(string $value, string $type): string
    {
        return match ($type) {
            'email' => $this->maskEmail($value),
            'phone' => $this->maskPhone($value),
            'name' => $this->maskName($value),
            'card' => $this->maskCard($value),
            'ip' => $this->maskIp($value),
            'full' => '[REDACTED]',
            default => $value,
        };
    }

    /**
     * Mask email: john@example.com → j***@e***.com
     */
    private function maskEmail(string $email): string
    {
        $parts = explode('@', $email, 2);
        if (count($parts) !== 2) {
            return '***@***.***';
        }

        [$local, $domain] = $parts;
        $domainParts = explode('.', $domain);
        $tld = array_pop($domainParts);
        $domainBase = implode('.', $domainParts);

        $maskedLocal = $this->maskString($local);
        $maskedDomain = $this->maskString($domainBase);

        return "{$maskedLocal}@{$maskedDomain}.{$tld}";
    }

    /**
     * Mask phone: +8801712345678 → +880***5678
     */
    private function maskPhone(string $phone): string
    {
        // Strip non-digit except leading +
        $hasPlus = str_starts_with($phone, '+');
        $digits = preg_replace('/\D/', '', $phone);

        if (strlen($digits) < 6) {
            return '***';
        }

        $prefix = substr($digits, 0, 3);
        $suffix = substr($digits, -4);
        $masked = ($hasPlus ? '+' : '') . $prefix . '***' . $suffix;

        return $masked;
    }

    /**
     * Mask name: John Doe → J*** D***
     */
    private function maskName(string $name): string
    {
        $words = explode(' ', trim($name));
        $masked = array_map(function (string $word) {
            if (strlen($word) <= 1) {
                return $word;
            }
            return $word[0] . str_repeat('*', min(3, strlen($word) - 1));
        }, $words);

        return implode(' ', $masked);
    }

    /**
     * Mask card: 4111111111111111 → ****1111
     */
    private function maskCard(string $card): string
    {
        $digits = preg_replace('/\D/', '', $card);
        if (strlen($digits) < 4) {
            return '****';
        }
        return '****' . substr($digits, -4);
    }

    /**
     * Mask IP: 192.168.1.100 → 192.168.*.*
     */
    private function maskIp(string $ip): string
    {
        // IPv4
        if (str_contains($ip, '.')) {
            $octets = explode('.', $ip);
            if (count($octets) === 4) {
                return "{$octets[0]}.{$octets[1]}.*.*";
            }
        }

        // IPv6 — mask the host portion
        if (str_contains($ip, ':')) {
            $parts = explode(':', $ip);
            $keep = min(3, count($parts));
            $masked = array_slice($parts, 0, $keep);
            return implode(':', $masked) . '::***';
        }

        return '***';
    }

    /**
     * Mask a string: keep first char, replace rest with ***.
     */
    private function maskString(string $str): string
    {
        if (strlen($str) <= 1) {
            return $str;
        }
        return $str[0] . str_repeat('*', min(3, strlen($str) - 1));
    }
}
