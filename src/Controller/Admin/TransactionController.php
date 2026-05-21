<?php
declare(strict_types=1);

namespace OwnPay\Controller\Admin;

use OwnPay\Container;
use OwnPay\Service\Admin\AdminSession;
use OwnPay\Http\Request;
use OwnPay\Http\Response;
use OwnPay\Repository\TransactionRepository;
use OwnPay\Repository\SmsParsedRepository;
use OwnPay\Repository\AuditLogRepository;
use OwnPay\Service\System\PaginationService;
use OwnPay\Service\System\AuditService;
use OwnPay\Event\EventManager;
use OwnPay\Support\DateHelper;

final class TransactionController
{
    use AdminPageTrait;

    private Container $c;
    private AdminSession $session;
    private TransactionRepository $txns;
    private SmsParsedRepository $smsRepo;
    private AuditLogRepository $auditRepo;
    private EventManager $events;
    private AuditService $audit;

    public function __construct(
        Container $c,
        AdminSession $session,
        TransactionRepository $txns,
        SmsParsedRepository $smsRepo,
        AuditLogRepository $auditRepo,
        EventManager $events,
        AuditService $audit
    ) {
        $this->c         = $c;
        $this->session   = $session;
        $this->txns      = $txns;
        $this->smsRepo   = $smsRepo;
        $this->auditRepo = $auditRepo;
        $this->events    = $events;
        $this->audit     = $audit;
    }

    public function index(Request $req): Response
    {
        $brand = $this->c->get(\OwnPay\Service\Brand\BrandContext::class);
        $brand->resolveFromRequest($req);
        $mid = $brand->getActiveBrandId();

        $page = max(1, (int) $req->query('page', '1'));
        $filters = [
            'q'         => $req->query('q', ''),
            'status'    => $req->query('status', ''),
            'gateway'   => $req->query('gateway', ''),
            'date_from' => $req->query('date_from', ''),
            'date_to'   => $req->query('date_to', ''),
        ];

        $repo = $this->txns->forTenant($mid);
        $total = $repo->countFiltered($filters);
        $pagination = PaginationService::calculate($page, 25, $total);
        $transactions = $repo->listFiltered($filters, $pagination['per_page'], $pagination['offset']);
        $gateways = $this->txns->getDistinctGateways($mid);

        return $this->renderAdminPage('admin/transactions/index.twig', [
            'transactions' => $transactions,
            'pagination'   => $pagination,
            'filters'      => $filters,
            'gateways'     => $gateways,
            'active_page'  => 'transactions',
        ]);
    }

    public function show(Request $req): Response
    {
        $id = (int) $req->param('id');
        $brand = $this->c->get(\OwnPay\Service\Brand\BrandContext::class);
        $brand->resolveFromRequest($req);
        $mid = $brand->getActiveBrandId();

        $txn = $this->txns->forTenant($mid)->findScoped($id);
        if ($txn === null) {
            $this->session->flashError('Transaction not found');
            return Response::redirect('/admin/transactions');
        }

        $smsData  = $this->smsRepo->listForTransaction($id);
        $auditLog = $this->auditRepo->listForEntity('transaction', $id);

        return $this->renderAdminPage('admin/transactions/edit.twig', [
            'txn'         => $txn,
            'sms_data'    => $smsData,
            'audit_log'   => $auditLog,
            'active_page' => 'transactions',
        ]);
    }

    public function updateStatus(Request $req): Response
    {
        $id = (int) $req->param('id');
        $brand = $this->c->get(\OwnPay\Service\Brand\BrandContext::class);
        $brand->resolveFromRequest($req);
        $mid = $brand->getActiveBrandId();

        $newStatus = $req->post('status', '');
        if (!in_array($newStatus, ['completed', 'canceled', 'refunded'], true)) {
            $this->session->flashError('Invalid status');
            return Response::redirect("/admin/transactions/{$id}");
        }

        $txn = $this->txns->forTenant($mid)->findScoped($id);
        if ($txn === null) {
            $this->session->flashError('Transaction not found');
            return Response::redirect('/admin/transactions');
        }

        if ($txn['status'] === $newStatus || ($newStatus === 'canceled' && $txn['status'] === 'cancelled')) {
            $this->session->flashSuccess("Transaction is already {$newStatus}");
            return Response::redirect("/admin/transactions/{$id}");
        }

        $transactionService = $this->c->get(\OwnPay\Service\Payment\TransactionService::class);
        $ledgerService = $this->c->get(\OwnPay\Service\Payment\LedgerService::class);

        $this->events->doAction('transaction.status.before', $txn, $newStatus);

        if ($newStatus === 'completed') {
            $transactionService->complete($id, $mid);
            $updatedTxn = $this->txns->forTenant($mid)->findScoped($id);
            if ($updatedTxn !== null) {
                $ledgerService->recordPaymentReceived(
                    $mid,
                    $id,
                    (string) $updatedTxn['amount'],
                    (string) ($updatedTxn['fee'] ?? '0.00'),
                    (string) $updatedTxn['currency']
                );
            }
        } elseif ($newStatus === 'refunded') {
            $this->txns->forTenant($mid)->updateScoped($id, ['status' => 'refunded', 'updated_at' => DateHelper::now()]);
            $ledgerService->recordRefund(
                $mid,
                $id,
                (string) $txn['amount'],
                (string) $txn['currency']
            );
        } elseif ($newStatus === 'canceled') {
            $transactionService->cancel($id, $mid);
        }

        $this->events->doAction('transaction.status.changed', array_merge($txn, ['status' => $newStatus]));
        $this->audit->log('transaction.status_changed', 'transaction', $id, ['status' => $txn['status']], ['status' => $newStatus]);

        $this->session->flashSuccess("Transaction marked {$newStatus}");
        return Response::redirect("/admin/transactions/{$id}");
    }
}
