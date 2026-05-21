<?php
declare(strict_types=1);

namespace OwnPay\Gateway;

/**
 * Trait providing sensible default implementations for the GatewayAdapterInterface contract.
 *
 * Gateway adapters can import this trait to avoid boilerplate code for features they do not support,
 * progressively overriding only the specific capabilities they need to implement.
 */
/** @phpstan-ignore trait.unused */
trait GatewayDefaults
{
    /**
     * Fallback payment initiation returning a default HTML alert message.
     *
     * @param array{amount: string, currency: string, trx_id: string, redirect_url: string, cancel_url: string, metadata?: array<string, mixed>} $params Core transaction parameters.
     * @param array<string, mixed> $credentials Decrypted, merchant-configured gateway credentials.
     * @return array{redirect_url?: string|null, form_html?: string} Default fallback payload.
     */
    public function initiate(array $params, array $credentials): array
    {
        return [
            'redirect_url' => null,
            'form_html'    => '<div class="op-alert op-alert-warning">This gateway does not support programmatic initiation.</div>',
        ];
    }

    /**
     * Fallback verification indicating failure of transaction lookups.
     *
     * @param array<string, mixed> $callbackData Payload received from the payment provider.
     * @param array<string, mixed> $credentials Decrypted, merchant-configured gateway credentials.
     * @return array{success: bool, gateway_trx_id?: string|null, amount?: string|null, status?: string} Unsuccessful verification state.
     */
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
     * Default webhook verification that accepts all payloads as authentic.
     *
     * Gateways must override this to check signatures, check SSL certificates, or execute IPNs.
     *
     * @param string $rawBody The raw HTTP request payload.
     * @param array<string, string> $headers Inbound HTTP request headers.
     * @param array<string, mixed> $credentials Decrypted, merchant-configured gateway credentials.
     * @return bool Always true by default.
     */
    public function verifyWebhook(string $rawBody, array $headers, array $credentials): bool
    {
        return true;
    }

    /**
     * Fallback refund processor indicating refunds are unsupported.
     *
     * @param string $gatewayTrxId The processor's transaction identifier.
     * @param string $amount The numeric amount to refund.
     * @param array<string, mixed> $credentials Decrypted, merchant-configured gateway credentials.
     * @return array{success: bool, refund_id?: string|null, error?: string} Failed refund status.
     */
    public function refund(string $gatewayTrxId, string $amount, array $credentials): array
    {
        return [
            'success'   => false,
            'refund_id' => null,
            'error'     => 'Refunds not supported by this gateway',
        ];
    }

    /**
     * Checks support for standard gateway capabilities, defaulting to false.
     *
     * @param string $feature Name of the capability (e.g., 'refund', 'subscription').
     * @return bool Always false.
     */
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
     * Default list of supported currencies. An empty array permits any currency.
     *
     * @return string[] Empty array.
     */
    public function supportedCurrencies(): array
    {
        return [];
    }
}

