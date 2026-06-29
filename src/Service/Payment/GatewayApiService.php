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
            return ['success' => false, 'error' => 'Verification failed'];
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

        $db = \OwnPay\Core\Database::getInstance();
        $transaction = null;
        $amountMismatch = false;

        try {
            $db->transaction(function () use ($db, $merchantId, $trxId, $gwTrxId, $verification, &$transaction, &$amountMismatch) {
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

                    if (in_array($transaction['status'], ['pending', 'processing', 'callback_processing'], true)) {
                        $txnId = $transaction['id'] ?? 0;
                        $amt = $transaction['amount'] ?? '0.00';
                        $feeVal = $transaction['fee'] ?? '0.00';
                        $cur = $transaction['currency'] ?? 'BDT';
                        if (is_scalar($txnId) && is_scalar($amt) && is_scalar($feeVal) && is_scalar($cur)) {
                            $this->transactions->complete((int) $txnId, $merchantId);

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
