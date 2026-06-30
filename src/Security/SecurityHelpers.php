<?php
declare(strict_types=1);

namespace OwnPay\Security;

/**
 * Class SecurityHelpers
 *
 * Provides cryptographic and sanitization utility methods, including CSRF token management,
 * secure random generation, XSS prevention escaping, filename cleansing, constant-time comparison,
 * and secure API key generation.
 *
 * @package OwnPay\Security
 */
final class SecurityHelpers
{
    /**
     * Resolves, generates, or retrieves the CSRF token associated with the current session.
     *
     * @return string The hex-encoded CSRF token, or an empty string if session is inactive.
     */
    public static function csrfToken(): string
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return '';
        }
        if (empty($_SESSION['_csrf_token'])) {
            $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
        }
        $token = $_SESSION['_csrf_token'] ?? '';
        return is_string($token) ? $token : '';
    }

    /**
     * Generates a pre-formatted HTML hidden input field containing the active CSRF token.
     *
     * @return string The HTML hidden input tag.
     */
    public static function csrfField(): string
    {
        $token = self::csrfToken();
        return '<input type="hidden" name="_csrf_token" value="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '">';
    }

    /**
     * Generates a cryptographically secure random hexadecimal string.
     *
     * @param int $length The desired length of the returned string. Defaults to 32.
     * @return string The hex-encoded secure random string.
     */
    public static function randomString(int $length = 32): string
    {
        $byteLength = (int) ceil($length / 2);
        return bin2hex(random_bytes($byteLength > 0 ? $byteLength : 1));
    }

    /**
     * Escapes input string to make it safe for rendering in an HTML context (XSS mitigation).
     *
     * @param string $value The raw input string.
     * @return string The escaped safe HTML string.
     */
    public static function escapeHtml(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * URL-encodes a string parameter value safely according to RFC 3986.
     *
     * @param string $value The raw parameter string.
     * @return string The URL-encoded parameter string.
     */
    public static function escapeUrl(string $value): string
    {
        return rawurlencode($value);
    }

    /**
     * Cleanses a filename to mitigate path-traversal attacks and eliminate non-alphanumeric characters.
     *
     * Replaces disallowed characters with underscores and truncates lengths exceeding 200 characters.
     *
     * @param string $filename The raw filename.
     * @return string The sanitized safe filename.
     */
    public static function sanitizeFilename(string $filename): string
    {
        // Extract basic filename components to prevent directory traversal paths.
        $filename = basename($filename);
        // Replace non-alphanumeric characters (excluding dot, dash, and underscore) with underscores.
        $filename = preg_replace('/[^a-zA-Z0-9._-]/', '_', $filename) ?? $filename;
        // Strip leading periods to handle potential hidden file vulnerabilities.
        $filename = ltrim($filename, '.');
        // Truncate the filename length to prevent filesystem limits.
        return mb_substr($filename, 0, 200);
    }

    /**
     * Standardizes a string into a lowercase URL slug containing only alphanumeric characters and hyphens.
     *
     * @param string $value The raw input string.
     * @return string The formatted slug.
     */
    public static function sanitizeSlug(string $value): string
    {
        $slug = strtolower(trim($value));
        $slug = preg_replace('/[^a-z0-9-]/', '-', $slug) ?? $slug;
        $slug = preg_replace('/-+/', '-', $slug) ?? $slug;
        return trim($slug, '-');
    }

    /**
     * Compares two strings in constant-time to mitigate side-channel timing attacks.
     *
     * @param string $known The verified known string.
     * @param string $user The user-supplied input string.
     * @return bool True if both strings match, otherwise false.
     */
    public static function timingSafeEquals(string $known, string $user): bool
    {
        return hash_equals($known, $user);
    }

    /**
     * Generates a new API key pair conforming to the format 'op_{prefix}.{secret}'.
     *
     * @return array{key: string, prefix: string, hash: string} An array containing the full key, prefix, and sha256 hash.
     */
    public static function generateApiKey(): array
    {
        $prefix = bin2hex(random_bytes(4)); // 8 hex chars.
        $secret = bin2hex(random_bytes(24)); // 48 hex chars.
        $fullKey = "op_{$prefix}.{$secret}";

        return [
            'key'    => $fullKey,
            'prefix' => $prefix,
            'hash'   => hash('sha256', $fullKey),
        ];
    }
}
