<?php
declare(strict_types=1);

namespace OwnPay\Controller\Admin;

use OwnPay\Container;
use OwnPay\Service\Admin\AdminSession;
use OwnPay\Http\Request;
use OwnPay\Http\Response;
use OwnPay\Event\EventManager;
use OwnPay\Service\System\AuditService;

/**
 * Class SettingsController
 *
 * Administrative controller managing platform-wide settings (general settings, maintenance mode,
 * branding uploads, landing page content, checkout settings, and currencies exchange rates).
 *
 * Fired actions:
 * - `settings.saved`: Triggered immediately after settings are modified.
 *
 * @package OwnPay\Controller\Admin
 */
final class SettingsController
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
     * @var EventManager The hooks and actions event manager.
     */
    private EventManager $events;

    /**
     * @var \OwnPay\Repository\SettingsRepository The settings repository.
     */
    private \OwnPay\Repository\SettingsRepository $settingsRepo;

    /**
     * @var AuditService The application audit logging service.
     */
    private AuditService $audit;

    /**
     * SettingsController constructor.
     *
     * @param Container                             $c            The dependency injection container.
     * @param AdminSession                          $session      The administrative session service.
     * @param EventManager                          $events       The hooks and actions event manager.
     * @param \OwnPay\Repository\SettingsRepository $settingsRepo The settings repository.
     * @param AuditService                          $audit        The application audit logging service.
     */
    public function __construct(Container $c, AdminSession $session, EventManager $events, \OwnPay\Repository\SettingsRepository $settingsRepo, AuditService $audit)
    {
        $this->c = $c;
        $this->session = $session;
        $this->events = $events;
        $this->settingsRepo = $settingsRepo;
        $this->audit = $audit;
    }

    /**
     * Renders settings manager page with settings loaded for all groups.
     *
     * @param Request $req       The incoming HTTP request.
     * @param string  $activeTab The currently active settings tab.
     *
     * @return Response The settings manager page response.
     */
    public function index(Request $req, string $activeTab = 'general'): Response
    {
        $settings    = $this->settingsRepo->getGroup('general');
        $branding    = $this->settingsRepo->getGroup('branding');
        $landing     = $this->settingsRepo->getGroup('landing');
        $checkout    = $this->settingsRepo->getGroup('checkout');
        $theme       = $this->settingsRepo->getGroup('theme');

        /** @phpstan-ignore booleanAnd.rightAlwaysTrue */
        if (isset($settings['faqs']) && is_string($settings['faqs'])) {
            $decoded = json_decode($settings['faqs'], true);
            /** @phpstan-ignore booleanAnd.rightAlwaysTrue */
            $settings['faqs'] = (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) ? $decoded : [];
        }
        /** @phpstan-ignore booleanAnd.rightAlwaysTrue */
        if (isset($landing['features']) && is_string($landing['features'])) {
            $decoded = json_decode($landing['features'], true);
            /** @phpstan-ignore booleanAnd.rightAlwaysTrue */
            $landing['features'] = (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) ? $decoded : [];
        }

        // Maintenance lock file status
        $lockFile = dirname(__DIR__, 3) . '/storage/.maintenance';
        if (file_exists($lockFile) && empty($settings['maintenance_mode'])) {
            $settings['maintenance_mode'] = '1';
        }

        $currencyService = $this->c->get(\OwnPay\Service\Payment\CurrencyService::class);
        $allCurrencies   = $currencyService->listAll();
        $timezones       = \DateTimeZone::listIdentifiers();

        $brand = $this->c->get(\OwnPay\Service\Brand\BrandContext::class);
        $brand->resolveFromRequest($req);
        $mid = $brand->getActiveBrandId();
        $apiKeys = $this->c->get(\OwnPay\Service\Customer\ApiKeyService::class)->list($mid);

        // Retrieve or auto-generate Cron Secret
        $cronSecret = $settings['cron_secret'] ?? '';
        if (empty($cronSecret)) {
            $cronSecret = bin2hex(random_bytes(16));
            $this->settingsRepo->set('general', 'cron_secret', $cronSecret);
            $settings['cron_secret'] = $cronSecret;
        }

        // Build Cron trigger URL white-labeled using DomainUrlService
        $urlService = $this->c->get(\OwnPay\Service\Domain\DomainUrlService::class);
        $baseUrl = $urlService->resolveBaseUrl($mid, $req);
        $cronUrl = rtrim($baseUrl, '/') . '/cron/' . $cronSecret;

        // Fetch all registered Cron Jobs and their execution logs
        $runner = $this->c->get(\OwnPay\Cron\CronJobRunner::class);
        $rawJobs = $runner->getJobs();
        $cronJobs = [];
        $descriptions = [
            'QueueWorker'         => 'Processes pending background jobs and tasks in the system queue.',
            'SmsVerification'     => 'Matches pending SMS transaction notifications received from mobile devices.',
            'WebhookRetry'        => 'Retries failed webhook delivery attempts to external merchant URLs.',
            'BalanceVerification' => 'Audits double-entry ledger bookkeeping to detect account balance mismatches.',
            'CurrencyUpdate'      => 'Updates fiat exchange rates and synchronizes standard platform currencies.',
            'DnsVerification'     => 'Verifies DNS records and SSL status for custom merchant domains.',
            'UpdateCheck'         => 'Checks for new core platform releases and software system updates.',
            'SystemUpdate'        => 'Downloads and applies approved software updates dynamically.',
        ];

        foreach ($rawJobs as $name => $config) {
            $lastRun = $runner->getLastRunTime($name);
            $elapsedStr = 'Never';
            if ($lastRun !== null) {
                $elapsed = time() - $lastRun;
                if ($elapsed < 60) {
                    $elapsedStr = 'Just now';
                } elseif ($elapsed < 3600) {
                    $mins = floor($elapsed / 60);
                    $elapsedStr = $mins . ($mins === 1 ? ' min ago' : ' mins ago');
                } elseif ($elapsed < 86400) {
                    $hours = floor($elapsed / 3600);
                    $elapsedStr = $hours . ($hours === 1 ? ' hour ago' : ' hours ago');
                } else {
                    $days = floor($elapsed / 86400);
                    $elapsedStr = $days . ($days === 1 ? ' day ago' : ' days ago');
                }
            }

            $cronJobs[] = [
                'name'               => $name,
                'schedule'           => $config['schedule'],
                'last_run'           => $elapsedStr,
                'last_run_timestamp' => $lastRun,
                'description'        => $descriptions[$name] ?? 'System scheduled background process.',
            ];
        }

        return $this->renderAdminPage('admin/settings/index.twig', [
            'settings'          => $settings,
            'branding'          => $branding,
            'landing'           => $landing,
            'checkout_settings' => $checkout,
            'theme'             => $theme,
            'currencies'        => $allCurrencies,
            'all_currencies'    => $allCurrencies,
            'timezones'         => $timezones,
            'api_keys'          => $apiKeys,
            'active_page'       => 'settings',
            'default_tab'       => $activeTab,
            'cron_secret'       => $cronSecret,
            'cron_url'          => $cronUrl,
            'cron_jobs'         => $cronJobs,
        ]);
    }

    /**
     * Processes settings updates submitted via forms.
     *
     * @param Request $req The incoming HTTP request.
     *
     * @return Response The HTTP redirect response.
     */
    public function save(Request $req): Response
    {
        $tab  = $req->post('_tab', 'general');
        $data = $req->post();
        unset($data['_csrf_token'], $data['_tab']);

        switch ($tab) {
            case 'branding':
                $this->saveBranding($data, $req);
                break;

            case 'landing':
                $this->saveLanding($data);
                break;

            case 'payment':
                $this->savePayment($data);
                break;

            case 'checkout':
                $this->saveCheckout($data);
                break;

            case 'theme':
                $this->saveTheme($data);
                break;

            default:
                $this->saveGeneral($data);
                break;
        }

        $this->events->doAction('settings.saved', ['tab' => $tab, 'data' => $data]);
        $this->audit->log('settings.saved', 'settings', null, null, ['tab' => $tab]);
        $this->session->flashSuccess('Settings saved');
        return Response::redirect('/admin/settings/' . $tab);
    }

    /**
     * Handles uploading branding logos and favicon files, saving them securely to the uploads folder.
     *
     * @param Request $req The incoming HTTP request.
     *
     * @return Response The HTTP redirect response.
     */
    public function upload(Request $req): Response
    {
        $uploadDir = dirname(__DIR__, 3) . '/public/assets/uploads/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        $saved = [];
        foreach (['site_logo', 'site_favicon'] as $field) {
            $file = $req->file($field);
            if ($file === null || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                continue;
            }
            $allowed = ['image/png', 'image/jpeg', 'image/gif', 'image/svg+xml', 'image/webp', 'image/x-icon', 'image/vnd.microsoft.icon'];
            $mime = mime_content_type($file['tmp_name']);
            if (!in_array($mime, $allowed, true)) {
                $this->session->flashError("Invalid file type for {$field}");
                return Response::redirect('/admin/settings/branding');
            }
            $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
            $filename = $field . '_' . time() . '.' . strtolower($ext);
            $dest = $uploadDir . $filename;
            if (move_uploaded_file($file['tmp_name'], $dest)) {
                $path = '/assets/uploads/' . $filename;
                $this->settingsRepo->set('branding', $field, $path);
                $saved[$field] = $path;
            }
        }

        $this->audit->log('branding.upload', 'settings', null, null, ['files' => array_keys($saved)]);
        $this->session->flashSuccess('Branding files uploaded successfully');
        return Response::redirect('/admin/settings/branding');
    }

    /**
     * Renders settings manager index page showing the requested settings tab.
     *
     * @param Request $req The incoming HTTP request.
     *
     * @return Response The settings manager page response.
     */
    public function tab(Request $req): Response
    {
        $tab = $req->param('tab', 'general');
        // Map sidebar shortcuts
        $activePageMap = [
            'branding' => 'branding',
            'landing'  => 'landing-editor',
        ];
        return $this->index($req, $tab);
    }

    // ─── Private save helpers ─────────────────────────────────

    /**
     * Persists general settings parameters and manages the system-wide maintenance lock file.
     *
     * @param array<string, mixed> $data General configuration parameters.
     *
     * @return void
     */
    private function saveGeneral(array $data): void
    {
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

        if (isset($data['faqs'])) {
            $data['faqs'] = json_encode(array_values($data['faqs']));
        }

        $whitelist = [
            'app_name', 'base_url', 'timezone', 'support_email', 'footer_text',
            'maintenance_mode', 'default_currency', 'exchange_rate_mode',
            'payment_expiry_minutes', 'invoice_due_days', 'auto_approve_payments',
            'smtp_host', 'smtp_port', 'smtp_encryption', 'smtp_username', 'smtp_password',
            'mail_from_email', 'mail_from_name', 'webhook_url', 'api_rate_limit',
            'session_timeout', 'max_login_attempts', 'ip_allowlist', 'force_https',
            'require_2fa', 'admin_notification_email', 'email_on_payment', 'email_on_refund',
            'faqs', 'sms_positive_keywords', 'sms_negative_keywords',
            'sms_filter_rules_check_interval_hours'
        ];

        $filtered = [];
        foreach ($whitelist as $key) {
            if (isset($data[$key])) {
                $filtered[$key] = is_array($data[$key]) ? json_encode($data[$key]) : (string) $data[$key];
            }
        }

        $this->settingsRepo->bulkSet('general', $filtered);

        // Sync maintenance lock file
        $lockFile = dirname(__DIR__, 3) . '/storage/.maintenance';
        if (!empty($filtered['maintenance_mode']) && $filtered['maintenance_mode'] !== '0') {
            file_put_contents($lockFile, json_encode([
                'reason'      => 'System maintenance in progress. Please try again shortly.',
                'retry_after' => 600,
                'started_at'  => date('c'),
            ], JSON_THROW_ON_ERROR));
        } elseif (file_exists($lockFile)) {
            @unlink($lockFile);
        }
    }

    /**
     * Persists settings parameters under the branding group.
     *
     * @param array<string, mixed> $data Branding parameters.
     * @param Request              $req  The incoming request context.
     *
     * @return void
     */
    private function saveBranding(array $data, Request $req): void
    {
        $whitelist = [
            'admin_panel_title',
            'site_seo_title',
            'site_meta_description',
            'site_keywords',
            'brand_tagline',
        ];
        $filtered = [];
        foreach ($whitelist as $key) {
            if (isset($data[$key])) {
                $filtered[$key] = (string) $data[$key];
            }
        }
        $this->settingsRepo->bulkSet('branding', $filtered);
    }

    /**
     * Persists settings parameters under the landing page group.
     *
     * @param array<string, mixed> $data Landing editor parameters.
     *
     * @return void
     */
    private function saveLanding(array $data): void
    {
        $checkboxFields = ['landing_enabled', 'landing_show_faq', 'landing_show_features'];
        foreach ($checkboxFields as $cb) {
            if (!isset($data[$cb])) {
                $data[$cb] = '0';
            }
        }
        if (isset($data['features'])) {
            $data['features'] = json_encode(array_values($data['features']));
        }

        $whitelist = [
            'landing_enabled',
            'landing_title',
            'landing_subtitle',
            'landing_cta_text',
            'landing_cta_url',
            'landing_show_features',
            'landing_show_faq',
            'admin_login_slug',
            'features',
        ];

        $filtered = [];
        foreach ($whitelist as $key) {
            if (isset($data[$key])) {
                $filtered[$key] = is_array($data[$key]) ? json_encode($data[$key]) : (string) $data[$key];
            }
        }
        $this->settingsRepo->bulkSet('landing', $filtered);
    }

    /**
     * Saves general payment rules and updates currency exchange rates.
     *
     * @param array<string, mixed> $data Payment parameters.
     *
     * @return void
     */
    private function savePayment(array $data): void
    {
        $checkboxFields = ['auto_approve_payments'];
        foreach ($checkboxFields as $cb) {
            if (!isset($data[$cb])) {
                $data[$cb] = '0';
            }
        }

        // Handle currency updates if present
        $currencies = $data['currencies'] ?? null;
        if ($currencies !== null && is_array($currencies)) {
            $currencySvc = $this->c->get(\OwnPay\Service\Payment\CurrencyService::class);
            foreach ($currencies as $code => $cData) {
                if (isset($cData['rate'])) {
                    $currencySvc->updateExchangeRate($code, (string) $cData['rate']);
                }
            }
        }

        $whitelist = [
            'default_currency',
            'exchange_rate_mode',
            'payment_expiry_minutes',
            'invoice_due_days',
            'auto_approve_payments',
        ];

        $filtered = [];
        foreach ($whitelist as $key) {
            if (isset($data[$key])) {
                $filtered[$key] = (string) $data[$key];
            }
        }

        $this->settingsRepo->bulkSet('general', $filtered);
    }

    /**
     * Segregates and persists configurations for checkout and general settings groups.
     *
     * @param array<string, mixed> $data Raw checkout settings fields.
     *
     * @return void
     */
    private function saveCheckout(array $data): void
    {
        // Normalize checkboxes
        foreach (['timer_enabled', 'show_faq'] as $cb) {
            if (!isset($data[$cb])) {
                $data[$cb] = '0';
            }
        }

        $whitelist = [
            'checkout_success_msg',
            'checkout_pending_msg',
            'checkout_failed_msg',
            'timer_enabled',
            'timer_seconds',
            'show_faq',
        ];

        $filtered = [];
        foreach ($whitelist as $key) {
            if (isset($data[$key])) {
                $filtered[$key] = (string) $data[$key];
            }
        }

        $this->settingsRepo->bulkSet('checkout', $filtered);
    }

    /**
     * Persists settings parameters under the theme group.
     *
     * @param array<string, mixed> $data Theme customization parameters.
     *
     * @return void
     */
    private function saveTheme(array $data): void
    {
        $whitelist = [
            'primary_color',
            'accent_color',
        ];
        $filtered = [];
        foreach ($whitelist as $key) {
            if (isset($data[$key])) {
                $filtered[$key] = (string) $data[$key];
            }
        }
        $this->settingsRepo->bulkSet('theme', $filtered);
    }

    /**
     * Regenerates the Cron Secret and redirects back.
     *
     * @param Request $req The incoming HTTP request.
     * @return Response The redirect response.
     */
    public function regenerateCronSecret(Request $req): Response
    {
        $newSecret = bin2hex(random_bytes(16));
        $this->settingsRepo->set('general', 'cron_secret', $newSecret);

        $this->audit->log('cron.secret_regenerated', 'settings');
        $this->session->flashSuccess('Cron secret regenerated successfully');

        return Response::redirect('/admin/settings/cron');
    }

    /**
     * Manually triggers execution of a specific cron job by name.
     *
     * @param Request $req The incoming HTTP request.
     * @return Response The redirect response.
     */
    public function runCronJob(Request $req): Response
    {
        $jobName = $req->param('jobName');
        if (empty($jobName)) {
            $this->session->flashError('No job name specified');
            return Response::redirect('/admin/settings/cron');
        }

        try {
            $runner = $this->c->get(\OwnPay\Cron\CronJobRunner::class);
            $result = $runner->runJob($jobName);

            if ($result['status'] === 'completed') {
                $duration = $result['duration'];
                $this->audit->log('cron.manual_run', 'settings', null, null, ['job' => $jobName, 'status' => 'completed', 'duration' => $duration]);
                $this->session->flashSuccess("Cron job '{$jobName}' executed successfully in {$duration}s");
            } else {
                $error = $result['error'] ?? 'Unknown error';
                $this->audit->log('cron.manual_run_failed', 'settings', null, null, ['job' => $jobName, 'error' => $error]);
                $this->session->flashError("Cron job '{$jobName}' failed: {$error}");
            }
        } catch (\Throwable $e) {
            $this->session->flashError("Failed to trigger job '{$jobName}': " . $e->getMessage());
        }

        return Response::redirect('/admin/settings/cron');
    }
}
