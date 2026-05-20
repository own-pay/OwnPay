<?php
declare(strict_types=1);

namespace OwnPay\Gateway;

use OwnPay\Event\EventManager;
use OwnPay\Repository\GatewayConfigRepository;
use OwnPay\Repository\SettingsRepository;
use OwnPay\Security\FieldEncryptor;

/**
 * Gateway bridge — routes payment operations to correct adapter.
 *
 * Adapters are registered exclusively by PluginLoader::loadActive() during boot.
 * Only plugins marked active in op_plugins are loaded — no filesystem bypass.
 *
 * Fires: gateway.capture.before (filter), gateway.capture.after (action)
 */
final class GatewayBridge
{
    private GatewayConfigRepository $configs;
    private FieldEncryptor $encryptor;
    private EventManager $events;
    private SettingsRepository $settings;

    /** @var array<string, GatewayAdapterInterface> */
    private array $adapters = [];

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
     * Register gateway adapter.
     */
    public function registerAdapter(GatewayAdapterInterface $adapter): void
    {
        $this->adapters[$adapter->slug()] = $adapter;
    }

    /**
     * Initiate payment via gateway.
     */
    public function initiate(string $gatewaySlug, int $merchantId, array $params): array
    {
        $adapter = $this->resolveAdapter($gatewaySlug);
        $credentials = $this->decryptCredentials($gatewaySlug, $merchantId);

        // Pre-capture filter
        $params = $this->events->applyFilter('gateway.capture.before', $params, $gatewaySlug, $merchantId);

        $result = $adapter->initiate($params, $credentials);

        $this->events->doAction('gateway.capture.after', $gatewaySlug, $result, $params);

        return $result;
    }

    /**
     * Verify payment callback.
     */
    public function verify(string $gatewaySlug, int $merchantId, array $callbackData): array
    {
        $adapter = $this->resolveAdapter($gatewaySlug);
        $credentials = $this->decryptCredentials($gatewaySlug, $merchantId);

        return $adapter->verify($callbackData, $credentials);
    }

    /**
     * Process refund via gateway.
     */
    public function refund(string $gatewaySlug, int $merchantId, string $gatewayTrxId, string $amount): array
    {
        $adapter = $this->resolveAdapter($gatewaySlug);
        $credentials = $this->decryptCredentials($gatewaySlug, $merchantId);

        return $adapter->refund($gatewayTrxId, $amount, $credentials);
    }

    /**
     * AUD-G6: Verify webhook signature via gateway adapter.
     *
     * @return bool True if signature valid (or adapter has no verification)
     */
    public function verifyWebhookSignature(string $gatewaySlug, int $merchantId, string $rawBody, array $headers): bool
    {
        if (!$this->hasAdapter($gatewaySlug)) {
            return true; // No adapter — can't verify, allow through
        }
        $adapter = $this->adapters[$gatewaySlug];
        $credentials = $this->decryptCredentials($gatewaySlug, $merchantId);
        return $adapter->verifyWebhook($rawBody, $headers, $credentials);
    }

    /**
     * Check if an adapter is registered for a gateway slug.
     */
    public function hasAdapter(string $slug): bool
    {
        return isset($this->adapters[$slug]);
    }

    /**
     * Get all registered adapter slugs (for diagnostics).
     *
     * @return string[]
     */
    public function getRegisteredSlugs(): array
    {
        return array_keys($this->adapters);
    }

    /**
     * Get supported currencies for a gateway.
     * Empty = any currency accepted.
     * @return string[]
     */
    public function getSupportedCurrencies(string $slug): array
    {
        if (!isset($this->adapters[$slug])) {
            return [];
        }
        return $this->adapters[$slug]->supportedCurrencies();
    }

    private function resolveAdapter(string $slug): GatewayAdapterInterface
    {
        if (!isset($this->adapters[$slug])) {
            throw new \RuntimeException("Gateway adapter not found: {$slug}");
        }
        return $this->adapters[$slug];
    }

    /**
     * Decrypt gateway credentials from DB.
     * @return array<string, string>
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
        return json_decode($decrypted, true) ?: [];
    }
}
