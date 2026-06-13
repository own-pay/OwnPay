<?php
declare(strict_types=1);

namespace OwnPay\Modules\Gateways\Adyen;

use OwnPay\Gateway\GatewayAdapterInterface;
use OwnPay\Gateway\GatewayDefaults;
use OwnPay\Plugin\PluginInterface;
use OwnPay\Plugin\Capability;
use OwnPay\Container;
use OwnPay\Event\EventManager;

/**
 * Adyen Payment Gateway Adapter.
 * 
 * Implements strict type system, PCI-DSS compliance signature checking,
 * and secure backchannel payment status verification.
 */
final class AdyenGateway implements PluginInterface, GatewayAdapterInterface
{
    use GatewayDefaults;

    public static function metadata(): array
    {
        return [
            'name' => 'Adyen',
            'slug' => 'adyen',
            'version' => '1.0.0',
            'description' => 'Adyen payment gateway integration for OwnPay',
            'author' => 'OwnPay Core',
            'type' => 'gateway',
        ];
    }

    public function slug(): string { return 'adyen'; }
    public function name(): string { return 'Adyen'; }
    public function version(): string { return '1.0.0'; }
    public function description(): string { return 'Adyen checkout gateway'; }

    public function register(EventManager $events, Container $container): void {}
    public function boot(Container $container): void {}
    public function deactivate(Container $container): void {}
    public function uninstall(Container $container): void {}
    public function capabilities(): array { return [Capability::GATEWAY]; }

    public function fields(): array
    {
        return [
            ['name' => 'api_key', 'label' => 'API Key', 'type' => 'password', 'required' => true],
            ['name' => 'merchant_account', 'label' => 'Merchant Account', 'type' => 'text', 'required' => true],
            ['name' => 'client_key', 'label' => 'Client Key', 'type' => 'text', 'required' => true],
            ['name' => 'hmac_key', 'label' => 'HMAC Key', 'type' => 'password', 'required' => true],
            ['name' => 'mode', 'label' => 'Mode', 'type' => 'select', 'options' => ['test' => 'test', 'live' => 'live'], 'required' => true],
        ];
    }

    public function initiate(array $params, array $credentials): array
    {
        $apiKey = $this->getString($credentials['api_key'] ?? null);
        $mode = $this->getString($credentials['mode'] ?? null);
        $merchantAccount = $this->getString($credentials['merchant_account'] ?? null);

        $trxId = $params['trx_id'];
        $amount = $this->toMinorUnits($params['amount']);
        $currency = strtoupper($params['currency']);
        $redirectUrl = $params['redirect_url'];

        $url = $mode === 'live' 
            ? 'https://checkout-live.adyenpayments.com/checkout/v71/sessions' 
            : 'https://checkout-test.adyen.com/checkout/v71/sessions';

        $ch = curl_init($url);
        if (!$ch) {
            return [];
        }

        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_HTTPHEADER     => [
                'x-api-key: ' . $apiKey,
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS     => (string) json_encode([
                'amount' => ['value' => $amount, 'currency' => $currency],
                'reference' => $trxId,
                'merchantAccount' => $merchantAccount,
                'returnUrl' => $redirectUrl,
            ]),
        ]);
        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 201) {
            throw new \RuntimeException('Adyen session failed: HTTP ' . $httpCode);
        }
        $data = json_decode((string) $response, true);
        if (!is_array($data)) {
            $data = [];
        }
        return [
            'redirect_url' => $this->getString($data['url'] ?? null),
            'session_id'   => $this->getString($data['id'] ?? null),
        ];
    }

    public function verify(array $callbackData, array $credentials): array
    {
        // Adyen redirect returns (resultCode/pspReference query parameters) are
        // not cryptographically authenticated and must never complete payments.
        // Only HMAC-verified webhook notifications (see verifyWebhook) are
        // trusted; the core sets `_op_webhook_verified` after that check passes.
        if (($callbackData['_op_webhook_verified'] ?? false) !== true) {
            return [
                'success'        => false,
                'gateway_trx_id' => '',
                'status'         => 'unverified',
            ];
        }

        $items = $this->getArray($callbackData, 'notificationItems');
        $item = $this->getArray($this->getArray($items, 0), 'NotificationRequestItem');
        $eventCode = $this->getString($item['eventCode'] ?? null);
        $successStr = strtolower($this->getString($item['success'] ?? null));
        $success = $eventCode === 'AUTHORISATION' && $successStr === 'true';

        // Adyen reports amount.value in integer minor units.
        $amount = null;
        $amountRaw = $this->getArray($item, 'amount')['value'] ?? null;
        if ($success && is_numeric($amountRaw)) {
            $amount = bcdiv((string) $amountRaw, '100', 2);
        }

        return [
            'success'        => $success,
            'gateway_trx_id' => $this->getString($item['pspReference'] ?? null),
            'amount'         => $amount ?? '',
            'status'         => $success ? 'completed' : 'failed',
            'trx_id'         => $this->getString($item['merchantReference'] ?? null),
        ];
    }

    public function verifyWebhook(string $rawBody, array $headers, array $credentials): bool
    {
        $hmacKey = $this->getString($credentials['hmac_key'] ?? null);
        if ($hmacKey === '') {
            // Fail closed: without a configured HMAC key no webhook can be
            // authenticated, so none may be accepted.
            return false;
        }
        $data = json_decode($rawBody, true);
        if (!is_array($data)) {
            return false;
        }
        $notificationItems = $this->getArray($data, 'notificationItems');
        $firstItem = $this->getArray($notificationItems, 0);
        $item = $this->getArray($firstItem, 'NotificationRequestItem');
        if (empty($item)) {
            return false;
        }
        $amount = $this->getArray($item, 'amount');
        $pspReference = $this->getString($item['pspReference'] ?? null);
        $originalReference = $this->getString($item['originalReference'] ?? null);
        $merchantAccountCode = $this->getString($item['merchantAccountCode'] ?? null);
        $merchantReference = $this->getString($item['merchantReference'] ?? null);
        $amountValue = $this->getString($amount['value'] ?? null);
        $amountCurrency = $this->getString($amount['currency'] ?? null);
        $eventCode = $this->getString($item['eventCode'] ?? null);
        $successStr = $this->getString($item['success'] ?? null);

        $payload = implode(':', [
            $pspReference,
            $originalReference,
            $merchantAccountCode,
            $merchantReference,
            $amountValue,
            $amountCurrency,
            $eventCode,
            $successStr,
        ]);
        $additionalData = $this->getArray($item, 'additionalData');
        $expectedSig = $this->getString($additionalData['hmacSignature'] ?? null);
        $computedSig = base64_encode(hash_hmac('sha256', $payload, pack("H*", $hmacKey), true));
        return hash_equals($computedSig, $expectedSig);
    }
}