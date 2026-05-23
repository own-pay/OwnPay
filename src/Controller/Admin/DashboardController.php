<?php
declare(strict_types=1);

namespace OwnPay\Controller\Admin;

use OwnPay\Container;
use OwnPay\Http\Request;
use OwnPay\Http\Response;
use OwnPay\Event\EventManager;
use OwnPay\Service\Admin\AdminSession;
use OwnPay\Service\Auth\AuthSessionService;
use OwnPay\Service\Brand\BrandContext;
use OwnPay\Repository\TransactionRepository;
use OwnPay\Repository\CustomerRepository;
use OwnPay\Repository\AuditLogRepository;
use OwnPay\Repository\MerchantUserRepository;
use OwnPay\Support\DateHelper;

/**
 * Class DashboardController
 *
 * Coordinates rendering of the admin panel home/dashboard, reports, activity logs,
 * account settings, and report data exports.
 *
 * @package OwnPay\Controller\Admin
 */
final class DashboardController
{
    use AdminPageTrait;

    /**
     * @var array<int, string> Allowed fragment names for dynamic template loading.
     */
    private const ALLOWED_FRAGMENTS = [
        'recent-transactions', 'stats', 'gateway-status',
        'alerts', 'quick-actions',
    ];

    /**
     * @var Container The dependency injection container.
     */
    private Container $c;

    /**
     * @var AdminSession The administrative session service.
     */
    private AdminSession $session;

    /**
     * @var EventManager The system hook and filter event manager.
     */
    private EventManager $events;

    /**
     * @var BrandContext The brand context manager.
     */
    private BrandContext $brand;

    /**
     * @var AuthSessionService The authentication session service.
     */
    private AuthSessionService $auth;

    /**
     * @var TransactionRepository The transaction repository.
     */
    private TransactionRepository $txnRepo;

    /**
     * @var CustomerRepository The customer repository.
     */
    private CustomerRepository $customerRepo;

    /**
     * @var AuditLogRepository The audit log repository.
     */
    private AuditLogRepository $auditRepo;

    /**
     * @var MerchantUserRepository The repository for merchant users.
     */
    private MerchantUserRepository $userRepo;

    /**
     * DashboardController constructor.
     *
     * @param Container              $c            The dependency injection container.
     * @param AdminSession           $session      The administrative session service.
     * @param EventManager           $events       The system hook and filter event manager.
     * @param BrandContext           $brand        The brand context manager.
     * @param AuthSessionService     $auth         The authentication session service.
     * @param TransactionRepository  $txnRepo      The transaction repository.
     * @param CustomerRepository     $customerRepo The customer repository.
     * @param AuditLogRepository     $auditRepo    The audit log repository.
     * @param MerchantUserRepository $userRepo     The repository for merchant users.
     */
    public function __construct(
        Container $c,
        AdminSession $session,
        EventManager $events,
        BrandContext $brand,
        AuthSessionService $auth,
        TransactionRepository $txnRepo,
        CustomerRepository $customerRepo,
        AuditLogRepository $auditRepo,
        MerchantUserRepository $userRepo
    ) {
        $this->c            = $c;
        $this->session      = $session;
        $this->events       = $events;
        $this->brand        = $brand;
        $this->auth         = $auth;
        $this->txnRepo      = $txnRepo;
        $this->customerRepo = $customerRepo;
        $this->auditRepo    = $auditRepo;
        $this->userRepo     = $userRepo;
    }

