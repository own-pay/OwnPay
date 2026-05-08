<?php
declare(strict_types=1);

namespace OwnPay\Controller\Admin;

use OwnPay\Container;
use OwnPay\Service\Admin\AdminSession;
use OwnPay\Http\Request;
use OwnPay\Http\Response;
use OwnPay\Event\EventManager;

final class SettingsController
{
    use AdminPageTrait;

    private Container $c;
    private AdminSession $session;
    private EventManager $events;
    private \OwnPay\Repository\SettingsRepository $settingsRepo;

    public function __construct(Container $c, AdminSession $session, EventManager $events, \OwnPay\Repository\SettingsRepository $settingsRepo)
    {
        $this->c = $c;
        $this->session = $session;
        $this->events = $events;
        $this->settingsRepo = $settingsRepo;
    }

    public function index(Request $req, string $activeTab = 'general'): Response
    {
        $settings = $this->settingsRepo->getGroup('general');

        if (isset($settings['faqs']) && is_string($settings['faqs'])) {
            $decoded = json_decode($settings['faqs'], true);
            $settings['faqs'] = (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) ? $decoded : [];
        }

        // Also check maintenance lock file status
        $lockFile = dirname(__DIR__, 3) . '/storage/.maintenance';
        if (file_exists($lockFile) && empty($settings['maintenance_mode'])) {
            $settings['maintenance_mode'] = '1';
        }

        $currencyService = $this->c->get(\OwnPay\Service\Payment\CurrencyService::class);
        $currencies = $currencyService->listAll();
        $timezones = \DateTimeZone::listIdentifiers();

        $brand = $this->c->get(\OwnPay\Service\Brand\BrandContext::class);
        $brand->resolveFromRequest($req);
        $mid = $brand->getActiveBrandId();
        $apiKeys = $this->c->get(\OwnPay\Service\Customer\ApiKeyService::class)->list($mid);

        return $this->renderAdminPage('admin/settings/index.twig', [
            'settings'    => $settings,
            'currencies'  => $currencies,
            'timezones'   => $timezones,
            'api_keys'    => $apiKeys,
            'active_page' => 'settings',
            'default_tab' => $activeTab,
        ]);
    }

    public function save(Request $req): Response
    {
        $data = $req->post();
        unset($data['_csrf_token']);

        // Checkbox fields: unchecked = no POST value → explicitly set to '0'
        $checkboxFields = [
            'maintenance_mode', 'force_https', 'require_2fa',
            'sms_verification', 'auto_approve_payments',
            'email_on_payment', 'email_on_refund',
        ];
        foreach ($checkboxFields as $cb) {
            if (!isset($data[$cb])) {
                $data[$cb] = '0';
            }
        }

        // Handle FAQs as JSON
        if (isset($data['faqs'])) {
            $data['faqs'] = json_encode(array_values($data['faqs']));
        }

        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = json_encode($value);
            }
        }

        $this->settingsRepo->bulkSet('general', $data);

        // Sync maintenance lock file with DB setting
        $lockFile = dirname(__DIR__, 3) . '/storage/.maintenance';
        if (!empty($data['maintenance_mode']) && $data['maintenance_mode'] !== '0') {
            file_put_contents($lockFile, json_encode([
                'reason'     => 'System maintenance in progress. Please try again shortly.',
                'retry_after' => 600,
                'started_at' => date('c'),
            ]));
        } elseif (file_exists($lockFile)) {
            @unlink($lockFile);
        }

        $this->events->doAction('settings.saved', $data);
        $this->session->flashSuccess('Settings saved');
        return Response::redirect('/admin/settings');
    }

    /** GET /admin/settings/{tab} — tabbed settings view */
    public function tab(Request $req): Response
    {
        $tab = $req->param('tab', 'general');
        return $this->index($req, $tab);
    }
}
