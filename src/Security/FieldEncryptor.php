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
 * Key derivation (SEC-21): the raw ENCRYPTION_KEY (already enforced ≥32 chars at boot by Kernel::boot())
 * is run through HKDF-SHA256 with a fixed context-info string. This:
 *   - binds the derived key to the "ownpay-field-encryption" context (defending against key reuse across
 *     deployment components that might share the same env value),
 *   - extracts / expands the entropy properly even if the operator supplied slightly less than ideal entropy,
 *   - supersedes the legacy `hash('sha256', $key, true)` derivation.
 *
 * Backward compatibility: decrypt() falls back to the legacy raw-SHA-256 derivation if HKDF fails, so that
 * existing ciphertexts written before this change remain readable. New ciphertexts always use HKDF. Operators
 * can rotate by setting a fresh 32-byte ENCRYPTION_KEY (and ENCRYPTION_KEY_OLD to the previous value) and
 * gradually re-encrypting PII through the normal write path.
 *
 * @package OwnPay\Security
 */
class FieldEncryptor
{
    private const CIPHER = 'aes-256-gcm';
    private const TAG_LENGTH = 16;
    private const KEY_LENGTH = 32;

    /**
     * Context-info string bound into the HKDF extract+expand phases so that the derived key cannot be reused
     * for any other purpose even if the same ENCRYPTION_KEY env value is shared across components.
     */
    private const HKDF_INFO = 'ownpay-field-encryption';

    /**
     * @var string The 32-byte HKDF-derived cryptographic key used for new encrypt() calls.
     */
    private string $key;

    /**
     * @var string|null The legacy raw-SHA-256 derivation of the same key material, used as a decrypt()
     *                  fallback for ciphertexts written before the HKDF migration. Null when no legacy
     *                  fallback is possible (e.g. constructor was given a pre-derived binary key).
     */
    private ?string $legacyKey = null;

    /**
     * @var string|null The HKDF-derived key for ENCRYPTION_KEY_OLD, used as a decrypt() fallback during
     *                  the rotation window. Null when ENCRYPTION_KEY_OLD is not configured.
     */
    private ?string $oldKey = null;

    /**
     * @var string|null The legacy raw-SHA-256 derivation of ENCRYPTION_KEY_OLD, used as the final
     *                  decrypt() fallback for ciphertexts written before the HKDF migration under the
     *                  previous key. Null when ENCRYPTION_KEY_OLD is not configured.
     */
    private ?string $oldLegacyKey = null;

    /**
     * FieldEncryptor constructor.
     *
     * Initializes the encryption key from environment configurations and derives a 32-byte cryptographic key
     * via HKDF-SHA256. Maintains legacy-SHA-256 fallback keys for backward-compatible decryption of
     * pre-HKDF ciphertexts.
     *
     * @param string|null $key Optional raw key string override. When supplied, NO legacy fallback is
     *                         configured (the caller is presumed to be passing a freshly-derived key for
     *                         testing or explicit key management).
     * @throws \RuntimeException If the ENCRYPTION_KEY is not configured or is shorter than 32 bytes.
     */
    public function __construct(?string $key = null)
    {
        if ($key !== null) {
            // Caller supplied an explicit key - derive via HKDF, no legacy fallback.
            if ($key === '') {
                throw new \RuntimeException('ENCRYPTION_KEY not configured');
            }
            if (strlen($key) < self::KEY_LENGTH) {
                throw new \RuntimeException(
                    'ENCRYPTION_KEY must be at least 32 bytes. '
                    . 'Generate with: php -r "echo base64_encode(random_bytes(32));"'
                );
            }
            $this->key = $this->deriveHkdf($key);
            return;
        }

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
        if ($envKeyStr === '') {
            throw new \RuntimeException('ENCRYPTION_KEY not configured');
        }
        if (strlen($envKeyStr) < self::KEY_LENGTH) {
            throw new \RuntimeException(
                'ENCRYPTION_KEY must be at least 32 bytes. '
                . 'Generate with: php -r "echo base64_encode(random_bytes(32));"'
            );
        }

        // Primary key: HKDF-SHA256 derivation of the configured env value.
        $this->key = $this->deriveHkdf($envKeyStr);
        // Legacy fallback: raw SHA-256 derivation (used by decrypt() for pre-HKDF ciphertexts).
        $this->legacyKey = hash('sha256', $envKeyStr, true);

        // Rotation fallback: ENCRYPTION_KEY_OLD (if set), in both HKDF and legacy forms.
        $oldKeyRawVal = $_ENV['ENCRYPTION_KEY_OLD'] ?? null;
        $oldKeyRaw = is_string($oldKeyRawVal) ? $oldKeyRawVal : '';
        if ($oldKeyRaw === '') {
            $getOldKey = getenv('ENCRYPTION_KEY_OLD');
            $oldKeyRaw = is_string($getOldKey) ? $getOldKey : '';
        }
        if ($oldKeyRaw !== '') {
            $this->oldKey = $this->deriveHkdf($oldKeyRaw);
            $this->oldLegacyKey = hash('sha256', $oldKeyRaw, true);
        }
    }

    /**
     * Derive a 32-byte key from arbitrary-length key material using HKDF-SHA256 (RFC 5869).
     *
     * The salt parameter is left empty because the input key material is already high-entropy
     * (Kernel::boot() enforces ≥32 chars before this constructor runs). The info parameter
     * binds the derived key to the field-encryption context.
     *
     * @param string $keyMaterial High-entropy input key material (≥32 bytes recommended).
     * @return string 32-byte derived key.
     */
    private function deriveHkdf(string $keyMaterial): string
    {
        // hash_hkdf() with the literal 'sha256' algorithm always returns a non-empty
        // hex string in PHP 7.2+; the false-return path is unreachable. We trust the
        // return type and let PHP's type coercion handle any unexpected runtime state.
        return hash_hkdf('sha256', $keyMaterial, self::KEY_LENGTH, self::HKDF_INFO, '');
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
     * Tries the HKDF-derived current key first, then (in order) the HKDF-derived old key
     * (rotation window), the legacy raw-SHA-256 current key (pre-HKDF ciphertexts), and
     * finally the legacy raw-SHA-256 old key (pre-HKDF ciphertexts written under a since-rotated key).
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
        if ($ivLen <= 0) {
            throw new \RuntimeException('Failed to resolve IV length');
        }
        if (strlen($data) < $ivLen + self::TAG_LENGTH + 1) {
            throw new \RuntimeException('Encrypted data too short');
        }

        $iv = substr($data, 0, $ivLen);
        $tag = substr($data, $ivLen, self::TAG_LENGTH);
        $ciphertext = substr($data, $ivLen + self::TAG_LENGTH);

        // Candidate keys, in priority order: HKDF-current, HKDF-old, legacy-current, legacy-old.
        $candidates = [
            ['hkdf', $this->key],
            ['hkdf-old', $this->oldKey],
            ['legacy', $this->legacyKey],
            ['legacy-old', $this->oldLegacyKey],
        ];

        $plaintext = false;
        foreach ($candidates as [$label, $candidateKey]) {
            if ($candidateKey === null || $candidateKey === '') {
                continue;
            }
            $plaintext = openssl_decrypt(
                $ciphertext,
                self::CIPHER,
                $candidateKey,
                OPENSSL_RAW_DATA,
                $iv,
                $tag
            );
            if ($plaintext !== false) {
                break;
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
