<?php
declare(strict_types=1);

namespace OwnPay\Service\Customer;

use OwnPay\Repository\ApiKeyRepository;
use OwnPay\Security\SecurityHelpers;

/**
 * Service orchestrating API key lifecycles.
 *
 * Implements prefix-based key verification, SHA-256 key hashing, and timing-safe 
 * comparisons for merchant API authentication.
 */
final class ApiKeyService
{
    /**
     * @var ApiKeyRepository Repository managing API keys database records.
     */
    private ApiKeyRepository $keys;

    /**
     * Constructs a new ApiKeyService instance.
     *
     * @param ApiKeyRepository $keys The API key repository.
     */
    public function __construct(ApiKeyRepository $keys)
    {
        $this->keys = $keys;
    }

    /**
     * Maximum API key lifetime in seconds (365 days) when no explicit expiration is provided.
     *
     * Enforced as a defense-in-depth measure: even if a caller forgets to pass $expiresAt,
     * a stolen key cannot live indefinitely. Callers may still pass an explicit shorter
     * expiration, but not a longer one — see generate().
     */
    private const int MAX_KEY_LIFETIME_SECONDS = 86400 * 365;

    /**
     * Generates a new API key for a specified merchant.
     *
     * If $expiresAt is null, a default maximum lifetime (365 days) is applied.
     * If $expiresAt is provided but exceeds the maximum lifetime, it is clamped down.
     * This ensures no API key can outlive MAX_KEY_LIFETIME_SECONDS regardless of caller input.
     *
     * @param int $merchantId Unique identifier of the merchant/brand.
     * @param string $label Descriptive name/label for the API key.
     * @param array<string> $scopes Allowed scopes for the API key.
     * @param string|null $expiresAt Optional expiration timestamp (ISO-8601). Null = default max lifetime.
     * @return array{key: string, prefix: string} The full generated key and its prefix.
     */
    public function generate(int $merchantId, string $label, array $scopes = ['read', 'write'], ?string $expiresAt = null): array
    {
        $keyData = SecurityHelpers::generateApiKey();

        $expiresAt = $this->enforceMaxLifetime($expiresAt);

        $this->keys->forTenant($merchantId)->createScoped([
            'key_prefix' => $keyData['prefix'],
            'key_hash'   => $keyData['hash'],
            'name'       => $label,
            'scopes'     => json_encode($scopes),
            'status'     => 'active',
            'expires_at' => $expiresAt,
        ]);

        return [
            'key'    => $keyData['key'],
            'prefix' => $keyData['prefix'],
        ];
    }

    /**
     * Ensures $expiresAt is non-null and does not exceed the maximum allowed lifetime.
     *
     * - If null: returns an ISO-8601 timestamp MAX_KEY_LIFETIME_SECONDS in the future.
     * - If provided and within the limit: returned unchanged.
     * - If provided but exceeds the limit: clamped down to the maximum.
     *
     * @param string|null $expiresAt ISO-8601 timestamp or null.
     * @return non-empty-string ISO-8601 timestamp guaranteed to be within the maximum lifetime.
     */
    private function enforceMaxLifetime(?string $expiresAt): string
    {
        $maxTimestamp = time() + self::MAX_KEY_LIFETIME_SECONDS;

        if ($expiresAt === null || $expiresAt === '') {
            return $this->normalizeDatetime($maxTimestamp);
        }

        $parsed = strtotime($expiresAt);
        if ($parsed === false) {
            // Unparseable input — fail safe to the default max lifetime.
            return $this->normalizeDatetime($maxTimestamp);
        }

        if ($parsed > $maxTimestamp) {
            return $this->normalizeDatetime($maxTimestamp);
        }

        return $this->normalizeDatetime($parsed);
    }

    /**
     * Normalizes a Unix timestamp into a MariaDB/MySQL-friendly datetime string.
     *
     * PHP's `date('c', $ts)` produces an ISO-8601 string like
     * "2027-08-13T23:21:08+00:00". While that format is valid ISO-8601, the
     * `DATETIME[(6)]` column type used throughout OwnPay rejects the `T`
     * separator and the timezone offset on MariaDB 11.x with the default
     * `STRICT_TRANS_TABLES` sql_mode, raising:
     *
     *   SQLSTATE[22007]: Invalid datetime format: 1292 Incorrect datetime
     *   value: '2027-08-13T23:21:08+00:00' for column ... `expires_at`
     *
     * To keep the storage layer robust regardless of the host's sql_mode, we
     * always emit the canonical `Y-m-d H:i:s` representation. The timezone
     * offset is intentionally dropped because DATETIME columns store the
     * literal value verbatim (they are not timezone-aware); the application
     * layer treats all stored timestamps as UTC.
     *
     * @param int $timestamp Unix timestamp (assumed UTC).
     * @return non-empty-string Formatted as "Y-m-d H:i:s" in UTC.
     */
    private function normalizeDatetime(int $timestamp): string
    {
        return gmdate('Y-m-d H:i:s', $timestamp);
    }

