<?php
declare(strict_types=1);

namespace OwnPay\Service\Payment;

use OwnPay\Gateway\GatewayBridge;
use OwnPay\Repository\GatewayConfigRepository;
use OwnPay\Repository\GatewayRepository;

/**
 * Provides a public entry point for executing gateway API operations.
 *
 * Orchestrates interaction between the GatewayBridge, TransactionService,
 * FeeService, and LedgerService to execute complete transaction life cycles,
 * including payment initiation, callback verification, and double-entry ledger posting.
 */
final class GatewayApiService
{
    /**
     * @var GatewayBridge The bridge adapter manager for payment gateways.
     */
    private GatewayBridge $bridge;

    /**
     * @var GatewayRepository The gateway definition storage repository.
     */
    private GatewayRepository $gateways;

    /**
     * @var TransactionService Service layer for managing transaction models.
     */
    private TransactionService $transactions;

    /**
     * @var FeeService Service layer for fee calculations.
     */
    private FeeService $fees;

    /**
     * @var LedgerService Service layer for bookkeeping and ledger accounting.
     */
    private LedgerService $ledger;

    /**
     * GatewayApiService constructor.
     *
     * @param GatewayBridge $bridge Payment gateway adapter orchestration bridge.
     * @param GatewayRepository $gateways Repository to look up gateway metadata.
     * @param TransactionService $transactions Service managing transaction state.
     * @param FeeService $fees Service running fee rule matrices.
     * @param LedgerService $ledger Service posting ledger balances.
     */
    public function __construct(
        GatewayBridge $bridge,
        GatewayRepository $gateways,
        TransactionService $transactions,
        FeeService $fees,
        LedgerService $ledger
    ) {
        $this->bridge = $bridge;
        $this->gateways = $gateways;
        $this->transactions = $transactions;
        $this->fees = $fees;
        $this->ledger = $ledger;
    }

    /**
     * Initiates a payment process through a specified gateway.
     *
     * Calculates fees and creates a pending transaction if one is not already provided.
     * Invokes the underlying gateway's adapter, sanitizes custom redirection HTML forms,
     * and handles unexpected failures by marking transactions as failed.
     *
     * @param int $merchantId The ID of the merchant/brand.
     * @param string $gatewaySlug The identifier string of the target payment gateway.
     * @param array<string, mixed> $params The transaction parameters (amount, currency, etc.).
     * @return array{success: bool, error?: string, transaction?: array<string, mixed>, redirect_url?: string|null, form_html?: string|null} Payment outcome payload.
     */
    public function initiatePayment(int $merchantId, string $gatewaySlug, array $params): array
    {
        $gateway = $this->gateways->findBySlug($gatewaySlug);
        if ($gateway === null || $gateway['status'] !== 'active') {
            return ['success' => false, 'error' => 'Gateway not available'];
        }

        // Calculate fee
        $fee = $this->fees->calculate(
            $params['amount'],
            $params['currency'],
            $gatewaySlug,
            $merchantId
        );

        // When called from checkout, the transaction already exists — skip creation
        if (!empty($params['existing_txn'])) {
            $transaction = ['trx_id' => $params['trx_id']];
        } else {
            // Create transaction (API direct-call flow)
            $transaction = $this->transactions->create($merchantId, [
                'gateway_slug'      => $gatewaySlug,
                'amount'            => $params['amount'],
                'fee'               => $fee,
                'currency'          => $params['currency'],
                'method'            => 'api',
                'sender_account'    => $params['sender_account'] ?? null,
                'reference'         => $params['reference'] ?? null,
                'payment_intent_id' => $params['payment_intent_id'] ?? null,
                'customer_id'       => $params['customer_id'] ?? null,
                'metadata'          => isset($params['metadata']) ? json_encode($params['metadata']) : null,
            ]);
        }

        // Initiate via gateway adapter
        try {
            $result = $this->bridge->initiate($gatewaySlug, $merchantId, [
                'amount'       => $params['amount'],
                'currency'     => $params['currency'],
                'trx_id'       => $transaction['trx_id'],
                'redirect_url' => $params['redirect_url'] ?? '',
                'cancel_url'   => $params['cancel_url'] ?? '',
            ]);

            $output = [
                'success'      => true,
                'transaction'  => $transaction,
                'redirect_url' => $result['redirect_url'] ?? null,
                // Defense-in-depth — sanitize form_html even from
                // trusted gateway plugins. Strip event handlers and javascript: URIs
                // while preserving forms, inputs, and inline auto-submit scripts.
                'form_html'    => self::sanitizeFormHtml($result['form_html'] ?? null),
            ];

            return $output;

        } catch (\RuntimeException $e) {
            // Gateway adapter not registered / config error — surface to caller
            // Only mark transaction as failed if we own it (direct API flow, not checkout)
            if (isset($transaction['id'])) {
                $this->transactions->fail((int) $transaction['id'], $merchantId, $e->getMessage());
            }
            return ['success' => false, 'error' => $e->getMessage()];
        } catch (\Throwable $e) {
            if (isset($transaction['id'])) {
                $this->transactions->fail((int) $transaction['id'], $merchantId, $e->getMessage());
            }
            return ['success' => false, 'error' => 'Gateway initiation failed'];
        }
    }

