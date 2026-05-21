<?php
declare(strict_types=1);

namespace OwnPay\Service\Domain;

use OwnPay\Event\EventManager;
use OwnPay\Repository\DomainRepository;
use OwnPay\Support\DateHelper;

/**
 * Domain service — custom domain CRUD, DNS verification, URL generation.
 *
 * Fires: domain.mapped, domain.verified, domain.removed
 */
final class DomainService
{
    private DomainRepository $domains;
    private DnsVerifier $dnsVerifier;
    private EventManager $events;

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
     * Map custom domain to merchant.
     */
    public function map(int $merchantId, string $domain, string $type = 'checkout'): array
    {
        // Validate domain format
        if (!$this->isValidDomain($domain)) {
            return ['success' => false, 'error' => 'Invalid domain format'];
        }

        // Check not already mapped
        $existing = $this->domains->findByDomain($domain);
        if ($existing !== null) {
            return ['success' => false, 'error' => 'Domain already in use'];
        }

        // Generate verification token
        $verificationToken = 'op-verify-' . bin2hex(random_bytes(16));

        $id = $this->domains->forTenant($merchantId)->createScoped([
            'domain'             => strtolower($domain),
            'type'               => $type,
            'status'             => 'pending',
            'dns_verified'       => 0,
            'verification_token' => $verificationToken,
        ]);

        $this->events->doAction('domain.mapped', $domain, $merchantId);

        // BUG-29 FIX: Strip port from HTTP_HOST before DNS lookup.
        // gethostbyname('ownpay.test:8443') fails silently.
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
     * Verify domain DNS — checks TXT record (ownership) and A record (routing).
     */
    public function verify(int $domainId, int $merchantId): array
    {
        $domain = $this->domains->forTenant($merchantId)->findScoped($domainId);
        if ($domain === null) {
            return ['success' => false, 'error' => 'Domain not found'];
        }

        // Step 1: TXT verification (ownership proof)
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

        // Step 2: A record check (routing — warning only, not blocking)
        // BUG-29 FIX: Strip port from HTTP_HOST before DNS lookup.
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
     * Remove domain mapping.
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
     * Generate URL for merchant's custom domain.
     */
    public function merchantUrl(int $merchantId, string $path = '/'): string
    {
        $activeDomain = $this->domains->forTenant($merchantId)->findActiveDomain();
        if ($activeDomain !== null) {
            $scheme = (getenv('APP_HTTPS') === 'true') ? 'https' : 'http';
            return $scheme . '://' . $activeDomain['domain'] . '/' . ltrim($path, '/');
        }
        // Fallback to app domain
        $appDomain = getenv('APP_DOMAIN') ?: 'localhost';
        return 'https://' . $appDomain . '/' . ltrim($path, '/');
    }

    private function isValidDomain(string $domain): bool
    {
        return (bool) preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/i', $domain);
    }

    /**
     * Verify domain DNS (alias for verify).
     * Called by Api\Admin\DomainController.
     */
    public function verifyDomain(int $domainId, int $merchantId): array
    {
        return $this->verify($domainId, $merchantId);
    }
}
