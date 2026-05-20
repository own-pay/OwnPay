<?php
declare(strict_types=1);

namespace OwnPay\Gateway;

/**
 * Gateway defaults trait — provides sensible defaults for GatewayAdapterInterface.
 *
 * API gateways override: initiate(), verify()
 * All gateways can progressively add refund(), verifyWebhook(), and supports() capabilities.
 */
/** @phpstan-ignore trait.unused */
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

    /**
     * AUD-G6: Default webhook verification — returns true (no-op).
     * Gateway plugins override this to implement HMAC/signature checks.
     */
    public function verifyWebhook(string $rawBody, array $headers, array $credentials): bool
    {
        return true;
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

    /**
     * Default: accept any currency. Gateways override for currency-specific requirements.
     * @return string[]
     */
    public function supportedCurrencies(): array
    {
        return [];
    }
}
