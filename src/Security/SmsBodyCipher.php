<?php

declare(strict_types=1);

namespace OwnPay\Security;

/**
 * Encrypts and decrypts the `op_sms_parsed.body` column.
 *
 * The cipher rides on FieldEncryptor (server-side ENCRYPTION_KEY, HKDF-derived,
 * AES-256-GCM). It adds a version prefix so the scheme can rotate later:
 *
 * - `v1:` — version 1 of the scheme (current).
 *
 * The prefix also makes ciphertexts distinguishable from pre-migration plaintext,
 * which is what lets the backfill run in resumable batches and lets readers tolerate
 * legacy rows during the transition window (before the backfill completes).
 *
 * The parsed fields (amount, trx_id, sender, match status ...) stay plaintext by
 * design: they are what the matcher and SQL filters depend on. Searching by body
 * content is no longer possible; that is an accepted trade-off.
 */
final class SmsBodyCipher
{
    private const PREFIX = 'v1:';

    public function __construct(
        private readonly FieldEncryptor $encryptor,
    ) {
    }

    /**
     * Encrypts a plaintext SMS body into a versioned ciphertext string.
     *
     * @param string $plaintext The raw SMS body to protect.
     * @return string The versioned ciphertext ("v1:" prefix + base64 payload).
     */
    public function encrypt(string $plaintext): string
    {
        return self::PREFIX . $this->encryptor->encrypt($plaintext);
    }

    /**
     * Whether a stored value is a versioned ciphertext (vs pre-migration plaintext).
     */
    public function isEncrypted(string $stored): bool
    {
        return str_starts_with($stored, self::PREFIX);
    }

    /**
     * Decrypts a stored body. Strings without the version prefix are treated as
     * pre-migration plaintext and returned verbatim (read tolerance only).
     *
     * @param string $stored The stored body value.
     * @return string The decrypted (or legacy plaintext) body.
     * @throws \RuntimeException When the ciphertext is malformed or fails authentication.
     */
    public function decrypt(string $stored): string
    {
        if (!$this->isEncrypted($stored)) {
            return $stored;
        }
        return $this->encryptor->decrypt(substr($stored, strlen(self::PREFIX)));
    }

    /**
     * Decrypts a stored body for display. Returns a safe placeholder on any failure
     * (tampered/corrupted data renders as an unreadable marker, never as an exception
     * path that could leak ciphertext fragments into output).
     */
    public function decryptForDisplay(string $stored): string
    {
        try {
            return $this->decrypt($stored);
        } catch (\Throwable) {
            return '[unreadable message]';
        }
    }
}
