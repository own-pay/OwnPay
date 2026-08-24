<?php
declare(strict_types=1);

namespace OwnPay\Gateway;

use OwnPay\Event\EventManager;
use OwnPay\Repository\GatewayConfigRepository;
use OwnPay\Repository\SettingsRepository;
use OwnPay\Security\FieldEncryptor;

/**
 * GatewayBridge routes payment operations to the corresponding gateway adapter.
 *
 * Gateway adapters are dynamically registered during the system boot phase
 * via `PluginLoader::loadActive()`. This class supports initiating payments,
 * verifying callbacks, processing refunds, and validating webhook signatures.
 *
 * Hooks:
 * - Filter 'gateway.capture.before': Allows modification of parameters before passing to the adapter.
 * - Action 'gateway.capture.after': Fired after a payment is successfully initiated.
 */
final class GatewayBridge
{
    private GatewayConfigRepository $configs;
    private FieldEncryptor $encryptor;
    private EventManager $events;
    private SettingsRepository $settings;

    /**
     * @var array<string, \OwnPay\Gateway\GatewayAdapterInterface> Registered adapters list.
     */
    private array $adapters = [];

    /**
     * Initialize the GatewayBridge service.
     *
     * @param \OwnPay\Repository\GatewayConfigRepository $configs Repository for retrieving gateway configurations.
     * @param \OwnPay\Security\FieldEncryptor $encryptor Decryption service for sensitive gateway credentials.
     * @param \OwnPay\Event\EventManager $events Event manager to fire system action and filter hooks.
     * @param \OwnPay\Repository\SettingsRepository $settings Repository to access brand/system settings.
     */
    public function __construct(
        GatewayConfigRepository $configs,
        FieldEncryptor $encryptor,
        EventManager $events,
        SettingsRepository $settings
    ) {
        $this->configs = $configs;
        $this->encryptor = $encryptor;
        $this->events = $events;
        $this->settings = $settings;
    }

    /**
     * Register a gateway adapter in the bridge registry.
     *
     * @param \OwnPay\Gateway\GatewayAdapterInterface $adapter The gateway adapter instance to register.
     * @return void
     */
    public function registerAdapter(GatewayAdapterInterface $adapter): void
    {
        $this->adapters[$adapter->slug()] = $adapter;
    }

    /**
     * Unregister a gateway adapter from the bridge registry.
     *
     * Used during plugin deactivation to cleanly remove the adapter
     * so no further payments are routed through it.
     *
     * @param \OwnPay\Gateway\GatewayAdapterInterface $adapter The gateway adapter instance to unregister.
     * @return void
     */
    public function unregisterAdapter(GatewayAdapterInterface $adapter): void
    {
        unset($this->adapters[$adapter->slug()]);
    }

    /**
     * Initiate a payment capture/charge process via the specified gateway adapter.
     *
     * Decrypts credentials for the brand (merchantId) and applies hook filters before initiating.
     *
     * @param string $gatewaySlug Unique identifier of the gateway adapter (e.g., 'stripe', 'paypal').
     * @param int $merchantId The ID of the brand/merchant context.
     * @param array<string, mixed> $params Payment parameters (amount, currency, customer details, etc.).
     * @return array<string, mixed> The response from the gateway adapter (redirect URL, status, etc.).
     * @throws \RuntimeException If the adapter is not found.
     */
    public function initiate(string $gatewaySlug, int $merchantId, array $params): array
    {
        $adapter = $this->resolveAdapter($gatewaySlug);
        $credentials = $this->decryptCredentials($gatewaySlug, $merchantId);

        // Pre-capture filter
        $filteredParams = $this->events->applyFilter('gateway.capture.before', $params, $gatewaySlug, $merchantId);

        if (!is_array($filteredParams) ||
            !isset($filteredParams['amount']) || !is_string($filteredParams['amount']) ||
            !isset($filteredParams['currency']) || !is_string($filteredParams['currency']) ||
            !isset($filteredParams['trx_id']) || !is_string($filteredParams['trx_id']) ||
            !isset($filteredParams['redirect_url']) || !is_string($filteredParams['redirect_url']) ||
            !isset($filteredParams['cancel_url']) || !is_string($filteredParams['cancel_url'])) {
            throw new \RuntimeException("Invalid payment parameters structure");
        }

        $metadata = $filteredParams['metadata'] ?? null;
        if ($metadata !== null && !is_array($metadata)) {
            unset($filteredParams['metadata']);
        }

        /** @var array{amount: string, currency: string, trx_id: string, redirect_url: string, cancel_url: string, metadata?: array<string, mixed>} $paramsChecked */
        $paramsChecked = $filteredParams;

        $result = $adapter->initiate($paramsChecked, $credentials);

        $this->events->doAction('gateway.capture.after', $gatewaySlug, $result, $paramsChecked);

        return $result;
    }

