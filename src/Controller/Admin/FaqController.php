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
        $faqsVal  = $req->post('faqs', []);
        $faqs = is_array($faqsVal) ? $faqsVal : [];
        $clean = [];
        foreach ($faqs as $f) {
            if (is_array($f) && !empty($f['question'])) {
                $questionVal = $f['question'];
                $answerVal = $f['answer'] ?? '';
                $clean[] = [
                    'question' => is_string($questionVal) ? $questionVal : '',
                    'answer' => is_string($answerVal) ? $answerVal : ''
                ];
            }
        }

        // Save FAQs scoped to the active brand, not global.
        $brand = $this->c->get(\OwnPay\Service\Brand\BrandContext::class);
        if (!$brand instanceof \OwnPay\Service\Brand\BrandContext) {
            throw new \RuntimeException('BrandContext service unavailable');
        }
        $brand->resolveFromRequest($req);
        $midVal = $brand->getActiveBrandId();
        $mid = is_int($midVal) ? $midVal : 0;

        $faqJson = json_encode($clean);
        if ($faqJson === false) {
            $this->session->flashError('Failed to serialize FAQs.');
            return Response::redirect('/admin/settings#tab-faq');
        }

        if ($mid > 0) {
            $this->settings->setScoped('general', 'faqs', $faqJson, $mid);
        } else {
            // Fallback to global (should only happen if no brands exist)
            $this->settings->set('general', 'faqs', $faqJson);
        }

        $this->session->flashSuccess('FAQs saved');
        return Response::redirect('/admin/settings#tab-faq');
    }
}
