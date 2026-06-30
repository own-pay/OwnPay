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
        MerchantUserRepository $userRepo
    ) {
        $this->c            = $c;
        $this->session      = $session;
        $this->events       = $events;
        $this->brand        = $brand;
        $this->auth         = $auth;
        $this->txnRepo      = $txnRepo;
        $this->customerRepo = $customerRepo;
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

        // Decrypt customer details for rendering
        $enc = $this->c->get(\OwnPay\Security\FieldEncryptor::class);
        if (!$enc instanceof \OwnPay\Security\FieldEncryptor) {
            throw new \RuntimeException('FieldEncryptor service unavailable');
        }
        $recent = array_map(function (array $txn) use ($enc) {
            if (!empty($txn['customer_name']) && is_string($txn['customer_name'])) {
                try {
                    $txn['customer_name'] = $enc->decrypt($txn['customer_name']);
                } catch (\Throwable $e) {
                    $txn['customer_name'] = '[encrypted]';
                }
            } else {
                $txn['customer_name'] = '-';
            }
            if (!empty($txn['customer_email']) && is_string($txn['customer_email'])) {
                try {
                    $txn['customer_email'] = $enc->decrypt($txn['customer_email']);
                } catch (\Throwable $e) {
                    $txn['customer_email'] = '[encrypted]';
                }
            } else {
                $txn['customer_email'] = '-';
            }
            return $txn;
        }, $recent);

        $brandBreakdown = [];
        if ($isGlobal) {
            $brandBreakdown = $this->txnRepo->getGlobalBrandBreakdown();
        }

        $stats = $this->events->applyFilter('admin.dashboard.stats', $stats);

        $settingsRepo = $this->c->get(\OwnPay\Repository\SettingsRepository::class);
        if (!$settingsRepo instanceof \OwnPay\Repository\SettingsRepository) {
            throw new \RuntimeException('SettingsRepository service unavailable');
        }
        $onboardingCompleted = (int) $settingsRepo->get('system', 'onboarding_completed', '0');

        $currencies = [];
        $timezones = [];
        if ($onboardingCompleted === 0) {
            $currencySvc = $this->c->get(\OwnPay\Service\Payment\CurrencyService::class);
            if (!$currencySvc instanceof \OwnPay\Service\Payment\CurrencyService) {
                throw new \RuntimeException('CurrencyService unavailable');
            }
            $currencies = $currencySvc->listAll();
            // TODO: Timezone will be dynamicly show from DB not hardcoded
            $timezones = [
                'UTC' => 'UTC (GMT+00:00)',

                // Americas
                'America/New_York' => 'New York (EST/EDT - GMT-05:00)',
                'America/Chicago' => 'Chicago (CST/CDT - GMT-06:00)',
                'America/Denver' => 'Denver (MST/MDT - GMT-07:00)',
                'America/Phoenix' => 'Phoenix (MST - GMT-07:00)',
                'America/Los_Angeles' => 'Los Angeles (PST/PDT - GMT-08:00)',
                'America/Anchorage' => 'Anchorage (AKST/AKDT - GMT-09:00)',
                'Pacific/Honolulu' => 'Honolulu (HST - GMT-10:00)',
                'America/Sao_Paulo' => 'Sao Paulo (BRT - GMT-03:00)',
                'America/Argentina/Buenos_Aires' => 'Buenos Aires (ART - GMT-03:00)',
                'America/Mexico_City' => 'Mexico City (CST - GMT-06:00)',
                'America/Toronto' => 'Toronto (EST/EDT - GMT-05:00)',

                // Europe
                'Europe/London' => 'London (GMT/BST - GMT+00:00)',
                'Europe/Dublin' => 'Dublin (GMT/IST - GMT+00:00)',
                'Europe/Paris' => 'Paris (CET/CEST - GMT+01:00)',
                'Europe/Berlin' => 'Berlin (CET/CEST - GMT+01:00)',
                'Europe/Rome' => 'Rome (CET/CEST - GMT+01:00)',
                'Europe/Amsterdam' => 'Amsterdam (CET/CEST - GMT+01:00)',
                'Europe/Athens' => 'Athens (EET/EEST - GMT+02:00)',
                'Europe/Istanbul' => 'Istanbul (TRT - GMT+03:00)',
                'Europe/Moscow' => 'Moscow (MSK - GMT+03:00)',

                // Asia / Middle East
                'Asia/Dubai' => 'Dubai (GST - GMT+04:00)',
                'Asia/Karachi' => 'Karachi (PKT - GMT+05:00)',
                'Asia/Kolkata' => 'Kolkata (IST - GMT+05:30)',
                'Asia/Kathmandu' => 'Kathmandu (NPT - GMT+05:45)',
                'Asia/Dhaka' => 'Dhaka (BST - GMT+06:00)',
                'Asia/Bangkok' => 'Bangkok (ICT - GMT+07:00)',
                'Asia/Singapore' => 'Singapore (SGT - GMT+08:00)',
                'Asia/Hong_Kong' => 'Hong Kong (HKT - GMT+08:00)',
                'Asia/Shanghai' => 'Shanghai (CST - GMT+08:00)',
                'Asia/Tokyo' => 'Tokyo (JST - GMT+09:00)',
                'Asia/Seoul' => 'Seoul (KST - GMT+09:00)',
                'Asia/Jakarta' => 'Jakarta (WIB - GMT+07:00)',
                'Asia/Manila' => 'Manila (PST - GMT+08:00)',

                // Africa
                'Africa/Cairo' => 'Cairo (EET/EEST - GMT+02:00)',
                'Africa/Johannesburg' => 'Johannesburg (SAST - GMT+02:00)',
                'Africa/Lagos' => 'Lagos (WAT - GMT+01:00)',

                // Oceania
                'Australia/Perth' => 'Perth (AWST - GMT+08:00)',
                'Australia/Adelaide' => 'Adelaide (ACST/ACDT - GMT+09:30)',
                'Australia/Sydney' => 'Sydney (AEST/AEDT - GMT+10:00)',
                'Australia/Melbourne' => 'Melbourne (AEST/AEDT - GMT+10:00)',
                'Pacific/Auckland' => 'Auckland (NZST/NZDT - GMT+12:00)',
            ];
        }

        $statsArray = is_array($stats) ? $stats : [];

        // 2. Fetch and calculate trend and KPI metrics
        $db = $this->txnRepo->getDatabase();
        $merchantWhere = $isGlobal ? '' : 'AND merchant_id = :mid';
        $queryParams = $isGlobal ? [] : ['mid' => $brandId];

        // Today's Payments & Today's Volume
        $todayStats = $db->fetchOne(
            "SELECT COUNT(*) as cnt, COALESCE(SUM(amount), 0) as vol 
             FROM op_transactions 
             WHERE status = 'completed' AND DATE(created_at) = CURDATE() {$merchantWhere}",
            $queryParams
        ) ?: ['cnt' => 0, 'vol' => 0];

        $yesterdayStats = $db->fetchOne(
            "SELECT COUNT(*) as cnt, COALESCE(SUM(amount), 0) as vol 
             FROM op_transactions 
             WHERE status = 'completed' AND DATE(created_at) = DATE_SUB(CURDATE(), INTERVAL 1 DAY) {$merchantWhere}",
            $queryParams
        ) ?: ['cnt' => 0, 'vol' => 0];

        $todayCount = is_scalar($todayStats['cnt'] ?? null) ? (int)$todayStats['cnt'] : 0;
        $todayVol = is_scalar($todayStats['vol'] ?? null) ? (float)$todayStats['vol'] : 0.0;
        $yesterdayVol = is_scalar($yesterdayStats['vol'] ?? null) ? (float)$yesterdayStats['vol'] : 0.0;

        $todayTrendVal = 0.0;
        if ($yesterdayVol > 0) {
            $todayTrendVal = (($todayVol - $yesterdayVol) / $yesterdayVol) * 100;
        } elseif ($todayVol > 0) {
            $todayTrendVal = 100.0;
        }
        $todayTrendPercent = ($todayTrendVal >= 0 ? '+' : '') . number_format($todayTrendVal, 1) . '%';

        // Past 30 days vs Prev 30 days trends
        $p30Stats = $db->fetchOne(
            "SELECT COUNT(*) as cnt, COALESCE(SUM(amount), 0) as vol
             FROM op_transactions
             WHERE status = 'completed' AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) {$merchantWhere}",
            $queryParams
        );
        $prev30Stats = $db->fetchOne(
            "SELECT COUNT(*) as cnt, COALESCE(SUM(amount), 0) as vol
             FROM op_transactions
             WHERE status = 'completed' AND created_at BETWEEN DATE_SUB(NOW(), INTERVAL 60 DAY) AND DATE_SUB(NOW(), INTERVAL 30 DAY) {$merchantWhere}",
            $queryParams
        );

        $p30Count = is_scalar($p30Stats['cnt'] ?? null) ? (int)$p30Stats['cnt'] : 0;
        $p30Vol = is_scalar($p30Stats['vol'] ?? null) ? (float)$p30Stats['vol'] : 0.0;
        $prev30Count = is_scalar($prev30Stats['cnt'] ?? null) ? (int)$prev30Stats['cnt'] : 0;
        $prev30Vol = is_scalar($prev30Stats['vol'] ?? null) ? (float)$prev30Stats['vol'] : 0.0;

        $paymentsTrendVal = 0.0;
        if ($prev30Count > 0) {
            $paymentsTrendVal = (($p30Count - $prev30Count) / $prev30Count) * 100;
        } elseif ($p30Count > 0) {
            $paymentsTrendVal = 100.0;
        }
        $paymentsTrendPercent = ($paymentsTrendVal >= 0 ? '+' : '') . number_format($paymentsTrendVal, 1) . '%';

        $revenueTrendVal = 0.0;
        if ($prev30Vol > 0) {
            $revenueTrendVal = (($p30Vol - $prev30Vol) / $prev30Vol) * 100;
        } elseif ($p30Vol > 0) {
            $revenueTrendVal = 100.0;
        }
        $revenueTrendPercent = ($revenueTrendVal >= 0 ? '+' : '') . number_format($revenueTrendVal, 1) . '%';

        $c30Val = $db->fetchColumn(
            "SELECT COUNT(*) FROM op_customers WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) " . ($isGlobal ? '' : 'AND merchant_id = :mid'),
            $isGlobal ? [] : ['mid' => $brandId]
        );
        $c30Count = is_scalar($c30Val) ? (int)$c30Val : 0;

        $prevC30Val = $db->fetchColumn(
            "SELECT COUNT(*) FROM op_customers WHERE created_at BETWEEN DATE_SUB(NOW(), INTERVAL 60 DAY) AND DATE_SUB(NOW(), INTERVAL 30 DAY) " . ($isGlobal ? '' : 'AND merchant_id = :mid'),
            $isGlobal ? [] : ['mid' => $brandId]
        );
        $prevC30Count = is_scalar($prevC30Val) ? (int)$prevC30Val : 0;

        $customerTrendVal = 0.0;
        if ($prevC30Count > 0) {
            $customerTrendVal = (($c30Count - $prevC30Count) / $prevC30Count) * 100;
        } elseif ($c30Count > 0) {
            $customerTrendVal = 100.0;
        }
        $customerTrendPercent = ($customerTrendVal >= 0 ? '+' : '') . number_format($customerTrendVal, 1) . '%';

        // Monthly Revenue Target & Gauge
        $monthlyRevenueTarget = (float) $settingsRepo->get('general', 'monthly_revenue_target', '10000.00');
        if ($monthlyRevenueTarget <= 0) {
            $monthlyRevenueTarget = 10000.00;
        }

        $monthlyRevenueVolVal = $db->fetchColumn(
            "SELECT COALESCE(SUM(amount), 0) 
             FROM op_transactions 
             WHERE status = 'completed' AND created_at >= DATE_FORMAT(NOW(), '%Y-%m-01 00:00:00') {$merchantWhere}",
            $queryParams
        );
        $monthlyRevenueVol = is_scalar($monthlyRevenueVolVal) ? (float)$monthlyRevenueVolVal : 0.0;

        $gaugePercent = min(100, max(0, (int) (($monthlyRevenueVol / $monthlyRevenueTarget) * 100)));

        // Recent Payment Intents (latest 5)
        $paymentIntentsRepo = $this->c->get(\OwnPay\Repository\PaymentIntentRepository::class);
        $intentsList = [];
        if ($paymentIntentsRepo instanceof \OwnPay\Repository\PaymentIntentRepository) {
            $intentsQuery = ($isGlobal ? $paymentIntentsRepo->forAllTenants() : $paymentIntentsRepo->forTenant((int)$brandId))
                ->paginateScoped(1, 5, '1=1', [], 'created_at DESC');
            $intentsList = $intentsQuery['items'] ?? [];
        }

        // 3. Construct Chart.js datasets
        // Today chart: hourly blocks
        $todayLabels = [];
        $todayData = [];
        for ($i = 23; $i >= 0; $i -= 4) {
            $hour = (int) date('H', strtotime("-{$i} hours"));
            $todayLabels[] = sprintf('%02d:00', $hour);
            
            $hParams = $queryParams;
            $hParams['start'] = date('Y-m-d H:00:00', strtotime("-{$i} hours"));
            $hParams['end'] = date('Y-m-d H:59:59', strtotime("-{$i} hours"));
            
            $val = $db->fetchColumn(
                "SELECT COALESCE(SUM(amount), 0) FROM op_transactions 
                 WHERE status = 'completed' AND created_at BETWEEN :start AND :end {$merchantWhere}",
                $hParams
            );
            $todayData[] = is_scalar($val) ? (float)$val : 0.0;
        }
        $revenueChartToday = ['labels' => $todayLabels, 'data' => $todayData];

        // 7d chart
        $labels7d = [];
        $data7d = [];
        for ($i = 6; $i >= 0; $i--) {
            $dayStr = date('Y-m-d', strtotime("-{$i} days"));
            $labels7d[] = date('D', strtotime("-{$i} days"));
            
            $dParams = $queryParams;
            $dParams['start'] = $dayStr . ' 00:00:00';
            $dParams['end'] = $dayStr . ' 23:59:59';
            
            $val = $db->fetchColumn(
                "SELECT COALESCE(SUM(amount), 0) FROM op_transactions 
                 WHERE status = 'completed' AND created_at BETWEEN :start AND :end {$merchantWhere}",
                $dParams
            );
            $data7d[] = is_scalar($val) ? (float)$val : 0.0;
        }
        $revenueChart7d = ['labels' => $labels7d, 'data' => $data7d];

        // 30d chart
        $labels30d = [];
        $data30d = [];
        for ($i = 29; $i >= 0; $i -= 3) {
            $dayStr = date('Y-m-d', strtotime("-{$i} days"));
            $labels30d[] = date('M d', strtotime("-{$i} days"));
            
            $dParams = $queryParams;
            $dParams['start'] = date('Y-m-d 00:00:00', strtotime("-{$i} days"));
            $dParams['end'] = date('Y-m-d 23:59:59', strtotime("-{$i} days"));
            
            $val = $db->fetchColumn(
                "SELECT COALESCE(SUM(amount), 0) FROM op_transactions 
                 WHERE status = 'completed' AND created_at BETWEEN :start AND :end {$merchantWhere}",
                $dParams
            );
            $data30d[] = is_scalar($val) ? (float)$val : 0.0;
        }
        $revenueChart30d = ['labels' => $labels30d, 'data' => $data30d];

        // All (annual) chart
        $labelsAll = [];
        $dataAll = [];
        for ($i = 11; $i >= 0; $i--) {
            $monthStart = date('Y-m-01 00:00:00', strtotime("-{$i} months"));
            $monthEnd = date('Y-m-t 23:59:59', strtotime("-{$i} months"));
            $labelsAll[] = date('M', strtotime("-{$i} months"));
            
            $mParams = $queryParams;
            $mParams['start'] = $monthStart;
            $mParams['end'] = $monthEnd;
            
            $val = $db->fetchColumn(
                "SELECT COALESCE(SUM(amount), 0) FROM op_transactions 
                 WHERE status = 'completed' AND created_at BETWEEN :start AND :end {$merchantWhere}",
                $mParams
            );
            $dataAll[] = is_scalar($val) ? (float)$val : 0.0;
        }
        $revenueChartAll = ['labels' => $labelsAll, 'data' => $dataAll];

        // Construct the dashboard object expected by the new UI
        $dashboardData = [
            'total_revenue'   => is_scalar($statsArray['total_revenue'] ?? null) ? (string)$statsArray['total_revenue'] : '0.00',
            'revenue_trend'   => $revenueTrendPercent . ' vs last month',
            'completed_count' => is_scalar($statsArray['completed_count'] ?? null) ? (int)$statsArray['completed_count'] : 0,
            'pending_count'   => is_scalar($statsArray['pending_count'] ?? null) ? (int)$statsArray['pending_count'] : 0,
            'customer_count'  => is_scalar($statsArray['customer_count'] ?? null) ? (int)$statsArray['customer_count'] : 0,
            'customer_trend'  => $customerTrendPercent . ' vs last month',
            'gateway_message' => 'All Systems Operational',
            'chart_tabs'      => ['Daily', 'Weekly', 'Monthly'],
            'active_tab'      => 'Weekly',
            'payments_trend_percent' => $paymentsTrendPercent,
            'revenue_trend_percent'   => $revenueTrendPercent,
            'customer_trend_percent'  => $customerTrendPercent,
            'today_count'             => $todayCount,
            'today_trend_percent'     => $todayTrendPercent,
            'monthly_revenue'         => number_format($monthlyRevenueVol, 2),
            'gauge_target'            => number_format($monthlyRevenueTarget, 2),
            'gauge_percent'           => $gaugePercent,
            'revenue_chart_today'     => $revenueChartToday,
            'revenue_chart_7d'        => $revenueChart7d,
            'revenue_chart_30d'       => $revenueChart30d,
            'revenue_chart'           => $revenueChartAll,
            'recent_tx'       => array_map(function (array $tx) {
                $name = $tx['customer_name'];
                $email = $tx['customer_email'];
                
                $currencyVal = $tx['currency'] ?? 'USD';
                $currency = is_string($currencyVal) ? $currencyVal : 'USD';
                
                $amountVal = $tx['amount'] ?? '0.00';
                $amount = is_string($amountVal) || is_numeric($amountVal) ? (string)$amountVal : '0.00';
                
                $statusVal = $tx['status'] ?? 'pending';
                $status = is_string($statusVal) ? $statusVal : 'pending';
                
                $timeVal = $tx['created_at'] ?? 'just now';
                $time = is_string($timeVal) ? $timeVal : 'just now';

                return [
                    'initials' => ($name !== '' && $name !== '-') ? strtoupper(substr($name, 0, 2)) : 'UN',
                    'name'     => $name,
                    'email'    => $email,
                    'amount'   => $currency . ' ' . $amount,
                    'status'   => $status,
                    'description' => !empty($tx['reference']) ? $tx['reference'] : 'Payment',
                    'time'     => $time
                ];
            }, $recent)
        ];

        return $this->renderAdminPage('admin/dashboard.twig', [
            'dashboard'            => $dashboardData,
            'stats'                => $stats,
            'recent_transactions'  => $recent,
            'range'                => $range,
            'active_page'          => 'dashboard',
            'is_global_view'       => $isGlobal,
            'brand_breakdown'      => $brandBreakdown,
            'onboarding_completed' => $onboardingCompleted,
            'currencies'           => $currencies,
            'timezones'            => $timezones,
            'payment_intents'      => $intentsList,
        ]);
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
        $isGlobal = $this->brand->isGlobalView();
        $mid = $this->brand->getActiveBrandId();
        if ($mid === null && !$isGlobal) {
            throw new \RuntimeException('Active brand ID is not set.');
        }

        $fromVal = $req->query('from', DateHelper::monthStart());
        $from = is_string($fromVal) ? $fromVal : DateHelper::monthStart();
        $toVal = $req->query('to', DateHelper::today());
        $to = is_string($toVal) ? $toVal : DateHelper::today();
        $gatewayVal = $req->query('gateway', '');
        $gateway = is_string($gatewayVal) ? $gatewayVal : '';

        $report   = $this->txnRepo->getReportData($isGlobal ? null : $mid, $from, $to, $gateway !== '' ? $gateway : null);
        $gateways = $this->txnRepo->getDistinctGateways($isGlobal ? null : $mid);

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

        /** @var \OwnPay\Service\System\TranslationService $transSvc */
        $transSvc = $this->c->get(\OwnPay\Service\System\TranslationService::class);
        $languages = $transSvc->getActiveLanguages();

        return $this->renderAdminPage('admin/my-account.twig', [
            'active_page'  => 'profile',
            'profile_user' => $user,
            'languages'    => $languages,
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
        if (!is_array($data)) {
            $data = [];
        }
        $userId = $this->session->userId();

        if ($userId === null) {
            $loginSlug = $this->resolveLoginSlug();
            return Response::redirect('/' . $loginSlug);
        }

        $type = is_string($data['type'] ?? null) ? $data['type'] : '';

        if ($type === 'profile') {
            $nameVal = $data['name'] ?? '';
            $emailVal = $data['email'] ?? '';
            $langVal = $data['language'] ?? '';
            $name  = \OwnPay\Service\System\InputSanitizer::string(is_string($nameVal) ? $nameVal : '');
            $email = \OwnPay\Service\System\InputSanitizer::email(is_string($emailVal) ? $emailVal : '');
            $langCode = trim(is_string($langVal) ? $langVal : '');

            /** @var \OwnPay\Service\System\TranslationService $transSvc */
            $transSvc = $this->c->get(\OwnPay\Service\System\TranslationService::class);
            
            $language = null;
            if ($langCode !== '') {
                if ($transSvc->exists($langCode)) {
                    $language = $langCode;
                }
            }

            if ($name !== '' && $email !== '') {
                $this->userRepo->updateProfile($userId, $name, $email, $language);
                $this->session->updateProfile($name, $email);
                $this->session->flashSuccess('Profile updated successfully.');
            } else {
                $this->session->flashError('Name and email are required.');
            }
        } elseif ($type === 'password') {
            $currentVal = $data['current_password'] ?? '';
            $newVal     = $data['new_password'] ?? '';
            $confirmVal = $data['confirm_password'] ?? '';

            $current = is_string($currentVal) ? $currentVal : '';
            $new     = is_string($newVal) ? $newVal : '';
            $confirm = is_string($confirmVal) ? $confirmVal : '';

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
    /**
     * Neutralizes a CSV cell against spreadsheet formula injection.
     *
     * Values exposed to the `export.row` filter (or future free-text columns)
     * could begin with =, +, -, @, or a control character that Excel/Sheets
     * would evaluate as a formula when the export is opened. Prefixing a single
     * quote forces the cell to be treated as literal text.
     *
     * @param string $value The raw cell value.
     * @return string The formula-safe cell value.
     */
    private static function csvCell(string $value): string
    {
        if ($value !== '' && preg_match('/^[=+\-@\t\r]/', $value) === 1) {
            return "'" . $value;
        }
        return $value;
    }

    public function exportCsv(Request $req): Response
    {
        $this->brand->resolveFromRequest($req);
        $isGlobal = $this->brand->isGlobalView();
        $mid = $this->brand->getActiveBrandId();
        if ($mid === null && !$isGlobal) {
            throw new \RuntimeException('Active brand ID is not set.');
        }

        $fromVal = $req->query('from', DateHelper::monthStart());
        $from = is_string($fromVal) ? $fromVal : DateHelper::monthStart();
        $toVal = $req->query('to', DateHelper::today());
        $to = is_string($toVal) ? $toVal : DateHelper::today();
        $gatewayVal = $req->query('gateway', '');
        $gateway = is_string($gatewayVal) ? $gatewayVal : '';

        $rows = $this->txnRepo->getExportData($isGlobal ? null : $mid, $from, $to, $gateway !== '' ? $gateway : null);

        $rows = array_map(
            function ($row) {
                $filtered = $this->events->applyFilter('export.row', $row);
                return is_array($filtered) ? $filtered : $row;
            },
            $rows
        );

        ob_start();
        $out = fopen('php://output', 'w');
        if (is_resource($out)) {
            fputcsv($out, ['ID', 'Gateway', 'Currency', 'Amount', 'Status', 'Date']);
            foreach ($rows as $row) {
                $idVal = $row['id'] ?? '';
                $gwVal = $row['gateway_slug'] ?? '';
                $curVal = $row['currency'] ?? '';
                $amtVal = $row['amount'] ?? '';
                $statVal = $row['status'] ?? '';
                $dateVal = $row['created_at'] ?? '';
                fputcsv($out, [
                    self::csvCell(is_scalar($idVal) ? (string)$idVal : ''),
                    self::csvCell(is_scalar($gwVal) ? (string)$gwVal : ''),
                    self::csvCell(is_scalar($curVal) ? (string)$curVal : ''),
                    self::csvCell(is_scalar($amtVal) ? (string)$amtVal : ''),
                    self::csvCell(is_scalar($statVal) ? (string)$statVal : ''),
                    self::csvCell(is_scalar($dateVal) ? (string)$dateVal : ''),
                ]);
            }
            fclose($out);
        }
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
        $siteNameVal = $data['site_name'] ?? '';
        $siteTaglineVal = $data['site_tagline'] ?? '';
        $timezoneVal = $data['timezone'] ?? '';
        $currencyVal = $data['currency'] ?? '';

        $siteName = \OwnPay\Service\System\InputSanitizer::string(is_string($siteNameVal) ? $siteNameVal : '');
        $siteTagline = \OwnPay\Service\System\InputSanitizer::string(is_string($siteTaglineVal) ? $siteTaglineVal : '');
        $timezone = \OwnPay\Service\System\InputSanitizer::string(is_string($timezoneVal) ? $timezoneVal : '');
        $currency = \OwnPay\Service\System\InputSanitizer::string(is_string($currencyVal) ? $currencyVal : '');
        
        $timerMinutesVal = $data['timer_minutes'] ?? 10;
        $timerMinutes = max(1, is_int($timerMinutesVal) || is_string($timerMinutesVal) ? (int)$timerMinutesVal : 10);
        $timerSeconds = $timerMinutes * 60;
        
        $reqPhoneVal = $data['require_customer_phone'] ?? '0';
        $requirePhone = (is_string($reqPhoneVal) && $reqPhoneVal === '1') ? '1' : '0';
        $landingEnabledVal = $data['landing_page_enabled'] ?? '0';
        $landingPageEnabled = (is_string($landingEnabledVal) && $landingEnabledVal === '1') ? '1' : '0';

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
        $brandNameVal = $data['brand_name'] ?? '';
        $brandEmailVal = $data['brand_email'] ?? '';
        $brandPhoneVal = $data['brand_phone'] ?? '';
        $brandCurrencyVal = $data['brand_currency'] ?? '';
        $brandTimezoneVal = $data['brand_timezone'] ?? '';

        $brandName = \OwnPay\Service\System\InputSanitizer::string(is_string($brandNameVal) ? $brandNameVal : '');
        $brandEmail = \OwnPay\Service\System\InputSanitizer::email(is_string($brandEmailVal) ? $brandEmailVal : '');
        $brandPhone = \OwnPay\Service\System\InputSanitizer::string(is_string($brandPhoneVal) ? $brandPhoneVal : '');
        $brandCurrency = \OwnPay\Service\System\InputSanitizer::string(is_string($brandCurrencyVal) ? $brandCurrencyVal : '');
        $brandTimezone = \OwnPay\Service\System\InputSanitizer::string(is_string($brandTimezoneVal) ? $brandTimezoneVal : '');

        if ($brandName === '' || $brandEmail === '' || $brandCurrency === '' || $brandTimezone === '') {
            return Response::json(['success' => false, 'error' => 'Brand name, email, currency, and timezone are required.']);
        }

        /** @var \OwnPay\Repository\MerchantRepository $merchantRepo */
        $merchantRepo = $this->c->get(\OwnPay\Repository\MerchantRepository::class);
        $slug = strtolower(trim((string) preg_replace('/[^A-Za-z0-9-]+/', '-', $brandName)));
        if ($slug === '') {
            $slug = 'brand';
        }
        $existing = $merchantRepo->findBySlug($slug);
        if ($existing) {
            $slug .= '-' . random_int(100, 999);
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

        $brandIdInt = (int) $brandId;
        // Auto-scope superadmin session to this new brand
        $_SESSION['active_brand_id'] = $brandIdInt;
        $_SESSION['auth_merchant_id'] = $brandIdInt;

        // Associate user if possible
        $userId = $this->session->userId();
        if ($userId !== null) {
            /** @var \OwnPay\Repository\MerchantUserRepository $userRepo */
            $userRepo = $this->c->get(\OwnPay\Repository\MerchantUserRepository::class);
            $userRepo->update((int) $userId, ['merchant_id' => $brandIdInt]);
        }

        return Response::json(['success' => true, 'brand_id' => $brandIdInt]);
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
        $providerVal = $data['provider'] ?? 'smtp';
        $fromEmailVal = $data['from_email'] ?? '';
        $fromNameVal = $data['from_name'] ?? 'OwnPay';

        $provider = \OwnPay\Service\System\InputSanitizer::string(is_string($providerVal) ? $providerVal : 'smtp');
        $fromEmail = \OwnPay\Service\System\InputSanitizer::email(is_string($fromEmailVal) ? $fromEmailVal : '');
        $fromName = \OwnPay\Service\System\InputSanitizer::string(is_string($fromNameVal) ? $fromNameVal : 'OwnPay');
        
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
            $hostVal = $data['smtp_host'] ?? '';
            $portVal = $data['smtp_port'] ?? '587';
            $userVal = $data['smtp_user'] ?? '';
            $passVal = $data['smtp_password'] ?? '';
            $encVal = $data['smtp_encryption'] ?? 'tls';

            $settings['smtp_host'] = \OwnPay\Service\System\InputSanitizer::string(is_string($hostVal) ? $hostVal : '');
            $settings['smtp_port'] = \OwnPay\Service\System\InputSanitizer::string(is_string($portVal) ? $portVal : '587');
            $settings['smtp_user'] = \OwnPay\Service\System\InputSanitizer::string(is_string($userVal) ? $userVal : '');
            $settings['smtp_password'] = \OwnPay\Service\System\InputSanitizer::string(is_string($passVal) ? $passVal : '');
            $settings['smtp_encryption'] = \OwnPay\Service\System\InputSanitizer::string(is_string($encVal) ? $encVal : 'tls');

            if ($settings['smtp_host'] === '') {
                return Response::json(['success' => false, 'error' => 'SMTP Host is required.']);
            }
        } elseif ($provider === 'mailgun') {
            $mgDomainVal = $data['mailgun_domain'] ?? '';
            $mgKeyVal = $data['mailgun_key'] ?? '';

            $settings['mailgun_domain'] = \OwnPay\Service\System\InputSanitizer::string(is_string($mgDomainVal) ? $mgDomainVal : '');
            $settings['mailgun_key'] = \OwnPay\Service\System\InputSanitizer::string(is_string($mgKeyVal) ? $mgKeyVal : '');

            if ($settings['mailgun_domain'] === '' || $settings['mailgun_key'] === '') {
                return Response::json(['success' => false, 'error' => 'Mailgun Domain and API Key are required.']);
            }
        } elseif ($provider === 'sendgrid') {
            $sgKeyVal = $data['sendgrid_key'] ?? '';
            $settings['sendgrid_key'] = \OwnPay\Service\System\InputSanitizer::string(is_string($sgKeyVal) ? $sgKeyVal : '');

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
            $pluginIdVal = $plugin['id'] ?? 0;
            $pluginId = is_int($pluginIdVal) || is_string($pluginIdVal) ? (int)$pluginIdVal : 0;
            $pluginRepo->update($pluginId, ['status' => 'active']);
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
        $brandIdVal = $data['brand_id'] ?? 0;
        $brandId = is_int($brandIdVal) || is_string($brandIdVal) ? (int)$brandIdVal : 0;
        $gatewayTypeVal = $data['gateway_type'] ?? '';
        $gatewayType = \OwnPay\Service\System\InputSanitizer::string(is_string($gatewayTypeVal) ? $gatewayTypeVal : '');

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
                $gatewayIdVal = $gateway['id'] ?? 0;
                $gwId = is_int($gatewayIdVal) || is_string($gatewayIdVal) ? (int)$gatewayIdVal : 0;
            }

            $credentials = [];
            if ($gatewayType === 'stripe') {
                $stripeKeyVal = $data['stripe_key'] ?? '';
                $stripeSecretVal = $data['stripe_secret'] ?? '';
                $stripeKey = \OwnPay\Service\System\InputSanitizer::string(is_string($stripeKeyVal) ? $stripeKeyVal : '');
                $stripeSecret = \OwnPay\Service\System\InputSanitizer::string(is_string($stripeSecretVal) ? $stripeSecretVal : '');
                if ($stripeKey === '' || $stripeSecret === '') {
                    return Response::json(['success' => false, 'error' => 'Stripe Publishable Key and Secret Key are required.']);
                }
                $credentials = [
                    'publishable_key' => $stripeKey,
                    'secret_key'      => $stripeSecret
                ];
            } else {
                $paypalClientIdVal = $data['paypal_client_id'] ?? '';
                $paypalSecretVal = $data['paypal_secret'] ?? '';
                $paypalClientId = \OwnPay\Service\System\InputSanitizer::string(is_string($paypalClientIdVal) ? $paypalClientIdVal : '');
                $paypalSecret = \OwnPay\Service\System\InputSanitizer::string(is_string($paypalSecretVal) ? $paypalSecretVal : '');
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
            $credsJson = json_encode($credentials);
            if ($credsJson === false) {
                return Response::json(['success' => false, 'error' => 'Failed to serialize credentials.']);
            }
            $encCreds = $enc->encrypt($credsJson);

            /** @var \OwnPay\Repository\GatewayConfigRepository $configRepo */
            $configRepo = $this->c->get(\OwnPay\Repository\GatewayConfigRepository::class);
            $configRepo = $configRepo->forTenant($brandId);

            $existingConfig = $configRepo->findForGateway($gwId);
            if ($existingConfig) {
                $configIdVal = $existingConfig['id'] ?? 0;
                $configId = is_int($configIdVal) || is_string($configIdVal) ? (int)$configIdVal : 0;
                $configRepo->update($configId, [
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
                $pluginIdVal = $plugin['id'] ?? 0;
                $pluginId = is_int($pluginIdVal) || is_string($pluginIdVal) ? (int)$pluginIdVal : 0;
                $pluginRepo->update($pluginId, ['status' => 'active']);
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
            $manualNameVal = $data['manual_name'] ?? '';
            $manualDetailsVal = $data['manual_details'] ?? '';
            $manualName = \OwnPay\Service\System\InputSanitizer::string(is_string($manualNameVal) ? $manualNameVal : '');
            $manualDetails = \OwnPay\Service\System\InputSanitizer::string(is_string($manualDetailsVal) ? $manualDetailsVal : '');
            
            if ($manualName === '' || $manualDetails === '') {
                return Response::json(['success' => false, 'error' => 'Manual Gateway Name and Payment details/instructions are required.']);
            }

            /** @var \OwnPay\Repository\MerchantRepository $merchantRepo */
            $merchantRepo = $this->c->get(\OwnPay\Repository\MerchantRepository::class);
            $brand = $merchantRepo->find($brandId);
            $brandCurrency = is_array($brand) && is_string($brand['default_currency'] ?? null) ? $brand['default_currency'] : 'USD';

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
            if (!$settings instanceof \OwnPay\Repository\SettingsRepository) {
                return 'login';
            }
            $slugSetting = $settings->get('landing', 'admin_login_slug', 'login');
            return is_string($slugSetting) ? $slugSetting : 'login';
        } catch (\Throwable) {
            return 'login';
        }
    }
}