    /**
     * Renders the administrative home dashboard, aggregating performance metrics.
     *
     * @param Request $req The incoming HTTP request.
     *
     * @return Response The dashboard page response.
     */
    public function index(Request $req): Response
    {
        $this->brand->resolveFromRequest($req);
        $range = $req->query('range', 'today');

        $dateFilter = match ($range) {
            'today' => "AND DATE(created_at) = CURDATE()",
            '7d'    => "AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)",
            '30d'   => "AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)",
            default => '',
        };

        $isGlobal = $this->session->isSuperadmin() && $this->brand->isGlobalView();
        $brandId = $this->brand->getActiveBrandId();

        $stats = $this->txnRepo->getDashboardStats($isGlobal, $brandId, $dateFilter);
        $stats['customer_count'] = $this->customerRepo->countForDashboard(
            $isGlobal ? null : $brandId
        );

        $recent = $this->txnRepo->getRecentDashboardTransactions($isGlobal, $brandId);

        // Decrypt customer names for rendering
        $enc = $this->c->get(\OwnPay\Security\FieldEncryptor::class);
        $recent = array_map(function (array $txn) use ($enc) {
            if (!empty($txn['customer_name'])) {
                try {
                    $txn['customer_name'] = $enc->decrypt($txn['customer_name']);
                } catch (\Throwable $e) {
                    $txn['customer_name'] = '[encrypted]';
                }
            } else {
                $txn['customer_name'] = '—';
            }
            return $txn;
        }, $recent);

        $brandBreakdown = [];
        if ($isGlobal) {
            $brandBreakdown = $this->txnRepo->getGlobalBrandBreakdown();
        }

        $stats = $this->events->applyFilter('admin.dashboard.stats', $stats);

        $settingsRepo = $this->c->get(\OwnPay\Repository\SettingsRepository::class);
        $onboardingCompleted = (int) $settingsRepo->get('system', 'onboarding_completed', '0');

        $currencies = [];
        $timezones = [];
        if ($onboardingCompleted === 0) {
            $currencies = $this->c->get(\OwnPay\Service\Payment\CurrencyService::class)->listAll();
            $timezones = [
                'UTC' => 'UTC (GMT+00:00)',
                'America/New_York' => 'New York (EST/EDT)',
                'America/Chicago' => 'Chicago (CST/CDT)',
                'America/Los_Angeles' => 'Los Angeles (PST/PDT)',
                'Europe/London' => 'London (GMT/BST)',
                'Europe/Paris' => 'Paris (CET/CEST)',
                'Asia/Dhaka' => 'Dhaka (GMT+06:00)',
                'Asia/Kolkata' => 'Kolkata (GMT+05:30)',
                'Asia/Dubai' => 'Dubai (GMT+04:00)',
                'Asia/Singapore' => 'Singapore (GMT+08:00)',
                'Australia/Sydney' => 'Sydney (GMT+10:00/11:00)',
            ];
        }

        return $this->renderAdminPage('admin/dashboard.twig', [
            'stats'                => $stats,
            'recent_transactions'  => $recent,
            'range'                => $range,
            'active_page'          => 'dashboard',
            'is_global_view'       => $isGlobal,
            'brand_breakdown'      => $brandBreakdown,
            'onboarding_completed' => $onboardingCompleted,
            'currencies'           => $currencies,
            'timezones'            => $timezones,
        ]);
    }

    /**
     * Renders dynamic layout fragments/widgets via AJAX.
     *
     * @param Request $req The incoming HTTP request.
     *
     * @return Response The HTML template snippet response.
     */
    public function fragment(Request $req): Response
    {
        $page = $req->param('page');
        if ($page === '' || !in_array($page, self::ALLOWED_FRAGMENTS, true)) {
            return Response::html('', 404);
        }
        return $this->renderAdminPage("admin/fragments/{$page}.twig");
    }

    /**
     * Renders transaction volume and analytics reports.
     *
     * @param Request $req The incoming HTTP request.
     *
     * @return Response The reporting page response.
     */
    public function reports(Request $req): Response
    {
        $this->brand->resolveFromRequest($req);
        $mid = $this->brand->getActiveBrandId();

        $from    = $req->query('from', DateHelper::monthStart());
        $to      = $req->query('to', DateHelper::today());
        $gateway = $req->query('gateway', '');

        $report   = $this->txnRepo->getReportData($mid, $from, $to, $gateway ?: null);
        $gateways = $this->txnRepo->getDistinctGateways($mid);

        $report = $this->events->applyFilter('report.data', $report, ['from' => $from, 'to' => $to]);

        return $this->renderAdminPage('admin/reports.twig', [
            'active_page'  => 'reports',
            'gateways'     => $gateways,
            'report'       => $report,
            'filters'      => ['from' => $from, 'to' => $to, 'gateway' => $gateway],
            'query_string' => http_build_query(['from' => $from, 'to' => $to, 'gateway' => $gateway]),
        ]);
    }

