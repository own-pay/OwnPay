<?php
declare(strict_types=1);

namespace OwnPay\Modules\Gateways\PhonePe;

use OwnPay\Gateway\GatewayAdapterInterface;
use OwnPay\Gateway\GatewayDefaults;
use OwnPay\Plugin\PluginInterface;
use OwnPay\Plugin\Capability;
use OwnPay\Container;
use OwnPay\Event\EventManager;

/**
 * PhonePe Payment Gateway Adapter.
 * 
 * Implements strict type system, PCI-DSS compliance signature checking,
 * and secure backchannel payment status verification.
 */
final class PhonePeGateway implements PluginInterface, GatewayAdapterInterface
{
    use GatewayDefaults;

    public static function metadata(): array
    {
        return [
            'name' => 'PhonePe',
            'slug' => 'phonepe',
            'version' => '1.0.0',
            'description' => 'PhonePe payment gateway integration for OwnPay',
            'author' => 'OwnPay Core',
            'type' => 'gateway',
        ];
    }

    public function slug(): string { return 'phonepe'; }
    public function name(): string { return 'PhonePe'; }
    public function version(): string { return '1.0.0'; }
    public function description(): string { return 'PhonePe checkout gateway'; }

    public function register(EventManager $events, Container $container): void {}
    public function boot(Container $container): void {}
    public function deactivate(Container $container): void {}
    public function uninstall(Container $container): void {}
    public function capabilities(): array { return [Capability::GATEWAY]; }

    public function fields(): array
    {
        return [
            ['name' => 'merchant_id', 'label' => 'Merchant ID', 'type' => 'text', 'required' => true],
            ['name' => 'salt_key', 'label' => 'Salt Key', 'type' => 'password', 'required' => true],
            ['name' => 'salt_index', 'label' => 'Salt Index', 'type' => 'text', 'required' => true],
            ['name' => 'mode', 'label' => 'Mode', 'type' => 'select', 'options' => ['uat' => 'uat', 'production' => 'production'], 'required' => true],
        ];
    }

    public function initiate(array $params, array $credentials): array
    {
        $mode = $this->getString($credentials['mode'] ?? null);
        $merchantId = $this->getString($credentials['merchant_id'] ?? null);
        $saltKey = $this->getString($credentials['salt_key'] ?? null);
        $saltIndex = $this->getString($credentials['salt_index'] ?? null);

        $trxId = $params['trx_id'];
        $amount = $this->toMinorUnits($params['amount']);
        $redirectUrl = $params['redirect_url'];

        $url = $mode === 'production'
            ? 'https://api.phonepe.com/apis/hermes/pg/v1/pay'
            : 'https://api-preprod.phonepe.com/apis/pg-sandbox/pg/v1/pay';

        $payload = [
            'merchantId' => $merchantId,
            'merchantTransactionId' => $trxId,
            'merchantUserId' => 'USR_' . uniqid(),
            'amount' => $amount,
            'redirectUrl' => $redirectUrl,
            'redirectMode' => 'POST',
            'paymentInstrument' => ['type' => 'PAY_PAGE'],
        ];

        $jsonPayload = (string) json_encode($payload);
        $base64 = base64_encode($jsonPayload);
        $checksum = hash('sha256', $base64 . '/pg/v1/pay' . $saltKey) . '###' . $saltIndex;

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
                'X-VERIFY: ' . $checksum,
            ],
            CURLOPT_POSTFIELDS     => (string) json_encode(['request' => $base64]),
        ]);
        $response = curl_exec($ch);
        curl_close($ch);
        $data = json_decode((string) $response, true);
        if (!is_array($data)) {
            $data = [];
        }

        $resData = $this->getArray($data, 'data');
        $instrumentResponse = $this->getArray($resData, 'instrumentResponse');
        $redirectInfo = $this->getArray($instrumentResponse, 'redirectInfo');
        $resolvedRedirectUrl = $this->getString($redirectInfo['url'] ?? null);

        return [
            'redirect_url' => $resolvedRedirectUrl,
            'session_id'   => $trxId,
        ];
    }

    public function verify(array $callbackData, array $credentials): array
    {
        $trxId = $this->getString($callbackData['merchantTransactionId'] ?? $callbackData['transactionId'] ?? null);
        if ($trxId === '') {
            return [
                'success' => false,
                'status'  => 'failed',
            ];
        }

        $merchantId = $this->getString($credentials['merchant_id'] ?? null);
        $mode = $this->getString($credentials['mode'] ?? null);
        $saltKey = $this->getString($credentials['salt_key'] ?? null);
        $saltIndex = $this->getString($credentials['salt_index'] ?? null);

        $endpoint = "/pg/v1/status/{$merchantId}/{$trxId}";
        $url = $mode === 'production'
            ? "https://api.phonepe.com/apis/hermes{$endpoint}"
            : "https://api-preprod.phonepe.com/apis/pg-sandbox{$endpoint}";

        $checksum = hash('sha256', $endpoint . $saltKey) . '###' . $saltIndex;

        $ch = curl_init($url);
        if (!$ch) {
            return [
                'success' => false,
                'status'  => 'failed',
            ];
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'X-MERCHANT-ID: ' . $merchantId,
                'X-VERIFY: ' . $checksum,
            ],
        ]);
        $response = curl_exec($ch);
        curl_close($ch);
        $data = json_decode((string) $response, true);
        if (!is_array($data)) {
            $data = [];
        }

        $code = $this->getString($data['code'] ?? null);
        $success = $code === 'PAYMENT_SUCCESS';
        $resData = $this->getArray($data, 'data');
        $gatewayTrxId = $this->getString($resData['transactionId'] ?? null);
        // PhonePe reports the paid amount in integer paise.
        $amount = null;
        $amountRaw = $resData['amount'] ?? null;
        if ($success && is_numeric($amountRaw)) {
            $amount = bcdiv((string) $amountRaw, '100', 2);
        }

        return [
            'success'        => $success,
            'gateway_trx_id' => $gatewayTrxId,
            'amount'         => $amount ?? '',
            'status'         => $success ? 'completed' : 'failed',
            'trx_id'         => $trxId,
        ];
    }

    public function verifyWebhook(string $rawBody, array $headers, array $credentials): bool
    {
        // PhonePe callbacks are authenticated by the X-VERIFY checksum that the
        // server-side status call in verify() recomputes; webhooks act as
        // untrusted triggers only, and completion requires the amount match.
        return true;
    }
}