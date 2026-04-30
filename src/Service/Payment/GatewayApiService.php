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
    private GatewayConfigRepository $configs;
    private TransactionService $transactions;
    private FeeService $fees;
    private LedgerService $ledger;

    public function __construct(
        GatewayBridge $bridge,
        GatewayRepository $gateways,
        GatewayConfigRepository $configs,
        TransactionService $transactions,
        FeeService $fees,
        LedgerService $ledger
    ) {
        $this->bridge = $bridge;
        $this->gateways = $gateways;
        $this->configs = $configs;
        $this->transactions = $transactions;
        $this->fees = $fees;
        $this->ledger = $ledger;
    }

    /**
     * Initiate payment through API gateway.
     *
     * @return array{success: bool, redirect_url?: string, transaction?: array, error?: string}
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

        // Create transaction
        $transaction = $this->transactions->create($merchantId, [
            'gateway_slug'  => $gatewaySlug,
            'amount'        => $params['amount'],
            'fee'           => $fee,
            'currency'      => $params['currency'],
            'method'        => 'api',
            'sender_account' => $params['sender_account'] ?? null,
            'reference'     => $params['reference'] ?? null,
            'payment_intent_id' => $params['payment_intent_id'] ?? null,
            'customer_id'   => $params['customer_id'] ?? null,
            'metadata'      => isset($params['metadata']) ? json_encode($params['metadata']) : null,
        ]);

        // Initiate via gateway adapter
        try {
            $result = $this->bridge->initiate($gatewaySlug, $merchantId, [
                'amount'       => $params['amount'],
                'currency'     => $params['currency'],
                'trx_id'       => $transaction['trx_id'],
                'redirect_url' => $params['redirect_url'] ?? '',
                'cancel_url'   => $params['cancel_url'] ?? '',
            ]);

            return [
                'success'      => true,
                'transaction'  => $transaction,
                'redirect_url' => $result['redirect_url'] ?? null,
                'form_html'    => $result['form_html'] ?? null,
            ];

        } catch (\Throwable $e) {
            $this->transactions->fail((int) $transaction['id'], $merchantId, $e->getMessage());
            return ['success' => false, 'error' => 'Gateway initiation failed'];
        }
    }

    /**
     * Handle gateway callback/IPN.
     */
    public function handleCallback(int $merchantId, string $gatewaySlug, array $callbackData): array
    {
        $verification = $this->bridge->verify($gatewaySlug, $merchantId, $callbackData);

        if (!$verification['success']) {
            return ['success' => false, 'error' => 'Verification failed'];
        }

        // Find transaction by gateway TRX ID
        $trxId = $verification['gateway_trx_id'] ?? '';
        // Complete the transaction
        if (!empty($callbackData['trx_id'])) {
            $transaction = $this->transactions->findByTrxId($merchantId, $callbackData['trx_id']);
            if ($transaction !== null && $transaction['status'] === 'pending') {
                $this->transactions->complete((int) $transaction['id'], $merchantId);

                // Record in ledger
                $this->ledger->recordPaymentReceived(
                    $merchantId,
                    (int) $transaction['id'],
                    $transaction['amount'],
                    $transaction['fee'],
                    $transaction['currency']
                );

                return ['success' => true, 'transaction' => $transaction];
            }
        }

        return ['success' => false, 'error' => 'Transaction not found or already processed'];
    }
}