    /**
     * Displays a log of administrative activities and audits.
     *
     * @param Request $req The incoming HTTP request.
     *
     * @return Response The activities history log page.
     */
    public function activities(Request $req): Response
    {
        $this->brand->resolveFromRequest($req);
        $mid = $this->brand->getActiveBrandId();

        $page   = max(1, (int) ($req->query('page', '1')));
        $limit  = 50;
        $offset = ($page - 1) * $limit;

        $merchantScope = $this->session->isSuperadmin() ? null : $mid;
        $total      = $this->auditRepo->countFiltered($merchantScope);
        $activities = $this->auditRepo->listPaginated($merchantScope, $limit, $offset);

        return $this->renderAdminPage('admin/activities.twig', [
            'active_page' => 'activities',
            'activities'  => $activities,
            'pagination'  => [
                'page'        => $page,
                'total_pages' => (int) ceil($total / $limit),
                'total'       => $total,
            ],
        ]);
    }

    /**
     * Renders the logged in administrator's profile page.
     *
     * @param Request $req The incoming HTTP request.
     *
     * @return Response The account settings page.
     */
    public function myAccount(Request $req): Response
    {
        $userId = $this->session->userId();
        if ($userId === null) {
            $loginSlug = $this->resolveLoginSlug();
            return Response::redirect('/' . $loginSlug);
        }

        $user = $this->userRepo->findById($userId);
        $this->session->set('two_fa_enabled', (bool) ($user['two_factor_enabled'] ?? false));

        return $this->renderAdminPage('admin/my-account.twig', [
            'active_page'  => 'profile',
            'profile_user' => $user,
        ]);
    }

    /**
     * Updates profile or credentials of the active administrator user.
     *
     * @param Request $req The incoming HTTP request.
     *
     * @return Response The redirect response.
     */
    public function updateAccount(Request $req): Response
    {
        $data   = $req->post();
        $userId = $this->session->userId();

        if ($userId === null) {
            $loginSlug = $this->resolveLoginSlug();
            return Response::redirect('/' . $loginSlug);
        }

        if (($data['type'] ?? '') === 'profile') {
            $name  = \OwnPay\Service\System\InputSanitizer::string($data['name'] ?? '');
            $email = \OwnPay\Service\System\InputSanitizer::email($data['email'] ?? '');
            if ($name !== '' && $email !== '') {
                $this->userRepo->updateProfile($userId, $name, $email);
                $this->session->updateProfile($name, $email);
                $this->session->flashSuccess('Profile updated successfully.');
            } else {
                $this->session->flashError('Name and email are required.');
            }
        } elseif (($data['type'] ?? '') === 'password') {
            $current = $data['current_password'] ?? '';
            $new     = $data['new_password'] ?? '';
            $confirm = $data['confirm_password'] ?? '';

            $hash = $this->userRepo->getPasswordHash($userId);
            if (!$hash || !password_verify($current, $hash)) {
                $this->session->flashError('Current password is incorrect.');
            } elseif ($new === '' || $new !== $confirm || strlen($new) < 8) {
                $this->session->flashError('New password mismatch or too short (min 8 chars).');
            } else {
                $this->userRepo->updatePassword($userId, password_hash($new, PASSWORD_ARGON2ID));
                $this->session->flashSuccess('Password updated. Please log in again.');
                $this->auth->logout();
                $loginSlug = $this->resolveLoginSlug();
                return Response::redirect('/' . $loginSlug);
            }
        }

        return Response::redirect('/admin/my-account');
    }

    /**
     * Exports transaction data matching the filter parameters into CSV.
     *
     * @param Request $req The incoming HTTP request.
     *
     * @return Response The downloadable CSV file attachment response.
     */
    public function exportCsv(Request $req): Response
    {
        $this->brand->resolveFromRequest($req);
        $mid = $this->brand->getActiveBrandId();

        $from    = $req->query('from', DateHelper::monthStart());
        $to      = $req->query('to', DateHelper::today());
        $gateway = $req->query('gateway', '');

        $rows = $this->txnRepo->getExportData($mid, $from, $to, $gateway ?: null);

        $rows = array_map(
            fn($row) => $this->events->applyFilter('export.row', $row),
            $rows
        );

        ob_start();
        $out = fopen('php://output', 'w');
        fputcsv($out, ['ID', 'Gateway', 'Currency', 'Amount', 'Status', 'Date']);
        foreach ($rows as $row) {
            fputcsv($out, [
                $row['id'], $row['gateway_slug'], $row['currency'],
                $row['amount'], $row['status'], $row['created_at'],
            ]);
        }
        fclose($out);
        $csv = ob_get_clean() ?: '';

        $filename = "report_{$from}_{$to}.csv";
        return Response::html($csv, 200)
            ->withHeader('Content-Type', 'text/csv; charset=UTF-8')
            ->withHeader('Content-Disposition', "attachment; filename=\"{$filename}\"");
    }

