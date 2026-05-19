<?php
declare(strict_types=1);

namespace OwnPay\Security;

/**
 * AES-256-GCM field-level encryption for PII columns.
 *
 * Per pci-compliance skill: encrypt PII at rest, unique IV per operation.
 * Used for: customer name, email, phone stored in op_customers.
 */
final class FieldEncryptor
{
    private const CIPHER = 'aes-256-gcm';
    private const TAG_LENGTH = 16;

    private string $key;

    public function __construct(?string $key = null)
    {
        $this->key = $key ?? ($_ENV['ENCRYPTION_KEY'] ?? $_ENV['APP_KEY'] ?? (getenv('ENCRYPTION_KEY') ?: (getenv('APP_KEY') ?: '')));
        if ($this->key === '') {
            throw new \RuntimeException('ENCRYPTION_KEY not configured');
        }
        // Derive 32-byte key from whatever was provided
        $this->key = hash('sha256', $this->key, true);
    }

    /**
     * Encrypt plaintext -> base64(iv + tag + ciphertext).
     */
    public function encrypt(string $plaintext): string
    {
        $iv = random_bytes(openssl_cipher_iv_length(self::CIPHER));
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

        // Pack: IV (12) + TAG (16) + CIPHERTEXT
        return base64_encode($iv . $tag . $ciphertext);
    }

    /**
     * Decrypt base64(iv + tag + ciphertext) ─ plaintext.
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
            $oldKeyRaw = $_ENV['ENCRYPTION_KEY_OLD'] ?? (getenv('ENCRYPTION_KEY_OLD') ?: '');
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
     * Generate a deterministic hash for lookup (not reversible).
     * Used for email_hash, phone_hash columns.
     */
    public function hash(string $value): string
    {
        return hash_hmac('sha256', strtolower(trim($value)), $this->key);
    }

    /**
     * Alias for hash() — deterministic HMAC for PII lookups.
     */
    public function deterministicHash(string $value): string
    {
        return $this->hash($value);
    }
}
