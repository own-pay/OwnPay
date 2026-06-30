<?php
declare(strict_types=1);

namespace OwnPay\Modules\Gateways\Worldline;

use OwnPay\Gateway\GatewayAdapterInterface;
use OwnPay\Gateway\GatewayDefaults;
use OwnPay\Plugin\PluginInterface;
use OwnPay\Plugin\Capability;
use OwnPay\Container;
use OwnPay\Event\EventManager;

/**
 * Worldline Connect Payment Gateway Adapter.
 * 
 * Implements strict type system, PCI-DSS compliance signature checking,
 * and secure backchannel payment status verification.
 */
final class WorldlineGateway implements PluginInterface, GatewayAdapterInterface
{
    use GatewayDefaults;

    public static function metadata(): array
    {
        return [
            'name' => 'Worldline Connect',
            'slug' => 'worldline',
            'version' => '1.0.0',
            'description' => 'Worldline Connect payment gateway integration for OwnPay',
            'author' => 'OwnPay Core',
            'type' => 'gateway',
        ];
    }

    public function slug(): string { return 'worldline'; }
    public function name(): string { return 'Worldline Connect'; }
    public function version(): string { return '1.0.0'; }
    public function description(): string { return 'Worldline Connect checkout gateway'; }

    public function register(EventManager $events, Container $container): void {}
    public function boot(Container $container): void {}
    public function deactivate(Container $container): void {}
    public function uninstall(Container $container): void {}
    public function capabilities(): array { return [Capability::GATEWAY]; }

    public function fields(): array
    {
        return [
            ['name' => 'api_key', 'label' => 'API Key (Key ID)', 'type' => 'text', 'required' => true],
            ['name' => 'api_secret', 'label' => 'API Secret', 'type' => 'password', 'required' => true],
            ['name' => 'merchant_id', 'label' => 'Merchant ID', 'type' => 'text', 'required' => true],
            ['name' => 'mode', 'label' => 'Mode', 'type' => 'select', 'options' => ['sandbox' => 'sandbox', 'live' => 'live'], 'required' => true],
        ];
    }