    /**
     * Rotates an existing API key by revoking it and generating a replacement
     * that inherits the original key's scopes, label, and expiration.
     *
     * The original key's `name`, `scopes`, and `expires_at` are preserved so that
     * rotation never silently downgrades a key with `admin` scope to the default
     * `['read','write']` set, and so the caller does not have to re-supply the
     * original label. If the original record cannot be found (already deleted,
     * wrong tenant, ...) the method still rotates by revoking and generating a
     * fresh key using the provided fallback `$label`.
     *
     * @param int $merchantId Unique identifier of the merchant/brand.
     * @param int $keyId Unique identifier of the API key to rotate.
     * @param string $label Fallback descriptive name/label if the original record is missing.
     * @return array{key: string, prefix: string} The newly generated key structure.
     */
    public function rotate(int $merchantId, int $keyId, string $label): array
    {
        // Fetch the original key BEFORE revoking so we can inherit its scopes,
        // name, and expires_at. Without this, rotation silently downgraded any
        // key with the `admin` scope to the default ['read','write'] set.
        $original = $this->keys->forTenant($merchantId)->findScoped($keyId);

        $this->keys->forTenant($merchantId)->updateScoped($keyId, ['status' => 'revoked']);

        if (is_array($original)) {
            $rawScopes = $original['scopes'] ?? '[]';
            $decoded = is_string($rawScopes) ? json_decode($rawScopes, true) : null;
            if (!is_array($decoded) || $decoded === []) {
                $scopes = ['read', 'write'];
            } else {
                // Normalise to a list<string>: drop anything that is not a string
                // (defensive against tampered rows) and re-index.
                $scopes = array_values(array_filter($decoded, 'is_string'));
                if ($scopes === []) {
                    $scopes = ['read', 'write'];
                }
            }
            $rawName = $original['name'] ?? null;
            $name = is_string($rawName) && $rawName !== '' ? $rawName : $label;
            $rawExp = $original['expires_at'] ?? null;
            $expiresAt = is_string($rawExp) && $rawExp !== '' ? $rawExp : null;

            return $this->generate($merchantId, $name, $scopes, $expiresAt);
        }

        return $this->generate($merchantId, $label);
    }

    /**
     * Revokes a specified API key.
     *
     * @param int $merchantId Unique identifier of the merchant/brand.
     * @param int $keyId Unique identifier of the API key to revoke.
     * @return int Number of keys actually revoked (0 if the id does not exist or belongs to another merchant).
     */
    public function revoke(int $merchantId, int $keyId): int
    {
        return $this->keys->forTenant($merchantId)->updateScoped($keyId, ['status' => 'revoked']);
    }

    /**
     * Locks an API key, immediately preventing it from authorizing any request.
     * Reversible via unlock() - no key rotation is required or performed.
     *
     * @param int $merchantId Unique identifier of the merchant/brand.
     * @param int $keyId Unique identifier of the API key to lock.
     * @return int Number of keys actually locked (0 if the id does not exist or belongs to another merchant).
     */
    public function lock(int $merchantId, int $keyId): int
    {
        return $this->keys->forTenant($merchantId)->updateScoped($keyId, ['status' => 'locked']);
    }

    /**
     * Unlocks a previously locked API key, restoring it to active immediately.
     *
     * @param int $merchantId Unique identifier of the merchant/brand.
     * @param int $keyId Unique identifier of the API key to unlock.
     * @return int Number of keys actually unlocked (0 if the id does not exist or belongs to another merchant).
     */
    public function unlock(int $merchantId, int $keyId): int
    {
        return $this->keys->forTenant($merchantId)->updateScoped($keyId, ['status' => 'active']);
    }

    /**
     * Retrieves active API keys for a merchant, masking hash fields.
     *
     * @param int $merchantId Unique identifier of the merchant/brand.
     * @return array<int, array<string, mixed>> List of active API key records with hashes removed.
     */
    public function list(int $merchantId): array
    {
        $keys = $this->keys->forTenant($merchantId)->listActiveKeys();

        return array_map(function (array $key) {
            // The stored hash column is `key_hash`; defensively strip it (and the
            // legacy `hash` alias) so it can never leak even if the SELECT changes.
            unset($key['key_hash'], $key['hash']);
            if (isset($key['scopes']) && is_string($key['scopes'])) {
                $key['scopes'] = json_decode($key['scopes'], true);
            }
            if (!is_array($key['scopes'] ?? null)) {
                $key['scopes'] = [];
            }
            return $key;
        }, $keys);
    }

    /**
     * Retrieves every API key for a merchant regardless of status, masking hash fields.
     *
     * @param int $merchantId Unique identifier of the merchant/brand.
     * @return array<int, array<string, mixed>> List of all API key records with hashes removed.
     */
    public function listAll(int $merchantId): array
    {
        $keys = $this->keys->forTenant($merchantId)->listAllKeys();

        return array_map(function (array $key) {
            unset($key['key_hash'], $key['hash']);
            if (isset($key['scopes']) && is_string($key['scopes'])) {
                $key['scopes'] = json_decode($key['scopes'], true);
            }
            if (!is_array($key['scopes'] ?? null)) {
                $key['scopes'] = [];
            }
            return $key;
        }, $keys);
    }
}
