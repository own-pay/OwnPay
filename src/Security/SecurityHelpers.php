<?php

declare(strict_types=1);

namespace OwnPay\Security;

/**
 * Central security utility functions for Own Pay.
 *
 * Addresses audit findings SEC-01 through SEC-12.
 * All functions are stateless and safe for concurrent use.
 */
final class SecurityHelpers
{
    /**
     * XSS-safe output encoder (SEC-06).
     *
     * Use in all HTML output: <?= SecurityHelpers::e($value) ?>
     * Shortcut: the global `e()` function wraps this.
     */
    public static function e(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Generate a cryptographically secure random string (SEC-10).
     *
     * Replaces insecure str_shuffle/mt_rand patterns.
     *
     * @param int    $length   Desired output length
     * @param string $alphabet Character set to draw from
     */
    public static function generateSecureRandom(
        int $length = 32,
        string $alphabet = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz'
    ): string {
        $max = strlen($alphabet) - 1;
        $result = '';

        for ($i = 0; $i < $length; $i++) {
            $result .= $alphabet[random_int(0, $max)];
        }

        return $result;
    }

    /**
     * Generate a strong password with guaranteed character class diversity (SEC-10).
     */
    public static function generateStrongPassword(int $length = 16): string
    {
        $upper = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $lower = 'abcdefghijklmnopqrstuvwxyz';
        $digits = '0123456789';
        $symbols = '@#$%&!*^-_=+';
        $all = $upper . $lower . $digits . $symbols;

        // Guarantee at least one of each class
        $password = $upper[random_int(0, strlen($upper) - 1)];
        $password .= $lower[random_int(0, strlen($lower) - 1)];
        $password .= $digits[random_int(0, strlen($digits) - 1)];
        $password .= $symbols[random_int(0, strlen($symbols) - 1)];

        // Fill the rest
        for ($i = 4; $i < $length; $i++) {
            $password .= $all[random_int(0, strlen($all) - 1)];
        }

        // Shuffle the result securely
        $chars = str_split($password);
        for ($i = count($chars) - 1; $i > 0; $i--) {
            $j = random_int(0, $i);
            [$chars[$i], $chars[$j]] = [$chars[$j], $chars[$i]];
        }

        return implode('', $chars);
    }

    /**
     * Verify an HMAC signature with timing-safe comparison (SEC-02, SEC-03).
     *
     * @param string $payload   The raw payload that was signed
     * @param string $signature The received signature to verify
     * @param string $secret    The shared secret
     * @param string $algo      Hash algorithm (default: sha256)
     */
    public static function verifyHmacSignature(
        string $payload,
        string $signature,
        string $secret,
        string $algo = 'sha256'
    ): bool {
        $expected = hash_hmac($algo, $payload, $secret);
        return hash_equals($expected, $signature);
    }

    /**
     * Validate that a timestamp is within the freshness window (SEC-02).
     *
     * @param string|int $timestamp Unix timestamp to check
     * @param int        $maxSkew   Maximum allowed skew in seconds (default: 300 = 5 min)
     */
    public static function isTimestampFresh(string|int $timestamp, int $maxSkew = 300): bool
    {
        if (!ctype_digit((string) $timestamp)) {
            return false;
        }

        return abs(time() - (int) $timestamp) <= $maxSkew;
    }

    /**
     * Sanitize a zip entry path to prevent zip slip attacks (SEC-05).
     *
     * @param string $entryPath The path from the zip entry
     * @param string $targetDir The intended extraction directory
     * @return string|false     Clean path or false if traversal detected
     */
    public static function sanitizeZipEntry(string $entryPath, string $targetDir): string|false
    {
        // Normalize separators
        $entryPath = str_replace('\\', '/', $entryPath);

        // Reject traversal patterns
        if (
            str_contains($entryPath, '../') ||
            str_contains($entryPath, '..\\') ||
            str_starts_with($entryPath, '/')
        ) {
            error_log("[SecurityHelpers] Zip slip detected: {$entryPath}");
            return false;
        }

        // Build the full target path and verify it's inside the target dir
        $fullPath = realpath($targetDir) . DIRECTORY_SEPARATOR . $entryPath;
        $realTarget = realpath($targetDir);

        if ($realTarget === false) {
            return false;
        }

        // Ensure the resolved path is under the target directory
        if (!str_starts_with($fullPath, $realTarget . DIRECTORY_SEPARATOR)) {
            error_log("[SecurityHelpers] Zip entry escapes target dir: {$fullPath}");
            return false;
        }

        return $fullPath;
    }

    /**
     * Validate a file's integrity using SHA-256 (SEC-05).
     * Replaces sha1_file usage.
     */
    public static function verifyFileHash(string $filePath, string $expectedHash): bool
    {
        if (!file_exists($filePath)) {
            return false;
        }

        $actualHash = hash_file('sha256', $filePath);
        return hash_equals($expectedHash, $actualHash);
    }

    /**
     * Validate an uploaded file's MIME type using finfo (SEC-11).
     *
     * @param string $tmpPath       Path to the uploaded temp file
     * @param array  $allowedMimes  Allowed MIME types
     */
    public static function validateUploadMime(string $tmpPath, array $allowedMimes): bool
    {
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($tmpPath);

        return in_array($mime, $allowedMimes, true);
    }

    /**
     * Get enhanced security headers for all responses (SEC-12).
     *
     * @return array<string, string>
     */
    public static function getSecurityHeaders(): array
    {
        return [
            'X-Frame-Options' => 'SAMEORIGIN',
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=()',
            'X-XSS-Protection' => '1; mode=block',
            'Content-Security-Policy' => "default-src 'self'; script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com; img-src 'self' data: https:; connect-src 'self'; frame-ancestors 'self';",
        ];
    }

    /**
     * Apply security headers to the current response (SEC-12).
     */
    public static function applySecurityHeaders(): void
    {
        if (headers_sent()) {
            return;
        }

        foreach (self::getSecurityHeaders() as $name => $value) {
            header("{$name}: {$value}");
        }

        // HSTS only on HTTPS
        if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
        }
    }

    /**
     * Get secure session cookie parameters (SEC-08).
     */
    public static function getSecureSessionParams(): array
    {
        return [
            'lifetime' => 0,        // Session cookie (expires when browser closes)
            'path' => '/',
            'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
            'httponly' => true,
            'samesite' => 'Lax',
        ];
    }

    /**
     * Start a hardened session (SEC-08).
     */
    public static function startSecureSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        session_set_cookie_params(self::getSecureSessionParams());
        session_start();
    }

    /**
     * Regenerate session ID after authentication (SEC-08).
     */
    public static function regenerateSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }
}

// ── Global shortcut function ─────────────────────────────────────────

if (!function_exists('e')) {
    /**
     * XSS-safe output encoder.
     * Usage: <?= e($value) ?>
     */
    function e(mixed $value): string
    {
        return SecurityHelpers::e($value);
    }
}
