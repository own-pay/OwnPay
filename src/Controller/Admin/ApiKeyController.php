<?php
declare(strict_types=1);

namespace OwnPay\Controller\Admin;

use OwnPay\Container;
use OwnPay\Http\Request;
use OwnPay\Http\Response;
use OwnPay\Service\Admin\AdminSession;
use OwnPay\Service\Customer\ApiKeyService;

final class ApiKeyController
{
    use AdminPageTrait;

    private Container $c;
    private AdminSession $session;
    private ApiKeyService $keys;

    public function __construct(Container $c, AdminSession $session, ApiKeyService $keys)
    {
        $this->c       = $c;
        $this->session = $session;
        $this->keys    = $keys;
    }

    public function index(Request $req): Response
    {
        return Response::redirect('/admin/settings#tab-api');
    }

    public function generate(Request $req): Response
    {
        $brand = $this->c->get(\OwnPay\Service\Brand\BrandContext::class);
        $brand->resolveFromRequest($req);
        $mid = $brand->getActiveBrandId();
        $label = $req->post('label', 'Default');
        $key = $this->keys->generate($mid, $label);

        // Store generated key in session for one-time display in template.
        // The developer hub template reads this and shows a professional copy panel.
        $_SESSION['_generated_api_key'] = $key['key'];
        $_SESSION['_generated_api_key_label'] = $label;

        $this->session->flashSuccess("API key \"{$label}\" generated successfully. Copy it below — it won't be shown again.");
        return Response::redirect('/admin/developer');
    }

    public function revoke(Request $req): Response
    {
        $id = (int) $req->param('id');
        $brand = $this->c->get(\OwnPay\Service\Brand\BrandContext::class);
        $brand->resolveFromRequest($req);
        $mid = $brand->getActiveBrandId();
        $this->keys->revoke($mid, $id);
        $this->session->flashSuccess('API key revoked');
        return Response::redirect('/admin/settings#tab-api');
    }
}
