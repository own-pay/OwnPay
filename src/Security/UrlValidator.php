<?php
declare(strict_types=1);

namespace OwnPay\Security;

/**
 * URL validator â€” validates and sanitizes URLs for redirects and webhooks.
 *
 * Per OWASP: prevent SSRF, open redirect, protocol injection.
 */
final class UrlValidator
{
    /** Allowed schemes for redirects */
    private const ALLOWED_SCHEMES = ['http', 'https'];

    /** Blocked private IP ranges (SSRF prevention) */
    private const BLOCKED_RANGES = [
        '10.0.0.0/8',
        '172.16.0.0/12',
        '192.168.0.0/16',
        '127.0.0.0/8',
        '169.254.0.0/16',
        '0.0.0.0/8',
    ];

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
            // Direct IP â€” check if private
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
     * Sanitize URL â€” remove javascript:, data:, and other dangerous schemes.
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
