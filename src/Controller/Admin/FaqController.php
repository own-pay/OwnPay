<?php
declare(strict_types=1);

namespace OwnPay\Controller\Admin;

use OwnPay\Container;
use OwnPay\Service\Admin\AdminSession;
use OwnPay\Http\Request;
use OwnPay\Http\Response;
use OwnPay\Repository\SettingsRepository;

final class FaqController
{
    use AdminPageTrait;

    private Container $c;
    private AdminSession $session;
    private SettingsRepository $settings;

    public function __construct(Container $c, AdminSession $session, SettingsRepository $settings)
    {
        $this->c        = $c;
        $this->session  = $session;
        $this->settings = $settings;
    }

    public function index(Request $req): Response
    {
        return Response::redirect('/admin/settings#tab-faq');
    }

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
