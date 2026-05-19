<?php
declare(strict_types=1);

namespace OwnPay\Security;

/**
 * Security helpers — CSRF token gen, secure random, input sanitization.
 *
 * Per OWASP: cryptographically secure random, output encoding.
 */
final class SecurityHelpers
{
    /**
     * Generate or retrieve CSRF token for current session.
     */
    public static function csrfToken(): string
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return '';
        }
        if (empty($_SESSION['_csrf_token'])) {
            $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['_csrf_token'];
    }

    /**
     * Generate CSRF hidden input field.
     */
    public static function csrfField(): string
    {
        $token = self::csrfToken();
        return '<input type="hidden" name="_csrf_token" value="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '">';
    }

    /**
     * Generate cryptographically secure random string.
     */
    public static function randomString(int $length = 32): string
    {
        return bin2hex(random_bytes((int) ceil($length / 2)));
    }

    /**
     * Sanitize string for safe HTML output.
     */
    public static function escapeHtml(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Sanitize for use in URL parameter.
     */
    public static function escapeUrl(string $value): string
    {
        return rawurlencode($value);
    }

    /**
     * Sanitize filename — remove path traversal, special chars.
     */
    public static function sanitizeFilename(string $filename): string
    {
        // Remove path components
        $filename = basename($filename);
        // Remove non-alphanumeric except dot, dash, underscore
        $filename = preg_replace('/[^a-zA-Z0-9._-]/', '_', $filename) ?? $filename;
        // Remove leading dots (hidden files)
        $filename = ltrim($filename, '.');
        // Limit length
        return mb_substr($filename, 0, 200);
    }

    /**
     * Sanitize slug — lowercase, alphanumeric + dashes only.
     */
    public static function sanitizeSlug(string $value): string
    {
        $slug = strtolower(trim($value));
        $slug = preg_replace('/[^a-z0-9-]/', '-', $slug) ?? $slug;
        $slug = preg_replace('/-+/', '-', $slug) ?? $slug;
        return trim($slug, '-');
    }

    /**
     * Constant-time string comparison.
     */
    public static function timingSafeEquals(string $known, string $user): bool
    {
        return hash_equals($known, $user);
    }

    /**
     * Generate API key: op_XXXXXXXX.YYYYYYYYYYYY
     * Returns [full_key, prefix, hash].
     *
     * @return array{key: string, prefix: string, hash: string}
     */
    public static function generateApiKey(): array
    {
        $prefix = bin2hex(random_bytes(4)); // 8 hex chars
        $secret = bin2hex(random_bytes(24)); // 48 hex chars
        $fullKey = "op_{$prefix}.{$secret}";

        return [
            'key'    => $fullKey,
            'prefix' => $prefix,
            'hash'   => hash('sha256', $fullKey),
        ];
    }
}
