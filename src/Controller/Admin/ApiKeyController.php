<?php
declare(strict_types=1);

namespace OwnPay\Controller\Admin;

use OwnPay\Container;
use OwnPay\Http\Request;
use OwnPay\Http\Response;
use OwnPay\Service\Admin\AdminSession;
use OwnPay\Service\Customer\ApiKeyService;
use OwnPay\Service\System\AuditService;

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
     * @var AuditService The application audit logging service.
     */
    private AuditService $audit;

    /**
     * ApiKeyController constructor.
     *
     * @param Container     $c       The dependency injection container.
     * @param AdminSession  $session The administrative session service.
     * @param ApiKeyService $keys    The API key management service.
     * @param AuditService  $audit   The application audit logging service.
     */
    public function __construct(Container $c, AdminSession $session, ApiKeyService $keys, AuditService $audit)
    {
        $this->c       = $c;
        $this->session = $session;
        $this->keys    = $keys;
        $this->audit   = $audit;
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

        // Security: the 'admin' scope grants unrestricted access to every
        // /api/v1/admin/* endpoint (AdminBearerAuthMiddleware only checks for
        // the 'admin' scope string, not for specific permissions). Allowing any
        // staff member with the 'api_keys.manage' permission to mint an
        // admin-scoped key would let them self-elevate to full admin-api
        // control without their role actually holding the underlying
        // permissions (devices.manage, sms.manage, etc.).
        // Restrict the 'admin' scope to superadmins only — the same guard that
        // RolesController::update() applies via $_SESSION['is_superadmin'].
        // Non-superadmins can still mint read/write keys for routine work.
        // See audit finding CUS-5 / issue #198.
        if (in_array('admin', $scopes, true) && !$this->session->isSuperadmin()) {
            $this->session->flashError('Only superadmins can generate admin-scoped API keys.');
            return Response::redirect('/admin/developer');
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
        $count = $this->keys->revoke($mid, $id);
        if ($count === 0) {
            $this->session->flashError('API key not found or already revoked');
        } else {
            $this->session->flashSuccess('API key revoked');
            $this->audit->log('api_key.revoked', 'api_keys', $id, null, ['merchant_id' => $mid]);
        }
        $referer = $req->header('Referer');
        $redirectUrl = str_contains($referer, '/admin/settings')
            ? '/admin/settings#tab-api'
            : '/admin/developer';
        return Response::redirect($redirectUrl);
    }

    /**
     * Locks an existing API key for the active brand, immediately preventing it from
     * authorizing any request. Reversible via unlock().
     *
     * @param Request $req The incoming HTTP request.
     *
     * @return Response The HTTP response redirecting back to the developer hub.
     */
    public function lock(Request $req): Response
    {
        $idVal = $req->param('id');
        $id = (int) $idVal;
        $brand = $this->c->get(\OwnPay\Service\Brand\BrandContext::class);
        if (!$brand instanceof \OwnPay\Service\Brand\BrandContext) {
            throw new \RuntimeException('BrandContext service unavailable');
        }
        $brand->resolveFromRequest($req);
        $mid = $brand->getWriteMerchantId();
        $count = $this->keys->lock($mid, $id);
        if ($count === 0) {
            $this->session->flashError('API key not found');
        } else {
            $this->session->flashSuccess('API key locked');
            $this->audit->log('api_key.locked', 'api_keys', $id, null, ['merchant_id' => $mid]);
        }
        $referer = $req->header('Referer');
        $redirectUrl = str_contains($referer, '/admin/settings')
            ? '/admin/settings#tab-api'
            : '/admin/developer';
        return Response::redirect($redirectUrl);
    }

    /**
     * Unlocks a previously locked API key for the active brand, restoring it to active
     * immediately.
     *
     * @param Request $req The incoming HTTP request.
     *
     * @return Response The HTTP response redirecting back to the developer hub.
     */
    public function unlock(Request $req): Response
    {
        $idVal = $req->param('id');
        $id = (int) $idVal;
        $brand = $this->c->get(\OwnPay\Service\Brand\BrandContext::class);
        if (!$brand instanceof \OwnPay\Service\Brand\BrandContext) {
            throw new \RuntimeException('BrandContext service unavailable');
        }
        $brand->resolveFromRequest($req);
        $mid = $brand->getWriteMerchantId();
        $count = $this->keys->unlock($mid, $id);
        if ($count === 0) {
            $this->session->flashError('API key not found');
        } else {
            $this->session->flashSuccess('API key unlocked');
            $this->audit->log('api_key.unlocked', 'api_keys', $id, null, ['merchant_id' => $mid]);
        }
        $referer = $req->header('Referer');
        $redirectUrl = str_contains($referer, '/admin/settings')
            ? '/admin/settings#tab-api'
            : '/admin/developer';
        return Response::redirect($redirectUrl);
    }
}
