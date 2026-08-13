<?php
declare(strict_types=1);

namespace OwnPay\Controller\Admin;

use OwnPay\Container;
use OwnPay\Enum\TransactionStatus;
use OwnPay\Service\Admin\AdminSession;
use OwnPay\Http\Request;
use OwnPay\Http\Response;
use OwnPay\Repository\TransactionRepository;
use OwnPay\Repository\SmsParsedRepository;
use OwnPay\Repository\AuditLogRepository;
use OwnPay\Service\System\PaginationService;
use OwnPay\Service\System\AuditService;
use OwnPay\Event\EventManager;

/**
 * Controller for managing payment transactions within the admin portal.
 */
final class TransactionController
{
    use AdminPageTrait;

    /**
     * The dependency injection container.
     */
    private Container $c;

    /**
     * The admin session manager.
     */
    private AdminSession $session;

    /**
     * The transaction repository.
     */
    private TransactionRepository $txns;

    /**
     * The parsed SMS repository.
     */
    private SmsParsedRepository $smsRepo;

    /**
     * The audit log repository.
     */
    private AuditLogRepository $auditRepo;

    /**
     * The event manager instance.
     */
    private EventManager $events;

    /**
     * The audit service instance.
     */
    private AuditService $audit;

    /**
     * TransactionController constructor.
     *
     * @param Container $c The dependency injection container.
     * @param AdminSession $session The admin session manager.
     * @param TransactionRepository $txns The transaction repository.
     * @param SmsParsedRepository $smsRepo The parsed SMS repository.
     * @param AuditLogRepository $auditRepo The audit log repository.
     * @param EventManager $events The event manager instance.
     * @param AuditService $audit The audit service instance.
     */
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

    /**
     * Display a paginated list of transactions filtered by the active brand and user query.
     *
     * @param Request $req The incoming HTTP request.
     * @return Response The HTTP response with the transaction index page.
     * @throws \Exception If lookup or rendering fails.
     */
    public function index(Request $req): Response
    {
        $mid = $this->resolveMerchant($req);
        $isGlobal = $this->isGlobalView();

        $pageVal = $req->query('page', '1');
        $page = max(1, is_scalar($pageVal) && is_numeric($pageVal) ? (int) $pageVal : 1);
        
        $qVal = $req->query('q', '');
        $statusVal = $req->query('status', '');
        $gatewayVal = $req->query('gateway', '');
        $dateFromVal = $req->query('date_from', '');
        $dateToVal = $req->query('date_to', '');

        $filters = [
            'q'         => is_string($qVal) ? $qVal : '',
            'status'    => is_string($statusVal) ? $statusVal : '',
            'gateway'   => is_string($gatewayVal) ? $gatewayVal : '',
            'date_from' => is_string($dateFromVal) ? $dateFromVal : '',
            'date_to'   => is_string($dateToVal) ? $dateToVal : '',
        ];

        $repo = $isGlobal ? $this->txns->forAllTenants() : $this->txns->forTenant($mid);
        $total = $repo->countFiltered($filters);
        $pagination = PaginationService::calculate($page, 25, $total);
        $transactions = $repo->listFiltered($filters, $pagination['per_page'], $pagination['offset']);

        $enc = $this->c->get(\OwnPay\Security\FieldEncryptor::class);
        if ($enc instanceof \OwnPay\Security\FieldEncryptor) {
            $transactions = array_map(function (array $txn) use ($enc) {
                if (!empty($txn['customer_name']) && is_string($txn['customer_name'])) {
                    try {
                        $txn['customer_name'] = $enc->decrypt($txn['customer_name']);
                    } catch (\Throwable $e) {
                        $txn['customer_name'] = '[encrypted]';
                    }
                } else {
                    $txn['customer_name'] = '-';
                }
                return $txn;
            }, $transactions);
        }

        $gateways = $this->txns->getDistinctGateways($isGlobal ? null : $mid);

        return $this->renderAdminPage('admin/transactions/index.twig', [
            'transactions' => $transactions,
            'pagination'   => $pagination,
            'filters'      => $filters,
            'gateways'     => $gateways,
            'active_page'  => 'transactions',
        ]);
    }

