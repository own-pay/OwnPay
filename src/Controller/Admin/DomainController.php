<?php
declare(strict_types=1);

namespace OwnPay\Controller\Admin;

use OwnPay\Container;
use OwnPay\Service\Admin\AdminSession;
use OwnPay\Http\Request;
use OwnPay\Http\Response;
use OwnPay\Security\UrlValidator;
use OwnPay\Service\Domain\DomainService;

/**
 * Class DomainController
 *
 * Coordinates administrative custom domain configuration, validation, mapping, verification, and deletion.
 *
 * @package OwnPay\Controller\Admin
 */
final class DomainController
{
    use AdminPageTrait;

    /**
     * @var Container The dependency injection container.
     */
    private Container $c;

    /**
     * @var AdminSession The administrative session service.
     */
    private AdminSession $session;

    /**
     * @var DomainService The custom domain management service.
     */
    private DomainService $domains;

    /**
     * DomainController constructor.
     *
     * @param Container     $c       The dependency injection container.
     * @param AdminSession  $session The administrative session service.
     * @param DomainService $domains The custom domain management service.
     */
    public function __construct(Container $c, AdminSession $session, DomainService $domains)
    {
        $this->c = $c;
        $this->session = $session;
        $this->domains = $domains;
    }

    /**
     * Renders the custom domains overview list page for the active brand.
     *
     * @param Request $req The incoming HTTP request.
     *
     * @return Response The custom domains dashboard view response.
     */
    public function index(Request $req): Response
    {
        $brand = $this->c->get(\OwnPay\Service\Brand\BrandContext::class);
        if (!$brand instanceof \OwnPay\Service\Brand\BrandContext) {
            throw new \RuntimeException('BrandContext service unavailable');
        }
        $brand->resolveFromRequest($req);
        $mid = $brand->getActiveBrandId();
        if ($mid === null) {
            throw new \RuntimeException('No active brand found.');
        }

        $repo = $this->c->get(\OwnPay\Repository\DomainRepository::class);
        if (!$repo instanceof \OwnPay\Repository\DomainRepository) {
            throw new \RuntimeException('DomainRepository service unavailable');
        }
        $list = $repo->forTenant($mid)->listAllScoped();

        $merchantRepo = $this->c->get(\OwnPay\Repository\MerchantRepository::class);
        if (!$merchantRepo instanceof \OwnPay\Repository\MerchantRepository) {
            throw new \RuntimeException('MerchantRepository service unavailable');
        }

        foreach ($list as &$d) {
            $merchantIdVal = $d['merchant_id'] ?? 0;
            $merchantId = is_int($merchantIdVal) || is_string($merchantIdVal) ? (int)$merchantIdVal : 0;
            $m = $merchantRepo->find($merchantId);
            $d['merchant_name'] = is_array($m) && is_string($m['name'] ?? null) ? $m['name'] : '-';
            $d['status_pill'] = self::computeStatusPill($d);
        }

        // Use the configured APP_DOMAIN for the server-IP hint. The request
        // Host header is attacker-controlled and must never drive
        // gethostbyname() lookups (audit DOM-4). On misconfigured installs
        // (APP_DOMAIN unset) we degrade to '127.0.0.1' rather than leaking
        // through client input.
        $appDomainVal = $_ENV['APP_DOMAIN'] ?? getenv('APP_DOMAIN') ?: '';
        $serverHost = is_string($appDomainVal) && $appDomainVal !== ''
            ? $appDomainVal
            : '127.0.0.1';
        $serverHost = (string) (parse_url('https://' . $serverHost, PHP_URL_HOST) ?: '127.0.0.1');

        return $this->renderAdminPage('admin/domains/index.twig', [
            'domains'     => $list,
            'active_page' => 'domains',
            'server_ip'   => gethostbyname($serverHost),
        ]);
    }

