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
     * Generates a new API key for a specified merchant.
     *
     * @param int $merchantId Unique identifier of the merchant/brand.
     * @param string $label Descriptive name/label for the API key.
     * @param array<string> $scopes Allowed scopes for the API key.
     * @param string|null $expiresAt Optional expiration timestamp (ISO-8601).
     * @return array{key: string, prefix: string} The full generated key and its prefix.
     */
    public function generate(int $merchantId, string $label, array $scopes = ['read', 'write'], ?string $expiresAt = null): array
    {
        $keyData = SecurityHelpers::generateApiKey();

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
     * Rotates an existing API key by revoking it and generating a replacement.
     *
     * @param int $merchantId Unique identifier of the merchant/brand.
     * @param int $keyId Unique identifier of the API key to rotate.
     * @param string $label Descriptive name/label for the new API key.
     * @return array{key: string, prefix: string} The newly generated key structure.
     */
    public function rotate(int $merchantId, int $keyId, string $label): array
    {
        $this->keys->forTenant($merchantId)->updateScoped($keyId, ['status' => 'revoked']);

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
}
