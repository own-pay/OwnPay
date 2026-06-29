<?php
declare(strict_types=1);

namespace OwnPay\Controller\Admin;

use OwnPay\Container;
use OwnPay\Service\Admin\AdminSession;
use OwnPay\Http\Request;
use OwnPay\Http\Response;
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
        }

        // Use the configured APP_DOMAIN for the server-IP hint, not the
        // attacker-controlled Host header (which would let a merchant-user drive
        // gethostbyname() lookups against arbitrary domains).
        $appDomainVal = $_ENV['APP_DOMAIN'] ?? getenv('APP_DOMAIN') ?: '';
        $serverHost = is_string($appDomainVal) && $appDomainVal !== ''
            ? $appDomainVal
            : ($req->header('Host') ?: '127.0.0.1');
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
        $type = is_string($typeVal) && in_array($typeVal, ['checkout', 'admin', 'api'], true) ? $typeVal : 'checkout';

        $redirectUrlVal = $req->post('redirect_url', '');
        $redirectUrl = is_string($redirectUrlVal) ? trim($redirectUrlVal) : null;
        if ($redirectUrl === '') {
            $redirectUrl = null;
        }

        $result = $this->domains->map($mid, $domain, $type, $redirectUrl);
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
        $type = is_string($typeVal) && in_array($typeVal, ['checkout', 'admin', 'api'], true) ? $typeVal : 'checkout';

        $redirectUrlVal = $req->post('redirect_url', '');
        $redirectUrl = is_string($redirectUrlVal) ? trim($redirectUrlVal) : null;
        if ($redirectUrl === '') {
            $redirectUrl = null;
        }

        $statusVal = $req->post('status', 'pending');
        $status = is_string($statusVal) && in_array($statusVal, ['active', 'pending', 'inactive'], true) ? $statusVal : 'pending';

        $dnsVerifiedVal = $req->post('dns_verified', '0');
        $dnsVerified = (is_scalar($dnsVerifiedVal) && (int) $dnsVerifiedVal === 1) ? 1 : 0;

        $isPrimaryVal = $req->post('is_primary', '0');
        $isPrimary = (is_scalar($isPrimaryVal) && (int) $isPrimaryVal === 1) ? 1 : 0;

        $updateData = [
            'type'         => $type,
            'redirect_url' => $redirectUrl,
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
     * Redirects back to either the custom domains overview or settings tab based on Referer.
     */
    private function redirectBack(Request $req): Response
    {
        $referer = $req->header('Referer');
        if ($referer !== '' && str_contains($referer, '/admin/settings')) {
            return Response::redirect('/admin/settings/domains');
        }
        return Response::redirect('/admin/domains');
    }
}
