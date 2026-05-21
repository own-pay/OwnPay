<?php
declare(strict_types=1);

namespace OwnPay\Service\Payment;

use OwnPay\Gateway\GatewayBridge;
use OwnPay\Repository\GatewayConfigRepository;
use OwnPay\Repository\GatewayRepository;

/**
 * Gateway API service — public API for gateway operations.
 *
 * Orchestrates GatewayBridge + TransactionService for full payment flow.
 */
final class GatewayApiService
{
    private GatewayBridge $bridge;
    private GatewayRepository $gateways;
    private TransactionService $transactions;
    private FeeService $fees;
    private LedgerService $ledger;

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
     * Initiate payment through API gateway.
     *
     * @param int $merchantId
     * @param string $gatewaySlug
     * @param array $params
     * @return array
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
                // BUG-35 FIX: Defense-in-depth — sanitize form_html even from
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
     * Handle gateway callback/IPN.
     *
     * @param int $merchantId
     * @param string $gatewaySlug
     * @param array $callbackData
     * @return array
     */
    public function handleCallback(int $merchantId, string $gatewaySlug, array $callbackData): array
    {
        $verification = $this->bridge->verify($gatewaySlug, $merchantId, $callbackData);

        if (!$verification['success']) {
            return ['success' => false, 'error' => 'Verification failed'];
        }

        // AUD-C1 fix: Resolve transaction using multiple callback field names.
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

        // FIX: Use TransactionService methods (not repo methods) so audit/event hooks fire
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

        if ($transaction !== null && $transaction['status'] === 'pending') {
            // FIX: Use TransactionService::complete() — fires events + audit log
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
     * BUG-35 FIX: Sanitize gateway form_html — defense-in-depth.
     *
     * Gateway plugins are admin-installed and trusted, but a compromised or
     * buggy plugin could inject dangerous patterns. Strip:
     *  - Event handler attributes (onclick, onerror, onload, etc.)
     *  - javascript: URIs in href/action/src attributes
     *  - External <script src="..."> tags (but preserve inline <script> for auto-submit)
     *
     * Preserves: <form>, <input>, <button>, inline <script>document.forms[0].submit()</script>
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