    /**
     * Verify a payment return/callback payload from the external gateway.
     *
     * @param string $gatewaySlug Unique identifier of the gateway adapter.
     * @param int $merchantId The ID of the brand/merchant context.
     * @param array<string, mixed> $callbackData Parameters received via the gateway callback request.
     * @return array<string, mixed> Verification result (status, transaction ID, error message, etc.).
     * @throws \RuntimeException If the adapter is not found.
     */
    public function verify(string $gatewaySlug, int $merchantId, array $callbackData): array
    {
        $adapter = $this->resolveAdapter($gatewaySlug);
        $credentials = $this->decryptCredentials($gatewaySlug, $merchantId);

        return $adapter->verify($callbackData, $credentials);
    }

    /**
     * Process a refund for a transaction via the specified gateway.
     *
     * @param string $gatewaySlug Unique identifier of the gateway adapter.
     * @param int $merchantId The ID of the brand/merchant context.
     * @param string $gatewayTrxId The transaction identifier assigned by the gateway.
     * @param string $amount High-precision amount string (BCMath-compatible) to refund.
     * @param string|null $sessionId The gateway's original checkout session/payment ID (e.g.
     *                               bKash's paymentID), when the caller has one on record. Some
     *                               gateways' refund APIs require this in addition to the settled
     *                               transaction id. Passed through the credentials array under
     *                               '_session_id' rather than a new interface parameter, so every
     *                               existing gateway adapter (most of which never need it) is
     *                               unaffected - adapters that do need it simply read that key.
     * @return array<string, mixed> The refund processing status and result details.
     * @throws \RuntimeException If the adapter is not found.
     */
    public function refund(string $gatewaySlug, int $merchantId, string $gatewayTrxId, string $amount, ?string $sessionId = null): array
    {
        $adapter = $this->resolveAdapter($gatewaySlug);
        $credentials = $this->decryptCredentials($gatewaySlug, $merchantId);
        if ($sessionId !== null && $sessionId !== '') {
            $credentials['_session_id'] = $sessionId;
        }

        return $adapter->refund($gatewayTrxId, $amount, $credentials);
    }

    /**
     * Reports whether the specified gateway adapter exposes a refund-status query API.
     *
     * Used by {@see \OwnPay\Cron\RefundReconciliationJob} to decide whether to
     * probe the gateway for the current status of a refund stuck in 'pending'.
     * When this returns false, the job logs a warning and skips gateway probing
     * for that adapter, leaving the existing 24-hour stale-pending auto-fail
     * backstop as the only reconciliation path.
     *
     * @param string $gatewaySlug Unique identifier of the gateway adapter.
     * @return bool True when the adapter's getRefundStatus() is implemented and meaningful.
     */
    public function supportsRefundStatus(string $gatewaySlug): bool
    {
        if (!isset($this->adapters[$gatewaySlug])) {
            return false;
        }
        return $this->adapters[$gatewaySlug]->supportsRefundStatus();
    }

    /**
     * Query the gateway for the current status of a previously-initiated refund.
     *
     * Resolves the adapter, decrypts the merchant's credentials, and delegates
     * to the adapter's getRefundStatus(). Returns null when the adapter is not
     * registered, does not support refund-status queries, or cannot determine
     * the status - in all these cases the caller (RefundReconciliationJob)
     * must treat the refund as unresolved and leave it pending for the 24-hour
     * backstop (rather than prematurely failing it and releasing the balance
     * hold on a refund that may still be in flight at the gateway).
     *
     * @param string $gatewaySlug Unique identifier of the gateway adapter.
     * @param int $merchantId The ID of the brand/merchant context.
     * @param string $gatewayRefundId The gateway-side refund identifier (or transaction ID fallback).
     * @return string|null One of 'succeeded', 'failed', 'pending', 'not_found', or null when unknown.
     */
    public function getRefundStatus(string $gatewaySlug, int $merchantId, string $gatewayRefundId): ?string
    {
        if (!isset($this->adapters[$gatewaySlug])) {
            return null;
        }
        $adapter = $this->adapters[$gatewaySlug];
        if (!$adapter->supportsRefundStatus()) {
            return null;
        }
        $credentials = $this->decryptCredentials($gatewaySlug, $merchantId);
        return $adapter->getRefundStatus($gatewayRefundId, $credentials);
    }