    /**
     * Step 1: Saves platform settings from onboarding wizard.
     *
     * @param Request $req The incoming HTTP request.
     * @return Response The JSON response.
     */
    public function saveOnboardingSettings(Request $req): Response
    {
        $data = $req->all();
        $siteName = \OwnPay\Service\System\InputSanitizer::string($data['site_name'] ?? '');
        $siteTagline = \OwnPay\Service\System\InputSanitizer::string($data['site_tagline'] ?? '');
        $timezone = \OwnPay\Service\System\InputSanitizer::string($data['timezone'] ?? '');
        $currency = \OwnPay\Service\System\InputSanitizer::string($data['currency'] ?? '');
        
        $timerMinutes = max(1, (int) ($data['timer_minutes'] ?? 10));
        $timerSeconds = $timerMinutes * 60;
        
        $requirePhone = ($data['require_customer_phone'] ?? '0') === '1' ? '1' : '0';
        $landingPageEnabled = ($data['landing_page_enabled'] ?? '0') === '1' ? '1' : '0';

        if ($siteName === '' || $timezone === '' || $currency === '') {
            return Response::json(['success' => false, 'error' => 'System name, currency, and timezone are required.']);
        }

        /** @var \OwnPay\Repository\SettingsRepository $settingsRepo */
        $settingsRepo = $this->c->get(\OwnPay\Repository\SettingsRepository::class);
        
        // Save Platform Branding & Names
        $settingsRepo->set('general', 'app_name', $siteName);
        $settingsRepo->set('general', 'site_name', $siteName);
        $settingsRepo->set('branding', 'site_name', $siteName);
        $settingsRepo->set('general', 'site_tagline', $siteTagline);
        
        // Save Localization & Timezones
        $settingsRepo->set('general', 'timezone', $timezone);
        $settingsRepo->set('general', 'default_timezone', $timezone);
        $settingsRepo->set('general', 'currency', $currency);
        $settingsRepo->set('general', 'base_currency', $currency);
        $settingsRepo->set('general', 'default_currency', $currency);
        
        // Save Functional First-Time Configurations
        $settingsRepo->set('general', 'landing_page_enabled', $landingPageEnabled);
        $settingsRepo->set('checkout', 'timer_seconds', (string) $timerSeconds);
        $settingsRepo->set('checkout', 'require_customer_phone', $requirePhone);

        return Response::json(['success' => true]);
    }