    /**
     * Maps a new custom domain to the active brand.
     *
     * @param Request $req The incoming HTTP request.
     *
     * @return Response The redirect response.
     */
    public function store(Request $req): Response
    {
        $brand = $this->c->get(\OwnPay\Service\Brand\BrandContext::class);
        if (!$brand instanceof \OwnPay\Service\Brand\BrandContext) {
            throw new \RuntimeException('BrandContext service unavailable');
        }
        $brand->resolveFromRequest($req);
        $mid = $brand->getActiveBrandId();
        if ($mid === null) {
            throw new \RuntimeException('No active brand found.');
        }

        $domainVal = $req->post('domain', '');
        $domain = is_string($domainVal) ? trim($domainVal) : '';
        if ($domain === '') {
            $this->session->flashError('Domain required');
            return $this->redirectBack($req);
        }

        $typeVal = $req->post('type', 'checkout');
        $type = is_string($typeVal) && in_array($typeVal, ['checkout', 'api'], true) ? $typeVal : 'checkout';

        $redirectUrlVal = $req->post('redirect_url', '');
        $redirectUrl = is_string($redirectUrlVal) ? trim($redirectUrlVal) : null;
        if ($redirectUrl === '') {
            $redirectUrl = null;
        }

        // SECURITY (DOM-2): validate redirect_url before persisting. Without
        // this check, a brand admin can set redirect_url to an external host
        // (e.g. https://attacker.com) and 302-redirect every visitor of the
        // brand's custom domain root to a phishing site. The validator accepts
        // same-origin absolute URLs (http/https, host matches the configured
        // custom domain) or relative paths starting with `/` (which can never
        // escape the custom domain origin). External hosts, javascript:, data:,
        // and protocol-relative `//host` values are rejected.
        try {
            $validatedRedirect = $this->validateRedirectUrl($redirectUrl, $domain);
        } catch (\InvalidArgumentException $e) {
            $errorMsg = $e->getMessage();
            if ($req->isAjax()) {
                return Response::json(['success' => false, 'error' => $errorMsg]);
            }
            $this->session->flashError($errorMsg);
            return $this->redirectBack($req);
        }

        $result = $this->domains->map($mid, $domain, $type, $validatedRedirect);

        if ($req->isAjax()) {
            if (empty($result['success'])) {
                return Response::json(['success' => false, 'error' => $result['error']]);
            }
            $repo = $this->c->get(\OwnPay\Repository\DomainRepository::class);
            if (!$repo instanceof \OwnPay\Repository\DomainRepository) {
                throw new \RuntimeException('DomainRepository service unavailable');
            }
            $created = $repo->forTenant($mid)->findScoped((int) $result['domain_id']);
            return Response::json([
                'success' => true,
                'domain'  => $this->domainJsonPayload($created ?? []),
            ]);
        }

        if (!empty($result['success'])) {
            $this->session->flashSuccess('Domain added. ' . $result['instructions']);
        } else {
            $this->session->flashError($result['error']);
        }
        return $this->redirectBack($req);
    }

    /**
     * Triggers DNS checks (TXT & A records) to verify domain mappings.
     *
     * @param Request $req The incoming HTTP request.
     *
     * @return Response The redirect response.
     */
    public function verify(Request $req): Response
    {
        $id = (int) $req->param('id');
        $brand = $this->c->get(\OwnPay\Service\Brand\BrandContext::class);
        if (!$brand instanceof \OwnPay\Service\Brand\BrandContext) {
            throw new \RuntimeException('BrandContext service unavailable');
        }
        $brand->resolveFromRequest($req);
        $mid = $brand->getActiveBrandId();
        if ($mid === null) {
            throw new \RuntimeException('No active brand found.');
        }

        $result = $this->domains->verify($id, $mid);

        if ($req->isAjax()) {
            if (empty($result['success'])) {
                return Response::json(['success' => false, 'error' => $result['error']]);
            }
            $repo = $this->c->get(\OwnPay\Repository\DomainRepository::class);
            if (!$repo instanceof \OwnPay\Repository\DomainRepository) {
                throw new \RuntimeException('DomainRepository service unavailable');
            }
            $updated = $repo->forTenant($mid)->findScoped($id);
            return Response::json([
                'success' => true,
                'domain'  => $this->domainJsonPayload($updated ?? []),
                'warning' => $result['warning'] ?? null,
            ]);
        }

        if (!empty($result['success'])) {
            $msg = 'DNS verified!';
            if (!empty($result['warning'])) {
                $msg .= ' ⚠️ ' . $result['warning'];
            }
            $this->session->flashSuccess($msg);
        } else {
            $this->session->flashError($result['error']);
        }
        return $this->redirectBack($req);
    }

