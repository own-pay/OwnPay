<?php
declare(strict_types=1);

namespace OwnPay\Gateway;

/**
 * Interface for payment gateway adapters.
 *
 * Defines the contract that every gateway adapter plugin must implement to orchestrate
 * transactions, handle webhooks, process refunds, and expose capability matrices.
 */
interface GatewayAdapterInterface
{
    /**
     * Returns the unique slug identifying the gateway adapter (e.g., 'stripe', 'bkash').
     *
     * @return string The unique slug identifier.
     */
    public function slug(): string;

    /**
     * Initiates a payment process with the payment provider.
     *
     * @param array{amount: string, currency: string, trx_id: string, redirect_url: string, cancel_url: string, metadata?: array<string, mixed>} $params Core transaction parameters.
     * @param array<string, mixed> $credentials Decrypted, merchant-configured gateway credentials.
     * @return array{redirect_url?: string, form_html?: string, session_id?: string} payment response containing the redirect URL or raw HTML form.
     */
    public function initiate(array $params, array $credentials): array;

    /**
     * Verifies the authenticity and status of a payment callback or webhook.
     *
     * @param array<string, mixed> $callbackData Payload received from the payment provider.
     * @param array<string, mixed> $credentials Decrypted, merchant-configured gateway credentials.
     * @return array{success: bool, gateway_trx_id?: string, amount?: string, status?: string} Verification outcome metadata.
     */
    public function verify(array $callbackData, array $credentials): array;

    /**
     * Validates the integrity of an incoming webhook payload using signatures or callback endpoints.
     *
     * Each gateway implements its own validation routines:
     *   - Stripe: HMAC-SHA256 signature calculation using webhook signing secret.
     *   - PayPal: IPN verification loop via verification POST back.
     *   - SSLCommerz: Hash check utilizing MD5 and the configured store password.
     *
     * @param string $rawBody The raw HTTP request payload.
     * @param array<string, string> $headers Inbound HTTP request headers.
     * @param array<string, mixed> $credentials Decrypted, merchant-configured gateway credentials.
     * @return bool True if the webhook signature is authentic; false otherwise.
     */
    public function verifyWebhook(string $rawBody, array $headers, array $credentials): bool;

    /**
     * Processes a refund request against the transaction at the payment gateway.
     *
     * @param string $gatewayTrxId The processor's transaction identifier.
     * @param string $amount The numeric amount to refund.
     * @param array<string, mixed> $credentials Decrypted, merchant-configured gateway credentials.
     * @return array{success: bool, refund_id?: string, error?: string} Refund operation results.
     */
    public function refund(string $gatewayTrxId, string $amount, array $credentials): array;

    /**
     * Reports whether this adapter exposes a refund-status query API.
     *
     * Adapters that can synchronously confirm whether a previously-initiated
     * refund succeeded, failed, or was never recorded at the gateway should
     * override this to return true and implement getRefundStatus().
     *
     * Default is false so the reconciliation job knows to skip gateway probing
     * for adapters that have no such API (in which case the existing 24-hour
     * stale-pending backstop applies).
     *
     * @return bool True when getRefundStatus() is implemented and meaningful.
     */
    public function supportsRefundStatus(): bool;

    /**
     * Queries the gateway for the current status of a previously-initiated refund.
     *
     * Used by RefundReconciliationJob to reconcile refunds stuck in 'pending'
     * status - typically because PHP crashed between the gateway call and the
     * local status update. Adapters that do not support refund-status queries
     * should leave the default null-returning stub in GatewayDefaults.
     *
     * The $gatewayRefundId is the value returned in the 'refund_id' field of
     * refund() when the refund was first initiated. When that ID was never
     * persisted locally, callers may also pass the original gateway_trx_id of
     * the underlying transaction - adapters that can resolve refund status
     * from the transaction ID should accept either form; adapters that cannot
     * should return null.
     *
     * @param string $gatewayRefundId The gateway-side refund identifier (or transaction ID fallback).
     * @param array<string, mixed> $credentials Decrypted, merchant-configured gateway credentials.
     * @return string|null One of 'succeeded', 'failed', 'pending', 'not_found', or null when the
     *                     status cannot be determined (unknown / API unavailable / unsupported).
     */
    public function getRefundStatus(string $gatewayRefundId, array $credentials): ?string;

    /**
     * Checks whether the gateway adapter supports a specific capability or feature.
     *
     * @param string $feature Name of the capability (e.g., 'refund', 'subscription').
     * @return bool True if supported; false otherwise.
     */
    public function supports(string $feature): bool;

    /**
     * Returns a list of currency codes supported natively by the gateway.
     *
     * An empty array indicates that the gateway is currency-agnostic or relies on dynamic rates.
     *
     * @return string[] Array of supported ISO 4217 currency codes.
     */
    public function supportedCurrencies(): array;
}

