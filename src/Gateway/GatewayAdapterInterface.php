<?php

declare(strict_types=1);

namespace OwnPay\Gateway;

/**
 * Contract for all payment gateway adapters.
 *
 * Each gateway plugin (bKash, Nagad, SSLCommerz, Stripe, etc.) MUST implement
 * this interface. The interface bridges the legacy gateway pattern
 * (info/color/fields/process_payment/callback) with the new unified API.
 *
 * Gateway types:
 *   - "api"        → implements process_payment() + callback()
 *   - "automation"  → implements instructions(), supported_languages(), lang_text()
 *   - "manual"      → implements instructions(), supported_languages(), lang_text()
 */
interface GatewayAdapterInterface
{
    // ──────────────────────────────────────────────
    //  METADATA — required by ALL gateways
    // ──────────────────────────────────────────────

    /**
     * Return gateway metadata.
     *
     * @return array{
     *     title: string,
     *     logo: string,
     *     currency: string,
     *     tab: string,
     *     gateway_type: string,
     *     sender_key?: string,
     *     sender_type?: string
     * }
     */
    public function info(): array;

    /**
     * Return branding colours for the checkout UI.
     *
     * @return array{
     *     primary_color: string,
     *     text_color: string,
     *     btn_color: string,
     *     btn_text_color: string
     * }
     */
    public function color(): array;

    /**
     * Return the configuration fields shown in the admin settings panel.
     *
     * @return array<int, array{name: string, label: string, type: string, options?: array}>
     */
    public function fields(): array;

    // ──────────────────────────────────────────────
    //  PAYMENT FLOW — required by "api" gateways
    // ──────────────────────────────────────────────

    /**
     * Initiate a payment redirect / session with the gateway.
     *
     * @param  array  $data  Transaction + gateway options payload.
     * @return void          Outputs HTML / JS redirect directly.
     */
    public function process_payment(array $data = []): void;

    /**
     * Handle the callback / return URL from the gateway.
     *
     * @param  array  $data  Transaction + gateway options payload.
     * @return void          Outputs HTML / JS redirect directly.
     */
    public function callback(array $data = []): void;

    // ──────────────────────────────────────────────
    //  NEW UNIFIED API  (Phase 3+)
    //  Gateways should progressively implement these.
    //  Default trait implementations return "not supported".
    // ──────────────────────────────────────────────

    /**
     * Verify a payment status with the gateway.
     *
     * @param string $gatewayRef  Gateway reference ID
     * @param string $reference   Our internal reference
     * @return array{verified: bool, status: string, amount: ?string, gateway_txn_id: ?string, raw_response: array}
     */
    public function verify(string $gatewayRef, string $reference): array;

    /**
     * Issue a refund through the gateway.
     *
     * @param string $gatewayTxnId  Gateway's transaction ID
     * @param string $amount        Refund amount (DECIMAL string)
     * @param string $reason        Reason for refund
     * @return array{success: bool, refund_ref: ?string, raw_response: array}
     */
    public function refund(string $gatewayTxnId, string $amount, string $reason = ''): array;

    /**
     * Whether this gateway supports programmatic refunds.
     */
    public function supportsRefund(): bool;
}
