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
 * Dashboard controller — admin home, reports, activities, profile.
 */
final class DashboardController
{
    use AdminPageTrait;

    /** Allowed fragment names — security whitelist against path traversal */
    private const ALLOWED_FRAGMENTS = [
        'recent-transactions', 'stats', 'gateway-status',
        'alerts', 'quick-actions',
    ];

    private Container $c;
    private AdminSession $session;
    private EventManager $events;
    private BrandContext $brand;
    private AuthSessionService $auth;
    private TransactionRepository $txnRepo;
    private CustomerRepository $customerRepo;
    private AuditLogRepository $auditRepo;
    private MerchantUserRepository $userRepo;

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
     * GET /admin/fragment/{page} — AJAX fragment loader.
     */
    public function fragment(Request $req): Response
    {
        $page = (string) ($req->param('page') ?? '');
        if ($page === '' || !in_array($page, self::ALLOWED_FRAGMENTS, true)) {
            return Response::html('', 404);
        }
        return $this->renderAdminPage("admin/fragments/{$page}.twig");
    }

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
        $csv = ob_get_clean();

        $filename = "report_{$from}_{$to}.csv";
        return Response::html($csv, 200, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }
}
