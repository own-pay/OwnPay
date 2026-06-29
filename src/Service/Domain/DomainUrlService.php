<?php
declare(strict_types=1);

namespace OwnPay\Service\Domain;

use OwnPay\Core\Database;
use OwnPay\Http\Request;
use OwnPay\Repository\DomainRepository;

/**
 * Central URL resolver for the white-labeled custom domain pipeline.
 *
 * Directs URL resolution across all checkout processes, callback handlers, and redirects.
 * Resolves URLs using a priority hierarchy:
 * 1. GATEWAY_CALLBACK_URL override (for testing tunnels).
 * 2. Brand-specific primary active custom domain.
 * 3. General APP_URL configuration.
 * 4. Request context host metadata.
 * 5. General fallback domain.
 */
final class DomainUrlService
{
    /**
     * @var DomainRepository Repository interface for domain records.
     */
    private DomainRepository $domainRepo;

    /**
     * @var array<int, string|null> In-memory cache of resolved domains mapped by merchant ID.
     */
    private array $domainCache = [];

    /**
     * Constructs a new DomainUrlService instance.
     *
     * @param DomainRepository $domainRepo The domain repository.
     */
    public function __construct(DomainRepository $domainRepo)
    {
        $this->domainRepo = $domainRepo;
    }

    /**
     * Resolves the white-labeled base URL for a specified merchant.
     *
     * @param int $merchantId Unique identifier of the merchant/brand.
     * @param Request|null $req Optional request context.
     * @return string Resolved base URL (scheme + host, no trailing slash).
     */
    public function resolveBaseUrl(int $merchantId, ?Request $req = null): string
    {
        $gatewayCallback = $this->envGet('GATEWAY_CALLBACK_URL');
        if ($gatewayCallback !== '') {
            return rtrim($gatewayCallback, '/');
        }

        $customDomain = $this->getBrandDomain($merchantId);
        if ($customDomain !== null) {
            return 'https://' . $customDomain;
        }

        $appUrl = $this->envGet('APP_URL');
        if ($appUrl !== '') {
            return rtrim($appUrl, '/');
        }

        if ($req !== null) {
            $requestHost = $req->host();
            $requestHostName = strtolower(explode(':', $requestHost)[0]);
            if ($requestHostName !== '' && $requestHostName !== 'localhost') {
                $appDomain = strtolower(explode(':', $this->envGet('APP_DOMAIN'))[0]);
                $brandDomain = $this->getBrandDomain($merchantId);
                $brandHostName = $brandDomain !== null ? strtolower($brandDomain) : '';
                if (($appDomain !== '' && $requestHostName === $appDomain)
                    || ($brandHostName !== '' && $requestHostName === $brandHostName)
                ) {
                    return $req->scheme() . '://' . $requestHost;
                }
            }
        }

        return 'https://localhost';
    }

    /**
     * Builds the public checkout entry URL for a given payment intent token.
     *
     * @param int $merchantId Unique identifier of the merchant/brand.
     * @param string $token Cryptographically unique token identifying the payment intent.
     * @param Request|null $req Optional request context.
     * @return string Fully qualified checkout URL.
     */
    public function buildCheckoutUrl(int $merchantId, string $token, ?Request $req = null): string
    {
        return $this->resolveBaseUrl($merchantId, $req) . '/checkout/intent/' . $token;
    }

    /**
     * Builds the gateway status callback URL for a payment intent.
     *
     * @param int $merchantId Unique identifier of the merchant/brand.
     * @param string $token Cryptographically unique token identifying the payment intent.
     * @param Request|null $req Optional request context.
     * @return string Fully qualified status callback URL.
     */
    public function buildCallbackUrl(int $merchantId, string $token, ?Request $req = null): string
    {
        return $this->resolveBaseUrl($merchantId, $req) . '/checkout/intent/' . $token . '/status';
    }

    /**
     * Builds the legacy callback status URL for non-intent checkout flows.
     *
     * @param int $merchantId Unique identifier of the merchant/brand.
     * @param string $trxId Transaction identifier.
     * @param Request|null $req Optional request context.
     * @return string Fully qualified legacy status callback URL.
     */
    public function buildLegacyCallbackUrl(int $merchantId, string $trxId, ?Request $req = null): string
    {
        return $this->resolveBaseUrl($merchantId, $req) . '/checkout/' . $trxId . '/status';
    }

    /**
     * Retrieves the primary active custom domain name for a brand.
     *
     * Caches resolved hostnames locally to optimize multiple lookups within a single request.
     *
     * @param int $merchantId Unique identifier of the merchant/brand.
     * @return string|null Active custom domain name, or null if none is configured or active.
     */
    public function getBrandDomain(int $merchantId): ?string
    {
        if (array_key_exists($merchantId, $this->domainCache)) {
            return $this->domainCache[$merchantId];
        }

        try {
            $domain = $this->domainRepo->forTenant($merchantId)->findActiveDomain();
            $domainName = $domain['domain'] ?? null;
            $this->domainCache[$merchantId] = is_string($domainName) ? $domainName : null;
        } catch (\Throwable) {
            $this->domainCache[$merchantId] = null;
        }

        $res = $this->domainCache[$merchantId];
        return is_string($res) ? $res : null;
    }

    /**
     * Resolves an environment variable value from standard global stores.
     *
     * @param string $key Environment key name.
     * @return string Resolved environment value.
     */
    private function envGet(string $key): string
    {
        $val = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key) ?: '';
        return is_scalar($val) ? (string) $val : '';
    }
}
