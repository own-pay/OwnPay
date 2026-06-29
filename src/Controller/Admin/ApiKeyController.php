<?php
declare(strict_types=1);

namespace OwnPay\Controller\Admin;

use OwnPay\Container;
use OwnPay\Http\Request;
use OwnPay\Http\Response;
use OwnPay\Service\Admin\AdminSession;
use OwnPay\Service\Customer\ApiKeyService;

/**
 * Class ApiKeyController
 *
 * Handles API key generation and revocation for brands within the administration interface.
 *
 * @package OwnPay\Controller\Admin
 */
final class ApiKeyController
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
     * @var ApiKeyService The API key management service.
     */
    private ApiKeyService $keys;

    /**
     * ApiKeyController constructor.
     *
     * @param Container     $c       The dependency injection container.
     * @param AdminSession  $session The administrative session service.
     * @param ApiKeyService $keys    The API key management service.
     */
    public function __construct(Container $c, AdminSession $session, ApiKeyService $keys)
    {
        $this->c       = $c;
        $this->session = $session;
        $this->keys    = $keys;
    }

    /**
     * Redirects to the API settings tab.
     *
     * @param Request $req The incoming HTTP request.
     *
     * @return Response The HTTP response redirecting to the settings tab.
     */
    public function index(Request $req): Response
    {
        return Response::redirect('/admin/settings#tab-api');
    }

    /**
     * Generates a new API key for the active brand.
     *
     * @param Request $req The incoming HTTP request.
     *
     * @return Response The HTTP response redirecting to the developer hub.
     */
    public function generate(Request $req): Response
    {
        $brand = $this->c->get(\OwnPay\Service\Brand\BrandContext::class);
        if (!$brand instanceof \OwnPay\Service\Brand\BrandContext) {
            throw new \RuntimeException('BrandContext service unavailable');
        }
        $brand->resolveFromRequest($req);
        // All Brands view → platform-owner id: the key and the data it creates are platform-owned
        // and readable only by All Brands. Brand view → that brand's id: key + data are brand-owned,
        // readable by the brand AND All Brands (via the unfiltered All-Brands reads).
        $mid = $brand->getWriteMerchantId();
        $labelVal = $req->post('label', 'Default');
        $label = is_string($labelVal) ? $labelVal : 'Default';

        $scopesVal = $req->post('scopes');
        $scopes = ['read', 'write'];
        if (is_array($scopesVal)) {
            $allowed = ['read', 'write', 'admin'];
            $valid = [];
            foreach ($scopesVal as $s) {
                if (is_string($s) && in_array($s, $allowed, true)) {
                    $valid[] = $s;
                }
            }
            if (!empty($valid)) {
                $scopes = array_values(array_unique($valid));
            }
        }

        $key = $this->keys->generate($mid, $label, $scopes);

        $_SESSION['_generated_api_key'] = $key['key'];
        $_SESSION['_generated_api_key_label'] = $label;

        $this->session->flashSuccess("API key \"{$label}\" generated successfully. Copy it below - it won't be shown again.");
        return Response::redirect('/admin/developer');
    }

    /**
     * Revokes an existing API key for the active brand.
     *
     * @param Request $req The incoming HTTP request.
     *
     * @return Response The HTTP response redirecting back to the API settings tab.
     */
    public function revoke(Request $req): Response
    {
        $idVal = $req->param('id');
        $id = (int)$idVal;
        $brand = $this->c->get(\OwnPay\Service\Brand\BrandContext::class);
        if (!$brand instanceof \OwnPay\Service\Brand\BrandContext) {
            throw new \RuntimeException('BrandContext service unavailable');
        }
        $brand->resolveFromRequest($req);
        // All Brands view revokes platform-owned keys; a brand view revokes its own.
        $mid = $brand->getWriteMerchantId();
        $this->keys->revoke($mid, $id);
        $this->session->flashSuccess('API key revoked');
        $referer = $req->header('Referer');
        $redirectUrl = str_contains($referer, '/admin/settings') 
            ? '/admin/settings#tab-api' 
            : '/admin/developer';
        return Response::redirect($redirectUrl);
    }
}