    /**
     * Removes an existing custom domain mapping.
     *
     * @param Request $req The incoming HTTP request.
     *
     * @return Response The redirect response.
     */
    public function delete(Request $req): Response
    {
        $id = (int) $req->param('id');
        $brand = $this->c->get(\OwnPay\Service\Brand\BrandContext::class);
        if (!$brand instanceof \OwnPay\Service\Brand\BrandContext) {
            throw new \RuntimeException('BrandContext service unavailable');
        }
        $brand->resolveFromRequest($req);
        $mid = $brand->getActiveBrandId();
        if ($mid === null) {
            throw new \RuntimeException('No active brand found.');
        }

        if ($req->isAjax()) {
            $this->domains->remove($id, $mid);
            return Response::json(['success' => true]);
        }

        $this->domains->remove($id, $mid);
        $this->session->flashSuccess('Domain removed');
        return $this->redirectBack($req);
    }

    /**
     * Designates a custom domain as primary for the brand.
     *
     * @param Request $req The incoming HTTP request.
     *
     * @return Response The redirect response.
     */
    public function primary(Request $req): Response
    {
        $id = (int) $req->param('id');
        $brand = $this->c->get(\OwnPay\Service\Brand\BrandContext::class);
        if (!$brand instanceof \OwnPay\Service\Brand\BrandContext) {
            throw new \RuntimeException('BrandContext service unavailable');
        }
        $brand->resolveFromRequest($req);
        $mid = $brand->getActiveBrandId();
        if ($mid === null) {
            throw new \RuntimeException('No active brand found.');
        }

        if ($req->isAjax()) {
            try {
                $this->domains->makePrimary($id, $mid);
            } catch (\Throwable $e) {
                return Response::json(['success' => false, 'error' => $e->getMessage()]);
            }
            $repo = $this->c->get(\OwnPay\Repository\DomainRepository::class);
            if (!$repo instanceof \OwnPay\Repository\DomainRepository) {
                throw new \RuntimeException('DomainRepository service unavailable');
            }
            $updated = $repo->forTenant($mid)->findScoped($id);
            return Response::json(['success' => true, 'domain' => $this->domainJsonPayload($updated ?? [])]);
        }

        try {
            $this->domains->makePrimary($id, $mid);
            $this->session->flashSuccess('Primary domain updated');
        } catch (\Throwable $e) {
            $this->session->flashError($e->getMessage());
        }
        return $this->redirectBack($req);
    }

    /**
     * Updates an existing custom domain mapping configuration.
     *
     * @param Request $req The incoming HTTP request.
     *
     * @return Response The redirect response.
     */
    public function update(Request $req): Response
    {
        $id = (int) $req->param('id');
        $brand = $this->c->get(\OwnPay\Service\Brand\BrandContext::class);
        if (!$brand instanceof \OwnPay\Service\Brand\BrandContext) {
            throw new \RuntimeException('BrandContext service unavailable');
        }
        $brand->resolveFromRequest($req);
        $mid = $brand->getActiveBrandId();
        if ($mid === null) {
            throw new \RuntimeException('No active brand found.');
        }

        $repo = $this->c->get(\OwnPay\Repository\DomainRepository::class);
        if (!$repo instanceof \OwnPay\Repository\DomainRepository) {
            throw new \RuntimeException('DomainRepository service unavailable');
        }

        $domainRecord = $repo->forTenant($mid)->findScoped($id);
        if ($domainRecord === null) {
            $this->session->flashError('Domain not found');
            return $this->redirectBack($req);
        }

        $typeVal = $req->post('type', 'checkout');
        $type = is_string($typeVal) && in_array($typeVal, ['checkout', 'api'], true) ? $typeVal : 'checkout';

        $redirectUrlVal = $req->post('redirect_url', '');
        $redirectUrl = is_string($redirectUrlVal) ? trim($redirectUrlVal) : null;
        if ($redirectUrl === '') {
            $redirectUrl = null;
        }

        // SECURITY (DOM-2): same redirect_url validation as store(). The
        // configured custom domain is the same-origin host the redirect must
        // stay within. Reject external hosts / dangerous schemes up-front so
        // the stored value can never become an open-redirect sink.
        $domainName = is_string($domainRecord['domain'] ?? null) ? (string) $domainRecord['domain'] : '';
        try {
            $validatedRedirect = $this->validateRedirectUrl($redirectUrl, $domainName);
        } catch (\InvalidArgumentException $e) {
            $errorMsg = $e->getMessage();
            if ($req->isAjax()) {
                return Response::json(['success' => false, 'error' => $errorMsg]);
            }
            $this->session->flashError($errorMsg);
            return $this->redirectBack($req);
        }

        $statusVal = $req->post('status', 'pending');
        $status = is_string($statusVal) && in_array($statusVal, ['active', 'pending', 'inactive'], true) ? $statusVal : 'pending';

        $dnsVerifiedVal = $req->post('dns_verified', '0');
        $dnsVerified = (is_scalar($dnsVerifiedVal) && (int) $dnsVerifiedVal === 1) ? 1 : 0;

        $isPrimaryVal = $req->post('is_primary', '0');
        $isPrimary = (is_scalar($isPrimaryVal) && (int) $isPrimaryVal === 1) ? 1 : 0;

        $updateData = [
            'type'         => $type,
            'redirect_url' => $validatedRedirect,
            'status'       => $status,
            'dns_verified' => $dnsVerified,
        ];

        // Handles toggling primary status
        if ($isPrimary === 1 && !$domainRecord['is_primary']) {
            $this->domains->makePrimary($id, $mid);
        } elseif ($isPrimary === 0 && $domainRecord['is_primary']) {
            $db = $repo->getDatabase();
            $db->update(
                "UPDATE op_domains SET is_primary = 0 WHERE id = :id AND merchant_id = :mid",
                ['id' => $id, 'mid' => $mid]
            );
        }

        $repo->forTenant($mid)->updateScoped($id, $updateData);

        if ($req->isAjax()) {
            $updated = $repo->forTenant($mid)->findScoped($id);
            return Response::json(['success' => true, 'domain' => $this->domainJsonPayload($updated ?? [])]);
        }

        $this->session->flashSuccess('Domain settings updated successfully.');
        return $this->redirectBack($req);
    }