    /**
     * Show details for a specific transaction including SMS verification logs and audits.
     *
     * @param Request $req The incoming HTTP request.
     * @return Response The HTTP response with transaction details or redirect.
     * @throws \Exception If lookup or rendering fails.
     */
    public function show(Request $req): Response
    {
        $id = (int) $req->param('id');
        $isGlobal = $this->isGlobalView();
        $mid = $this->resolveMerchant($req);

        $txn = ($isGlobal ? $this->txns->forAllTenants() : $this->txns->forTenant($mid))->findScoped($id);
        if ($txn === null) {
            $this->session->flashError('Transaction not found');
            return Response::redirect('/admin/transactions');
        }
        // In global view, scope brand-specific lookups to the record's own brand.
        $recordMid = is_numeric($txn['merchant_id'] ?? null) ? (int) $txn['merchant_id'] : $mid;

        // Fetch and decrypt customer details if customer_id is present
        if (!empty($txn['customer_id'])) {
            $customerRepo = $this->c->get(\OwnPay\Repository\CustomerRepository::class);
            if ($customerRepo instanceof \OwnPay\Repository\CustomerRepository) {
                $customerIdVal = $txn['customer_id'];
                $customerId = is_numeric($customerIdVal) ? (int) $customerIdVal : 0;
                $customer = $customerRepo->forTenant($recordMid)->findScoped($customerId);
                if ($customer) {
                    $enc = $this->c->get(\OwnPay\Security\FieldEncryptor::class);
                    if ($enc instanceof \OwnPay\Security\FieldEncryptor) {
                        try {
                            $txn['customer_name']  = (!empty($customer['name_enc']) && is_string($customer['name_enc'])) ? $enc->decrypt($customer['name_enc']) : (is_string($customer['name'] ?? null) ? $customer['name'] : '-');
                            $txn['customer_email'] = (!empty($customer['email_enc']) && is_string($customer['email_enc'])) ? $enc->decrypt($customer['email_enc']) : (is_string($customer['email'] ?? null) ? $customer['email'] : '-');
                            $txn['customer_phone'] = (!empty($customer['phone_enc']) && is_string($customer['phone_enc'])) ? $enc->decrypt($customer['phone_enc']) : (is_string($customer['phone'] ?? null) ? $customer['phone'] : '-');
                        } catch (\Throwable $e) {
                            $txn['customer_name']  = '[encrypted]';
                            $txn['customer_email'] = '[encrypted]';
                            $txn['customer_phone'] = '-';
                        }
                    }
                }
            }
        }
        
        // Resolve gateway name
        $gwSlug = is_scalar($txn['gateway_slug'] ?? null) ? (string) $txn['gateway_slug'] : 'api';
        $txn['gateway_name'] = ucfirst($gwSlug);
        $gatewayRepo = $this->c->get(\OwnPay\Repository\GatewayRepository::class);
        if ($gatewayRepo instanceof \OwnPay\Repository\GatewayRepository) {
            $gw = $gatewayRepo->findBySlug($gwSlug);
            if ($gw) {
                $txn['gateway_name'] = $gw['name'] ?? $txn['gateway_name'];
            }
        }

        // Resolve customer IP address
        $txn['ip_address'] = $txn['ip_address'] ?? null;
        if (empty($txn['ip_address']) && !empty($txn['metadata'])) {
            $meta = is_string($txn['metadata']) ? json_decode($txn['metadata'], true) : $txn['metadata'];
            if (is_array($meta)) {
                $txn['ip_address'] = $meta['ip_address'] ?? null;
            }
        }

        $smsData  = $this->smsRepo->listForTransaction($id);
        // (REPO-7) Scope the audit log lookup by the record's own merchant_id
        // so the repository method's merchant_id filter is always exercised
        // from this call path. $recordMid was resolved above to the record's
        // own brand (or falls back to the active merchant in normal view).
        $auditLog = $this->auditRepo->listForEntity('transaction', $id, $recordMid);

        return $this->renderAdminPage('admin/transactions/edit.twig', [
            'txn'         => $txn,
            'sms_data'    => $smsData,
            'audit_log'   => $auditLog,
            'active_page' => 'transactions',
        ]);
    }

