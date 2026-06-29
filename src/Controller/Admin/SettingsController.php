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
        $brand = $this->c->get(\OwnPay\Service\Brand\BrandContext::class);
        if (!$brand instanceof \OwnPay\Service\Brand\BrandContext) {
            throw new \RuntimeException('BrandContext service not found.');
        }
        $brand->resolveFromRequest($req);
        $mid = $brand->getActiveBrandId();
        if ($mid === null) {
            throw new \RuntimeException('Brand ID not resolved.');
        }

        $isBrandView = ($mid > 0);
        $brandRecord = null;
        $brandSettings = [];
        $domains = [];

        $appDomainVal = $_ENV['APP_DOMAIN'] ?? getenv('APP_DOMAIN') ?: '';
        $serverHost = is_string($appDomainVal) && $appDomainVal !== ''
            ? $appDomainVal
            : $req->header('Host', 'localhost');
        $serverIp = gethostbyname($serverHost);

        if ($isBrandView) {
            $merchantRepo = $this->c->get(\OwnPay\Repository\MerchantRepository::class);
            if (!$merchantRepo instanceof \OwnPay\Repository\MerchantRepository) {
                throw new \RuntimeException('MerchantRepository not found.');
            }
            $brandRecord = $merchantRepo->find($mid);
            if (!is_array($brandRecord)) {
                $brandRecord = [];
            }
            
            $brandSettings = [];
            if (isset($brandRecord['settings']) && is_string($brandRecord['settings'])) {
                $decoded = json_decode($brandRecord['settings'], true);
                if (is_array($decoded)) {
                    $brandSettings = $decoded;
                }
            }
            
            $settings = [
                'support_email' => $brandSettings['support_email'] ?? ($brandRecord['email'] ?? ''),
                'footer_text' => $brandSettings['footer_text'] ?? '',
                'faqs' => $brandSettings['faqs'] ?? [],
            ];

            foreach (['mail_from_name', 'mail_from_email', 'admin_notification_email', 'email_on_payment', 'email_on_refund'] as $notifyKey) {
                $settings[$notifyKey] = $this->settingsRepo->getScopedOverride('general', $notifyKey, $mid) ?? '';
            }
            $inherited = [
                'mail_from_name'           => $this->settingsRepo->get('general', 'mail_from_name', ''),
                'mail_from_email'          => $this->settingsRepo->get('general', 'mail_from_email', ''),
                'admin_notification_email' => $this->settingsRepo->get('general', 'admin_notification_email', ''),
                'email_on_payment'         => $this->settingsRepo->get('general', 'email_on_payment', '0'),
                'email_on_refund'          => $this->settingsRepo->get('general', 'email_on_refund', '0'),
            ];
            $branding = [
                'site_logo' => $brandRecord['logo_path'] ?? null,
                'site_favicon' => $brandSettings['favicon'] ?? null,
                'primary_color' => $brandSettings['primary_color'] ?? '#0D9488',
                'accent_color' => $brandSettings['accent_color'] ?? '#0F766E',
            ];
            $checkout = [
                'checkout_success_msg' => $brandSettings['checkout_success_msg'] ?? '',
                'checkout_pending_msg' => $brandSettings['checkout_pending_msg'] ?? '',
                'checkout_failed_msg' => $brandSettings['checkout_failed_msg'] ?? '',
                'timer_enabled' => $brandSettings['timer_enabled'] ?? '0',
                'timer_seconds' => $brandSettings['timer_seconds'] ?? '900',
                'show_faq' => $brandSettings['show_faq'] ?? '0',
                'custom_css' => $brandSettings['custom_css'] ?? '',
                'custom_js' => $brandSettings['custom_js'] ?? '',
            ];
            $theme = [
                'primary_color' => $brandSettings['primary_color'] ?? '#0D9488',
                'accent_color' => $brandSettings['accent_color'] ?? '#0F766E',
            ];
            $landing = [];

            $domainRepo = $this->c->get(\OwnPay\Repository\DomainRepository::class);
            if ($domainRepo instanceof \OwnPay\Repository\DomainRepository) {
                $domains = $domainRepo->forTenant($mid)->listAllScoped();
                foreach ($domains as &$d) {
                    $d['merchant_name'] = $brandRecord['name'] ?? '-';
                }
            }

            if (!in_array($activeTab, ['branding', 'checkout', 'domains', 'faq', 'payment', 'notifications'], true)) {
                return Response::redirect('/admin/settings/branding');
            }
        } else {
            $inherited   = [];
            $settings    = $this->settingsRepo->getGroup('general');
            $branding    = $this->settingsRepo->getGroup('branding');
            $landing     = $this->settingsRepo->getGroup('landing');
            $checkout    = $this->settingsRepo->getGroup('checkout');
            $theme       = $this->settingsRepo->getGroup('theme');

            $smsSettings = $this->settingsRepo->getGroup('sms');
            $settings['sms_positive_keywords'] = $smsSettings['positive_keywords'] ?? '';
            $settings['sms_negative_keywords'] = $smsSettings['negative_keywords'] ?? '';
            $settings['sms_filter_rules_check_interval_hours'] = $smsSettings['filter_rules_check_interval_hours'] ?? '24';

            if (isset($settings['faqs'])) {
                $decoded = json_decode((string) $settings['faqs'], true);
                $settings['faqs'] = (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) ? $decoded : [];
            }
            if (isset($landing['features'])) {
                $decoded = json_decode((string) $landing['features'], true);
                $landing['features'] = (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) ? $decoded : [];
            }

            $lockFile = dirname(__DIR__, 3) . '/storage/.maintenance';
            if (file_exists($lockFile) && empty($settings['maintenance_mode'])) {
                $settings['maintenance_mode'] = '1';
            }
        }

        $currencyService = $this->c->get(\OwnPay\Service\Payment\CurrencyService::class);
        if (!$currencyService instanceof \OwnPay\Service\Payment\CurrencyService) {
            throw new \RuntimeException('CurrencyService not found.');
        }
        $allCurrencies   = $currencyService->listAll();
        $timezones       = \DateTimeZone::listIdentifiers();

        $apiKeyService = $this->c->get(\OwnPay\Service\Customer\ApiKeyService::class);
        if (!$apiKeyService instanceof \OwnPay\Service\Customer\ApiKeyService) {
            throw new \RuntimeException('ApiKeyService not found.');
        }
        $apiKeys = $apiKeyService->list($mid);

        // Retrieve or auto-generate Cron Secret
        $cronSecret = is_string($settings['cron_secret'] ?? null) ? $settings['cron_secret'] : '';
        if ($cronSecret === '') {
            $cronSecret = bin2hex(random_bytes(16));
            $this->settingsRepo->set('general', 'cron_secret', $cronSecret);
            $settings['cron_secret'] = $cronSecret;
        }

        // Build Cron trigger URL white-labeled using DomainUrlService
        $urlService = $this->c->get(\OwnPay\Service\Domain\DomainUrlService::class);
        if (!$urlService instanceof \OwnPay\Service\Domain\DomainUrlService) {
            throw new \RuntimeException('DomainUrlService not found.');
        }
        $baseUrl = $urlService->resolveBaseUrl($mid, $req);
        $baseUrlStr = (string) $baseUrl;
        $cronUrl = rtrim($baseUrlStr, '/') . '/cron/' . $cronSecret;

        // Fetch all registered Cron Jobs and their execution logs
        $runner = $this->c->get(\OwnPay\Cron\CronJobRunner::class);
        if (!$runner instanceof \OwnPay\Cron\CronJobRunner) {
            throw new \RuntimeException('CronJobRunner not found.');
        }
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
                    $mins = (int) floor($elapsed / 60);
                    $elapsedStr = $mins . ($mins === 1 ? ' min ago' : ' mins ago');
                } elseif ($elapsed < 86400) {
                    $hours = (int) floor($elapsed / 3600);
                    $elapsedStr = $hours . ($hours === 1 ? ' hour ago' : ' hours ago');
                } else {
                    $days = (int) floor($elapsed / 86400);
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

        $transSvc = $this->c->get(\OwnPay\Service\System\TranslationService::class);
        if (!$transSvc instanceof \OwnPay\Service\System\TranslationService) {
            throw new \RuntimeException('TranslationService not found.');
        }
        $languages = $transSvc->getAllLanguages();
        $defaultLanguage = $transSvc->getDefaultLanguage();

        // Optimization metrics calculations
        $twigCacheDir = dirname(__DIR__, 3) . '/storage/cache/twig';
        $generalCacheDir = dirname(__DIR__, 3) . '/storage/cache';
        $twigStats = $this->getDirectoryStats($twigCacheDir);
        $totalCacheStats = $this->getDirectoryStats($generalCacheDir);
        $generalCacheSize = max(0, $totalCacheStats['size'] - $twigStats['size']);
        $generalCacheCount = max(0, $totalCacheStats['count'] - $twigStats['count']);

        $db = $this->c->get(\OwnPay\Core\Database::class);
        $dbName = 'ownpay';
        $configApp = $this->c->get('config.app');
        $dbConfig = (is_array($configApp) && isset($configApp['db']) && is_array($configApp['db'])) ? $configApp['db'] : [];
        if (!empty($dbConfig['name']) && is_string($dbConfig['name'])) {
            $dbName = $dbConfig['name'];
        }

        $dbStats = [
            'total_size' => 0,
            'table_count' => 0,
            'free_size' => 0,
            'fragmented_tables' => []
        ];

        try {
            if ($db instanceof \OwnPay\Core\Database) {
                $tables = $db->fetchAll(
                    "SELECT TABLE_NAME, DATA_LENGTH, INDEX_LENGTH, DATA_FREE 
                     FROM information_schema.TABLES 
                     WHERE TABLE_SCHEMA = :db",
                    ['db' => $dbName]
                );
                $totalSize = 0;
                $freeSize = 0;
                $fragTables = [];
                foreach ($tables as $t) {
                    $dLen = is_numeric($t['DATA_LENGTH'] ?? null) ? (int) $t['DATA_LENGTH'] : 0;
                    $iLen = is_numeric($t['INDEX_LENGTH'] ?? null) ? (int) $t['INDEX_LENGTH'] : 0;
                    $dFree = is_numeric($t['DATA_FREE'] ?? null) ? (int) $t['DATA_FREE'] : 0;
                    $totalSize += ($dLen + $iLen);
                    $freeSize += $dFree;
                    if ($dFree > 0) {
                        $tNameVal = $t['TABLE_NAME'] ?? '';
                        $fragTables[] = [
                            'name' => is_scalar($tNameVal) ? (string)$tNameVal : '',
                            'free' => $dFree
                        ];
                    }
                }
                $dbStats = [
                    'total_size' => $totalSize,
                    'table_count' => count($tables),
                    'free_size' => $freeSize,
                    'fragmented_tables' => $fragTables
                ];
            }
        } catch (\Throwable) {
            // Best-effort DB statistics - keep the defaults on any query error.
        }

        $logStats = [
            'audit_count' => 0,
            'login_count' => 0,
            'comm_count' => 0,
            'webhook_count' => 0,
            'session_count' => 0
        ];

        try {
            if ($db instanceof \OwnPay\Core\Database) {
                $auditVal = $db->fetchColumn("SELECT COUNT(*) FROM op_audit_logs");
                $loginVal = $db->fetchColumn("SELECT COUNT(*) FROM op_login_attempts");
                $commVal = $db->fetchColumn("SELECT COUNT(*) FROM op_comm_log");
                $webhookVal = $db->fetchColumn("SELECT COUNT(*) FROM op_webhook_delivery_logs");
                $sessionVal = $db->fetchColumn("SELECT COUNT(*) FROM op_sessions");

                $logStats['audit_count'] = is_numeric($auditVal) ? (int) $auditVal : 0;
                $logStats['login_count'] = is_numeric($loginVal) ? (int) $loginVal : 0;
                $logStats['comm_count'] = is_numeric($commVal) ? (int) $commVal : 0;
                $logStats['webhook_count'] = is_numeric($webhookVal) ? (int) $webhookVal : 0;
                $logStats['session_count'] = is_numeric($sessionVal) ? (int) $sessionVal : 0;
            }
        } catch (\Throwable) {
            // Best-effort log statistics - keep the zero defaults on any query error.
        }

        $tempDir = dirname(__DIR__, 3) . '/storage/temp';
        $tempStats = $this->getDirectoryStats($tempDir);

        $queueObj = $this->c->get(\OwnPay\Queue\QueueInterface::class);
        $queueDriverStats = [
            'driver' => getenv('QUEUE_DRIVER') ?: 'file',
            'sizes' => [
                'default'  => 0,
                'webhooks' => 0,
                'sms'      => 0,
                'emails'   => 0,
                'telegram' => 0,
            ]
        ];
        if ($queueObj instanceof \OwnPay\Queue\QueueInterface) {
            foreach (array_keys($queueDriverStats['sizes']) as $qName) {
                try {
                    $queueDriverStats['sizes'][$qName] = $queueObj->size($qName);
                } catch (\Throwable) {
                }
            }
        }

        $dbQueueStats = [
            'pending' => 0,
            'processing' => 0,
            'completed' => 0,
            'failed' => 0,
        ];
        try {
            if ($db instanceof \OwnPay\Core\Database) {
                $rows = $db->fetchAll(
                    "SELECT status, COUNT(*) as cnt FROM op_job_queue GROUP BY status"
                );
                foreach ($rows as $row) {
                    $status = isset($row['status']) && is_string($row['status']) ? $row['status'] : '';
                    $cnt = $row['cnt'] ?? 0;
                    if ($status !== '' && array_key_exists($status, $dbQueueStats)) {
                        $dbQueueStats[$status] = is_numeric($cnt) ? (int) $cnt : 0;
                    }
                }
            }
        } catch (\Throwable) {
        }

        $optimizationSettings = [
            'log_retention_days' => (int) $this->settingsRepo->get('runtime', 'optimization.log_retention_days', '90'),
            'last_cache_clear' => $this->settingsRepo->get('runtime', 'optimization.last_cache_clear_time', ''),
            'last_db_optimize' => $this->settingsRepo->get('runtime', 'optimization.last_db_optimize_time', ''),
            'last_logs_purge' => $this->settingsRepo->get('runtime', 'optimization.last_logs_purge_time', ''),
            'last_uploads_purge' => $this->settingsRepo->get('runtime', 'optimization.last_uploads_purge_time', '')
        ];

        return $this->renderAdminPage('admin/settings/index.twig', [
            'queue_driver_stats' => $queueDriverStats,
            'db_queue_stats'     => $dbQueueStats,
            'settings'          => $settings,
            'branding'          => $branding,
            'landing'           => $landing,
            'checkout_settings' => $checkout,
            'theme'             => $theme,
            'currencies'        => $allCurrencies,
            'all_currencies'    => $allCurrencies,
            'base_currency'     => $this->settingsRepo->get('general', 'base_currency', 'USD'),
            'timezones'         => $timezones,
            'api_keys'          => $apiKeys,
            'active_page'       => 'settings',
            'default_tab'       => $activeTab,
            'cron_secret'       => $cronSecret,
            'cron_url'          => $cronUrl,
            'cron_jobs'         => $cronJobs,
            'languages'         => $languages,
            'default_language'  => $defaultLanguage,
            'general_cache_size' => $generalCacheSize,
            'general_cache_count' => $generalCacheCount,
            'twig_cache_size'   => $twigStats['size'],
            'twig_cache_count'  => $twigStats['count'],
            'db_stats'          => $dbStats,
            'log_stats'         => $logStats,
            'temp_uploads_size'  => $tempStats['size'],
            'temp_uploads_count' => $tempStats['count'],
            'opt_settings'      => $optimizationSettings,
            'is_brand_view'     => $isBrandView,
            'inherited'         => $inherited,
            'brand'             => $brandRecord,
            'domains'           => $domains,
            'server_host'       => $serverHost,
            'server_ip'         => $serverIp,
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
        $tabVal  = $req->post('_tab', 'general');
        $tab = is_string($tabVal) ? $tabVal : 'general';
        $postData = $req->post();
        $data = is_array($postData) ? $postData : [];
        unset($data['_csrf_token'], $data['_tab']);

        $brand = $this->c->get(\OwnPay\Service\Brand\BrandContext::class);
        if (!$brand instanceof \OwnPay\Service\Brand\BrandContext) {
            throw new \RuntimeException('BrandContext service not found.');
        }
        $brand->resolveFromRequest($req);
        $mid = $brand->getActiveBrandId();
        if ($mid === null) {
            throw new \RuntimeException('Brand ID not resolved.');
        }

        if ($mid > 0) {
            $merchantRepo = $this->c->get(\OwnPay\Repository\MerchantRepository::class);
            if (!$merchantRepo instanceof \OwnPay\Repository\MerchantRepository) {
                throw new \RuntimeException('MerchantRepository not found.');
            }
            $brandRecord = $merchantRepo->find($mid);
            if (!is_array($brandRecord)) {
                $this->session->flashError('Brand not found');
                return Response::redirect('/admin/settings');
            }
            
            $brandSettings = [];
            if (isset($brandRecord['settings']) && is_string($brandRecord['settings'])) {
                $decoded = json_decode($brandRecord['settings'], true);
                if (is_array($decoded)) {
                    $brandSettings = $decoded;
                }
            }
            
            switch ($tab) {
                case 'branding':
                    $primaryColorVal = $data['primary_color'] ?? ($brandSettings['primary_color'] ?? '#0D9488');
                    $brandSettings['primary_color'] = \OwnPay\Service\System\InputSanitizer::string(is_string($primaryColorVal) ? $primaryColorVal : '#0D9488');
                    
                    $accentColorVal = $data['accent_color'] ?? ($brandSettings['accent_color'] ?? '#0F766E');
                    $brandSettings['accent_color'] = \OwnPay\Service\System\InputSanitizer::string(is_string($accentColorVal) ? $accentColorVal : '#0F766E');
                    
                    $supportEmailVal = $data['support_email'] ?? ($brandSettings['support_email'] ?? '');
                    $brandSettings['support_email'] = \OwnPay\Service\System\InputSanitizer::email(is_string($supportEmailVal) ? $supportEmailVal : '');
                    
                    $footerTextVal = $data['footer_text'] ?? ($brandSettings['footer_text'] ?? '');
                    $brandSettings['footer_text'] = \OwnPay\Service\System\InputSanitizer::string(is_string($footerTextVal) ? $footerTextVal : '');

                    // Extract and update Brand profile fields (merged brand settings)
                    $brandNameVal = $data['brand_name'] ?? $brandRecord['name'];
                    $brandRecord['name'] = \OwnPay\Service\System\InputSanitizer::string(is_string($brandNameVal) ? $brandNameVal : '');
                    
                    $brandEmailVal = $data['brand_email'] ?? $brandRecord['email'];
                    $brandRecord['email'] = \OwnPay\Service\System\InputSanitizer::email(is_string($brandEmailVal) ? $brandEmailVal : '');
                    
                    $brandPhoneVal = $data['brand_phone'] ?? $brandRecord['phone'];
                    $brandRecord['phone'] = \OwnPay\Service\System\InputSanitizer::string(is_string($brandPhoneVal) ? $brandPhoneVal : '');
                    
                    $brandTimezoneVal = $data['brand_timezone'] ?? $brandRecord['timezone'];
                    $brandRecord['timezone'] = \OwnPay\Service\System\InputSanitizer::string(is_string($brandTimezoneVal) ? $brandTimezoneVal : 'Asia/Dhaka');
                    
                    $brandCurrencyVal = $data['brand_currency'] ?? $brandRecord['default_currency'];
                    $brandRecord['default_currency'] = \OwnPay\Service\System\InputSanitizer::string(is_string($brandCurrencyVal) ? $brandCurrencyVal : 'BDT');
                    break;
                    
                case 'payment':
                    $brandCurrencyVal = $data['brand_currency'] ?? $brandRecord['default_currency'];
                    $brandRecord['default_currency'] = \OwnPay\Service\System\InputSanitizer::string(is_string($brandCurrencyVal) ? $brandCurrencyVal : 'BDT');
                    break;
                    
                case 'checkout':
                    $successMsgVal = $data['checkout_success_msg'] ?? ($brandSettings['checkout_success_msg'] ?? '');
                    $brandSettings['checkout_success_msg'] = \OwnPay\Service\System\InputSanitizer::string(is_string($successMsgVal) ? $successMsgVal : '');

                    $pendingMsgVal = $data['checkout_pending_msg'] ?? ($brandSettings['checkout_pending_msg'] ?? '');
                    $brandSettings['checkout_pending_msg'] = \OwnPay\Service\System\InputSanitizer::string(is_string($pendingMsgVal) ? $pendingMsgVal : '');

                    $failedMsgVal = $data['checkout_failed_msg'] ?? ($brandSettings['checkout_failed_msg'] ?? '');
                    $brandSettings['checkout_failed_msg'] = \OwnPay\Service\System\InputSanitizer::string(is_string($failedMsgVal) ? $failedMsgVal : '');
                    
                    $brandSettings['timer_enabled'] = isset($data['timer_enabled']) && $data['timer_enabled'] === '1' ? '1' : '0';
                    $timerSecondsVal = $data['timer_seconds'] ?? ($brandSettings['timer_seconds'] ?? '900');
                    $brandSettings['timer_seconds'] = is_scalar($timerSecondsVal) ? (string) $timerSecondsVal : '900';
                    
                    $brandSettings['show_faq'] = isset($data['show_faq']) && $data['show_faq'] === '1' ? '1' : '0';
                    
                    $isSuperadmin = !empty($_SESSION['is_superadmin']);
                    if ($isSuperadmin) {
                        $customCssVal = $data['custom_css'] ?? ($brandSettings['custom_css'] ?? '');
                        $customCss = is_string($customCssVal) ? $customCssVal : '';
                        $customJsVal = $data['custom_js'] ?? ($brandSettings['custom_js'] ?? '');
                        $customJs = is_string($customJsVal) ? $customJsVal : '';

                        $customCss = preg_replace('/expression\s*\(|behavior\s*:|javascript\s*:/i', '', $customCss);
                        $customCss = preg_replace('/<\s*script\b[^>]*>(.*?)<\s*\/\s*script\s*>/is', '', is_string($customCss) ? $customCss : '');

                        $brandSettings['custom_css'] = is_string($customCss) ? $customCss : '';
                        $brandSettings['custom_js']  = $customJs;
                    }
                    break;
                    
                case 'faq':
                    if (isset($data['faqs']) && is_array($data['faqs'])) {
                        $cleanFaqs = [];
                        foreach ($data['faqs'] as $faq) {
                            if (is_array($faq) && isset($faq['question'], $faq['answer'])) {
                                $cleanFaqs[] = [
                                    'question' => \OwnPay\Service\System\InputSanitizer::string((string)$faq['question']),
                                    'answer' => \OwnPay\Service\System\InputSanitizer::string((string)$faq['answer']),
                                ];
                            }
                        }
                        $brandSettings['faqs'] = $cleanFaqs;
                    } else {
                        $brandSettings['faqs'] = [];
                    }
                    break;

                case 'notifications':
                    $this->saveBrandNotifications($data, $mid);
                    break;
            }
            
            $merchantRepo->updateBrand($mid, [
                'name'             => $brandRecord['name'],
                'email'            => $brandRecord['email'],
                'phone'            => $brandRecord['phone'],
                'timezone'         => $brandRecord['timezone'],
                'default_currency' => $brandRecord['default_currency'],
                'status'           => $brandRecord['status'],
                'logo_path'        => $brandRecord['logo_path'],
                'settings'         => json_encode($brandSettings, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ]);

            $this->events->doAction('settings.saved', ['tab' => $tab, 'data' => $data]);
            $this->audit->log('brand.settings.saved', 'merchant', $mid, null, ['tab' => $tab]);
            $this->session->flashSuccess('Settings saved');
            return Response::redirect('/admin/settings/' . $tab);
        }

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

        $referer = $req->header('Referer');
        if ($referer !== '' && str_contains($referer, '/admin/developer')) {
            return Response::redirect('/admin/developer#webhooks');
        }

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
        $brand = $this->c->get(\OwnPay\Service\Brand\BrandContext::class);
        if (!$brand instanceof \OwnPay\Service\Brand\BrandContext) {
            throw new \RuntimeException('BrandContext service not found.');
        }
        $brand->resolveFromRequest($req);
        $mid = $brand->getActiveBrandId();
        if ($mid === null) {
            throw new \RuntimeException('Brand ID not resolved.');
        }

        $isBrandView = ($mid > 0);
        $saved = [];
        $fs = new \OwnPay\Service\System\FilesystemService(dirname(__DIR__, 3) . '/public/assets');

        if ($isBrandView) {
            $merchantRepo = $this->c->get(\OwnPay\Repository\MerchantRepository::class);
            if (!$merchantRepo instanceof \OwnPay\Repository\MerchantRepository) {
                throw new \RuntimeException('MerchantRepository not found.');
            }
            $brandRecord = $merchantRepo->find($mid);
            if (!is_array($brandRecord)) {
                $this->session->flashError('Brand not found');
                return Response::redirect('/admin/settings/branding');
            }
            
            $brandSettings = [];
            if (isset($brandRecord['settings']) && is_string($brandRecord['settings'])) {
                $decoded = json_decode($brandRecord['settings'], true);
                if (is_array($decoded)) {
                    $brandSettings = $decoded;
                }
            }
            
            $logoPath = $brandRecord['logo_path'];
            $faviconPath = $brandSettings['favicon'] ?? null;

            $logoFile = $req->file('site_logo');
            if ($logoFile !== null && ($logoFile['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
                try {
                    if (isset($logoFile['name'], $logoFile['tmp_name']) && is_string($logoFile['name']) && is_string($logoFile['tmp_name'])) {
                        $storedPath = $fs->storeUpload($logoFile, 'uploads/brands');
                        $logoPath = '/assets/' . $storedPath;
                        $saved['logo'] = $logoPath;
                    }
                } catch (\Throwable $e) {
                    $this->session->flashError('Invalid file for brand logo: ' . $e->getMessage());
                }
            }

            $faviconFile = $req->file('site_favicon');
            if ($faviconFile !== null && ($faviconFile['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
                try {
                    if (isset($faviconFile['name'], $faviconFile['tmp_name']) && is_string($faviconFile['name']) && is_string($faviconFile['tmp_name'])) {
                        $storedPath = $fs->storeUpload($faviconFile, 'uploads/brands');
                        $faviconPath = '/assets/' . $storedPath;
                        $saved['favicon'] = $faviconPath;
                    }
                } catch (\Throwable $e) {
                    $this->session->flashError('Invalid file for brand favicon: ' . $e->getMessage());
                }
            }

            $brandSettings['logo'] = $logoPath;
            $brandSettings['favicon'] = $faviconPath;

            $merchantRepo->updateBrand($mid, [
                'name'             => $brandRecord['name'],
                'email'            => $brandRecord['email'],
                'phone'            => $brandRecord['phone'],
                'timezone'         => $brandRecord['timezone'],
                'default_currency' => $brandRecord['default_currency'],
                'status'           => $brandRecord['status'],
                'logo_path'        => $logoPath,
                'settings'         => json_encode($brandSettings, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ]);

            $this->audit->log('brand.branding.upload', 'merchant', $mid, null, ['files' => array_keys($saved)]);
            $this->session->flashSuccess('Branding files uploaded successfully');
            return Response::redirect('/admin/settings/branding');
        }

        foreach (['site_logo', 'site_favicon'] as $field) {
            $file = $req->file($field);
            if ($file === null || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                continue;
            }

            try {
                if (isset($file['name'], $file['tmp_name']) && is_string($file['name']) && is_string($file['tmp_name'])) {
                    $storedPath = $fs->storeUpload($file, 'uploads');
                    $path = '/assets/' . $storedPath;
                    $this->settingsRepo->set('branding', $field, $path);
                    $saved[$field] = $path;
                }
            } catch (\Throwable $e) {
                $this->session->flashError("Invalid file for {$field}: " . $e->getMessage());
                return Response::redirect('/admin/settings/branding');
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

    /**
     * Helper to recursively scan a directory for total file count and size.
     *
     * @param string $dir Target directory.
     * @return array{size: int, count: int}
     */
    private function getDirectoryStats(string $dir): array
    {
        $size = 0;
        $count = 0;
        if (!is_dir($dir)) {
            return ['size' => $size, 'count' => $count];
        }

        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iterator as $file) {
                if ($file instanceof \SplFileInfo && $file->isFile()) {
                    $size += $file->getSize();
                    $count++;
                }
            }
        } catch (\Throwable) {
            // Gracefully ignore directory permission or read errors
        }

        return ['size' => $size, 'count' => $count];
    }

    /**
     * Recursively unlinks directory contents while keeping the base directory intact.
     *
     * @param string $dir Target directory.
     * @return void
     */
    private function removeDirectoryContents(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        try {
            $items = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($items as $item) {
                if ($item instanceof \SplFileInfo) {
                    $realPath = $item->getRealPath();
                    if ($realPath !== false) {
                        $item->isDir() ? @rmdir($realPath) : @unlink($realPath);
                    }
                }
            }
        } catch (\Throwable) {
        }
    }

    /**
     * Clears application general key-value caches and unlinks Twig compiled templates.
     *
     * @param Request $req The incoming request context.
     * @return Response The redirect response.
     */
    public function optimizeCache(Request $req): Response
    {
        // 1. Flush key-value cache
        /** @var mixed $cache */
        $cache = $this->c->get(\OwnPay\Cache\CacheInterface::class);
        if ($cache instanceof \OwnPay\Cache\CacheInterface) {
            $cache->flush();
        }

        // 2. Clear Twig views cache
        $twigCacheDir = dirname(__DIR__, 3) . '/storage/cache/twig';
        if (is_dir($twigCacheDir)) {
            $this->removeDirectoryContents($twigCacheDir);
        }

        // 3. Clear Login slug cache
        $loginSlugCache = dirname(__DIR__, 3) . '/storage/cache/login_slug.cache';
        if (file_exists($loginSlugCache)) {
            @unlink($loginSlugCache);
        }

        $this->settingsRepo->set('runtime', 'optimization.last_cache_clear_time', date('Y-m-d H:i:s'));
        $this->audit->log('settings.cache_cleaned', 'settings');
        $this->session->flashSuccess('Application cache and compiled Twig templates cleared successfully.');

        return Response::redirect('/admin/settings/optimization');
    }

    /**
     * Executes index statistics updates globally and defragments key high-churn tables.
     *
     * @param Request $req The incoming request context.
     * @return Response The redirect response.
     */
    public function optimizeDatabase(Request $req): Response
    {
        $db = $this->c->get(\OwnPay\Core\Database::class);
        if (!$db instanceof \OwnPay\Core\Database) {
            $this->session->flashError('Database connection unavailable.');
            return Response::redirect('/admin/settings/optimization');
        }

        $dbName = 'ownpay';
        $configApp = $this->c->get('config.app');
        /** @var array<string, mixed> $dbConfig */
        $dbConfig = (is_array($configApp) && isset($configApp['db']) && is_array($configApp['db'])) ? $configApp['db'] : [];
        if (!empty($dbConfig['name']) && is_string($dbConfig['name'])) {
            $dbName = $dbConfig['name'];
        }

        try {
            // Get all tables in our schema
            $tables = $db->fetchAll(
                "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = :db",
                ['db' => $dbName]
            );

            // Fast, non-blocking stats update (ANALYZE) on all tables
            foreach ($tables as $t) {
                $tableName = (isset($t['TABLE_NAME']) && is_scalar($t['TABLE_NAME'])) ? (string) $t['TABLE_NAME'] : '';
                if ($tableName !== '' && str_starts_with($tableName, 'op_')) {
                    $db->execute("ANALYZE TABLE `{$tableName}`");
                }
            }

            // Safe defragmentation (OPTIMIZE) on high-churn tables table-by-table
            $hotTables = ['op_transactions', 'op_ledger_entries', 'op_sms_parsed', 'op_audit_logs'];
            foreach ($hotTables as $tableName) {
                $db->execute("OPTIMIZE TABLE `{$tableName}`");
            }

            $this->settingsRepo->set('runtime', 'optimization.last_db_optimize_time', date('Y-m-d H:i:s'));
            $this->audit->log('settings.db_optimized', 'settings');
            $this->session->flashSuccess('Database query statistics analyzed and fragmented index pages defragmented.');
        } catch (\Throwable $e) {
            $this->session->flashError('Database optimization failed: ' . $e->getMessage());
        }

        return Response::redirect('/admin/settings/optimization');
    }

    /**
     * Purges expired user sessions and logs older than the configurable retention setting.
     *
     * @param Request $req The incoming request context.
     * @return Response The redirect response.
     */
    public function optimizeLogs(Request $req): Response
    {
        $db = $this->c->get(\OwnPay\Core\Database::class);
        if (!$db instanceof \OwnPay\Core\Database) {
            $this->session->flashError('Database connection unavailable.');
            return Response::redirect('/admin/settings/optimization');
        }

        $retentionVal = $req->post('log_retention_days', '90');
        $retentionDays = is_numeric($retentionVal) ? (int)$retentionVal : 90;
        if (!in_array($retentionDays, [30, 60, 90, 180], true)) {
            $retentionDays = 90;
        }

        // Persist retention setting
        $this->settingsRepo->set('runtime', 'optimization.log_retention_days', (string) $retentionDays);

        try {
            $cutoffDate = date('Y-m-d H:i:s', strtotime("-{$retentionDays} days"));

            // 1. Purge old audit logs
            $db->execute("DELETE FROM op_audit_logs WHERE created_at < :cutoff", ['cutoff' => $cutoffDate]);

            // 2. Purge old login attempts
            $db->execute("DELETE FROM op_login_attempts WHERE created_at < :cutoff", ['cutoff' => $cutoffDate]);

            // 3. Purge old communication logs
            $db->execute("DELETE FROM op_comm_log WHERE created_at < :cutoff", ['cutoff' => $cutoffDate]);

            // 4. Purge old webhook delivery logs
            $db->execute("DELETE FROM op_webhook_delivery_logs WHERE created_at < :cutoff", ['cutoff' => $cutoffDate]);

            // 5. Purge expired sessions
            $db->execute("DELETE FROM op_sessions WHERE last_activity < :cutoff", ['cutoff' => time() - 86400]);

            $this->settingsRepo->set('runtime', 'optimization.last_logs_purge_time', date('Y-m-d H:i:s'));
            $this->audit->log('settings.logs_purged', 'settings', null, null, ['retention_days' => $retentionDays]);
            $this->session->flashSuccess("System logs and expired sessions older than {$retentionDays} days purged successfully.");
        } catch (\Throwable $e) {
            $this->session->flashError('Logs purge failed: ' . $e->getMessage());
        }

        return Response::redirect('/admin/settings/optimization');
    }

    /**
     * Purges transient temporary upload files older than 24 hours.
     *
     * @param Request $req The incoming request context.
     * @return Response The redirect response.
     */
    public function optimizeUploads(Request $req): Response
    {
        $tempDir = dirname(__DIR__, 3) . '/storage/temp';
        $count = 0;
        if (is_dir($tempDir)) {
            try {
                $iterator = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($tempDir, \FilesystemIterator::SKIP_DOTS),
                    \RecursiveIteratorIterator::CHILD_FIRST
                );
                $cutoff = time() - 86400; // 24 hours ago
                foreach ($iterator as $item) {
                    if ($item instanceof \SplFileInfo && $item->isFile()) {
                        $mTime = $item->getMTime();
                        if ($mTime < $cutoff) {
                            @unlink($item->getRealPath());
                            $count++;
                        }
                    }
                }
            } catch (\Throwable) {
                // Best-effort temp purge - a locked/in-use file is skipped, not fatal.
            }
        }

        $this->settingsRepo->set('runtime', 'optimization.last_uploads_purge_time', date('Y-m-d H:i:s'));
        $this->audit->log('settings.uploads_cleaned', 'settings', null, null, ['deleted_files_count' => $count]);
        $this->session->flashSuccess("Temporary upload files older than 24 hours purged successfully ({$count} files reclaimed).");

        return Response::redirect('/admin/settings/optimization');
    }

    /**
     * Executes all four platform manual optimization actions sequentially.
     *
     * @param Request $req The incoming request context.
     * @return Response The redirect response.
     */
    public function optimizeAll(Request $req): Response
    {
        // 1. Optimize cache
        $cache = $this->c->get(\OwnPay\Cache\CacheInterface::class);
        if ($cache instanceof \OwnPay\Cache\CacheInterface) {
            $cache->flush();
        }
        $twigCacheDir = dirname(__DIR__, 3) . '/storage/cache/twig';
        if (is_dir($twigCacheDir)) {
            $this->removeDirectoryContents($twigCacheDir);
        }
        $loginSlugCache = dirname(__DIR__, 3) . '/storage/cache/login_slug.cache';
        if (file_exists($loginSlugCache)) {
            @unlink($loginSlugCache);
        }
        $this->settingsRepo->set('runtime', 'optimization.last_cache_clear_time', date('Y-m-d H:i:s'));

        // 2. Optimize Database
        $db = $this->c->get(\OwnPay\Core\Database::class);
        if ($db instanceof \OwnPay\Core\Database) {
            $dbName = 'ownpay';
            $configApp = $this->c->get('config.app');
            /** @var array<string, mixed> $dbConfig */
            $dbConfig = (is_array($configApp) && isset($configApp['db']) && is_array($configApp['db'])) ? $configApp['db'] : [];
            if (!empty($dbConfig['name']) && is_string($dbConfig['name'])) {
                $dbName = $dbConfig['name'];
            }
            try {
                $tables = $db->fetchAll(
                    "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = :db",
                    ['db' => $dbName]
                );
                foreach ($tables as $t) {
                    $tableName = (isset($t['TABLE_NAME']) && is_scalar($t['TABLE_NAME'])) ? (string) $t['TABLE_NAME'] : '';
                    if ($tableName !== '' && str_starts_with($tableName, 'op_')) {
                        $db->execute("ANALYZE TABLE `{$tableName}`");
                    }
                }
                $hotTables = ['op_transactions', 'op_ledger_entries', 'op_sms_parsed', 'op_audit_logs'];
                foreach ($hotTables as $tableName) {
                    $db->execute("OPTIMIZE TABLE `{$tableName}`");
                }
            } catch (\Throwable) {
                // Best-effort table maintenance - ANALYZE/OPTIMIZE failures are non-fatal.
            }
            $this->settingsRepo->set('runtime', 'optimization.last_db_optimize_time', date('Y-m-d H:i:s'));
        }

        // 3. Purge Logs & Sessions
        if ($db instanceof \OwnPay\Core\Database) {
            $retentionVal = $req->post('log_retention_days', '90');
            $retentionDays = is_numeric($retentionVal) ? (int)$retentionVal : 90;
            if (!in_array($retentionDays, [30, 60, 90, 180], true)) {
                $retentionDays = 90;
            }
            $this->settingsRepo->set('runtime', 'optimization.log_retention_days', (string) $retentionDays);
            try {
                $cutoffDate = date('Y-m-d H:i:s', strtotime("-{$retentionDays} days"));
                $db->execute("DELETE FROM op_audit_logs WHERE created_at < :cutoff", ['cutoff' => $cutoffDate]);
                $db->execute("DELETE FROM op_login_attempts WHERE created_at < :cutoff", ['cutoff' => $cutoffDate]);
                $db->execute("DELETE FROM op_comm_log WHERE created_at < :cutoff", ['cutoff' => $cutoffDate]);
                $db->execute("DELETE FROM op_webhook_delivery_logs WHERE created_at < :cutoff", ['cutoff' => $cutoffDate]);
                $db->execute("DELETE FROM op_sessions WHERE last_activity < :cutoff", ['cutoff' => time() - 86400]);
            } catch (\Throwable) {
                // Best-effort retention purge - a failed delete is retried next run.
            }
            $this->settingsRepo->set('runtime', 'optimization.last_logs_purge_time', date('Y-m-d H:i:s'));
        }

        // 4. Clean Uploads
        $tempDir = dirname(__DIR__, 3) . '/storage/temp';
        $uploadsCount = 0;
        if (is_dir($tempDir)) {
            try {
                $iterator = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($tempDir, \FilesystemIterator::SKIP_DOTS),
                    \RecursiveIteratorIterator::CHILD_FIRST
                );
                $cutoff = time() - 86400;
                foreach ($iterator as $item) {
                    if ($item instanceof \SplFileInfo && $item->isFile()) {
                        $mTime = $item->getMTime();
                        if ($mTime < $cutoff) {
                            @unlink($item->getRealPath());
                            $uploadsCount++;
                        }
                    }
                }
            } catch (\Throwable) {
                // Best-effort temp purge - a locked/in-use file is skipped, not fatal.
            }
        }
        $this->settingsRepo->set('runtime', 'optimization.last_uploads_purge_time', date('Y-m-d H:i:s'));

        $this->audit->log('settings.full_optimization_completed', 'settings', null, null, ['deleted_files_count' => $uploadsCount]);
        $this->session->flashSuccess('Full platform maintenance and performance optimization run completed successfully.');

        return Response::redirect('/admin/settings/optimization');
    }

    // --- Private save helpers

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

        if (isset($data['faqs']) && is_array($data['faqs'])) {
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
            'faqs'
        ];

        $filtered = [];
        foreach ($whitelist as $key) {
            if (isset($data[$key])) {
                $filtered[$key] = is_array($data[$key]) ? (json_encode($data[$key]) ?: '') : (is_scalar($data[$key]) ? (string) $data[$key] : '');
            }
        }

        $this->settingsRepo->bulkSet('general', $filtered);

        // Save Admin Login URL Slug to landing group if present (as it was moved to Security tab)
        if (isset($data['admin_login_slug'])) {
            $slug = is_scalar($data['admin_login_slug']) ? (string) $data['admin_login_slug'] : 'login';
            $slugVal = preg_replace('/[^a-z0-9\-]/i', '', $slug); // sanitize slug
            $slug = is_string($slugVal) ? $slugVal : 'login';
            if ($slug === '') {
                $slug = 'login';
            }
            $this->settingsRepo->set('landing', 'admin_login_slug', $slug);

            // Invalidate login slug cache to apply changes immediately
            $cacheFile = dirname(__DIR__, 3) . '/storage/cache/login_slug.cache';
            if (file_exists($cacheFile)) {
                @unlink($cacheFile);
            }
        }

        // Save SMS group settings
        if (isset($data['sms_positive_keywords'])) {
            $this->settingsRepo->set('sms', 'positive_keywords', is_scalar($data['sms_positive_keywords']) ? (string) $data['sms_positive_keywords'] : '');
        }
        if (isset($data['sms_negative_keywords'])) {
            $this->settingsRepo->set('sms', 'negative_keywords', is_scalar($data['sms_negative_keywords']) ? (string) $data['sms_negative_keywords'] : '');
        }
        if (isset($data['sms_filter_rules_check_interval_hours'])) {
            $this->settingsRepo->set('sms', 'filter_rules_check_interval_hours', is_scalar($data['sms_filter_rules_check_interval_hours']) ? (string) $data['sms_filter_rules_check_interval_hours'] : '');
        }

        // Sync maintenance lock file
        $lockFile = dirname(__DIR__, 3) . '/storage/.maintenance';
        if (!empty($filtered['maintenance_mode'])) {
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
     * Persists per-brand email-notification overrides into op_system_settings (group 'general').
     *
     * A blank text field DELETES the brand override (re-inheriting the All-Brands default);
     * a non-blank value stores a brand-specific override. Toggle selects use '' = inherit,
     * '1' = on, '0' = off. This keeps every key resolvable via getScoped at send time.
     *
     * @param array<string, mixed> $data Submitted form data.
     * @param int $merchantId The active brand identifier.
     * @return void
     */
    private function saveBrandNotifications(array $data, int $merchantId): void
    {
        $rawName = $data['mail_from_name'] ?? '';
        $name = is_scalar($rawName) ? trim((string) $rawName) : '';
        $this->applyBrandOverride('mail_from_name', $merchantId, $name === '' ? '' : \OwnPay\Service\System\InputSanitizer::string($name));

        $rawFrom = $data['mail_from_email'] ?? '';
        $from = is_scalar($rawFrom) ? trim((string) $rawFrom) : '';
        $this->applyBrandOverride('mail_from_email', $merchantId, $from === '' ? '' : \OwnPay\Service\System\InputSanitizer::email($from));

        $rawAdmin = $data['admin_notification_email'] ?? '';
        $admin = is_scalar($rawAdmin) ? trim((string) $rawAdmin) : '';
        $this->applyBrandOverride('admin_notification_email', $merchantId, $admin === '' ? '' : \OwnPay\Service\System\InputSanitizer::email($admin));

        foreach (['email_on_payment', 'email_on_refund'] as $toggle) {
            $rawToggle = $data[$toggle] ?? '';
            $toggleVal = is_scalar($rawToggle) ? (string) $rawToggle : '';
            $this->applyBrandOverride($toggle, $merchantId, ($toggleVal === '1' || $toggleVal === '0') ? $toggleVal : '');
        }
    }

    /**
     * Writes or clears a single brand-scoped 'general' override.
     *
     * An empty value clears the override so the brand re-inherits the All-Brands default.
     *
     * @param string $key The settings key.
     * @param int $merchantId The active brand identifier.
     * @param string $value The sanitized value, or '' to clear (inherit).
     * @return void
     */
    private function applyBrandOverride(string $key, int $merchantId, string $value): void
    {
        if ($value === '') {
            $this->settingsRepo->deleteSettingScoped('general', $key, $merchantId);
        } else {
            $this->settingsRepo->setScoped('general', $key, $value, $merchantId);
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
                $filtered[$key] = is_scalar($data[$key]) ? (string) $data[$key] : '';
            }
        }
        $this->settingsRepo->bulkSet('branding', $filtered);

        $themeData = [];
        if (isset($data['primary_color'])) {
            $themeData['primary_color'] = is_scalar($data['primary_color']) ? (string) $data['primary_color'] : '#6C5CE7';
        }
        if (isset($data['accent_color'])) {
            $themeData['accent_color'] = is_scalar($data['accent_color']) ? (string) $data['accent_color'] : '#00CEC9';
        }
        if ($themeData !== []) {
            $this->settingsRepo->bulkSet('theme', $themeData);
        }
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
        if (isset($data['features']) && is_array($data['features'])) {
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
                $filtered[$key] = is_array($data[$key]) ? (json_encode($data[$key]) ?: '') : (is_scalar($data[$key]) ? (string) $data[$key] : '');
            }
        }
        $this->settingsRepo->bulkSet('landing', $filtered);

        // Invalidate login slug cache to apply changes immediately
        $cacheFile = dirname(__DIR__, 3) . '/storage/cache/login_slug.cache';
        if (file_exists($cacheFile)) {
            @unlink($cacheFile);
        }
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
            if ($currencySvc instanceof \OwnPay\Service\Payment\CurrencyService) {
                foreach ($currencies as $code => $cData) {
                    if (is_array($cData) && isset($cData['rate']) && is_scalar($cData['rate'])) {
                        $currencySvc->updateExchangeRate((string) $code, (string) $cData['rate']);
                    }
                }
            }
        }

        $oldDefaultCurrency = $this->settingsRepo->get('general', 'default_currency', 'USD');
        $newDefaultCurrency = is_scalar($data['default_currency'] ?? null) ? trim((string) $data['default_currency']) : 'USD';

        $whitelist = [
            'default_currency',
            'exchange_rate_mode',
            'exchange_rate_api_url',
            'payment_expiry_minutes',
            'invoice_due_days',
            'auto_approve_payments',
        ];

        $filtered = [];
        foreach ($whitelist as $key) {
            if (isset($data[$key])) {
                $filtered[$key] = is_scalar($data[$key]) ? (string) $data[$key] : '';
            }
        }

        // Keep base_currency and currency in sync
        $filtered['base_currency'] = $newDefaultCurrency;
        $filtered['currency'] = $newDefaultCurrency;

        $this->settingsRepo->bulkSet('general', $filtered);

        if ($oldDefaultCurrency !== $newDefaultCurrency) {
            $currencySvc = $this->c->get(\OwnPay\Service\Payment\CurrencyService::class);
            if ($currencySvc instanceof \OwnPay\Service\Payment\CurrencyService) {
                $currencySvc->syncRates();
            }
        }
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
                $filtered[$key] = is_scalar($data[$key]) ? (string) $data[$key] : '';
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
                $filtered[$key] = is_scalar($data[$key]) ? (string) $data[$key] : '';
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
            if ($runner instanceof \OwnPay\Cron\CronJobRunner) {
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
            }
        } catch (\Throwable $e) {
            $this->session->flashError("Failed to trigger job '{$jobName}': " . $e->getMessage());
        }

        return Response::redirect('/admin/settings/cron');
    }

    /**
     * Saves the default system language.
     *
     * @param Request $req The incoming request context.
     * @return Response
     */
    public function saveDefaultLanguage(Request $req): Response
    {
        $defaultLang = $req->post('default_language', 'en');
        $code = is_string($defaultLang) ? $defaultLang : 'en';
        /** @var \OwnPay\Service\System\TranslationService $transSvc */
        $transSvc = $this->c->get(\OwnPay\Service\System\TranslationService::class);
        
        try {
            $transSvc->setDefaultLanguage($code);
            $this->audit->log('settings.language_default_changed', 'settings', null, null, ['code' => $code]);
            $this->session->flashSuccess('Default system language updated successfully');
        } catch (\Throwable $e) {
            $this->session->flashError('Failed to update default language: ' . $e->getMessage());
        }

        return Response::redirect('/admin/settings/language');
    }

    /**
     * Manually creates a new language.
     *
     * @param Request $req The incoming request context.
     * @return Response
     */
    public function createLanguage(Request $req): Response
    {
        $codeVal = $req->post('code', '');
        $nameVal = $req->post('name', '');
        $code = is_string($codeVal) ? trim($codeVal) : '';
        $name = is_string($nameVal) ? trim($nameVal) : '';

        if ($code === '' || $name === '') {
            $this->session->flashError('Language code and name are required');
            return Response::redirect('/admin/settings/language');
        }

        if (!preg_match('/^[a-z]{2,5}$/', $code)) {
            $this->session->flashError('Language code must be 2 to 5 lowercase letters');
            return Response::redirect('/admin/settings/language');
        }

        /** @var \OwnPay\Service\System\TranslationService $transSvc */
        $transSvc = $this->c->get(\OwnPay\Service\System\TranslationService::class);

        if ($transSvc->exists($code)) {
            $this->session->flashError("Language code '{$code}' already exists");
            return Response::redirect('/admin/settings/language');
        }

        try {
            $transSvc->createLanguage($code, $name);
            $this->audit->log('settings.language_created', 'settings', null, null, ['code' => $code, 'name' => $name]);
            $this->session->flashSuccess("Language '{$name}' created successfully");
        } catch (\Throwable $e) {
            $this->session->flashError('Failed to create language: ' . $e->getMessage());
        }

        return Response::redirect('/admin/settings/language');
    }

    /**
     * Uploads a JSON translations file for a language.
     *
     * @param Request $req The incoming request context.
     * @return Response
     */
    public function uploadLanguage(Request $req): Response
    {
        $codeVal = $req->post('code', '');
        $nameVal = $req->post('name', '');
        $code = is_string($codeVal) ? trim($codeVal) : '';
        $name = is_string($nameVal) ? trim($nameVal) : '';
        $file = $req->file('language_file');

        if ($code === '' || $name === '') {
            $this->session->flashError('Language code and name are required');
            return Response::redirect('/admin/settings/language');
        }

        if (!preg_match('/^[a-z]{2,5}$/', $code)) {
            $this->session->flashError('Language code must be 2 to 5 lowercase letters');
            return Response::redirect('/admin/settings/language');
        }

        if ($file === null || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $this->session->flashError('Please select a valid JSON translation file');
            return Response::redirect('/admin/settings/language');
        }

        try {
            $tmpName = $file['tmp_name'] ?? null;
            if (!is_string($tmpName) || $tmpName === '') {
                throw new \RuntimeException('Uploaded temporary file not found');
            }
            $content = file_get_contents($tmpName);
            if ($content === false) {
                throw new \RuntimeException('Failed to read uploaded file');
            }
            
            $translations = json_decode($content, true);
            if (!is_array($translations)) {
                throw new \RuntimeException('Invalid JSON structure. Ensure it is a valid JSON array or object.');
            }

            $translationsClean = [];
            foreach ($translations as $k => $v) {
                $translationsClean[(string)$k] = $v;
            }

            /** @var \OwnPay\Service\System\TranslationService $transSvc */
            $transSvc = $this->c->get(\OwnPay\Service\System\TranslationService::class);
            $transSvc->uploadLanguage($code, $name, $translationsClean);
            
            $this->audit->log('settings.language_uploaded', 'settings', null, null, ['code' => $code, 'name' => $name]);
            $this->session->flashSuccess("Language '{$name}' uploaded/updated successfully");
        } catch (\Throwable $e) {
            $this->session->flashError('Failed to upload language file: ' . $e->getMessage());
        }

        return Response::redirect('/admin/settings/language');
    }

    /**
     * Renders the inline strings translation editor page.
     *
     * @param Request $req The incoming request context.
     * @return Response
     */
    public function translateLanguage(Request $req): Response
    {
        $code = $req->param('code', '');
        /** @var \OwnPay\Service\System\TranslationService $transSvc */
        $transSvc = $this->c->get(\OwnPay\Service\System\TranslationService::class);

        if (!$transSvc->exists($code)) {
            $this->session->flashError("Language code '{$code}' not found");
            return Response::redirect('/admin/settings/language');
        }

        $allLangs = $transSvc->getAllLanguages();
        $langName = $code;
        foreach ($allLangs as $l) {
            if ($l['code'] === $code) {
                $langName = $l['name'];
                break;
            }
        }

        $translations = $transSvc->getTranslations($code);
        $enTranslations = $transSvc->getTranslations('en');

        // Merge baseline English keys to make sure any missing key is editable
        $mergedTranslations = [];
        foreach ($enTranslations as $k => $v) {
            $mergedTranslations[$k] = $translations[$k] ?? '';
        }

        return $this->renderAdminPage('admin/settings/translate.twig', [
            'code'                => $code,
            'name'                => $langName,
            'strings'             => $mergedTranslations,
            'en_strings'          => $enTranslations,
            'active_page'         => 'settings',
            'default_tab'         => 'language',
        ]);
    }

    /**
     * Saves updated translation strings.
     *
     * @param Request $req The incoming request context.
     * @return Response
     */
    public function saveTranslations(Request $req): Response
    {
        $code = $req->param('code', '');
        $postStrings = $req->post('strings');
        $strings = is_array($postStrings) ? $postStrings : [];

        /** @var \OwnPay\Service\System\TranslationService $transSvc */
        $transSvc = $this->c->get(\OwnPay\Service\System\TranslationService::class);

        if (!$transSvc->exists($code)) {
            $this->session->flashError("Language code '{$code}' not found");
            return Response::redirect('/admin/settings/language');
        }

        try {
            // Clean up and save
            $clean = [];
            foreach ($strings as $k => $v) {
                $kStr = is_string($k) ? $k : (string)$k;
                $vStr = is_string($v) ? $v : (is_scalar($v) ? (string)$v : '');
                if ($kStr !== '') {
                    $clean[$kStr] = trim($vStr);
                }
            }
            $transSvc->saveTranslations($code, $clean);
            $this->audit->log('settings.language_translated', 'settings', null, null, ['code' => $code]);
            $this->session->flashSuccess('Translations saved successfully');
        } catch (\Throwable $e) {
            $this->session->flashError('Failed to save translations: ' . $e->getMessage());
        }

        return Response::redirect("/admin/settings/language/{$code}/translate");
    }

    /**
     * Deletes a language.
     *
     * @param Request $req The incoming request context.
     * @return Response
     */
    public function deleteLanguage(Request $req): Response
    {
        $code = $req->param('code', '');

        if ($code === 'en') {
            $this->session->flashError('Cannot delete the base English language');
            return Response::redirect('/admin/settings/language');
        }

        /** @var \OwnPay\Service\System\TranslationService $transSvc */
        $transSvc = $this->c->get(\OwnPay\Service\System\TranslationService::class);

        if (!$transSvc->exists($code)) {
            $this->session->flashError("Language code '{$code}' not found");
            return Response::redirect('/admin/settings/language');
        }

        try {
            $transSvc->deleteLanguage($code);
            $this->audit->log('settings.language_deleted', 'settings', null, null, ['code' => $code]);
            $this->session->flashSuccess('Language deleted successfully');
        } catch (\Throwable $e) {
            $this->session->flashError('Failed to delete language: ' . $e->getMessage());
        }

        return Response::redirect('/admin/settings/language');
    }
}
