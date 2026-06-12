<?php
declare(strict_types=1);

namespace OwnPay\Controller\Admin;

use OwnPay\Http\Response;
use OwnPay\Service\Admin\AdminSession;

/**
 * Trait AdminPageTrait
 *
 * Provides shared rendering capabilities for all administrative controllers.
 * Expects the incorporating controller to define properties for the DI container ($c)
 * and the administrative session service ($session).
 *
 * @package OwnPay\Controller\Admin
 */
trait AdminPageTrait
{
    /**
     * @var \OwnPay\Container
     */
    private \OwnPay\Container $c;

    /**
     * @var \OwnPay\Service\Admin\AdminSession
     */
    private \OwnPay\Service\Admin\AdminSession $session;

    /**
     * Renders an administrative page template injecting default layouts, active context,
     * flash notifications, active brand parameters, and firing template resolution event filters.
     *
     * @param string               $tpl  Relative path or namespace identifier of the Twig template.
     * @param array<string, mixed> $data Associative array of template variables.
     *
     * @return \OwnPay\Http\Response The compiled HTML response object.
     */
    private function renderAdminPage(string $tpl, array $data = []): Response
    {
        $c = $this->c;
        $session = $this->session;
        $twig = $c->get(\Twig\Environment::class);
        
        $appConfig = $c->get('config.app');
        $appName = is_array($appConfig) && isset($appConfig['name']) && is_string($appConfig['name']) ? $appConfig['name'] : 'Own Pay';
        $appVersion = is_array($appConfig) && isset($appConfig['version']) && is_string($appConfig['version']) ? $appConfig['version'] : '0.1.0';

        $data['app_name']    = $appName;
        $data['app_version'] = $appVersion;
        $data['csrf_token']  = \OwnPay\Security\SecurityHelpers::csrfToken();
        $data['current_user'] = $session->currentUser();
        $data['is_superadmin']   = $session->isSuperadmin();
        $data['unread_alerts']   = 0;

        $flash = $session->consumeFlash();
        $data['flash_success'] = $flash['success'] ?? null;
        $data['flash_error']   = $flash['error'] ?? null;

        if ($c->has(\OwnPay\Service\Brand\BrandContext::class)) {
            $brandCtx = $c->get(\OwnPay\Service\Brand\BrandContext::class);
            if ($brandCtx instanceof \OwnPay\Service\Brand\BrandContext) {
                $data['brands']          = $brandCtx->getAllBrands();
                $data['active_brand']    = $brandCtx->getActiveBrand();
                $data['active_brand_id'] = $brandCtx->getActiveBrandId();
            }
        }

        // Inject branding settings (logo, favicon, site title)
        if ($c->has(\OwnPay\Repository\SettingsRepository::class)) {
            $sr = $c->get(\OwnPay\Repository\SettingsRepository::class);
            if ($sr instanceof \OwnPay\Repository\SettingsRepository) {
                $data['settings_logo']  = $sr->get('branding', 'site_logo', '');
                $data['site_favicon']   = $sr->get('branding', 'site_favicon', '');
                $data['site_title']     = $sr->get('branding', 'admin_panel_title', $appName);
            }
        }

        // Apply brand-scoped visual customization if we are contextually in an active brand
        $activeBrandId = $data['active_brand_id'] ?? null;
        if (!empty($activeBrandId) && (is_int($activeBrandId) || is_string($activeBrandId)) && (int)$activeBrandId > 0) {
            if ($c->has(\OwnPay\Service\Brand\BrandThemeService::class)) {
                $themeSvc = $c->get(\OwnPay\Service\Brand\BrandThemeService::class);
                if ($themeSvc instanceof \OwnPay\Service\Brand\BrandThemeService) {
                    $brandTheme = $themeSvc->getBrandTheme((int)$activeBrandId);
                    if ($brandTheme['logo'] !== '') {
                        $data['settings_logo'] = $brandTheme['logo'];
                    }
                    if ($brandTheme['favicon'] !== '') {
                        $data['site_favicon'] = $brandTheme['favicon'];
                    }
                    if ($brandTheme['name'] !== '') {
                        $data['site_title'] = $brandTheme['name'];
                    }
                }
            }
        }

        // Plugin template override system
        // Plugins can modify template name (e.g. replace admin/dashboard.twig with custom version)
        // and inject/modify template data (add widgets, custom variables, etc.)
        if ($c->has(\OwnPay\Event\EventManager::class)) {
            $events = $c->get(\OwnPay\Event\EventManager::class);
            if ($events instanceof \OwnPay\Event\EventManager) {
                $tplFilter = $events->applyFilter('admin.template.resolve', $tpl, $data);
                $tpl = is_string($tplFilter) ? $tplFilter : $tpl;
                $dataFilter = $events->applyFilter('admin.template.data', $data, $tpl);
                $data = is_array($dataFilter) ? $dataFilter : $data;
            }
        }

        if (!$twig instanceof \Twig\Environment) {
            throw new \RuntimeException('Twig Environment not found');
        }

        return Response::html($twig->render($tpl, $data));
    }
}