    /**
     * Update status of a transaction (e.g. mark completed, cancel) and post double-entry ledger lines.
     *
     * Refunds are intentionally NOT handled here. The refund flow must go through
     * `RefundController::store` → `RefundService::create`, which enforces:
     *   - SELECT ... FOR UPDATE on the transaction row
     *   - Sum of prior refunds cannot exceed the original amount
     *   - Merchant payable ledger balance is sufficient net of pending refunds
     *   - Gateway refund API is invoked
     *   - Transaction status flips to 'refunded' only when the total refunded
     *     equals the original amount (not on any partial refund)
     *
     * The previous implementation accepted `status=refunded` here and posted a
     * full-amount refund ledger entry unconditionally, bypassing every one of
     * the guards above. Among other things, this allowed over-refund (because
     * the SUM(amount) cap was never checked) and posted ledger entries even
     * when partial refunds already existed, permanently unbalancing the books.
     * See audit finding PAY-1 / issue #173 for the full impact analysis.
     *
     * @param Request $req The incoming HTTP request.
     * @return Response The HTTP redirect response.
     * @throws \Exception If transaction service operations, double-entry ledger bookkeeping, or audits fail.
     */
    public function updateStatus(Request $req): Response
    {
        $id = (int) $req->param('id');
        $isGlobal = $this->isGlobalView();
        $mid = $this->resolveMerchant($req);

        $newStatus = $req->post('status', '');
        if (!in_array($newStatus, ['completed', 'canceled'], true)) {
            $this->session->flashError('Invalid status. Refunds must be processed via the refund form.');
            return Response::redirect("/admin/transactions/{$id}");
        }

        $txn = ($isGlobal ? $this->txns->forAllTenants() : $this->txns->forTenant($mid))->findScoped($id);
        if ($txn === null) {
            $this->session->flashError('Transaction not found');
            return Response::redirect('/admin/transactions');
        }
        // In global view, perform the write against the transaction's own brand.
        if ($isGlobal) {
            $mid = is_numeric($txn['merchant_id'] ?? null) ? (int) $txn['merchant_id'] : $mid;
        }

        if ($txn['status'] === $newStatus || ($newStatus === 'canceled' && $txn['status'] === 'cancelled')) {
            $this->session->flashSuccess("Transaction is already {$newStatus}");
            return Response::redirect("/admin/transactions/{$id}");
        }

        // State machine enforcement: terminal transactions must not be
        // re-completed or cancelled, otherwise ledger entries get posted for
        // money that never moved (e.g. "completing" an already-refunded payment).
        // $newStatus is already narrowed to 'completed'|'canceled' by the
        // whitelist guard above, so only the terminal-state check remains.
        $currentStatus = isset($txn['status']) && is_scalar($txn['status']) ? (string) $txn['status'] : '';
        $terminalStatuses = array_map(static fn(TransactionStatus $s) => $s->value, TransactionStatus::terminal());

        if (in_array($currentStatus, $terminalStatuses, true)) {
            $this->session->flashError("Cannot mark a {$currentStatus} transaction as {$newStatus}");
            return Response::redirect("/admin/transactions/{$id}");
        }

        $transactionService = $this->c->get(\OwnPay\Service\Payment\TransactionService::class);
        $ledgerService = $this->c->get(\OwnPay\Service\Payment\LedgerService::class);

        if (!$transactionService instanceof \OwnPay\Service\Payment\TransactionService) {
            throw new \RuntimeException('TransactionService not found.');
        }
        if (!$ledgerService instanceof \OwnPay\Service\Payment\LedgerService) {
            throw new \RuntimeException('LedgerService not found.');
        }

        $this->events->doAction('transaction.status.before', $txn, $newStatus);

        if ($newStatus === 'completed') {
            $transactionService->complete($id, $mid);
            $updatedTxn = $this->txns->forTenant($mid)->findScoped($id);
            if ($updatedTxn !== null) {
                $amountVal = $updatedTxn['amount'] ?? '0.00';
                $feeVal = $updatedTxn['fee'] ?? '0.00';
                $currencyVal = $updatedTxn['currency'] ?? 'BDT';
                $ledgerService->recordPaymentReceived(
                    $mid,
                    $id,
                    is_string($amountVal) ? $amountVal : '0.00',
                    is_string($feeVal) ? $feeVal : '0.00',
                    is_string($currencyVal) ? $currencyVal : 'BDT'
                );
            }
        } elseif ($newStatus === 'canceled') {
            $transactionService->cancel($id, $mid);
        }

        $this->events->doAction('transaction.status.changed', array_merge($txn, ['status' => $newStatus]));
        $this->audit->log('transaction.status_changed', 'transaction', $id, ['status' => $txn['status']], ['status' => $newStatus]);

        $this->session->flashSuccess("Transaction marked {$newStatus}");
        return Response::redirect("/admin/transactions/{$id}");
    }

    /**
     * Whether the admin is in the global "All Brands" (superadmin) view.
     *
     * @return bool True when operating across all brands.
     */
    private function isGlobalView(): bool
    {
        $brand = $this->c->get(\OwnPay\Service\Brand\BrandContext::class);
        return $brand instanceof \OwnPay\Service\Brand\BrandContext && $brand->isGlobalView();
    }

    /**
     * Resolves the active merchant context ID from the request.
     *
     * @param Request $req The incoming HTTP request.
     *
     * @return int The resolved merchant ID.
     */
    private function resolveMerchant(Request $req): int
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
        return $mid;
    }
}