    /**
     * Verify the signature of an incoming webhook call from the payment provider.
     *
     * AUD-G6: Secure webhook validation using gateway credentials. If no adapter is found,
     * signature verification is skipped to allow fallback behavior.
     *
     * @param string $gatewaySlug Unique identifier of the gateway adapter.
     * @param int $merchantId The ID of the brand/merchant context.
     * @param string $rawBody Raw HTTP request body.
     * @param array<string, string> $headers HTTP request headers.
     * @return bool True if signature is valid (or adapter does not implement verification), false otherwise.
     */
    public function verifyWebhookSignature(string $gatewaySlug, int $merchantId, string $rawBody, array $headers): bool
    {
        if (!$this->hasAdapter($gatewaySlug)) {
            return false; // No adapter - signature verification fails
        }
        $adapter = $this->adapters[$gatewaySlug];
        $credentials = $this->decryptCredentials($gatewaySlug, $merchantId);
        return $adapter->verifyWebhook($rawBody, $headers, $credentials);
    }

    /**
     * Verify whether a gateway adapter is currently registered in the bridge.
     *
     * @param string $slug Unique identifier of the gateway adapter.
     * @return bool True if registered, false otherwise.
     */
    public function hasAdapter(string $slug): bool
    {
        return isset($this->adapters[$slug]);
    }

    /**
     * Fetch all registered gateway adapter slugs for diagnostic purposes.
     *
     * @return string[] Array of registered gateway slug identifiers.
     */
    public function getRegisteredSlugs(): array
    {
        return array_keys($this->adapters);
    }

    /**
     * Fetch the list of currencies supported by the specified gateway adapter.
     *
     * An empty array indicates the gateway can accept any currency.
     *
     * @param string $slug Unique identifier of the gateway adapter.
     * @return string[] Array of uppercase ISO-4217 currency codes.
     */
    public function getSupportedCurrencies(string $slug): array
    {
        if (!isset($this->adapters[$slug])) {
            return [];
        }
        return $this->adapters[$slug]->supportedCurrencies();
    }

    /**
     * Resolves the minimum chargeable amount a gateway will accept for a currency, if it
     * declares one (see MinimumAmountAwareInterface). Used by the checkout page to hide a
     * gateway for under-minimum transactions instead of offering it and letting the customer
     * hit an API error after selecting it.
     *
     * @param string $slug Unique identifier of the gateway adapter.
     * @param int $merchantId The ID of the brand/merchant context.
     * @param string $currency ISO 4217 currency code (any case).
     * @return string|null The minimum amount as a decimal string, or null if the gateway isn't
     *                      registered, doesn't declare a minimum, or credentials can't be read.
     */
    public function minimumChargeAmount(string $slug, int $merchantId, string $currency): ?string
    {
        if (!isset($this->adapters[$slug]) || !$this->adapters[$slug] instanceof MinimumAmountAwareInterface) {
            return null;
        }
        try {
            $credentials = $this->decryptCredentials($slug, $merchantId);
        } catch (\Throwable $e) {
            return null;
        }
        return $this->adapters[$slug]->minimumChargeAmount(strtolower($currency), $credentials);
    }

    /**
     * Resolve a registered gateway adapter instance.
     *
     * @param string $slug Unique identifier of the gateway adapter.
     * @return \OwnPay\Gateway\GatewayAdapterInterface The resolved adapter instance.
     * @throws \RuntimeException If the adapter has not been registered.
     */
    private function resolveAdapter(string $slug): GatewayAdapterInterface
    {
        if (!isset($this->adapters[$slug])) {
            throw new \RuntimeException("Gateway adapter not found: {$slug}");
        }
        return $this->adapters[$slug];
    }

    /**
     * Decrypt and parse gateway configuration credentials for the specified merchant.
     *
     * Decrypts DB credentials from GatewayConfigRepository. If empty, falls back to brand-scoped
     * settings via SettingsRepository.
     *
     * @param string $gatewaySlug Unique identifier of the gateway adapter.
     * @param int $merchantId The ID of the brand/merchant context.
     * @return array<string, string> Decrypted configuration key-value pairs.
     */
    private function decryptCredentials(string $gatewaySlug, int $merchantId): array
    {
        $credentialsEnc = $this->configs->forTenant($merchantId)->findCredentialsBySlug($gatewaySlug);

        if ($credentialsEnc === null || $credentialsEnc === '') {
            // Fallback to scoped system settings
            $scopedSettings = $this->settings->getGroupScoped("plugin.{$gatewaySlug}", $merchantId);
            if (!empty($scopedSettings)) {
                return $scopedSettings;
            }
            return [];
        }

        $decrypted = $this->encryptor->decrypt($credentialsEnc);
        $decoded = json_decode($decrypted, true);
        if (!is_array($decoded)) {
            return [];
        }
        $result = [];
        foreach ($decoded as $k => $v) {
            if (is_string($k) && is_scalar($v)) {
                $result[$k] = (string) $v;
            }
        }
        return $result;
    }
}
