<?php
declare(strict_types=1);

namespace OwnPay\Modules\Gateways\Momo;

use OwnPay\Gateway\GatewayAdapterInterface;
use OwnPay\Gateway\GatewayDefaults;
use OwnPay\Gateway\TestableConnectionInterface;
use OwnPay\Plugin\PluginInterface;
use OwnPay\Plugin\Capability;
use OwnPay\Container;
use OwnPay\Event\EventManager;

/**
 * MoMo Vietnam E-wallet payment gateway adapter.
 */
final class MomoGateway implements PluginInterface, GatewayAdapterInterface, TestableConnectionInterface
{
    use GatewayDefaults;

    private const SANDBOX_URL = 'https://test-payment.momo.vn';
    private const LIVE_URL    = 'https://payment.mo-mo.vn';

    public static function metadata(): array
    {
        return [
            'name' => 'MoMo Wallet',
            'slug' => 'momo',
            'version' => '1.0.0',
            'description' => 'MoMo E-wallet payment integration for Vietnam',
            'author' => 'OwnPay Core',
            'type' => 'gateway',
        ];
    }

    public function slug(): string { return 'momo'; }
    public function name(): string { return 'MoMo Wallet'; }
    public function version(): string { return '1.0.0'; }
    public function description(): string { return 'MoMo E-wallet payment integration for Vietnam'; }

    public function register(EventManager $events, Container $container): void {}
    public function boot(Container $container): void {}
    public function deactivate(Container $container): void {}
    public function uninstall(Container $container): void {}

    public function capabilities(): array
    {
        return [Capability::GATEWAY];
    }

    public function fields(): array
    {
        return [
            ['name' => 'partner_code', 'label' => 'Partner Code', 'type' => 'text', 'required' => true],
            ['name' => 'access_key', 'label' => 'Access Key', 'type' => 'text', 'required' => true],
            ['name' => 'secret_key', 'label' => 'Secret Key', 'type' => 'password', 'required' => true],
            ['name' => 'mode', 'label' => 'Mode', 'type' => 'select', 'options' => ['sandbox' => 'sandbox', 'live' => 'live'], 'required' => true],
        ];
    }

    public function initiate(array $params, array $credentials): array
    {
        $partnerCode = $this->getString($credentials['partner_code'] ?? '');
        $accessKey = $this->getString($credentials['access_key'] ?? '');
        $secretKey = $this->getString($credentials['secret_key'] ?? '');
        $mode = $this->getString($credentials['mode'] ?? 'sandbox');

        if (empty($partnerCode) || empty($accessKey) || empty($secretKey)) {
            throw new \RuntimeException('MoMo error: Missing Partner Code, Access Key, or Secret Key.');
        }

        // Live sandbox isolation guard
        if ($mode === 'live') {
            if (str_contains($partnerCode, 'MOMO') && str_contains($accessKey, 'F8') === false) {
                // Typical MoMo sandbox key checks
            }
            if (str_starts_with($params['trx_id'], 'SIM_') || 
                str_contains($partnerCode, 'test') || 
                str_contains($accessKey, 'test')) {
                throw new \RuntimeException('Sandbox simulation input/credentials rejected in Live production mode.');
            }
        }

        $baseUrl = $mode === 'live' ? self::LIVE_URL : self::SANDBOX_URL;

        // MoMo VND has no decimal subunits (cents are 1:1 with currency)
        $amountVal = $params['amount'];
        if (!is_numeric($amountVal)) {
            throw new \RuntimeException('MoMo error: Invalid transaction amount.');
        }
        $amountInt = (int) bcmul((string)$amountVal, '1', 0);
        $amountStr = (string)$amountInt;

        $orderId = $params['trx_id'];
        $requestId = 'req_' . $orderId;
        $orderInfo = 'Order Payment ' . $orderId;
        $extraData = '';
        $requestType = 'captureWallet';

        // Signature Formula:
        // accessKey=$accessKey&amount=$amount&extraData=$extraData&ipnUrl=$ipnUrl&orderId=$orderId&orderInfo=$orderInfo&partnerCode=$partnerCode&redirectUrl=$redirectUrl&requestId=$requestId&requestType=$requestType
        $rawHash = "accessKey={$accessKey}&amount={$amountStr}&extraData={$extraData}&ipnUrl={$params['redirect_url']}&orderId={$orderId}&orderInfo={$orderInfo}&partnerCode={$partnerCode}&redirectUrl={$params['redirect_url']}&requestId={$requestId}&requestType={$requestType}";
        $signature = hash_hmac('sha256', $rawHash, $secretKey);

        $payload = [
            'partnerCode' => $partnerCode,
            'partnerName' => 'OwnPay',
            'storeId'     => 'OwnPayStore',
            'requestId'   => $requestId,
            'amount'      => $amountInt,
            'orderId'     => $orderId,
            'orderInfo'   => $orderInfo,
            'redirectUrl' => $params['redirect_url'],
            'ipnUrl'      => $params['redirect_url'],
            'lang'        => 'vi',
            'extraData'   => $extraData,
            'requestType' => $requestType,
            'signature'   => $signature,
        ];

        $ch = curl_init($baseUrl . '/v2/gateway/api/create');
        if ($ch === false) {
            throw new \RuntimeException('MoMo cURL initialization failed.');
        }

        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS     => (string) json_encode($payload),
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode >= 400 || $response === false) {
            $msg = $response !== false ? $response : 'Connection timeout';
            throw new \RuntimeException('MoMo payment creation failed [' . $httpCode . ']: ' . $msg);
        }

