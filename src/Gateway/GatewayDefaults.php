<?php

declare(strict_types=1);

namespace OwnPay\Gateway;

/**
 * Provides sensible defaults for optional GatewayAdapterInterface methods.
 *
 * API gateways should override: process_payment(), callback()
 * Automation / manual gateways typically don't need process_payment or callback.
 * All gateways can progressively add verify() and refund() support.
 */
trait GatewayDefaults
{
    public function process_payment(array $data = []): void
    {
        echo '<div class="alert alert-warning">This gateway does not support programmatic payment processing.</div>';
    }

    public function callback(array $data = []): void
    {
        echo '<div class="alert alert-warning">This gateway does not support callbacks.</div>';
    }

    public function verify(string $gatewayRef, string $reference): array
    {
        return [
            'verified' => false,
            'status' => 'unsupported',
            'amount' => null,
            'gateway_txn_id' => null,
            'raw_response' => ['error' => 'verify() not implemented for this gateway'],
        ];
    }

    public function refund(string $gatewayTxnId, string $amount, string $reason = ''): array
    {
        return [
            'success' => false,
            'refund_ref' => null,
            'raw_response' => ['error' => 'refund() not implemented for this gateway'],
        ];
    }

    public function supportsRefund(): bool
    {
        return false;
    }
}
