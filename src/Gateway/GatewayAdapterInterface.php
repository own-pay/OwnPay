<?php
declare(strict_types=1);

namespace OwnPay\Gateway;

/**
 * Gateway adapter interface — every API gateway plugin implements this.
 */
interface GatewayAdapterInterface
{
    /**
     * Gateway identifier.
     */
    public function slug(): string;

    /**
     * Initialize payment — returns redirect URL or form data.
     *
     * @param array{amount: string, currency: string, trx_id: string, redirect_url: string, cancel_url: string, metadata?: array} $params
     * @return array{redirect_url?: string, form_html?: string, session_id?: string}
     */
    public function initiate(array $params, array $credentials): array;

    /**
     * Verify payment callback/webhook.
     *
     * @return array{success: bool, gateway_trx_id?: string, amount?: string, status?: string}
     */
    public function verify(array $callbackData, array $credentials): array;

    /**
     * AUD-G6: Verify webhook signature/authenticity.
     *
     * Each gateway implements its own verification:
     *   - Stripe: HMAC-SHA256 via Stripe-Signature header
     *   - PayPal: IPN verification POST back
     *   - SSLCommerz: store_passwd hash check
     *
     * Default: returns true (backward compat for gateways without webhook signing).
     *
     * @param string $rawBody   Raw request body (for HMAC computation)
     * @param array  $headers   All request headers
     * @param array  $credentials Gateway credentials
     * @return bool True if signature is valid
     */
    public function verifyWebhook(string $rawBody, array $headers, array $credentials): bool;

    /**
     * Process refund.
     *
     * @return array{success: bool, refund_id?: string, error?: string}
     */
    public function refund(string $gatewayTrxId, string $amount, array $credentials): array;

    /**
     * Check if gateway supports feature.
     */
    public function supports(string $feature): bool;
}
