<?php
declare(strict_types=1);

namespace OwnPay\Controller\Admin;

use OwnPay\Container;
use OwnPay\Http\Request;
use OwnPay\Http\Response;
use OwnPay\Repository\PluginRepository;
use OwnPay\Service\Admin\AdminSession;
use OwnPay\Service\Brand\BrandContext;
use OwnPay\Service\Domain\DomainUrlService;

/**
 * Class GatewayWebhookController
 *
 * Renders the "Gateway Webhooks (IPN)" reference page: a plain-English explanation of inbound
 * gateway webhooks / IPN callbacks plus the exact URL(s) to paste into each payment gateway's
 * dashboard. The inbound endpoint itself is handled by Webhook\UnifiedWebhookController
 * (POST /webhook/{gateway}); this page only helps the operator configure the gateway side.
 *
 * URL resolution (matches the platform model):
 * - All Brands view  → the platform/main domain (APP_URL).
 * - Brand view       → that brand's verified custom domain when set; otherwise the page instructs
 *                      the operator to add a custom domain (or use the All Brands URL).
 *
 * @package OwnPay\Controller\Admin
 */
final class GatewayWebhookController
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
     * GatewayWebhookController constructor.
     *
     * @param Container    $c       The dependency injection container.
     * @param AdminSession $session The administrative session service.
     */
    public function __construct(Container $c, AdminSession $session)
    {
        $this->c       = $c;
        $this->session = $session;
    }

    /**
     * Renders the inbound gateway webhook / IPN reference and setup guide.
     *
     * @param Request $req The incoming HTTP request.
     *
     * @return Response The webhook reference page response.
     */
    public function index(Request $req): Response
    {
        $mid = 0;
        $brandName = null;
        $brand = $this->c->has(BrandContext::class) ? $this->c->get(BrandContext::class) : null;
        if ($brand instanceof BrandContext) {
            $brand->resolveFromRequest($req);
            $activeId = $brand->getActiveBrandId();
            $mid = $activeId ?? 0;
            $active = $brand->getActiveBrand();
            if (is_array($active) && isset($active['name']) && is_string($active['name'])) {
                $brandName = $active['name'];
            }
        }
        $isGlobal = $this->isGlobalBrandView();

        /** @var DomainUrlService $urlSvc */
        $urlSvc = $this->c->get(DomainUrlService::class);

        // Platform/main base (no brand domain): used by All Brands and as the brand fallback.
        $mainBase = rtrim($urlSvc->resolveBaseUrl(0, $req), '/');

        // Brand custom domain (only when scoped to a real brand).
        $brandDomain = (!$isGlobal && $mid > 0) ? $urlSvc->getBrandDomain($mid) : null;

        // Effective base shown to the operator for THIS view.
        if ($isGlobal) {
            $effectiveBase    = $mainBase;
            $usingBrandDomain = false;
        } else {
            $effectiveBase    = $brandDomain !== null ? 'https://' . $brandDomain : $mainBase;
            $usingBrandDomain = $brandDomain !== null;
        }

        // Ready-to-copy URLs for installed gateways (best-effort; the endpoint accepts any slug).
        $gateways = [];
        $pluginRepo = $this->c->has(PluginRepository::class) ? $this->c->get(PluginRepository::class) : null;
        if ($pluginRepo instanceof PluginRepository) {
            foreach ($pluginRepo->listByType('gateway') as $g) {
                $slug = (isset($g['slug']) && is_string($g['slug'])) ? $g['slug'] : '';
                if ($slug === '') {
                    continue;
                }
                $name = (isset($g['name']) && is_string($g['name']) && $g['name'] !== '') ? $g['name'] : $slug;
                $status = (isset($g['status']) && is_string($g['status'])) ? $g['status'] : '';
                $gateways[] = [
                    'slug'   => $slug,
                    'name'   => $name,
                    'status' => $status,
                    'url'    => $effectiveBase . '/webhook/' . $slug,
                ];
            }
        }

        $sampleSlug = $gateways[0]['slug'] ?? 'your-gateway';

        return $this->renderAdminPage('admin/gateway-webhooks/index.twig', [
            'active_page'        => 'gateway_webhooks',
            'is_global_view'     => $isGlobal,
            'brand_name'         => $brandName,
            'main_webhook_base'  => $mainBase . '/webhook',
            'effective_base'     => $effectiveBase . '/webhook',
            'using_brand_domain' => $usingBrandDomain,
            'brand_domain'       => $brandDomain,
            'gateways'           => $gateways,
            'sample_url'         => $effectiveBase . '/webhook/' . $sampleSlug,
        ]);
    }
}