    /**
     * Handles gateway callback/IPN verification and transaction completion.
     *
     * Validates callback parameters via the GatewayBridge. Resolves transaction references
     * from key callback attributes, and commits completion records along with ledger entries.
     *
     * @param int $merchantId The ID of the merchant/brand.
     * @param string $gatewaySlug The identifier string of the payment gateway.
     * @param array<string, mixed> $callbackData Incoming callback request payload parameters.
     * @return array{success: bool, error?: string, transaction?: array<string, mixed>} Verification response.
     */
    public function handleCallback(int $merchantId, string $gatewaySlug, array $callbackData): array
    {
        $verification = $this->bridge->verify($gatewaySlug, $merchantId, $callbackData);

        if (!$verification['success']) {
            return ['success' => false, 'error' => 'Verification failed'];
        }

        // Resolve transaction using multiple callback field names.
        // Different gateways use different parameter names for the merchant trx reference:
        //   SSLCommerz → tran_id, Stripe → reference, bKash → trx_id
        $trxId = '';
        $trxIdFields = ['trx_id', 'tran_id', 'order_id', 'reference', 'merchant_order_id'];
        foreach ($trxIdFields as $field) {
            if (!empty($callbackData[$field])) {
                $trxId = (string) $callbackData[$field];
                break;
            }
        }

        // Use TransactionService methods (not repo methods) so audit/event hooks fire
        $transaction = null;
        if ($trxId !== '') {
            $transaction = $this->transactions->findByTrxId($merchantId, $trxId);
        }

        // Fallback: lookup by gateway_trx_id (bank/gateway reference)
        if ($transaction === null && !empty($verification['gateway_trx_id'])) {
            $transaction = $this->transactions->findByGatewayTrxId(
                $merchantId,
                $verification['gateway_trx_id']
            );
        }

        if ($transaction !== null && in_array($transaction['status'], ['pending', 'processing', 'callback_processing'], true)) {
            // Use TransactionService::complete() — fires events + audit log
            $this->transactions->complete((int) $transaction['id'], $merchantId);

            // Record in ledger
            $this->ledger->recordPaymentReceived(
                $merchantId,
                (int) $transaction['id'],
                $transaction['amount'],
                $transaction['fee'] ?? '0.00',
                $transaction['currency']
            );

            return ['success' => true, 'transaction' => $transaction];
        }

        return ['success' => false, 'error' => 'Transaction not found or already processed'];
    }

    /**
     * Sanitize gateway form_html — defense-in-depth.
     *
     * Removes dangerous scripts and attributes while keeping basic form inputs, submit buttons,
     * and inline submission scripts (e.g. document.forms[0].submit()) intact.
     *
     * @param string|null $html Unsanitized raw redirection form HTML from gateway integrations.
     * @return string|null The cleaned form HTML string, or null/empty string as passed.
     */
    private static function sanitizeFormHtml(?string $html): ?string
    {
        if ($html === null || $html === '') {
            return $html;
        }

        // 1. Strip all event handler attributes (on*)
        $html = preg_replace('/\s+on\w+\s*=\s*["\'][^"\']*["\']/i', '', $html);
        // Also handle unquoted: onclick=alert(1)
        $html = preg_replace('/\s+on\w+\s*=\s*[^\s>]+/i', '', $html);

        // 2. Strip javascript: URIs in href/action/src/formaction attributes
        $html = preg_replace(
            '/(href|action|src|formaction)\s*=\s*["\']?\s*javascript\s*:/i',
            '$1="about:blank" data-sanitized="',
            $html
        );

        // 3. Strip <script src="..."> (external script loading) but keep inline <script>
        $html = preg_replace('/<script\s+[^>]*src\s*=\s*[^>]*>.*?<\/script>/is', '', $html);

        return $html;
    }
}
