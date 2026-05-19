<?php
declare(strict_types=1);

namespace OwnPay\Gateway;

use OwnPay\Event\EventManager;
use OwnPay\Repository\GatewayConfigRepository;
use OwnPay\Security\FieldEncryptor;

/**
 * Gateway bridge — routes payment operations to correct adapter.
 *
 * Fires: gateway.capture.before (filter), gateway.capture.after (action)
 */
final class GatewayBridge
{
    private GatewayConfigRepository $configs;
    private FieldEncryptor $encryptor;
    private EventManager $events;

    /** @var array<string, GatewayAdapterInterface> */
    private array $adapters = [];

    public function __construct(
        GatewayConfigRepository $configs,
        FieldEncryptor $encryptor,
        EventManager $events
    ) {
        $this->configs = $configs;
        $this->encryptor = $encryptor;
        $this->events = $events;
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
            return [];
        }

        $decrypted = $this->encryptor->decrypt($credentialsEnc);
        return json_decode($decrypted, true) ?: [];
    }
}
