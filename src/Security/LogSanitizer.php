<?php
declare(strict_types=1);

namespace OwnPay\Security;

/**
 * Log sanitizer â€” strips sensitive fields before logging.
 *
 * Per OWASP + security skill: never log passwords, tokens, keys, PII.
 */
final class LogSanitizer
{
    /** Fields to completely redact */
    private const REDACT_KEYS = [
        'password', 'password_hash', 'password_confirm',
        'totp_secret',
        'secret', 'key_hash', 'api_key', 'bearer_token',
        'jwt', 'token', 'refresh_token',
        'credentials_enc', 'webhook_secret',
        'credit_card', 'card_number', 'cvv', 'cvc',
        'ssn', 'social_security',
    ];

    /** Fields to mask (show partial) */
    private const MASK_KEYS = [
        'email', 'phone', 'name', 'ip_address',
        'email_enc', 'phone_enc', 'name_enc',
    ];

    /**
     * Sanitize array for logging.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public static function sanitize(array $data): array
    {
        $result = [];
        foreach ($data as $key => $value) {
            $lowerKey = strtolower($key);

            // Full redaction
            if (in_array($lowerKey, self::REDACT_KEYS, true) || self::containsSensitiveKey($lowerKey)) {
                $result[$key] = '[REDACTED]';
                continue;
            }

            // Partial mask
            if (in_array($lowerKey, self::MASK_KEYS, true)) {
                $result[$key] = is_string($value) ? PiiMasker::mask($value) : '[REDACTED]';
                continue;
            }

            // Recurse into nested arrays
            if (is_array($value)) {
                $result[$key] = self::sanitize($value);
                continue;
            }

            $result[$key] = $value;
        }
        return $result;
    }

    /**
     * Sanitize a string message (strip inline secrets).
     */
    public static function sanitizeMessage(string $message): string
    {
        // Mask bearer tokens
        $message = preg_replace('/Bearer\s+[A-Za-z0-9._-]+/i', 'Bearer [REDACTED]', $message) ?? $message;
        // Mask API keys
        $message = preg_replace('/op_[a-zA-Z0-9]{8}\.[a-zA-Z0-9]+/', 'op_[REDACTED]', $message) ?? $message;
        // Mask JWTs
        $message = preg_replace('/eyJ[A-Za-z0-9_-]+\.eyJ[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+/', '[JWT_REDACTED]', $message) ?? $message;

        return $message;
    }

    private static function containsSensitiveKey(string $key): bool
    {
        $patterns = ['_secret', '_key', '_token', '_hash'];
        foreach ($patterns as $pattern) {
            if (str_contains($key, $pattern)) {
                return true;
            }
        }
        return false;
    }
}
