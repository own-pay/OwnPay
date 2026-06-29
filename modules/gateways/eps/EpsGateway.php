<?php
declare(strict_types=1);

namespace OwnPay\Modules\Gateways\Eps;

use OwnPay\Gateway\GatewayAdapterInterface;
use OwnPay\Gateway\GatewayDefaults;
use OwnPay\Plugin\PluginInterface;
use OwnPay\Plugin\Capability;
use OwnPay\Container;
use OwnPay\Event\EventManager;

/**
 * EPS payment gateway - PluginInterface + GatewayAdapterInterface.
 */
final class EpsGateway implements PluginInterface, GatewayAdapterInterface
{
    use GatewayDefaults;

    private const SANDBOX_URL = 'https://sandboxpgapi.eps.com.bd';
    private const LIVE_URL    = 'https://pgapi.eps.com.bd';

    public static function metadata(): array
    {
        return [
            'name' => 'EPS', 'slug' => 'eps', 'version' => '1.0.0',
            'description' => 'EPS payment gateway integration',
            'author' => 'OwnPay Core', 'type' => 'gateway',
        ];
    }

    public function slug(): string { return 'eps'; }
    public function name(): string { return 'EPS'; }
    public function version(): string { return '1.0.0'; }
    public function description(): string { return 'EPS payment gateway integration'; }

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
            ['name' => 'merchant_id', 'label' => 'Merchant ID', 'type' => 'text', 'required' => true],
            ['name' => 'store_id', 'label' => 'Store ID', 'type' => 'text', 'required' => true],
            ['name' => 'hash_key', 'label' => 'Hash Key', 'type' => 'password', 'required' => true],
            ['name' => 'username', 'label' => 'Username', 'type' => 'text', 'required' => true],
            ['name' => 'password', 'label' => 'Password', 'type' => 'password', 'required' => true],
            ['name' => 'store_mode', 'label' => 'Mode', 'type' => 'select', 'options' => ['sandbox' => 'sandbox', 'live' => 'live'], 'required' => true],
        ];
    }

    public function initiate(array $params, array $credentials): array
    {
        $mode = $credentials['store_mode'] ?? 'sandbox';
        $baseUrl = $mode === 'live' ? self::LIVE_URL : self::SANDBOX_URL;

        $token = $this->getToken($baseUrl, $credentials);

        $merchantTransactionId = 'MTID' . rand(1000000, 9999999);
        $customerOrderId = 'ORDER' . rand(10000, 99999);

        $hashKeyRaw = $credentials['hash_key'] ?? '';
        $hashKey = is_scalar($hashKeyRaw) ? (string) $hashKeyRaw : '';
        $hmac = hash_hmac('sha512', $merchantTransactionId, $hashKey, true);
        $xHash = base64_encode($hmac);

        $separator = (strpos($params['redirect_url'], '?') !== false) ? '&' : '?';

        // Extract or default customer details from metadata
        $email = $params['metadata']['customer_email'] ?? 'customer@example.com';
        $phone = $params['metadata']['customer_phone'] ?? '01700000000';
        $name  = $params['metadata']['customer_name'] ?? 'Customer';

        $payload = [
            'storeId'               => $credentials['store_id'] ?? '',
            'CustomerOrderId'       => $customerOrderId,
            'merchantTransactionId' => $merchantTransactionId,
            'transactionTypeId'     => 1, // Web
            'financialEntityId'     => 0,
            'transitionStatusId'    => 0,
            'totalAmount'           => $params['amount'],
            'ipAddress'             => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
            'version'               => '1',
            'successUrl'            => $params['redirect_url'] . $separator . 'ppstatus=success&paymentID=' . urlencode($params['trx_id']),
            'failUrl'               => $params['cancel_url'],
            'cancelUrl'             => $params['cancel_url'],
            'customerName'          => $name,
            'customerEmail'         => $email,
            'CustomerAddress'       => 'Dhaka, Bangladesh',
            'CustomerAddress2'      => 'Dhaka, Bangladesh',
            'CustomerCity'          => 'Dhaka',
            'CustomerState'         => 'Dhaka',
            'CustomerPostcode'      => '1200',
            'CustomerCountry'       => 'BD',
            'CustomerPhone'         => $phone,
            'ShipmentName'          => $name,
            'ShipmentAddress'       => 'Dhaka, Bangladesh',
            'ShipmentAddress2'      => 'Dhaka, Bangladesh',
            'ShipmentCity'          => 'Dhaka',
            'ShipmentState'         => 'Dhaka',
            'ShipmentPostcode'      => '1200',
            'ShipmentCountry'       => 'BD',
            'ValueA'                => $params['trx_id'],
            'ValueB'                => '',
            'ValueC'                => '',
            'ValueD'                => '',
            'ShippingMethod'        => 'NO',
            'NoOfItem'              => '1',
            'ProductName'           => 'Payment ' . $params['trx_id'],
            'ProductProfile'        => 'general',
            'ProductCategory'       => 'Digital',
            'ProductList'           => [
                [
                    'ProductName'     => 'Payment ' . $params['trx_id'],
                    'NoOfItem'        => '1',
                    'ProductProfile'  => 'general',
                    'ProductCategory' => 'Digital',
                    'ProductPrice'    => (string)$params['amount']
                ]
            ]
        ];

        $ch = curl_init($baseUrl . '/v1/EPSEngine/InitializeEPS');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'x-hash: ' . $xHash,
                'Authorization: Bearer ' . $token
            ],
            CURLOPT_POSTFIELDS => (string) json_encode($payload),
        ]);

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || !$response) {
            throw new \RuntimeException('EPS API connection error: HTTP ' . $httpCode);
        }

        $data = json_decode((string) $response, true);
        if (!is_array($data) || empty($data['RedirectURL']) || !is_string($data['RedirectURL'])) {
            throw new \RuntimeException('EPS initiation failed: ' . $response);
        }

        return [
            'redirect_url' => $data['RedirectURL'],
            'session_id'   => $merchantTransactionId,
        ];
    }

    public function verify(array $callbackData, array $credentials): array
    {
        $status = $callbackData['Status'] ?? '';
        $merchantTransactionId = $callbackData['MerchantTransactionId'] ?? '';
        $rawTrxId = $callbackData['trx_id'] ?? $callbackData['paymentID'] ?? '';
        $trxId = is_scalar($rawTrxId) ? (string) $rawTrxId : '';

        $statusStr = is_scalar($status) ? (string) $status : '';
        $merchantTransactionIdStr = is_scalar($merchantTransactionId) ? (string) $merchantTransactionId : '';

        if ($statusStr !== 'Success' || $merchantTransactionIdStr === '') {
            return ['success' => false, 'gateway_trx_id' => '', 'status' => 'failed'];
        }

        $mode = $credentials['store_mode'] ?? 'sandbox';
        $baseUrl = $mode === 'live' ? self::LIVE_URL : self::SANDBOX_URL;

        $token = $this->getToken($baseUrl, $credentials);

        $hashKeyRaw = $credentials['hash_key'] ?? '';
        $hashKey = is_scalar($hashKeyRaw) ? (string) $hashKeyRaw : '';
        $hmac = hash_hmac('sha512', $merchantTransactionIdStr, $hashKey, true);
        $xHash = base64_encode($hmac);

        $url = $baseUrl . '/v1/EPSEngine/CheckMerchantTransactionStatus?merchantTransactionId=' . urlencode($merchantTransactionIdStr);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_HTTPHEADER     => [
                'x-hash: ' . $xHash,
                'Authorization: Bearer ' . $token
            ]
        ]);

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || !$response) {
            return ['success' => false, 'gateway_trx_id' => '', 'status' => 'api_error'];
        }

        $data = json_decode((string) $response, true);
        if (!is_array($data)) {
            return ['success' => false, 'gateway_trx_id' => '', 'status' => 'invalid_response'];
        }

        $statusVal = isset($data['Status']) && is_scalar($data['Status']) ? (string) $data['Status'] : '';
        $paid = strtolower($statusVal) === 'success';

        $epsTrxId = isset($data['EPSTransactionId']) && is_scalar($data['EPSTransactionId']) ? (string) $data['EPSTransactionId'] : $merchantTransactionIdStr;
        $totalAmount = isset($data['TotalAmount']) && is_scalar($data['TotalAmount']) ? (string) $data['TotalAmount'] : '';
        $valueA = isset($data['ValueA']) && is_scalar($data['ValueA']) ? (string) $data['ValueA'] : (string) $trxId;

        return [
            'success'        => $paid,
            'gateway_trx_id' => $epsTrxId,
            'amount'         => $totalAmount,
            'status'         => $paid ? 'completed' : 'failed',
            'trx_id'         => $valueA,
        ];
    }

    /** @param array<string, mixed> $credentials */
    private function getToken(string $baseUrl, array $credentials): string
    {
        $usernameRaw = $credentials['username'] ?? '';
        $username = is_scalar($usernameRaw) ? (string) $usernameRaw : '';
        $passwordRaw = $credentials['password'] ?? '';
        $password = is_scalar($passwordRaw) ? (string) $passwordRaw : '';
        $hashKeyRaw = $credentials['hash_key'] ?? '';
        $hashKey = is_scalar($hashKeyRaw) ? (string) $hashKeyRaw : '';

        $hmac = hash_hmac('sha512', $username, $hashKey, true);
        $xHash = base64_encode($hmac);

        $ch = curl_init($baseUrl . '/v1/Auth/GetToken');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'x-hash: ' . $xHash
            ],
            CURLOPT_POSTFIELDS => (string) json_encode([
                'userName' => $username,
                'password' => $password
            ]),
        ]);

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || !$response) {
            throw new \RuntimeException('EPS Token generation error: HTTP ' . $httpCode);
        }

        $data = json_decode((string) $response, true);
        if (!is_array($data) || empty($data['token']) || !is_string($data['token'])) {
            throw new \RuntimeException('EPS Token generation failed: ' . $response);
        }

        return $data['token'];
    }
}
