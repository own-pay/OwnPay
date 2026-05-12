<?php
declare(strict_types=1);

namespace OwnPay\Controller\Admin;

use OwnPay\Http\Response;
use OwnPay\Service\Admin\AdminSession;

/**
 * AdminPageTrait — shared rendering for admin controllers.
 *
 * Requires: $this->c (Container), $this->session (AdminSession)
 */
trait AdminPageTrait
{
    private function renderAdminPage(string $tpl, array $data = []): Response
    {
        $twig = $this->c->get(\Twig\Environment::class);
        $data['app_name']    = $this->c->get('config.app')['name'] ?? 'Own Pay';
        $data['app_version'] = $this->c->get('config.app')['version'] ?? '0.1.0';
        $data['csrf_token']  = \OwnPay\Security\SecurityHelpers::csrfToken();
        $data['current_user'] = $this->session->currentUser();
        $data['is_superadmin']   = $this->session->isSuperadmin();
        $data['unread_alerts']   = 0;

        $flash = $this->session->consumeFlash();
        $data['flash_success'] = $flash['success'];
        $data['flash_error']   = $flash['error'];

        if ($this->c->has(\OwnPay\Service\Brand\BrandContext::class)) {
            $brandCtx = $this->c->get(\OwnPay\Service\Brand\BrandContext::class);
            $data['brands']          = $brandCtx->getAllBrands();
            $data['active_brand']    = $brandCtx->getActiveBrand();
            $data['active_brand_id'] = $brandCtx->getActiveBrandId();
        }

        // Inject branding settings (logo, favicon, site title)
        if ($this->c->has(\OwnPay\Repository\SettingsRepository::class)) {
            $sr = $this->c->get(\OwnPay\Repository\SettingsRepository::class);
            $data['settings_logo']  = $sr->get('branding', 'site_logo', '');
            $data['site_favicon']   = $sr->get('branding', 'site_favicon', '');
            $data['site_title']     = $sr->get('branding', 'admin_panel_title', $data['app_name']);
        }

        return Response::html($twig->render($tpl, $data));
    }
}