    /**
     * Triggers an SSL handshake probe check on the custom domain.
     *
     * @param Request $req The incoming HTTP request.
     *
     * @return Response The redirect response.
     */
    public function checkSsl(Request $req): Response
    {
        $id = (int) $req->param('id');
        $brand = $this->c->get(\OwnPay\Service\Brand\BrandContext::class);
        if (!$brand instanceof \OwnPay\Service\Brand\BrandContext) {
            throw new \RuntimeException('BrandContext service unavailable');
        }
        $brand->resolveFromRequest($req);
        $mid = $brand->getActiveBrandId();
        if ($mid === null) {
            throw new \RuntimeException('No active brand found.');
        }

        $repo = $this->c->get(\OwnPay\Repository\DomainRepository::class);
        if (!$repo instanceof \OwnPay\Repository\DomainRepository) {
            throw new \RuntimeException('DomainRepository service unavailable');
        }

        $domainRecord = $repo->forTenant($mid)->findScoped($id);
        if ($domainRecord === null) {
            $this->session->flashError('Domain not found');
            return $this->redirectBack($req);
        }

        $domain = $domainRecord['domain'];
        if (!is_string($domain)) {
            $this->session->flashError('Domain field is not a string');
            return $this->redirectBack($req);
        }
        $sslStatus = $this->checkSslStatus($domain);

        $repo->forTenant($mid)->updateScoped($id, [
            'ssl_status' => $sslStatus
        ]);

        if ($req->isAjax()) {
            $updated = $repo->forTenant($mid)->findScoped($id);
            return Response::json([
                'success' => $sslStatus === 'active',
                'domain'  => $this->domainJsonPayload($updated ?? []),
                'error'   => $sslStatus === 'active' ? null : "SSL certificate check failed or certificate invalid/expired for {$domain}.",
            ]);
        }

        if ($sslStatus === 'active') {
            $this->session->flashSuccess("SSL certificate check succeeded for {$domain}! Status: Active");
        } else {
            $this->session->flashError("SSL certificate check failed or certificate invalid/expired for {$domain}.");
        }

        return $this->redirectBack($req);
    }

    /**
     * Checks if the custom domain has a valid SSL certificate.
     *
     * @param string $domain The custom domain.
     * @return string Status: 'active', 'none', or 'expired'
     */
    private function checkSslStatus(string $domain): string
    {
        $g = stream_context_create([
            "ssl" => [
                "capture_peer_cert" => true,
                "verify_peer" => true,
                "verify_peer_name" => true,
            ]
        ]);
        $r = @stream_socket_client(
            "ssl://{$domain}:443",
            $errno,
            $errstr,
            5,
            STREAM_CLIENT_CONNECT,
            $g
        );

        if ($r) {
            $cont = stream_context_get_params($r);
            if (isset($cont["options"]["ssl"]) 
                && is_array($cont["options"]["ssl"]) 
                && isset($cont["options"]["ssl"]["peer_certificate"])
            ) {
                $cert = $cont["options"]["ssl"]["peer_certificate"];
                if (is_string($cert) || is_object($cert)) {
                    /** @var string|\OpenSSLCertificate $cert */
                    $info = openssl_x509_parse($cert);
                    if (is_array($info) && isset($info['validTo_time_t'])) {
                        if ($info['validTo_time_t'] > time()) {
                            return 'active';
                        } else {
                            return 'expired';
                        }
                    }
                }
            }
            return 'active';
        }

        return 'none';
    }

