<?php
declare(strict_types=1);

namespace OwnPay\Security;

/**
 * Class FieldEncryptor
 *
 * Implements AES-256-GCM field-level encryption for securing Personally Identifiable Information (PII) at rest
 * compliant with PCI DSS and OWASP guidelines. Utilizes a cryptographically secure pseudo-random Initialization Vector (IV)
 * per operation, generating authenticated ciphertext via Galois/Counter Mode (GCM).
 *
 * @package OwnPay\Security
 */
class FieldEncryptor
{
    private const CIPHER = 'aes-256-gcm';
    private const TAG_LENGTH = 16;

    /**
     * @var string The 32-byte derived cryptographic key.
     */
    private string $key;

    /**
     * FieldEncryptor constructor.
     *
     * Initializes the encryption key from environment configurations and derives a 32-byte cryptographic key.
     *
     * @param string|null $key Optional raw key string override.
     * @throws \RuntimeException If the ENCRYPTION_KEY is not configured.
     */
    public function __construct(?string $key = null)
    {
        $envKey = $_ENV['ENCRYPTION_KEY'] ?? $_ENV['APP_KEY'] ?? null;
        $envKeyStr = is_string($envKey) ? $envKey : '';
        if ($envKeyStr === '') {
            $getenvKey = getenv('ENCRYPTION_KEY');
            $envKeyStr = is_string($getenvKey) ? $getenvKey : '';
        }
        if ($envKeyStr === '') {
            $getAppKey = getenv('APP_KEY');
            $envKeyStr = is_string($getAppKey) ? $getAppKey : '';
        }
        $this->key = $key ?? $envKeyStr;
        if ($this->key === '') {
            throw new \RuntimeException('ENCRYPTION_KEY not configured');
        }
        // Derive 32-byte key from whatever was provided.
        $this->key = hash('sha256', $this->key, true);
    }

    /**
     * Encrypts plaintext into a base64-encoded representation containing the IV, authorization tag, and ciphertext.
     *
     * @param string $plaintext The sensitive raw text to encrypt.
     * @return string The base64-encoded representation of the concatenated IV, tag, and ciphertext payload.
     * @throws \RuntimeException If the cryptographic encryption operation fails.
     */
    public function encrypt(string $plaintext): string
    {
        $ivLen = openssl_cipher_iv_length(self::CIPHER);
        if ($ivLen <= 0) {
            throw new \RuntimeException('Failed to resolve IV length');
        }
        $iv = random_bytes($ivLen);
        $tag = '';

        $ciphertext = openssl_encrypt(
            $plaintext,
            self::CIPHER,
            $this->key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '',
            self::TAG_LENGTH
        );

        if ($ciphertext === false) {
            throw new \RuntimeException('Encryption failed');
        }

        // Pack: IV (12) + TAG (16) + CIPHERTEXT.
        return base64_encode($iv . $tag . $ciphertext);
    }

    /**
     * Decrypts a base64-encoded encrypted string payload back to its original plaintext.
     *
     * Features cryptographic key rotation fallback compatibility via the ENCRYPTION_KEY_OLD configuration.
     *
     * @param string $encoded The base64-encoded string representing the concatenated IV, tag, and ciphertext payload.
     * @return string The decrypted raw plaintext.
     * @throws \RuntimeException If the payload structure is invalid, key resolution fails, or decryption is rejected (tampered/corrupted data).
     */
    public function decrypt(string $encoded): string
    {
        $data = base64_decode($encoded, true);
        if ($data === false) {
            throw new \RuntimeException('Invalid encrypted data');
        }

        $ivLen = openssl_cipher_iv_length(self::CIPHER);
        if (strlen($data) < $ivLen + self::TAG_LENGTH + 1) {
            throw new \RuntimeException('Encrypted data too short');
        }

        $iv = substr($data, 0, $ivLen);
        $tag = substr($data, $ivLen, self::TAG_LENGTH);
        $ciphertext = substr($data, $ivLen + self::TAG_LENGTH);

        $plaintext = openssl_decrypt(
            $ciphertext,
            self::CIPHER,
            $this->key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );

        // Key rotation support - try old key if current key fails.
        // Set ENCRYPTION_KEY_OLD in .env during rotation window.
        if ($plaintext === false) {
            $oldKeyRawVal = $_ENV['ENCRYPTION_KEY_OLD'] ?? null;
            $oldKeyRaw = is_string($oldKeyRawVal) ? $oldKeyRawVal : '';
            if ($oldKeyRaw === '') {
                $getOldKey = getenv('ENCRYPTION_KEY_OLD');
                $oldKeyRaw = is_string($getOldKey) ? $getOldKey : '';
            }
            if ($oldKeyRaw !== '') {
                $oldKey = hash('sha256', $oldKeyRaw, true);
                $plaintext = openssl_decrypt(
                    $ciphertext,
                    self::CIPHER,
                    $oldKey,    
                    OPENSSL_RAW_DATA,
                    $iv,
                    $tag
                );
            }
        }

        if ($plaintext === false) {
            throw new \RuntimeException('Decryption failed - data may be tampered');
        }

        return $plaintext;
    }

    /**
     * Generates a deterministic hash for indexed database lookups using HMAC-SHA-256.
     *
     * Used to query encrypted PII fields (e.g. email_hash, phone_hash) without exposing the raw plaintext values.
     *
     * @param string $value The raw input value.
     * @return string The deterministic hex-encoded HMAC representation.
     */
    public function hash(string $value): string
    {
        return hash_hmac('sha256', strtolower(trim($value)), $this->key);
    }

    /**
     * Alias for hash() to support standard deterministic HMAC PII lookups.
     *
     * @param string $value The raw input value.
     * @return string The deterministic hex-encoded HMAC representation.
     */
    public function deterministicHash(string $value): string
    {
        return $this->hash($value);
    }
}
