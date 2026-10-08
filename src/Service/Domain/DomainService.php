<?php
declare(strict_types=1);

namespace OwnPay\Service\Domain;

use OwnPay\Event\EventManager;
use OwnPay\Repository\DomainRepository;
use OwnPay\Service\System\HttpClient;
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
     * Cloudflare's published public IPv4 ranges (https://www.cloudflare.com/ips-v4).
     *
     * @var array<int, string>
     */
    private const CLOUDFLARE_IPV4_CIDRS = [
        '103.21.244.0/22',
        '103.22.200.0/22',
        '103.31.4.0/22',
        '104.16.0.0/13',
        '104.24.0.0/14',
        '108.162.192.0/18',
        '131.0.72.0/22',
        '141.101.64.0/18',
        '162.158.0.0/15',
        '172.64.0.0/13',
        '173.245.48.0/20',
        '188.114.96.0/20',
        '190.93.240.0/20',
        '197.234.240.0/22',
        '198.41.128.0/17',
    ];

    /**
     * Public-IP echo service used to discover the origin's egress IPv4 when the
     * web server does not expose a public SERVER_ADDR (e.g. php-fpm behind
     * nginx on a reverse-proxied / NAT'd host).
     */
    private const PUBLIC_IP_SERVICE_URL = 'https://icanhazip.com';

    /**
     * Transfer timeout (seconds) for the public-IP echo request. Deliberately
     * short: the hint must not block admin page renders waiting on a slow
     * third-party service.
     */
    private const PUBLIC_IP_SERVICE_TIMEOUT = 3;

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
    /**
     * Resolves the platform's own host from configuration for DNS A-record hints.
     *
     * The configured APP_DOMAIN / APP_URL is authoritative. The request Host
     * header is intentionally NOT consulted: it is attacker-controlled and must
     * never drive gethostbyname() lookups (DNS exfiltration / probing SSRF,
     * audit DOM-4). On misconfigured installs (neither env var set) we fall
     * back to the loopback address so the IP hint degrades to '127.0.0.1'
     * rather than leaking through client-controlled input.
     *
     * @return string The hostname to resolve to the server IP.
     */
    private function resolveServerHost(): string
    {
        $appDomainVal = $_ENV['APP_DOMAIN'] ?? getenv('APP_DOMAIN') ?: '';
        $host = is_string($appDomainVal) ? $appDomainVal : '';

        if ($host === '') {
            $appUrlVal = $_ENV['APP_URL'] ?? getenv('APP_URL') ?: '';
            $appUrl = is_string($appUrlVal) ? $appUrlVal : '';
            if ($appUrl !== '') {
                $parsedAppHost = parse_url($appUrl, PHP_URL_HOST);
                $host = is_string($parsedAppHost) ? $parsedAppHost : '';
            }
        }

        if ($host === '') {
            // No configured host: degrade to a placeholder rather than trusting
            // the attacker-controlled Host header. gethostbyname('127.0.0.1')
            // returns '127.0.0.1' verbatim, which is a safe hint value.
            return '127.0.0.1';
        }

        $parsed = parse_url("https://{$host}", PHP_URL_HOST);
        return is_string($parsed) ? $parsed : '127.0.0.1';
    }

    /**
     * Resolves the server IP shown as the custom-domain A-record hint and used
     * for A-record verification.
     *
     * Resolution order:
     *  1. An explicit APP_SERVER_IP override, when it is a valid IPv4 - the
     *     most deterministic option for operators who need a pinned value.
     *  2. The web server's SERVER_ADDR, when it is a public IPv4. The web
     *     server sets this from the network interface that served the request,
     *     so it stays the origin server's own address even when APP_DOMAIN is
     *     fronted by a CDN / reverse proxy (e.g. Cloudflare) - unlike
     *     gethostbyname(APP_DOMAIN), which would return the proxy's edge IP and
     *     silently break DNS verification (DOM-5).
     *  3. A public-IP echo service (https://icanhazip.com) when SERVER_ADDR is
     *     missing or private (php-fpm behind nginx on a proxied / NAT'd host).
     *     Because the request leaves from the origin itself, the service returns
     *     the server's genuine public egress IPv4.
     *  4. 127.0.0.1 as a last-resort hint when nothing above resolves.
     *
     * Only configuration and the server's own network state determine the
     * result; the request Host header is never consulted.
     *
     * @return string The dotted-quad IPv4 hint.
     */
    public function serverIp(): string
    {
        $overrideVal = $_ENV['APP_SERVER_IP'] ?? getenv('APP_SERVER_IP') ?: '';
        $override = is_string($overrideVal) ? trim($overrideVal) : '';
        if ($override !== '' && filter_var($override, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return $override;
        }

        $serverAddrVal = $_SERVER['SERVER_ADDR'] ?? '';
        $serverAddr = is_string($serverAddrVal) ? trim($serverAddrVal) : '';
        if ($serverAddr !== '' && self::isPublicIpv4($serverAddr)) {
            return $serverAddr;
        }

        $egress = '';
        try {
            $response = (new HttpClient(self::PUBLIC_IP_SERVICE_TIMEOUT))->get(self::PUBLIC_IP_SERVICE_URL);
            $egress = trim($response['body']);
        } catch (\Throwable) {
            // Unreachable / blocked echo service must never break a page render
            // or a DNS verification; degrade to the loopback hint below.
        }
        if ($egress !== '' && self::isPublicIpv4($egress)) {
            return $egress;
        }

        return '127.0.0.1';
    }

    /**
     * Rejects loopback, private, and reserved IPv4 addresses.
     *
     * @param string $ip A dotted-quad IPv4 address.
     * @return bool True only when the address is in public IPv4 space.
     */
    private static function isPublicIpv4(string $ip): bool
    {
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) !== false;
    }

    /**
     * Resolves the CNAME target used as the custom-domain routing hint.
     *
     * Returned from configuration (APP_DOMAIN / APP_URL) instead of a
     * hardcoded value so self-hosted installations show a correct target.
     *
     * @return string The host customers should CNAME their domain to.
     */
    public function cnameTarget(): string
    {
        return $this->resolveServerHost();
    }

    /**
     * Detects whether an IP address is a Cloudflare edge address.
     *
     * Used to flag when the A-record hint resolved from APP_DOMAIN is a proxy
     * address instead of the origin server (DOM-5).
     *
     * @param string $ip A dotted-quad IPv4 address.
     * @return bool True if the address falls inside a published Cloudflare IPv4 range.
     */
    public static function isCloudflareIp(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return false;
        }

        $ipBytes = inet_pton($ip);
        if ($ipBytes === false) {
            return false;
        }

        foreach (self::CLOUDFLARE_IPV4_CIDRS as $cidr) {
            if (str_contains($cidr, '/') === false) {
                continue;
            }
            [$network, $bitsStr] = explode('/', $cidr, 2);
            $prefix = (int) $bitsStr;
            $netBytes = inet_pton($network);
            if ($netBytes === false || !self::isValidIpv4Prefix($prefix)) {
                continue;
            }
            if (self::ipv4PrefixMatch($ipBytes, $netBytes, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Compares the leading $prefix bits of two packed IPv4 addresses.
     *
     * @param string $ip   Packed 4-byte IPv4 address (inet_pton output).
     * @param string $net  Packed 4-byte IPv4 network address.
     * @param int    $prefix Number of leading bits that must match (0-32).
     * @return bool True when both addresses share the given prefix.
     */
    private static function ipv4PrefixMatch(string $ip, string $net, int $prefix): bool
    {
        $fullBytes = (int) ($prefix / 8);
        $remain = $prefix % 8;

        for ($i = 0; $i < $fullBytes; $i++) {
            if ($ip[$i] !== $net[$i]) {
                return false;
            }
        }

        if ($remain > 0) {
            $mask = (0xFF << (8 - $remain)) & 0xFF;
            if ((ord($ip[$fullBytes]) & $mask) !== (ord($net[$fullBytes]) & $mask)) {
                return false;
            }
        }

        return true;
    }

    private static function isValidIpv4Prefix(int $prefix): bool
    {
        return $prefix >= 0 && $prefix <= 32;
    }

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
    public function map(int $merchantId, string $domain, string $type = 'checkout', ?string $redirectUrl = null): array
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
            'redirect_url'       => $redirectUrl,
        ]);

        $this->events->doAction('domain.mapped', $domain, $merchantId);

        $serverIp = $this->serverIp();

        return [
            'success'            => true,
            'domain_id'          => $id,
            'verification_token' => $verificationToken,
            'instructions'       => "Step 1: Add TXT record: _ownpay-verify.{$domain} = {$verificationToken}. Step 2: Point A record to {$serverIp}",
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

        $domainName = $domain['domain'] ?? '';
        $token = $domain['verification_token'] ?? '';
        if (!is_string($domainName) || !is_string($token) || $domainName === '' || $token === '') {
            return ['success' => false, 'error' => 'Invalid domain mapping configuration'];
        }

        $txtVerified = $this->dnsVerifier->verifyTxt(
            $domainName,
            $token
        );

        if (!$txtVerified) {
            return [
                'success' => false,
                'error'   => 'TXT record not found. Add _ownpay-verify.' . $domainName . ' with your verification token.',
            ];
        }

        $serverIp = $this->serverIp();
        $aRecordOk = $this->dnsVerifier->verifyARecord($domainName, $serverIp);

        if (!$aRecordOk) {
            // A-record check is gating: the domain must actually route traffic
            // to this OwnPay server before we mark it verified/active. Previously
            // dns_verified was set to 1 unconditionally and only a non-blocking
            // warning was returned, which let DomainMiddleware accept requests
            // for domains whose DNS still pointed elsewhere (audit DOM-3).
            return [
                'success' => false,
                'error'   => "TXT record verified, but the A record for {$domainName} does not point to {$serverIp}. "
                    . 'Update your DNS A record to the OwnPay server IP and re-run verification.',
            ];
        }

        $this->domains->forTenant($merchantId)->updateScoped($domainId, [
            'dns_verified'    => 1,
            'status'          => 'active',
            'dns_verified_at' => DateHelper::nowMicro(),
        ]);

        $this->events->doAction('domain.verified', $domainName, $merchantId);

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
            $domainName = $domain['domain'] ?? '';
            $this->domains->forTenant($merchantId)->deleteScoped($domainId);
            $this->events->doAction('domain.removed', is_string($domainName) ? $domainName : '', $merchantId);
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
        if ($activeDomain !== null && isset($activeDomain['domain']) && is_string($activeDomain['domain'])) {
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

    /**
     * Sets a custom domain as the primary domain for the brand, clearing primary status for other domains of that brand.
     *
     * @param int $domainId The domain ID.
     * @param int $merchantId The brand's merchant ID.
     * @return void
     */
    public function makePrimary(int $domainId, int $merchantId): void
    {
        $db = $this->domains->getDatabase();
        $db->transaction(function () use ($db, $domainId, $merchantId) {
            // Check if domain belongs to merchant
            $domain = $this->domains->forTenant($merchantId)->findScoped($domainId);
            if ($domain === null) {
                throw new \InvalidArgumentException('Domain not found or unauthorized');
            }

            // Clear primary status for all domains of this brand
            $db->update(
                "UPDATE op_domains SET is_primary = 0 WHERE merchant_id = :mid",
                ['mid' => $merchantId]
            );

            // Set this domain as primary
            $db->update(
                "UPDATE op_domains SET is_primary = 1 WHERE id = :id AND merchant_id = :mid",
                ['id' => $domainId, 'mid' => $merchantId]
            );
        });
    }
}
