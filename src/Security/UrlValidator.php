<?php
declare(strict_types=1);

namespace OwnPay\Security;

/**
 * URL validator — validates and sanitizes URLs for redirects and webhooks.
 *
 * Per OWASP: prevent SSRF, open redirect, protocol injection.
 */
final class UrlValidator
{
    /** Allowed schemes for redirects */
    private const ALLOWED_SCHEMES = ['http', 'https'];

    /** Blocked private IP ranges (SSRF prevention) */
    /** @phpstan-ignore classConstant.unused */
    private const BLOCKED_RANGES = [
        '10.0.0.0/8',
        '172.16.0.0/12',
        '192.168.0.0/16',
        '127.0.0.0/8',
        '169.254.0.0/16',
        '0.0.0.0/8',
    ];

        /**
     * Validate URL is safe for outbound client/server calls (no SSRF/open redirect).
     */
    public static function isSafeOutbound(string $url, ?string &$reason = null): bool
    {
        $url = trim($url);
        if ($url === '') {
            $reason = 'missing';
            return false;
        }

        $parsed = parse_url($url);
        if ($parsed === false || !isset($parsed['scheme'])) {
            $reason = 'scheme';
            return false;
        }

        $scheme = strtolower($parsed['scheme']);
        if (!in_array($scheme, self::ALLOWED_SCHEMES, true)) {
            $reason = 'scheme';
            return false;
        }

        if (isset($parsed['user']) || isset($parsed['pass'])) {
            $reason = 'userinfo';
            return false;
        }

        if (!isset($parsed['host'])) {
            $reason = 'scheme';
            return false;
        }

        $host = strtolower($parsed['host']);
        
        if ($host === 'localhost' || $host === '0.0.0.0' || $host === '[::]' || $host === '[::1]') {
            $reason = $host;
            return false;
        }

        $ip = $host;
        if (str_starts_with($ip, '[') && str_ends_with($ip, ']')) {
            $ip = substr($ip, 1, -1);
        }

        if (filter_var($ip, FILTER_VALIDATE_IP) !== false) {
            if ($ip === '127.0.0.1' || str_starts_with($ip, '127.')) {
                $reason = $ip;
                return false;
            }
            if (self::isPrivateIp($ip)) {
                $reason = $ip;
                return false;
            }
            if ($ip === '169.254.169.254' || str_starts_with($ip, '169.254.')) {
                $reason = '169.254';
                return false;
            }
            if ($ip === '::1') {
                $reason = '::1';
                return false;
            }
        }

        return true;
    }

/**
     * Validate URL is safe for redirect (no open redirect).
     */
    public static function isValidRedirect(string $url, ?string $allowedDomain = null): bool
    {
        // Must be absolute URL
        $parsed = parse_url($url);
        if ($parsed === false || !isset($parsed['scheme'], $parsed['host'])) {
            return false;
        }

        // Scheme check
        if (!in_array(strtolower($parsed['scheme']), self::ALLOWED_SCHEMES, true)) {
            return false;
        }

        // Domain restriction
        if ($allowedDomain !== null) {
            $host = strtolower($parsed['host']);
            if ($host !== strtolower($allowedDomain) && !str_ends_with($host, '.' . strtolower($allowedDomain))) {
                return false;
            }
        }

        return true;
    }

    /**
     * Validate URL is safe for server-side request (no SSRF).
     */
    public static function isValidWebhookUrl(string $url): bool
    {
        $parsed = parse_url($url);
        if ($parsed === false || !isset($parsed['scheme'], $parsed['host'])) {
            return false;
        }

        // HTTPS only for webhooks
        if (strtolower($parsed['scheme']) !== 'https') {
            return false;
        }

        // No IP addresses (force DNS resolution)
        $host = $parsed['host'];
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            // Direct IP — check if private
            if (self::isPrivateIp($host)) {
                return false;
            }
        }

        // Resolve hostname and check resolved IPs
        $ips = gethostbynamel($host);
        if ($ips === false || empty($ips)) {
            return false;
        }

        foreach ($ips as $ip) {
            if (self::isPrivateIp($ip)) {
                return false;
            }
        }

        // No localhost variants
        $blocked = ['localhost', '0.0.0.0', '[::]', '[::1]'];
        if (in_array(strtolower($host), $blocked, true)) {
            return false;
        }

        return true;
    }

    /**
     * Sanitize URL — remove javascript:, data:, and other dangerous schemes.
     */
    public static function sanitize(string $url): string
    {
        $url = trim($url);

        // Block dangerous schemes
        $dangerous = ['javascript:', 'data:', 'vbscript:', 'file:'];
        foreach ($dangerous as $scheme) {
            if (stripos($url, $scheme) === 0) {
                return '';
            }
        }

        return filter_var($url, FILTER_SANITIZE_URL) ?: '';
    }

    /**
     * Check if IP is in private/reserved range.
     */
    private static function isPrivateIp(string $ip): bool
    {
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) === false;
    }
}