    /**
     * Computes the single collapsed-card status pill for a domain record.
     * Priority order (first match wins): inactive > pending DNS > SSL issue > active.
     *
     * @param array<string, mixed> $domain
     * @return array{label: string, class: string}
     */
    public static function computeStatusPill(array $domain): array
    {
        $status = is_string($domain['status'] ?? null) ? $domain['status'] : '';
        $dnsVerified = !empty($domain['dns_verified']);
        $sslStatus = is_string($domain['ssl_status'] ?? null) ? $domain['ssl_status'] : 'none';

        if ($status === 'inactive') {
            return ['label' => 'Inactive', 'class' => 'op-badge-danger'];
        }
        if (!$dnsVerified || $status === 'pending' || $status === '') {
            return ['label' => 'Pending DNS', 'class' => 'op-badge-warning'];
        }
        if ($sslStatus !== 'active') {
            return ['label' => 'SSL Issue', 'class' => 'op-badge-warning'];
        }
        return ['label' => 'Active', 'class' => 'op-badge-success'];
    }

    /**
     * Builds the JSON `domain` payload shared by every AJAX-gated action response.
     *
     * @param array<string, mixed> $domain
     * @return array<string, mixed>
     */
    private function domainJsonPayload(array $domain): array
    {
        return [
            'id'                 => $domain['id'] ?? null,
            'domain'             => $domain['domain'] ?? '',
            'type'               => $domain['type'] ?? 'checkout',
            'redirect_url'       => $domain['redirect_url'] ?? null,
            'status'             => $domain['status'] ?? 'pending',
            'dns_verified'       => (bool) ($domain['dns_verified'] ?? false),
            'ssl_status'         => $domain['ssl_status'] ?? 'none',
            'is_primary'         => (bool) ($domain['is_primary'] ?? false),
            'verification_token' => $domain['verification_token'] ?? null,
            'status_pill'        => self::computeStatusPill($domain),
        ];
    }

    /**
     * Validates the redirect_url field for store()/update() (DOM-2).
     *
     * Returns:
     *   - null  if the input is empty (no redirect configured — always valid).
     *   - string (the validated value) if the input is a safe relative path
     *     (`/checkout`, `/foo?bar=1`) or a same-origin absolute URL
     *     (http/https whose host equals $allowedDomain or is a subdomain of it).
     *
     * Throws \InvalidArgumentException if the input is non-empty but fails
     * validation (external host, dangerous scheme, protocol-relative `//host`,
     * etc.). The caller is expected to catch it, flash an error, and abort the
     * request without persisting.
     *
     * @param string|null $url The trimmed user-supplied redirect URL (null = empty).
     * @param string $allowedDomain The custom domain being configured (same-origin constraint).
     * @return string|null The validated URL, or null if input was empty.
     * @throws \InvalidArgumentException When the URL is non-empty but invalid.
     */
    private function validateRedirectUrl(?string $url, string $allowedDomain): ?string
    {
        if ($url === null || $url === '') {
            return null;
        }

        // Relative path: must start with `/` but not `//` (protocol-relative,
        // resolves to https://host) or `/\` (backslash variant — some browsers
        // normalize this to `//`).
        if (str_starts_with($url, '/') && !str_starts_with($url, '//') && !str_starts_with($url, '/\\')) {
            return $url;
        }

        // Absolute URL: must be http/https and same-origin. UrlValidator rejects
        // external hosts, javascript:, data:, userinfo, and any non-http(s) scheme.
        if (UrlValidator::isValidRedirect($url, $allowedDomain)) {
            return $url;
        }

        throw new \InvalidArgumentException(
            'Redirect URL must be a relative path (e.g. /checkout) or an absolute URL on this domain (https://'
            . $allowedDomain . '/path).'
        );
    }

    /**
     * Redirects back to the custom domains overview page.
     */
    private function redirectBack(Request $req): Response
    {
        return Response::redirect('/admin/domains');
    }
}
