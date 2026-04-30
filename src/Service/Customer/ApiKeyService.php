<?php
declare(strict_types=1);

namespace OwnPay\Service\Customer;

use OwnPay\Repository\ApiKeyRepository;
use OwnPay\Security\SecurityHelpers;

/**
 * API key service — generate, rotate, revoke merchant API keys.
 *
 * Per OWASP: prefix-based lookup, sha256 hash storage, timing-safe compare.
 */
final class ApiKeyService
{
    private ApiKeyRepository $keys;

    public function __construct(ApiKeyRepository $keys)
    {
        $this->keys = $keys;
    }

    /**
     * Generate new API key for merchant.
     *
     * @return array{key: string, prefix: string} Full key returned only once
     */
    public function generate(int $merchantId, string $label, ?string $expiresAt = null): array
    {
        $keyData = SecurityHelpers::generateApiKey();

        $this->keys->forTenant($merchantId)->createScoped([
            'prefix'     => $keyData['prefix'],
            'hash'       => $keyData['hash'],
            'label'      => $label,
            'status'     => 'active',
            'expires_at' => $expiresAt,
        ]);

        return [
            'key'    => $keyData['key'], // Show only once
            'prefix' => $keyData['prefix'],
        ];
    }

    /**
     * Rotate key — generate new, revoke old.
     */
    public function rotate(int $merchantId, int $keyId, string $label): array
    {
        // Revoke old
        $this->keys->forTenant($merchantId)->updateScoped($keyId, ['status' => 'revoked']);

        // Generate new
        return $this->generate($merchantId, $label);
    }

    /**
     * Revoke API key.
     */
    public function revoke(int $merchantId, int $keyId): void
    {
        $this->keys->forTenant($merchantId)->updateScoped($keyId, ['status' => 'revoked']);
    }

    /**
     * List active keys for merchant (hashes masked).
     */
    public function list(int $merchantId): array
    {
        $keys = $this->keys->forTenant($merchantId)->listActiveKeys();
        // Mask hashes — only show prefix
        return array_map(function (array $key) {
            unset($key['hash']);
            return $key;
        }, $keys);
    }
}