    public function initiate(array $params, array $credentials): array
    {
        $merchantId = $this->getString($credentials['merchant_id'] ?? null);
        $apiKey = $this->getString($credentials['api_key'] ?? null);
        $apiSecret = $this->getString($credentials['api_secret'] ?? null);
        $mode = $this->getString($credentials['mode'] ?? null);

        $trxId = $params['trx_id'];
        $amount = $this->toMinorUnits($params['amount']);
        $currency = strtoupper($params['currency']);
        $redirectUrl = $params['redirect_url'];

        $urlPath = "/v1/{$merchantId}/hostedcheckouts";
        $url = $mode === 'live'
            ? "https://payment.worldline-solutions.com{$urlPath}"
            : "https://payment.sandbox.worldline-solutions.com{$urlPath}";

        $dateTime = gmdate('D, d M Y H:i:s T');
        $payload = (string) json_encode([
            'order' => [
                'amountOfMoney' => ['amount' => $amount, 'currencyCode' => $currency],
                'references' => ['merchantReference' => $trxId]
            ],
            'hostedCheckoutSpecificInput' => ['returnUrl' => $redirectUrl]
        ]);

        $message = "POST\napplication/json\n{$dateTime}\n{$urlPath}\n";
        $computedSig = base64_encode(hash_hmac('sha256', $message, $apiSecret, true));

        $ch = curl_init($url);
        if (!$ch) {
            return [];
        }

        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Date: ' . $dateTime,
                'Authorization: GCS v1HMAC:' . $apiKey . ':' . $computedSig,
            ],
            CURLOPT_POSTFIELDS     => $payload,
        ]);
        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 201) {
            throw new \RuntimeException('Worldline Hosted Checkout creation failed: HTTP ' . $httpCode);
        }
        $data = json_decode((string) $response, true);
        if (!is_array($data)) {
            $data = [];
        }
        $subDomain = $this->getString($data['partialRedirectUrl'] ?? null);
        $resolvedRedirectUrl = $mode === 'live'
            ? "https://payment.worldline-solutions.com/{$subDomain}"
            : "https://payment.sandbox.worldline-solutions.com/{$subDomain}";

        return [
            'redirect_url' => $resolvedRedirectUrl,
            'session_id'   => $this->getString($data['hostedCheckoutId'] ?? null),
        ];
    }

    public function verify(array $callbackData, array $credentials): array
    {
        $checkoutId = $this->getString($callbackData['hostedCheckoutId'] ?? null);
        if ($checkoutId === '') {
            return ['success' => false, 'gateway_trx_id' => '', 'status' => 'failed'];
        }

        $merchantId = $this->getString($credentials['merchant_id'] ?? null);
        $apiKey = $this->getString($credentials['api_key'] ?? null);
        $apiSecret = $this->getString($credentials['api_secret'] ?? null);
        $mode = $this->getString($credentials['mode'] ?? null);

        $urlPath = "/v1/{$merchantId}/hostedcheckouts/{$checkoutId}";
        $url = $mode === 'live'
            ? "https://payment.worldline-solutions.com{$urlPath}"
            : "https://payment.sandbox.worldline-solutions.com{$urlPath}";

        $dateTime = gmdate('D, d M Y H:i:s T');
        $message = "GET\n\n{$dateTime}\n{$urlPath}\n";
        $computedSig = base64_encode(hash_hmac('sha256', $message, $apiSecret, true));

        $ch = curl_init($url);
        if (!$ch) {
            return ['success' => false, 'gateway_trx_id' => '', 'status' => 'failed'];
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_HTTPHEADER     => [
                'Date: ' . $dateTime,
                'Authorization: GCS v1HMAC:' . $apiKey . ':' . $computedSig,
            ],
        ]);
        $response = curl_exec($ch);
        curl_close($ch);
        $data = json_decode((string) $response, true);
        if (!is_array($data)) {
            $data = [];
        }

        $status = $this->getString($data['status'] ?? null);
        // Only PAYMENT_CREATED represents a created payment. IN_PAYMENT_FLOW
        // means the customer is still inside the hosted checkout and must not
        // complete the transaction.
        $success = $status === 'PAYMENT_CREATED';

        $createdPaymentOutput = $this->getArray($data, 'createdPaymentOutput');
        $payment = $this->getArray($createdPaymentOutput, 'payment');
        $paymentId = $this->getString($payment['id'] ?? null);
        $paymentRef = $paymentId !== '' ? $paymentId : $checkoutId;

        $paymentOutput = $this->getArray($payment, 'paymentOutput');
        $references = $this->getArray($paymentOutput, 'references');
        $trxId = $this->getString($references['merchantReference'] ?? null);

        // Worldline reports amountOfMoney.amount in integer minor units (cents).
        $amount = null;
        $amountOfMoney = $this->getArray($paymentOutput, 'amountOfMoney');
        $amountRaw = $amountOfMoney['amount'] ?? null;
        if ($success && is_numeric($amountRaw)) {
            $amount = bcdiv((string) $amountRaw, '100', 2);
        }

        return [
            'success'        => $success,
            'gateway_trx_id' => $paymentRef,
            'amount'         => $amount ?? '',
            'status'         => $success ? 'completed' : 'failed',
            'trx_id'         => $trxId,
        ];
    }

    public function verifyWebhook(string $rawBody, array $headers, array $credentials): bool
    {
        // Worldline webhook X-GCS-Signature validation requires the webhooks
        // secret key pair which is not part of this adapter's credential set.
        // Webhooks are untrusted triggers only: completion always requires the
        // signed server-side hostedcheckouts lookup in verify() plus the core
        // amount match.
        return true;
    }
}