<?php

declare(strict_types=1);

namespace OwnPay\Service\Customer;

use OwnPay\Repository\ApiKeyRepository;

/**
 * ApiKeyService — hashed API key lifecycle management.
 *
 * Security model:
 *   - Raw API key is shown to the user ONCE at creation
 *   - Database stores ONLY the SHA-256 hash
 *   - Authentication: hash the incoming Bearer token and lookup by hash
 *   - Key prefix (first 8 chars) stored for identification purposes
 */
final class ApiKeyService
{
    private const KEY_PREFIX_LENGTH = 8;
    private const KEY_BYTE_LENGTH = 32; // 256-bit key

    private ApiKeyRepository $repo;
    private AuditLogger $audit;

    public function __construct(
        ?ApiKeyRepository $repo = null,
        ?AuditLogger $audit = null
    ) {
        $this->repo = $repo ?? new ApiKeyRepository();
        $this->audit = $audit ?? new AuditLogger();
    }

    /**
     * Create a new API key for a merchant.
     *
     * @param int      $merchantId
     * @param string   $name       Human-readable key name
     * @param array    $scopes     Permission scopes, e.g. ['create_payment', 'verify_payment']
     * @param string|null $expiresAt  Optional expiry datetime (Y-m-d H:i:s)
     *
     * @return array{
     *   rawKey: string,
     *   prefix: string,
     *   keyId: int,
     *   publicId: string,
     * }
     */
    public function create(
        int $merchantId,
        string $name,
        array $scopes = [],
        ?string $expiresAt = null
    ): array {
        // F10: regen-on-collision loop — UNIQUE KEY uq_ak_prefix prevents duplicates
        $maxAttempts = 5;
        $rawKey = $prefix = $keyHash = '';
        $id = null;
        $lastException = null;
        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $rawKey = 'op_live_' . bin2hex(random_bytes(self::KEY_BYTE_LENGTH));
            $prefix = substr($rawKey, 0, self::KEY_PREFIX_LENGTH);
            $keyHash = hash('sha256', $rawKey);
            try {
                $id = $this->repo->insert([
                    'merchant_id' => $merchantId,
                    'name' => $name,
                    'key_hash' => $keyHash,
                    'key_prefix' => $prefix,
                    'scopes' => !empty($scopes) ? json_encode($scopes) : null,
                    'expires_at' => $expiresAt,
                    'status' => 'active',
                ]);
                break;  // success
            } catch (\PDOException $e) {
                $lastException = $e;
                // MySQL duplicate-key error code is 1062 (SQLSTATE 23000)
                if ($e->errorInfo[1] !== 1062) {
                    throw $e;  // not a collision — re-throw
                }
                // Collision on prefix or hash — regenerate and retry
            }
        }
        if ($id === null) {
            throw new \RuntimeException(
                "Failed to generate non-colliding API key after {$maxAttempts} attempts",
                0,
                $lastException
            );
        }

        $key = $this->repo->findById($id);

        // Audit
        $this->audit->log(
            $merchantId,
            'api_key.created',
            'api_key',
            $key['public_id'],
            'system',
            'api_key_service',
            null,
            ['name' => $name, 'prefix' => $prefix, 'scopes' => $scopes]
        );

        return [
            'rawKey' => $rawKey,   // Return ONCE — never stored
            'prefix' => $prefix,
            'keyId' => $id,
            'publicId' => $key['public_id'],
        ];
    }

    /**
     * Authenticate a Bearer token.
     *
     * @param string $bearerToken The raw API key from Authorization header
     * @return array|null Merchant context array on success, null on failure
     */
    public function authenticate(string $bearerToken): ?array
    {
        $hash = hash('sha256', $bearerToken);
        $key = $this->repo->findByHash($hash);

        if ($key === null) {
            return null;
        }

        // Check expiry
        if (!empty($key['expires_at']) && strtotime($key['expires_at']) < time()) {
            return null;
        }

        // Record usage
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $this->repo->touchUsage((int) $key['id'], $ip);

        return [
            'key_id' => (int) $key['id'],
            'merchant_id' => (int) $key['merchant_id'],
            'scopes' => json_decode($key['scopes'] ?? '[]', true) ?: [],
            'key_prefix' => $key['key_prefix'],
            'name' => $key['name'],
        ];
    }

    /**
     * Revoke an API key.
     */
    public function revoke(int $keyId, int $merchantId): void
    {
        $key = $this->repo->findById($keyId);
        if ($key === null) {
            return;
        }

        $this->repo->revoke($keyId);

        $this->audit->log(
            $merchantId,
            'api_key.revoked',
            'api_key',
            $key['public_id'],
            'system',
            'api_key_service',
            ['status' => $key['status']],
            ['status' => 'revoked']
        );
    }

    /**
     * List all keys for a merchant (hashes are never exposed).
     */
    public function listByMerchant(int $merchantId): array
    {
        $keys = $this->repo->findByMerchant($merchantId);

        // Strip sensitive fields
        return array_map(function (array $key) {
            unset($key['key_hash']);
            return $key;
        }, $keys);
    }

    /**
     * Rotate an API key — generate new key, old key enters 24-hour grace period.
     *
     * Both old and new keys are valid during the grace window.
     * After 24h, the old key auto-expires on next authenticate() call.
     *
     * @param int $keyId      API key to rotate
     * @param int $merchantId Owner merchant
     * @return array{rawKey: string, prefix: string, newKeyId: int, oldKeyId: int, graceHours: int}
     */
    public function rotate(int $keyId, int $merchantId): array
    {
        $oldKey = $this->repo->findById($keyId);
        if ($oldKey === null || (int) $oldKey['merchant_id'] !== $merchantId) {
            throw new \InvalidArgumentException('API key not found or access denied.');
        }

        if ($oldKey['status'] !== 'active') {
            throw new \InvalidArgumentException('Only active keys can be rotated.');
        }

        // Set old key to expire in 24 hours (grace period)
        $graceExpiry = date('Y-m-d H:i:s', time() + 86400);
        $this->repo->setExpiry($keyId, $graceExpiry);

        // Decode scopes from old key
        $scopes = json_decode($oldKey['scopes'] ?? '[]', true) ?: [];

        // Create new replacement key with same scopes + name
        $result = $this->create(
            $merchantId,
            $oldKey['name'] . ' (rotated)',
            $scopes,
            $oldKey['expires_at'] ?? null // Keep original expiry if set
        );

        // Audit
        $this->audit->log(
            $merchantId,
            'api_key.rotated',
            'api_key',
            $oldKey['public_id'],
            'system',
            'api_key_service',
            ['key_prefix' => $oldKey['key_prefix']],
            ['new_key_prefix' => $result['prefix'], 'grace_hours' => 24]
        );

        return [
            'rawKey' => $result['rawKey'],
            'prefix' => $result['prefix'],
            'newKeyId' => $result['keyId'],
            'oldKeyId' => $keyId,
            'graceHours' => 24,
        ];
    }
}