    /**
     * Step 2: Creates the first Brand/Store.
     *
     * @param Request $req The incoming HTTP request.
     * @return Response The JSON response.
     */
    public function createOnboardingBrand(Request $req): Response
    {
        $data = $req->all();
        $brandName = \OwnPay\Service\System\InputSanitizer::string($data['brand_name'] ?? '');
        $brandEmail = \OwnPay\Service\System\InputSanitizer::email($data['brand_email'] ?? '');
        $brandPhone = \OwnPay\Service\System\InputSanitizer::string($data['brand_phone'] ?? '');
        $brandCurrency = \OwnPay\Service\System\InputSanitizer::string($data['brand_currency'] ?? '');
        $brandTimezone = \OwnPay\Service\System\InputSanitizer::string($data['brand_timezone'] ?? '');

        if ($brandName === '' || $brandEmail === '' || $brandCurrency === '' || $brandTimezone === '') {
            return Response::json(['success' => false, 'error' => 'Brand name, email, currency, and timezone are required.']);
        }

        /** @var \OwnPay\Repository\MerchantRepository $merchantRepo */
        $merchantRepo = $this->c->get(\OwnPay\Repository\MerchantRepository::class);
        $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $brandName)));
        if ($slug === '') {
            $slug = 'brand';
        }
        $existing = $merchantRepo->findBySlug($slug);
        if ($existing) {
            $slug .= '-' . rand(100, 999);
        }

        $brandId = $merchantRepo->createMerchant([
            'name'             => $brandName,
            'slug'             => $slug,
            'email'            => $brandEmail,
            'phone'            => $brandPhone,
            'default_currency' => $brandCurrency,
            'timezone'         => $brandTimezone,
            'status'           => 'active',
            'settings'         => json_encode([
                'primary_color'   => '#6366f1',
                'accent_color'    => '#4f46e5',
                'support_email'   => $brandEmail,
                'footer_text'     => '© ' . date('Y') . ' ' . $brandName,
                'show_powered_by' => true
            ])
        ]);

        // Auto-scope superadmin session to this new brand
        $_SESSION['active_brand_id'] = (int) $brandId;
        $_SESSION['auth_merchant_id'] = (int) $brandId;

        // Associate user if possible
        $userId = $this->session->userId();
        if ($userId !== null) {
            /** @var \OwnPay\Repository\MerchantUserRepository $userRepo */
            $userRepo = $this->c->get(\OwnPay\Repository\MerchantUserRepository::class);
            $userRepo->update((int) $userId, ['merchant_id' => $brandId]);
        }

        return Response::json(['success' => true, 'brand_id' => $brandId]);
    }

    /**
     * Step 3: Sets up Outgoing Mail (SMTP, Mailgun, or SendGrid) for the system.
     * Also activates the mail-gateway plugin in op_plugins database.
     *
     * @param Request $req The incoming HTTP request.
     * @return Response The JSON response.
     */
    public function setupOnboardingMail(Request $req): Response
    {
        $data = $req->all();
        $provider = \OwnPay\Service\System\InputSanitizer::string($data['provider'] ?? 'smtp');
        $fromEmail = \OwnPay\Service\System\InputSanitizer::email($data['from_email'] ?? '');
        $fromName = \OwnPay\Service\System\InputSanitizer::string($data['from_name'] ?? 'Own Pay');
        
        $skip = ($data['skip'] ?? '0') === '1';
        
        /** @var \OwnPay\Repository\SettingsRepository $settingsRepo */
        $settingsRepo = $this->c->get(\OwnPay\Repository\SettingsRepository::class);
        
        if ($skip) {
            // Default mail plugin settings to disabled, and skip activation
            $settingsRepo->set('plugin.mail-gateway', 'enabled', '0');
            return Response::json(['success' => true, 'skipped' => true]);
        }

        if ($fromEmail === '') {
            return Response::json(['success' => false, 'error' => 'Sender email address is required.']);
        }

        $settings = [
            'provider' => $provider,
            'from_email' => $fromEmail,
            'from_name' => $fromName,
            'enabled' => '1',
        ];

        if ($provider === 'smtp') {
            $settings['smtp_host'] = \OwnPay\Service\System\InputSanitizer::string($data['smtp_host'] ?? '');
            $settings['smtp_port'] = \OwnPay\Service\System\InputSanitizer::string($data['smtp_port'] ?? '587');
            $settings['smtp_user'] = \OwnPay\Service\System\InputSanitizer::string($data['smtp_user'] ?? '');
            $settings['smtp_password'] = \OwnPay\Service\System\InputSanitizer::string($data['smtp_password'] ?? '');
            $settings['smtp_encryption'] = \OwnPay\Service\System\InputSanitizer::string($data['smtp_encryption'] ?? 'tls');

            if ($settings['smtp_host'] === '') {
                return Response::json(['success' => false, 'error' => 'SMTP Host is required.']);
            }
        } elseif ($provider === 'mailgun') {
            $settings['mailgun_domain'] = \OwnPay\Service\System\InputSanitizer::string($data['mailgun_domain'] ?? '');
            $settings['mailgun_key'] = \OwnPay\Service\System\InputSanitizer::string($data['mailgun_key'] ?? '');

            if ($settings['mailgun_domain'] === '' || $settings['mailgun_key'] === '') {
                return Response::json(['success' => false, 'error' => 'Mailgun Domain and API Key are required.']);
            }
        } elseif ($provider === 'sendgrid') {
            $settings['sendgrid_key'] = \OwnPay\Service\System\InputSanitizer::string($data['sendgrid_key'] ?? '');

            if ($settings['sendgrid_key'] === '') {
                return Response::json(['success' => false, 'error' => 'SendGrid API Key is required.']);
            }
        } else {
            return Response::json(['success' => false, 'error' => 'Invalid email provider.']);
        }

        // Save all mail settings under the group plugin.mail-gateway
        foreach ($settings as $key => $val) {
            $settingsRepo->set('plugin.mail-gateway', $key, (string) $val);
        }

        // Activate the mail-gateway plugin in the op_plugins table
        /** @var \OwnPay\Repository\PluginRepository $pluginRepo */
        $pluginRepo = $this->c->get(\OwnPay\Repository\PluginRepository::class);
        $plugin = $pluginRepo->findBySlug('mail-gateway');
        
        if ($plugin) {
            $pluginRepo->update((int) $plugin['id'], ['status' => 'active']);
        } else {
            $pluginRepo->create([
                'slug'       => 'mail-gateway',
                'name'       => 'Mail Gateway',
                'type'       => 'addon',
                'status'     => 'active',
                'version'    => '1.0.0',
                'entrypoint' => 'Plugin.php',
                'manifest'   => json_encode([
                    'slug'    => 'mail-gateway',
                    'name'    => 'Mail Gateway',
                    'type'    => 'addon',
                    'version' => '1.0.0'
                ])
            ]);
        }

        return Response::json(['success' => true]);
    }

    /**
     * Step 4: Sets up Stripe, PayPal, or a Manual Gateway for the new brand.
     *
     * @param Request $req The incoming HTTP request.
     * @return Response The JSON response.
     */
    public function setupOnboardingGateway(Request $req): Response
    {
        $data = $req->all();
        $brandId = (int) ($data['brand_id'] ?? 0);
        $gatewayType = \OwnPay\Service\System\InputSanitizer::string($data['gateway_type'] ?? '');

        if ($brandId <= 0 || $gatewayType === '') {
            return Response::json(['success' => false, 'error' => 'Invalid request arguments.']);
        }

        if ($gatewayType === 'stripe' || $gatewayType === 'paypal') {
            $slug = ($gatewayType === 'stripe') ? 'stripe' : 'paypal-checkout';
            
            /** @var \OwnPay\Repository\GatewayRepository $gwRepo */
            $gwRepo = $this->c->get(\OwnPay\Repository\GatewayRepository::class);
            $gateway = $gwRepo->findBySlug($slug);
            if (!$gateway) {
                $gwId = (int) $gwRepo->create([
                    'slug'       => $slug,
                    'name'       => ($gatewayType === 'stripe') ? 'Stripe' : 'PayPal Checkout',
                    'type'       => 'api',
                    'is_builtin' => 1,
                    'status'     => 'active',
                    'sort_order' => 0
                ]);
            } else {
                $gwId = (int) $gateway['id'];
            }

            $credentials = [];
            if ($gatewayType === 'stripe') {
                $stripeKey = \OwnPay\Service\System\InputSanitizer::string($data['stripe_key'] ?? '');
                $stripeSecret = \OwnPay\Service\System\InputSanitizer::string($data['stripe_secret'] ?? '');
                if ($stripeKey === '' || $stripeSecret === '') {
                    return Response::json(['success' => false, 'error' => 'Stripe Publishable Key and Secret Key are required.']);
                }
                $credentials = [
                    'publishable_key' => $stripeKey,
                    'secret_key'      => $stripeSecret
                ];
            } else {
                $paypalClientId = \OwnPay\Service\System\InputSanitizer::string($data['paypal_client_id'] ?? '');
                $paypalSecret = \OwnPay\Service\System\InputSanitizer::string($data['paypal_secret'] ?? '');
                if ($paypalClientId === '' || $paypalSecret === '') {
                    return Response::json(['success' => false, 'error' => 'PayPal Client ID and Client Secret are required.']);
                }
                $credentials = [
                    'client_id'     => $paypalClientId,
                    'client_secret' => $paypalSecret
                ];
            }

            /** @var \OwnPay\Security\FieldEncryptor $enc */
            $enc = $this->c->get(\OwnPay\Security\FieldEncryptor::class);
            $encCreds = $enc->encrypt(json_encode($credentials));

            /** @var \OwnPay\Repository\GatewayConfigRepository $configRepo */
            $configRepo = $this->c->get(\OwnPay\Repository\GatewayConfigRepository::class);
            $configRepo = $configRepo->forTenant($brandId);

            $existingConfig = $configRepo->findForGateway((int) $gwId);
            if ($existingConfig) {
                $configRepo->update((int) $existingConfig['id'], [
                    'credentials_enc' => $encCreds,
                    'status'          => 'active',
                    'mode'            => 'sandbox'
                ]);
            } else {
                $configRepo->create([
                    'merchant_id'     => $brandId,
                    'gateway_id'      => $gwId,
                    'credentials_enc' => $encCreds,
                    'status'          => 'active',
                    'mode'            => 'sandbox'
                ]);
            }

            // Also ensure the corresponding plugin in op_plugins is set to active
            /** @var \OwnPay\Repository\PluginRepository $pluginRepo */
            $pluginRepo = $this->c->get(\OwnPay\Repository\PluginRepository::class);
            $plugin = $pluginRepo->findBySlug($slug);
            if ($plugin) {
                $pluginRepo->update((int) $plugin['id'], ['status' => 'active']);
            } else {
                $pluginRepo->create([
                    'slug'     => $slug,
                    'name'     => ($gatewayType === 'stripe') ? 'Stripe' : 'PayPal Checkout',
                    'type'     => 'gateway',
                    'status'   => 'active',
                    'version'  => '1.0.0',
                    'manifest' => json_encode(['slug' => $slug, 'name' => ($gatewayType === 'stripe') ? 'Stripe' : 'PayPal Checkout', 'type' => 'gateway'])
                ]);
            }

        } elseif ($gatewayType === 'manual') {
            $manualName = \OwnPay\Service\System\InputSanitizer::string($data['manual_name'] ?? '');
            $manualDetails = \OwnPay\Service\System\InputSanitizer::string($data['manual_details'] ?? '');
            
            if ($manualName === '' || $manualDetails === '') {
                return Response::json(['success' => false, 'error' => 'Manual Gateway Name and Payment details/instructions are required.']);
            }

            /** @var \OwnPay\Repository\MerchantRepository $merchantRepo */
            $merchantRepo = $this->c->get(\OwnPay\Repository\MerchantRepository::class);
            $brand = $merchantRepo->find($brandId);
            $brandCurrency = $brand['default_currency'] ?? 'USD';

            /** @var \OwnPay\Repository\ManualGatewayRepository $manualRepo */
            $manualRepo = $this->c->get(\OwnPay\Repository\ManualGatewayRepository::class);
            $manualRepo = $manualRepo->forTenant($brandId);
            $manualRepo->create([
                'merchant_id'  => $brandId,
                'slug'         => 'manual-bank',
                'name'         => $manualName,
                'instructions' => json_encode(['payment_details' => $manualDetails]),
                'status'       => 'active',
                'currency'     => $brandCurrency
            ]);
        } else {
            return Response::json(['success' => false, 'error' => 'Invalid gateway type.']);
        }

        return Response::json(['success' => true]);
    }

    /**
     * Step 4: Marks the onboarding wizard as completed.
     *
     * @param Request $req The incoming HTTP request.
     * @return Response The JSON response.
     */
    public function completeOnboarding(Request $req): Response
    {
        /** @var \OwnPay\Repository\SettingsRepository $settingsRepo */
        $settingsRepo = $this->c->get(\OwnPay\Repository\SettingsRepository::class);
        $settingsRepo->set('system', 'onboarding_completed', '1');

        return Response::json(['success' => true]);
    }

    /**
     * Step 5: Dismisses the onboarding wizard.
     *
     * @param Request $req The incoming HTTP request.
     * @return Response The JSON response.
     */
    public function dismissOnboarding(Request $req): Response
    {
        /** @var \OwnPay\Repository\SettingsRepository $settingsRepo */
        $settingsRepo = $this->c->get(\OwnPay\Repository\SettingsRepository::class);
        $settingsRepo->set('system', 'onboarding_completed', '1');

        return Response::json(['success' => true]);
    }

    /**
     * Resolves the dynamic login slug from the storage cache or settings.
     *
     * @return string
     */
    private function resolveLoginSlug(): string
    {
        $cacheFile = dirname(__DIR__, 3) . '/storage/cache/login_slug.cache';
        if (file_exists($cacheFile)) {
            $slug = @file_get_contents($cacheFile);
            if ($slug !== false && $slug !== '') {
                $slug = trim($slug);
                if (preg_match('/^[a-z0-9\-]+$/', $slug)) {
                    return $slug;
                }
            }
        }

        try {
            $settings = $this->c->get(\OwnPay\Repository\SettingsRepository::class);
            return $settings->get('landing', 'admin_login_slug', 'login');
        } catch (\Throwable) {
            return 'login';
        }
    }
}
