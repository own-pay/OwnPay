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

        $brandBreakdown = [];
        if ($isGlobal) {
            $brandBreakdown = $this->txnRepo->getGlobalBrandBreakdown();
        }

        $stats = $this->events->applyFilter('admin.dashboard.stats', $stats);

        return $this->renderAdminPage('admin/dashboard.twig', [
            'stats'               => $stats,
            'recent_transactions' => $recent,
            'range'               => $range,
            'active_page'         => 'dashboard',
            'is_global_view'      => $isGlobal,
            'brand_breakdown'     => $brandBreakdown,
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
            return Response::redirect('/login');
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
            return Response::redirect('/login');
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
                return Response::redirect('/login');
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
}
