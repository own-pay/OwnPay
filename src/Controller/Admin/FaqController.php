<?php
declare(strict_types=1);

namespace OwnPay\Controller\Admin;

use OwnPay\Container;
use OwnPay\Service\Admin\AdminSession;
use OwnPay\Http\Request;
use OwnPay\Http\Response;
use OwnPay\Repository\SettingsRepository;

/**
 * Class FaqController
 *
 * Handles management of frequently asked questions (FAQs) for the active brand/merchant.
 *
 * @package OwnPay\Controller\Admin
 */
final class FaqController
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
     * @var SettingsRepository The settings repository.
     */
    private SettingsRepository $settings;

    /**
     * FaqController constructor.
     *
     * @param Container          $c        The dependency injection container.
     * @param AdminSession       $session  The administrative session service.
     * @param SettingsRepository $settings The settings repository.
     */
    public function __construct(Container $c, AdminSession $session, SettingsRepository $settings)
    {
        $this->c        = $c;
        $this->session  = $session;
        $this->settings = $settings;
    }

    /**
     * Redirects to the system settings page with the FAQ tab active.
     *
     * @param Request $req The incoming HTTP request.
     *
     * @return Response The HTTP redirect response.
     */
    public function index(Request $req): Response
    {
        return Response::redirect('/admin/settings#tab-faq');
    }

    /**
     * Processes and persists updated FAQs, scoped to the active brand if available.
     *
     * @param Request $req The incoming HTTP request.
     *
     * @return Response The HTTP redirect response.
     */
    public function save(Request $req): Response
    {
        $faqs  = $req->post('faqs', []);
        $clean = [];
        foreach ($faqs as $f) {
            if (!empty($f['question'])) {
                $clean[] = ['question' => $f['question'], 'answer' => $f['answer'] ?? ''];
            }
        }

        // BUG-42 FIX: Save FAQs scoped to the active brand, not global.
        // Global-only writes cause all brands to share the same FAQ set.
        $brand = $this->c->get(\OwnPay\Service\Brand\BrandContext::class);
        $brand->resolveFromRequest($req);
        $mid = $brand->getActiveBrandId();

        if ($mid > 0) {
            $this->settings->setScoped('general', 'faqs', json_encode($clean), $mid);
        } else {
            // Fallback to global (should only happen if no brands exist)
            $this->settings->set('general', 'faqs', json_encode($clean));
        }

        $this->session->flashSuccess('FAQs saved');
        return Response::redirect('/admin/settings#tab-faq');
    }
}
