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

        $amountVal = $params['amount'] ?? '0.00';
        $amount = is_scalar($amountVal) ? (string) $amountVal : '0.00';
        $currencyVal = $params['currency'] ?? 'BDT';
        $currency = is_scalar($currencyVal) ? (string) $currencyVal : 'BDT';

        // Calculate fee
        $fee = $this->fees->calculate(
            $amount,
            $currency,
            $gatewaySlug,
            $merchantId
        );

        // When called from checkout, the transaction already exists - skip creation
        if (!empty($params['existing_txn'])) {
            $transaction = ['trx_id' => $params['trx_id']];
        } else {
            // Create transaction (API direct-call flow)
            $transaction = $this->transactions->create($merchantId, [
                'gateway_slug'      => $gatewaySlug,
                'amount'            => $amount,
                'fee'               => $fee,
                'currency'          => $currency,
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
                'amount'       => $amount,
                'currency'     => $currency,
                'trx_id'       => $transaction['trx_id'],
                'redirect_url' => $params['redirect_url'] ?? '',
                'cancel_url'   => $params['cancel_url'] ?? '',
            ]);

            // Persist the gateway's session/payment ID (e.g. bKash's paymentID, Stripe's checkout
            // session id) so it's available later - some gateways' refund APIs require the original
            // session id in addition to the settled transaction id (bKash does).
            $sessionId = $result['session_id'] ?? null;
            if (is_scalar($sessionId) && (string) $sessionId !== '') {
                // The 'existing_txn' (checkout) flow only passes 'trx_id' in $transaction, not the
                // numeric id - resolve it so the session id still gets persisted on that path too.
                $txnIdForMeta = $transaction['id'] ?? null;
                if (!is_scalar($txnIdForMeta)) {
                    $trxIdForLookup = $transaction['trx_id'] ?? null;
                    if (is_scalar($trxIdForLookup)) {
                        $found = $this->transactions->findByTrxId($merchantId, (string) $trxIdForLookup);
                        $txnIdForMeta = $found['id'] ?? null;
                    }
                }
                if (is_scalar($txnIdForMeta)) {
                    $this->transactions->updateMetadata((int) $txnIdForMeta, $merchantId, ['gateway_session_id' => (string) $sessionId]);
                }
            }

            $output = [
                'success'      => true,
                'transaction'  => $transaction,
                'redirect_url' => is_string($result['redirect_url'] ?? null) ? $result['redirect_url'] : null,
                'form_html'    => self::sanitizeFormHtml(is_string($result['form_html'] ?? null) ? $result['form_html'] : null),
            ];

            return $output;

        } catch (\RuntimeException $e) {
            $txnId = $transaction['id'] ?? null;
            if (is_scalar($txnId)) {
                $this->transactions->fail((int) $txnId, $merchantId, $e->getMessage());
            }
            return ['success' => false, 'error' => $e->getMessage()];
        } catch (\Throwable $e) {
            $txnId = $transaction['id'] ?? null;
            if (is_scalar($txnId)) {
                $this->transactions->fail((int) $txnId, $merchantId, $e->getMessage());
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
     * @param bool $webhookVerified True only when the caller has already validated the
     *                              payload's cryptographic webhook signature for this gateway.
     * @return array{success: bool, error?: string, transaction?: array<string, mixed>} Verification response.
     */
    public function handleCallback(int $merchantId, string $gatewaySlug, array $callbackData, bool $webhookVerified = false): array
    {
        unset($callbackData['_op_webhook_verified']);
        if ($webhookVerified) {
            $callbackData['_op_webhook_verified'] = true;
        }

        $verification = $this->bridge->verify($gatewaySlug, $merchantId, $callbackData);

        if (!$verification['success']) {
            $verificationError = $verification['error'] ?? 'Verification failed';
            return [
                'success' => false,
                'error'   => is_scalar($verificationError) ? (string) $verificationError : 'Verification failed',
            ];
        }

        $trxId = '';
        $trxIdFields = ['trx_id', 'tran_id', 'order_id', 'reference', 'merchant_order_id'];
        foreach ($trxIdFields as $field) {
            $fieldVal = $callbackData[$field] ?? null;
            if (is_scalar($fieldVal) && $fieldVal !== '') {
                $trxId = (string) $fieldVal;
                break;
            }
        }

        if ($trxId === '') {
            $vTrx = $verification['trx_id'] ?? null;
            if (is_scalar($vTrx) && (string) $vTrx !== '') {
                $trxId = (string) $vTrx;
            }
        }

        $gwTrxId = $verification['gateway_trx_id'] ?? null;
        $gwTrxIdStr = is_scalar($gwTrxId) && (string) $gwTrxId !== '' ? (string) $gwTrxId : null;

        $db = \OwnPay\Core\Database::getInstance();
        $transaction = null;
        $amountMismatch = false;

        try {
            $db->transaction(function () use ($db, $merchantId, $trxId, $gwTrxId, $gwTrxIdStr, $verification, $gatewaySlug, &$transaction, &$amountMismatch) {
                if ($trxId !== '') {
                    $transaction = $db->fetchOne(
                        "SELECT * FROM op_transactions WHERE trx_id = :t AND merchant_id = :mid LIMIT 1 FOR UPDATE",
                        ['t' => $trxId, 'mid' => $merchantId]
                    );
                }

                if ($transaction === null && is_string($gwTrxId) && $gwTrxId !== '') {
                    $transaction = $db->fetchOne(
                        "SELECT * FROM op_transactions WHERE gateway_trx_id = :gtid AND merchant_id = :mid LIMIT 1 FOR UPDATE",
                        ['gtid' => $gwTrxId, 'mid' => $merchantId]
                    );
                }

                if ($transaction !== null) {
                    // Reject if the gateway's own record of this payment (session/order metadata)
                    // points to a DIFFERENT transaction than the one we're about to complete.
                    // Without this, a customer could legitimately pay for transaction A and then
                    // replay A's callback/redirect identifiers (e.g. a Stripe session_id, or any
                    // other gateway's return param) against a second, same-amount 'processing'
                    // transaction B to complete B for free off a single real payment.
                    $verifiedTrxIdVal = $verification['trx_id'] ?? null;
                    $verifiedTrxIdStr = is_scalar($verifiedTrxIdVal) ? (string) $verifiedTrxIdVal : '';
                    $transactionTrxId = is_scalar($transaction['trx_id'] ?? null) ? (string) $transaction['trx_id'] : '';
                    if ($verifiedTrxIdStr !== '' && $transactionTrxId !== '' && $verifiedTrxIdStr !== $transactionTrxId) {
                        $transaction = null;
                        return;
                    }

                    // Reject if this gateway transaction id has already completed a DIFFERENT
                    // transaction. gateway_trx_id has no DB-level uniqueness constraint, so this
                    // is the only guard against replaying one already-paid gateway reference
                    // against multiple orders when the gateway doesn't echo back trx_id metadata
                    // (the check above then has nothing to compare against).
                    if ($gwTrxIdStr !== null) {
                        $reused = $db->fetchOne(
                            "SELECT id FROM op_transactions
                             WHERE gateway_trx_id = :gtid AND merchant_id = :mid AND id != :id AND status = 'completed'
                             LIMIT 1",
                            ['gtid' => $gwTrxIdStr, 'mid' => $merchantId, 'id' => $transaction['id']]
                        );
                        if ($reused !== null) {
                            $transaction = null;
                            return;
                        }
                    }

                    $expectedVal = $transaction['amount'] ?? null;
                    $metaRaw = $transaction['metadata'] ?? null;
                    $meta = is_string($metaRaw) ? json_decode($metaRaw, true) : null;
                    if (is_array($meta)
                        && isset($meta['converted_amount']) && is_scalar($meta['converted_amount'])
                        && (string) $meta['converted_amount'] !== ''
                    ) {
                        $expectedVal = $meta['converted_amount'];
                    }
                    $verifiedAmountVal = $verification['amount'] ?? null;
                    $verifiedAmountStr = is_scalar($verifiedAmountVal) ? (string) $verifiedAmountVal : '';
                    $expectedStr = is_scalar($expectedVal) ? (string) $expectedVal : '';
                    if (!is_numeric($verifiedAmountStr) || !is_numeric($expectedStr)
                        || bccomp($verifiedAmountStr, $expectedStr, 2) !== 0
                    ) {
                        $amountMismatch = true;
                        $transaction = null;
                        return;
                    }

                    if ($this->isCompletionEligible($transaction, $gatewaySlug)) {
                        $txnId = $transaction['id'] ?? 0;
                        $amt = $transaction['amount'] ?? '0.00';
                        $feeVal = $transaction['fee'] ?? '0.00';
                        $cur = $transaction['currency'] ?? 'BDT';
                        if (is_scalar($txnId) && is_scalar($amt) && is_scalar($feeVal) && is_scalar($cur)) {
                            $this->transactions->complete((int) $txnId, $merchantId, $gwTrxIdStr);

                            // Record in ledger
                            $this->ledger->recordPaymentReceived(
                                $merchantId,
                                (int) $txnId,
                                (string) $amt,
                                (string) $feeVal,
                                (string) $cur
                            );
                        }
                    } else {
                        $transaction = null;
                    }
                }
            });
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => 'Callback processing error: ' . $e->getMessage()];
        }

        if ($amountMismatch) {
            return ['success' => false, 'error' => 'Transaction amount mismatch'];
        }

        if ($transaction !== null) {
            return ['success' => true, 'transaction' => $transaction];
        }

        return ['success' => false, 'error' => 'Transaction not found or already processed'];
    }

    /**
     * Checks whether a webhook for the given gateway is allowed to complete a referenced
     * transaction, WITHOUT completing it - a pre-dispatch guard for plugin-registered webhook
     * handlers (see UnifiedWebhookController), which call TransactionService::complete()
     * directly and have no access to the core callback pipeline's gateway-match check.
     *
     * Reuses the exact isCompletionEligible() rule the core handleCallback() path already
     * enforces: a stale/replayed webhook from a gateway the customer has since abandoned must
     * not complete the transaction under the wrong gateway's identity. Fails OPEN (returns true)
     * when no transaction can be positively identified by the given reference - this only
     * rejects a positively-identified mismatch, it never blocks a plugin handler that resolves
     * the transaction some other way (e.g. solely by gateway_trx_id after its own lookup).
     *
     * @param string $trxRef Transaction reference extracted from the webhook payload (trx_id/order_id/etc).
     * @param int $merchantId The ID of the merchant/brand the webhook was routed to.
     * @param string $gatewaySlug The gateway that sent this webhook (route-determined, not attacker-controlled).
     * @return bool True if the webhook may proceed to complete this transaction.
     */
    public function isTransactionEligibleForWebhookCompletion(string $trxRef, int $merchantId, string $gatewaySlug): bool
    {
        if ($trxRef === '') {
            return true;
        }
        $db = \OwnPay\Core\Database::getInstance();
        $transaction = $db->fetchOne(
            "SELECT status, gateway_slug FROM op_transactions
             WHERE (trx_id = :t OR gateway_trx_id = :t2) AND merchant_id = :mid LIMIT 1",
            ['t' => $trxRef, 't2' => $trxRef, 'mid' => $merchantId]
        );
        if ($transaction === null) {
            return true;
        }
        return $this->isCompletionEligible($transaction, $gatewaySlug);
    }

    /**
     * Determines whether a webhook/callback is allowed to complete the given transaction.
     *
     * `pending` transactions are always eligible (pre-existing behavior, unrelated to the guard
     * below). Once a real gateway attempt has been recorded (`processing`/`callback_processing`),
     * the callback's gateway must match the transaction's CURRENT `gateway_slug` - this prevents
     * a late/stale webhook from a gateway the customer has since abandoned (e.g. went back to
     * checkout and picked a different gateway) from completing the transaction under the wrong
     * gateway's identity.
     *
     * @param array<string, mixed> $transaction The locked transaction row.
     * @param string $gatewaySlug The gateway that sent this callback (route-determined, not attacker-controlled).
     * @return bool True if the callback may complete this transaction.
     */
    private function isCompletionEligible(array $transaction, string $gatewaySlug): bool
    {
        $status = $transaction['status'] ?? '';
        if (!in_array($status, ['pending', 'processing', 'callback_processing'], true)) {
            return false;
        }
        $storedGateway = is_string($transaction['gateway_slug'] ?? null)
            ? $transaction['gateway_slug']
            : '';
        if ($status === 'pending') {
            return $storedGateway === '' || $storedGateway === $gatewaySlug;
        }
        return $storedGateway === $gatewaySlug;
    }

    /**
     * Sanitize gateway form_html - defense-in-depth.
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

        $html = (string) preg_replace(
            '/\s+on\w+\s*=\s*["\'][^"\']*["\']/i', '', 
            $html);
        $html = (string) preg_replace(
            '/\s+on\w+\s*=\s*[^\s>]+/i', '', 
            $html);
        $html = (string) preg_replace(
            '/(href|action|src|formaction)\s*=\s*["\']?\s*javascript\s*:/i',
            '$1="about:blank" data-sanitized="',
            $html
        );

        $html = (string) preg_replace_callback('/<script\b[^>]*>(.*?)<\/script>/is', function ($matches) {
            $scriptAttrs = $matches[0];
            $scriptContent = $matches[1];

            if (preg_match('/\bsrc\s*=/i', $scriptAttrs)) {
                return '';
            }
            $cleaned = preg_replace('/\s+/', '', $scriptContent);
            $cleanedContent = trim(is_string($cleaned) ? $cleaned : '');
            $allowedPattern = '/^(?:(?:window\.onload\s*=\s*function\(\s*\)\s*\{)?\s*document\.(?:forms\[0\]|forms\[[\'"][a-zA-Z0-9_\-]+[\'"]\]|getElementById\([\'"][a-zA-Z0-9_\-]+[\'"]\))\.submit\(\s*\);?\s*\}?)$/i';
            
            if (preg_match($allowedPattern, $cleanedContent)) {
                return "<script>" . trim($scriptContent) . "</script>";
            }

            return "<script>document.forms[0].submit();</script>";
        }, $html);

        return $html;
    }
}
