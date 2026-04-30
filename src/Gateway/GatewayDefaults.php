<?php
declare(strict_types=1);

namespace OwnPay\Gateway;

/**
 * Gateway defaults trait — provides sensible defaults for GatewayAdapterInterface.
 *
 * API gateways override: initiate(), verify()
 * All gateways can progressively add refund() and supports() capabilities.
 */
trait GatewayDefaults
{
    public function initiate(array $params, array $credentials): array
    {
        return [
            'redirect_url' => null,
            'form_html'    => '<div class="op-alert op-alert-warning">This gateway does not support programmatic initiation.</div>',
        ];
    }

    public function verify(array $callbackData, array $credentials): array
    {
        return [
            'success'        => false,
            'gateway_trx_id' => null,
            'amount'         => null,
            'status'         => 'unsupported',
        ];
    }

    public function refund(string $gatewayTrxId, string $amount, array $credentials): array
    {
        return [
            'success'   => false,
            'refund_id' => null,
            'error'     => 'Refunds not supported by this gateway',
        ];
    }

    public function supports(string $feature): bool
    {
        return match ($feature) {
            'refund'       => false,
            'recurring'    => false,
            'partial'      => false,
            'verification' => false,
            default        => false,
        };
    }
}
