<?php
declare(strict_types=1);

/**
 * OwnPay Landing Page Configuration
 * File: config/config.php
 */

// Define application path constants
define('ROOT_PATH', dirname(__DIR__));
define('APP_PATH', ROOT_PATH . '/app');
define('TEMPLATE_PATH', ROOT_PATH . '/templates');
define('PUBLIC_PATH', ROOT_PATH . '/public');

// 1. Helper to load .env variables from the main project root
function loadEnv(string $path): void
{
    if (!file_exists($path)) {
        return;
    }
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if (empty($line) || str_starts_with($line, '#')) {
            continue;
        }
        if (str_contains($line, '=')) {
            list($key, $value) = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);
            // Strip wrapping quotes
            if (str_starts_with($value, '"') && str_ends_with($value, '"')) {
                $value = substr($value, 1, -1);
            } elseif (str_starts_with($value, "'") && str_ends_with($value, "'")) {
                $value = substr($value, 1, -1);
            }
            if (!array_key_exists($key, $_SERVER) && !array_key_exists($key, $_ENV)) {
                putenv("{$key}={$value}");
                $_ENV[$key] = $value;
                $_SERVER[$key] = $value;
            }
        }
    }
}

// Load env from own project root
loadEnv(ROOT_PATH . '/.env');

// Fallbacks for DB credentials
define('DB_HOST', $_ENV['DB_HOST'] ?? 'localhost');
define('DB_PORT', (int)($_ENV['DB_PORT'] ?? 3306));
define('DB_NAME', $_ENV['DB_NAME'] ?? 'ownpay');
define('DB_USER', $_ENV['DB_USER'] ?? 'root');
define('DB_PASS', $_ENV['DB_PASS'] ?? 'root');

// App Configs
define('APP_URL', $_ENV['APP_URL'] ?? 'https://ownpay.test');
define('APP_DEBUG', filter_var($_ENV['APP_DEBUG'] ?? false, FILTER_VALIDATE_BOOLEAN));
define('BCRYPT_COST', (int)($_ENV['BCRYPT_COST'] ?? 12));
define('ENCRYPTION_KEY', $_ENV['ENCRYPTION_KEY'] ?? 'base64:FBndSfu4yW/mZSxs5QzToXZooIIzD4NwOS2Ppbs9Hkg=');

// 2. Encryption helpers for secure database config storage (AES-256-CBC)
class ConfigEncryptor
{
    private static string $cipher = 'aes-256-cbc';

    private static function getRawKey(): string
    {
        $raw = ENCRYPTION_KEY;
        if (str_starts_with($raw, 'base64:')) {
            return base64_decode(substr($raw, 7));
        }
        return hash('sha256', $raw, true);
    }

    public static function encrypt(string $plainText): string
    {
        $key = self::getRawKey();
        $ivlen = openssl_cipher_iv_length(self::$cipher);
        $iv = openssl_random_pseudo_bytes($ivlen);
        $ciphertext_raw = openssl_encrypt($plainText, self::$cipher, $key, OPENSSL_RAW_DATA, $iv);
        $hmac = hash_hmac('sha256', $ciphertext_raw, $key, true);
        return base64_encode($iv . $hmac . $ciphertext_raw);
    }

    public static function decrypt(string $cipherText): ?string
    {
        try {
            $key = self::getRawKey();
            $c = base64_decode($cipherText);
            $ivlen = openssl_cipher_iv_length(self::$cipher);
            $iv = substr($c, 0, $ivlen);
            $hmac = substr($c, $ivlen, 32);
            $ciphertext_raw = substr($c, $ivlen + 32);
            $original_plaintext = openssl_decrypt($ciphertext_raw, self::$cipher, $key, OPENSSL_RAW_DATA, $iv);
            $calcmac = hash_hmac('sha256', $ciphertext_raw, $key, true);
            if (hash_equals($hmac, $calcmac)) {
                return $original_plaintext ?: null;
            }
        } catch (Throwable $e) {
            // Decryption failure
        }
        return null;
    }
}
