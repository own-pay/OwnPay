<?php
declare(strict_types=1);

namespace OwnPay\Service\Domain;

use OwnPay\Event\EventManager;
use OwnPay\Repository\DomainRepository;
use OwnPay\Support\DateHelper;

/**
 * Service managing custom domain configurations for brands.
 *
 * Handles domain mapping operations, ownership verification via DNS TXT records,
 * routing checks via DNS A records, and white-label URL generation.
 */
final class DomainService
{
    /**
     * @var DomainRepository Repository interface for domain records.
     */
    private DomainRepository $domains;

    /**
     * @var DnsVerifier DNS query verifier service.
     */
    private DnsVerifier $dnsVerifier;

    /**
     * @var EventManager Application event dispatcher.
     */
    private EventManager $events;

    /**
     * Constructs a new DomainService instance.
     *
     * @param DomainRepository $domains The domain repository.
     * @param DnsVerifier $dnsVerifier The DNS verification utility.
     * @param EventManager $events The event dispatcher system.
     */
    public function __construct(
        DomainRepository $domains,
        DnsVerifier $dnsVerifier,
        EventManager $events
    ) {
        $this->domains = $domains;
        $this->dnsVerifier = $dnsVerifier;
        $this->events = $events;
    }

    /**
     * Maps a custom domain name to a merchant brand.
     *
     * Validates domain syntax, verifies uniqueness, generates verification tokens,
     * and maps instructions for routing IP setups.
     *
     * @param int $merchantId Unique identifier of the merchant/brand.
     * @param string $domain The target custom domain name.
     * @param string $type The domain mapping type (e.g. 'checkout').
     * @return array{success: true, domain_id: int|string, verification_token: string, instructions: string}|array{success: false, error: string} Mapping payload or error response.
     */
    public function map(int $merchantId, string $domain, string $type = 'checkout'): array
    {
        if (!$this->isValidDomain($domain)) {
            return ['success' => false, 'error' => 'Invalid domain format'];
        }

        $existing = $this->domains->findByDomain($domain);
        if ($existing !== null) {
            return ['success' => false, 'error' => 'Domain already in use'];
        }

        $verificationToken = 'op-verify-' . bin2hex(random_bytes(16));

        $id = $this->domains->forTenant($merchantId)->createScoped([
            'domain'             => strtolower($domain),
            'type'               => $type,
            'status'             => 'pending',
            'dns_verified'       => 0,
            'verification_token' => $verificationToken,
        ]);

        $this->events->doAction('domain.mapped', $domain, $merchantId);

        $httpHost = $_SERVER['HTTP_HOST'] ?? '127.0.0.1';
        $hostOnly = parse_url("https://{$httpHost}", PHP_URL_HOST) ?: $httpHost;
        $serverIp = gethostbyname($hostOnly);

        return [
            'success'            => true,
            'domain_id'          => $id,
            'verification_token' => $verificationToken,
            'instructions'       => "Step 1: Add TXT record: _ownpay-verification.{$domain} = {$verificationToken}. Step 2: Point A record to {$serverIp}",
        ];
    }

    /**
     * Verifies the DNS configuration of a mapped domain name.
     *
     * Validates ownership via TXT records first, then resolves the server routing target IP
     * by resolving host components without port numbers.
     *
     * @param int $domainId Unique identifier of the domain record.
     * @param int $merchantId Unique identifier of the merchant/brand.
     * @return array{success: true, warning?: string}|array{success: false, error: string} Verification results.
     */
    public function verify(int $domainId, int $merchantId): array
    {
        $domain = $this->domains->forTenant($merchantId)->findScoped($domainId);
        if ($domain === null) {
            return ['success' => false, 'error' => 'Domain not found'];
        }

        $txtVerified = $this->dnsVerifier->verifyTxt(
            $domain['domain'],
            $domain['verification_token']
        );

        if (!$txtVerified) {
            return [
                'success' => false,
                'error'   => 'TXT record not found. Add _ownpay-verification.' . $domain['domain'] . ' with your verification token.',
            ];
        }

        $httpHost = $_SERVER['HTTP_HOST'] ?? '127.0.0.1';
        $hostOnly = parse_url("https://{$httpHost}", PHP_URL_HOST) ?: $httpHost;
        $serverIp = gethostbyname($hostOnly);
        $aRecordOk = $this->dnsVerifier->verifyARecord($domain['domain'], $serverIp);

        $this->domains->forTenant($merchantId)->updateScoped($domainId, [
            'dns_verified'    => 1,
            'status'          => 'active',
            'dns_verified_at' => DateHelper::nowMicro(),
        ]);

        $this->events->doAction('domain.verified', $domain['domain'], $merchantId);

        if (!$aRecordOk) {
            return [
                'success' => true,
                'warning' => "TXT verified! But A record does not point to {$serverIp}. Checkout pages won't work until DNS propagates.",
            ];
        }

        return ['success' => true];
    }

    /**
     * Removes a mapped custom domain configuration.
     *
     * @param int $domainId Unique identifier of the domain mapping.
     * @param int $merchantId Unique identifier of the merchant/brand.
     * @return void
     */
    public function remove(int $domainId, int $merchantId): void
    {
        $domain = $this->domains->forTenant($merchantId)->findScoped($domainId);
        if ($domain !== null) {
            $this->domains->forTenant($merchantId)->deleteScoped($domainId);
            $this->events->doAction('domain.removed', $domain['domain'], $merchantId);
        }
    }

    /**
     * Resolves a white-labeled URL for the brand's active custom domain.
     *
     * Falls back to system default domains if no active custom domain is verified.
     *
     * @param int $merchantId Unique identifier of the merchant/brand.
     * @param string $path Target URL path component.
     * @return string Fully qualified domain name URL.
     */
    public function merchantUrl(int $merchantId, string $path = '/'): string
    {
        $activeDomain = $this->domains->forTenant($merchantId)->findActiveDomain();
        if ($activeDomain !== null) {
            $scheme = (getenv('APP_HTTPS') === 'true') ? 'https' : 'http';
            return $scheme . '://' . $activeDomain['domain'] . '/' . ltrim($path, '/');
        }

        $appDomain = getenv('APP_DOMAIN') ?: 'localhost';
        return 'https://' . $appDomain . '/' . ltrim($path, '/');
    }

    /**
     * Validates domain syntax formatting patterns.
     *
     * @param string $domain The candidate domain name.
     * @return bool True if syntax matches valid patterns; false otherwise.
     */
    private function isValidDomain(string $domain): bool
    {
        return (bool) preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/i', $domain);
    }

    /**
     * Verifies the DNS status of a mapped domain (alias handler).
     *
     * @param int $domainId Unique identifier of the domain.
     * @param int $merchantId Unique identifier of the merchant/brand.
     * @return array{success: true, warning?: string}|array{success: false, error: string} Verification results.
     */
    public function verifyDomain(int $domainId, int $merchantId): array
    {
        return $this->verify($domainId, $merchantId);
    }
}