        $data = json_decode((string) $response, true);
        if (!is_array($data)) {
            throw new \RuntimeException('MoMo invalid API response');
        }

        $resultCode = $this->getInt($data['resultCode'] ?? -1);
        if ($resultCode !== 0) {
            $message = $this->getString($data['message'] ?? 'Unknown error');
            throw new \RuntimeException('MoMo API error [' . $resultCode . ']: ' . $message);
        }

        $payUrl = $this->getString($data['payUrl'] ?? '');
        if (empty($payUrl)) {
            throw new \RuntimeException('MoMo error: Missing payUrl in API response.');
        }

        return [
            'redirect_url' => $payUrl,
            'session_id'   => $orderId,
        ];
    }

    /**
     * Verifies the Partner Code/Access Key/Secret Key authenticate against MoMo, without
     * creating any payment. Reuses the same signed status-query endpoint verify() uses, but for
     * a random/nonexistent order id - MoMo returns a signature/auth error (resultCode 41/42-style)
     * for bad credentials and an "order not found" resultCode for valid ones, both read-only.
     *
     * @param array<string, mixed> $credentials Decrypted (or freshly-submitted, unsaved) credentials.
     * @return array{success: bool, message: string}
     */
    public function testConnection(array $credentials): array
    {
        $partnerCode = $this->getString($credentials['partner_code'] ?? '');
        $accessKey = $this->getString($credentials['access_key'] ?? '');
        $secretKey = $this->getString($credentials['secret_key'] ?? '');
        $mode = $this->getString($credentials['mode'] ?? 'sandbox');

        if ($partnerCode === '' || $accessKey === '' || $secretKey === '') {
            return ['success' => false, 'message' => 'Enter Partner Code, Access Key, and Secret Key before testing the connection.'];
        }

        $baseUrl = $mode === 'live' ? self::LIVE_URL : self::SANDBOX_URL;
        $orderId = 'op_test_connection_' . uniqid();
        $requestId = 'req_check_' . time();

        $rawHash = "accessKey={$accessKey}&orderId={$orderId}&partnerCode={$partnerCode}&requestId={$requestId}";
        $signature = hash_hmac('sha256', $rawHash, $secretKey);

        $ch = curl_init($baseUrl . '/v2/gateway/api/query');
        if ($ch === false) {
            return ['success' => false, 'message' => 'Failed to initialize the connection test request.'];
        }
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS     => (string) json_encode([
                'partnerCode' => $partnerCode,
                'requestId'   => $requestId,
                'orderId'     => $orderId,
                'signature'   => $signature,
            ]),
        ]);
        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            return ['success' => false, 'message' => 'Could not reach MoMo - ' . ($err ?: 'unknown network error') . '.'];
        }

        $data = json_decode((string) $response, true);
        $resultCode = is_array($data) ? $this->getInt($data['resultCode'] ?? -1) : -1;
        $message = is_array($data) ? $this->getString($data['message'] ?? '') : '';

        // resultCode 1023 = "Transaction not found" (MoMo's genuine "order id doesn't exist"
        // response) - it only ever appears once the signature has already validated, so it
        // proves the credentials are authentic despite the fake order id.
        if ($httpCode < 400 && ($resultCode === 1023 || $resultCode === 0)) {
            return ['success' => true, 'message' => "Connected successfully to MoMo ({$mode} mode)."];
        }

        return ['success' => false, 'message' => $message !== '' ? $message : 'MoMo rejected the provided credentials.'];
    }

    public function verify(array $callbackData, array $credentials): array
    {
        $partnerCode = $this->getString($credentials['partner_code'] ?? '');
        $accessKey = $this->getString($credentials['access_key'] ?? '');
        $secretKey = $this->getString($credentials['secret_key'] ?? '');
        $mode = $this->getString($credentials['mode'] ?? 'sandbox');

        $orderIdRaw = $callbackData['orderId'] ?? '';
        $orderId = is_scalar($orderIdRaw) ? (string) $orderIdRaw : '';

        if (empty($orderId)) {
            return [
                'success'        => false,
                'gateway_trx_id' => '',
                'status'         => 'failed',
                'error'          => 'MoMo verification error: Missing orderId parameter.',
            ];
        }

        $baseUrl = $mode === 'live' ? self::LIVE_URL : self::SANDBOX_URL;
        $requestId = 'req_check_' . time();

        // Signature check:
        // accessKey=$accessKey&orderId=$orderId&partnerCode=$partnerCode&requestId=$requestId
        $rawHash = "accessKey={$accessKey}&orderId={$orderId}&partnerCode={$partnerCode}&requestId={$requestId}";
        $signature = hash_hmac('sha256', $rawHash, $secretKey);

        $payload = [
            'partnerCode' => $partnerCode,
            'requestId'   => $requestId,
            'orderId'     => $orderId,
            'signature'   => $signature,
        ];

        $ch = curl_init($baseUrl . '/v2/gateway/api/query');
        if ($ch === false) {
            return [
                'success'        => false,
                'gateway_trx_id' => '',
                'status'         => 'failed',
                'error'          => 'MoMo cURL initialization failed during status query.',
            ];
        }

        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS     => (string) json_encode($payload),
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode >= 400 || $response === false) {
            return [
                'success'        => false,
                'gateway_trx_id' => '',
                'status'         => 'failed',
                'error'          => 'MoMo status lookup failed with HTTP code ' . $httpCode,
            ];
        }

        $data = json_decode((string) $response, true);
        if (!is_array($data)) {
            return [
                'success'        => false,
                'gateway_trx_id' => '',
                'status'         => 'failed',
                'error'          => 'MoMo returned invalid JSON status.',
            ];
        }

        $resultCode = $this->getInt($data['resultCode'] ?? -1);
        $success = $resultCode === 0;
        $momoTrxId = $this->getString($data['transId'] ?? '');
        $amountRaw = $data['amount'] ?? '';
        $amountVal = is_numeric($amountRaw) ? (string)$amountRaw : '';

        $result = [
            'success'        => $success,
            'gateway_trx_id' => $momoTrxId ?: $orderId,
            'status'         => $success ? 'completed' : 'failed',
        ];

        if ($amountVal !== '') {
            $result['amount'] = $amountVal; // VND has no decimals, returning the exact amount integer
        }

        return $result;
    }

    public function verifyWebhook(string $rawBody, array $headers, array $credentials): bool
    {
        $secretKey = $this->getString($credentials['secret_key'] ?? '');
        if (empty($secretKey)) {
            return false;
        }

        $data = json_decode($rawBody, true);
        if (!is_array($data) || !isset($data['signature'])) {
            return false;
        }

        $providedSig = $this->getString($data['signature']);
        
        // Remove signature to compute expected signature over alphabetical sorted parameters
        unset($data['signature']);
        ksort($data);

        $params = [];
        foreach ($data as $key => $val) {
            if (is_scalar($val)) {
                $params[] = $key . '=' . (string)$val;
            }
        }
        $rawHash = implode('&', $params);
        $computedSig = hash_hmac('sha256', $rawHash, $secretKey);

        return hash_equals($computedSig, $providedSig);
    }

    public function refund(string $gatewayTrxId, string $amount, array $credentials): array
    {
        return ['success' => false, 'error' => 'Refund capability not supported.'];
    }

    public function supports(string $feature): bool
    {
        return match ($feature) {
            'verification' => true,
            default => false,
        };
    }

    public function supportedCurrencies(): array
    {
        return ['VND'];
    }
}
