<?php

declare(strict_types=1);

namespace OwnPay\Security;

use RuntimeException;

/**
 * FieldEncryptor — AES-256-GCM field-level encryption.
 *
 * Storage format: enc_v1:<nonce_hex>:<ciphertext_hex>:<tag_hex>
 *
 * The versioned prefix allows future algorithm migration without
 * breaking existing encrypted data.
 *
 * Usage:
 *   $enc = new FieldEncryptor();
 *   $cipher = $enc->encrypt('john@example.com');  // enc_v1:ab12...:cd34...:ef56...
 *   $plain  = $enc->decrypt($cipher);             // john@example.com
 */
final class FieldEncryptor
{
    private const ALGO = 'aes-256-gcm';
    private const VERSION = 'enc_v1';
    private const NONCE_LEN = 12;  // 96-bit nonce (GCM recommended)
    private const TAG_LEN = 16;  // 128-bit auth tag
    private const SEPARATOR = ':';

    private string $key;

    /**
     * @param string|null $hexKey 32-byte key as 64-char hex string.
     *                            Falls back to PII_ENCRYPTION_KEY env var.
     */
    public function __construct(?string $hexKey = null)
    {
        $hex = $hexKey ?? (getenv('PII_ENCRYPTION_KEY') ?: '');

        if (empty($hex) || strlen($hex) !== 64) {
            throw new RuntimeException(
                'PII_ENCRYPTION_KEY must be a 64-character hex string (32 bytes). ' .
                'Generate one with: php -r "echo bin2hex(random_bytes(32));"'
            );
        }

        $this->key = hex2bin($hex);
        if ($this->key === false || strlen($this->key) !== 32) {
            throw new RuntimeException('Invalid PII_ENCRYPTION_KEY: failed to decode hex.');
        }
    }

    /**
     * Encrypt a plaintext value.
     *
     * @param string $plaintext Raw value to encrypt
     * @return string Versioned encrypted string: enc_v1:<nonce>:<ciphertext>:<tag>
     */
    public function encrypt(string $plaintext): string
    {
        $nonce = random_bytes(self::NONCE_LEN);
        $tag = '';

        $ciphertext = openssl_encrypt(
            $plaintext,
            self::ALGO,
            $this->key,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            '',          // AAD (additional authenticated data)
            self::TAG_LEN
        );

        if ($ciphertext === false) {
            throw new RuntimeException('Encryption failed: ' . openssl_error_string());
        }

        return implode(self::SEPARATOR, [
            self::VERSION,
            bin2hex($nonce),
            bin2hex($ciphertext),
            bin2hex($tag),
        ]);
    }

    /**
     * Decrypt an encrypted value.
     *
     * @param string $encrypted Versioned encrypted string
     * @return string Original plaintext
     */
    public function decrypt(string $encrypted): string
    {
        // Pass through non-encrypted values
        if (!str_starts_with($encrypted, self::VERSION . self::SEPARATOR)) {
            return $encrypted;
        }

        $parts = explode(self::SEPARATOR, $encrypted);
        if (count($parts) !== 4) {
            throw new RuntimeException('Invalid encrypted format: expected 4 parts.');
        }

        [$version, $nonceHex, $ciphertextHex, $tagHex] = $parts;

        if ($version !== self::VERSION) {
            throw new RuntimeException("Unsupported encryption version: {$version}");
        }

        $nonce = hex2bin($nonceHex);
        $ciphertext = hex2bin($ciphertextHex);
        $tag = hex2bin($tagHex);

        if ($nonce === false || $ciphertext === false || $tag === false) {
            throw new RuntimeException('Invalid encrypted data: hex decode failed.');
        }

        $plaintext = openssl_decrypt(
            $ciphertext,
            self::ALGO,
            $this->key,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag
        );

        if ($plaintext === false) {
            throw new RuntimeException(
                'Decryption failed: data may be corrupted or key mismatch. ' .
                openssl_error_string()
            );
        }

        return $plaintext;
    }

    /**
     * Deterministic encryption for indexed lookups.
     *
     * Uses HMAC-derived nonce so identical plaintexts produce identical ciphertext.
     * TRADE-OFF: leaks equality — use only for search indexes, not primary storage.
     *
     * @param string $plaintext Value to encrypt deterministically
     * @return string Encrypted string (same format as encrypt())
     */
    public function encryptDeterministic(string $plaintext): string
    {
        // Derive a deterministic nonce from HMAC of the plaintext
        $hmac = hash_hmac('sha256', $plaintext, $this->key, true);
        $nonce = substr($hmac, 0, self::NONCE_LEN);
        $tag = '';

        $ciphertext = openssl_encrypt(
            $plaintext,
            self::ALGO,
            $this->key,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            '',
            self::TAG_LEN
        );

        if ($ciphertext === false) {
            throw new RuntimeException('Deterministic encryption failed.');
        }

        return implode(self::SEPARATOR, [
            self::VERSION,
            bin2hex($nonce),
            bin2hex($ciphertext),
            bin2hex($tag),
        ]);
    }

    /**
     * Generate a blind index for searching encrypted fields.
     *
     * Uses HMAC-SHA256, truncated to 32 hex chars, for fast equality lookups
     * without decrypting the field.
     */
    public function blindIndex(string $plaintext): string
    {
        return substr(hash_hmac('sha256', $plaintext, $this->key), 0, 32);
    }

    /**
     * Check if a value is encrypted.
     */
    public function isEncrypted(string $value): bool
    {
        return str_starts_with($value, self::VERSION . self::SEPARATOR);
    }
}
