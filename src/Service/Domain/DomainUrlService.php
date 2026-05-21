<?php
declare(strict_types=1);

namespace OwnPay\Service\Domain;

use OwnPay\Core\Database;
use OwnPay\Http\Request;
use OwnPay\Repository\DomainRepository;

/**
 * Central URL resolver for the white-label domain pipeline.
 *
 * All checkout URLs, gateway callback URLs, and API response URLs
 * MUST be generated through this service to ensure brand custom
 * domains are used when configured.
 *
 * Priority chain:
 *   1. GATEWAY_CALLBACK_URL env (dev ngrok override)
 *   2. Brand's primary active custom domain (op_domains)
 *   3. APP_URL env
 *   4. Request host
 *   5. Fallback: https://localhost
 */
final class DomainUrlService
{
    private DomainRepository $domainRepo;

    /** @var array<int, string|null> In-memory cache of resolved domains per merchant */
    private array $domainCache = [];

    public function __construct(DomainRepository $domainRepo)
    {
        $this->domainRepo = $domainRepo;
    }

    /**
     * Resolve the base URL for a specific brand.
     *
     * Used for constructing checkout URLs, callback URLs, etc.
     * Returns scheme + host (no trailing slash).
     */
    public function resolveBaseUrl(int $merchantId, ?Request $req = null): string
    {
        // 1. Dev override (ngrok, tunnels) — highest priority
        $gatewayCallback = $this->envGet('GATEWAY_CALLBACK_URL');
        if ($gatewayCallback !== '') {
            return rtrim($gatewayCallback, '/');
        }

        // 2. Brand's primary custom domain from op_domains
        $customDomain = $this->getBrandDomain($merchantId);
        if ($customDomain !== null) {
            return 'https://' . $customDomain;
        }

        // 3. APP_URL
        $appUrl = $this->envGet('APP_URL');
        if ($appUrl !== '') {
            return rtrim($appUrl, '/');
        }

        // 4. Current request host
        if ($req !== null && $req->host() !== '' && $req->host() !== 'localhost') {
            return $req->scheme() . '://' . $req->host();
        }

        // 5. Hard fallback
        return 'https://localhost';
    }

    /**
     * Build checkout URL for a payment intent token.
     */
    public function buildCheckoutUrl(int $merchantId, string $token, ?Request $req = null): string
    {
        return $this->resolveBaseUrl($merchantId, $req) . '/checkout/intent/' . $token;
    }

    /**
     * Build gateway callback/status URL for a payment intent token.
     */
    public function buildCallbackUrl(int $merchantId, string $token, ?Request $req = null): string
    {
        return $this->resolveBaseUrl($merchantId, $req) . '/checkout/intent/' . $token . '/status';
    }

    /**
     * Build gateway callback/status URL for legacy checkout (non-intent).
     */
    public function buildLegacyCallbackUrl(int $merchantId, string $trxId, ?Request $req = null): string
    {
        return $this->resolveBaseUrl($merchantId, $req) . '/checkout/' . $trxId . '/status';
    }

    /**
     * Get the primary active custom domain for a brand.
     * Returns domain hostname or null if none configured.
     */
    public function getBrandDomain(int $merchantId): ?string
    {
        if (array_key_exists($merchantId, $this->domainCache)) {
            return $this->domainCache[$merchantId];
        }

        try {
            $domain = $this->domainRepo->forTenant($merchantId)->findActiveDomain();
            $this->domainCache[$merchantId] = $domain ? $domain['domain'] : null;
        } catch (\Throwable) {
            $this->domainCache[$merchantId] = null;
        }

        return $this->domainCache[$merchantId];
    }

    /**
     * Read env var with standard fallback chain.
     */
    private function envGet(string $key): string
    {
        return (string) ($_ENV[$key] ?? $_SERVER[$key] ?? getenv($key) ?: '');
    }
}
