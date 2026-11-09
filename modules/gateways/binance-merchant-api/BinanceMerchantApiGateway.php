<?php
declare(strict_types=1);

namespace OwnPay\Modules\Gateways\BinanceMerchantApi;

use OwnPay\Gateway\GatewayAdapterInterface;
use OwnPay\Gateway\GatewayDefaults;
use OwnPay\Gateway\TestableConnectionInterface;
use OwnPay\Plugin\PluginInterface;
use OwnPay\Plugin\Capability;
use OwnPay\Container;
use OwnPay\Event\EventManager;

/**
 * Binance Pay Merchant API gateway - PluginInterface + GatewayAdapterInterface.
 */
final class BinanceMerchantApiGateway implements PluginInterface, GatewayAdapterInterface, TestableConnectionInterface
{
    use GatewayDefaults;

    private const API_URL = 'https://bpay.binanceapi.com/binancepay/openapi/v3/order';
    private const QUERY_URL = 'https://bpay.binanceapi.com/binancepay/openapi/v2/order/query';

    public static function metadata(): array
    {
        return [
            'name' => 'Binance Pay', 'slug' => 'binance-merchant-api', 'version' => '1.0.0',
            'description' => 'Binance Pay merchant API gateway integration',
            'author' => 'OwnPay Core', 'type' => 'gateway',
        ];
    }

    public function slug(): string { return 'binance-merchant-api'; }
    public function name(): string { return 'Binance Pay'; }
    public function version(): string { return '1.0.0'; }
    public function description(): string { return 'Binance Pay merchant API gateway integration'; }

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
            ['name' => 'merchant_api_key', 'label' => 'Merchant API Key', 'type' => 'text', 'required' => true],
            ['name' => 'merchant_secret_key', 'label' => 'Merchant Secret Key', 'type' => 'password', 'required' => true],
            ['name' => 'binance_public_key', 'label' => 'Binance Pay Public Key', 'type' => 'textarea', 'required' => true],
            ['name' => 'payment_currency', 'label' => 'Payment Currency', 'type' => 'select', 'options' => [
                'USDT'  => 'Tether (USDT)',
                'BUSD'  => 'Binance USD (BUSD)',
                'BTC'   => 'Bitcoin (BTC)',
                'ETH'   => 'Ethereum (ETH)',
                'BNB'   => 'Binance Coin (BNB)',
                'DOGE'  => 'Dogecoin (DOGE)',
                'FDUSD' => 'First Digital USD (FDUSD)',
                'DAI'   => 'Dai (DAI)',
                'TUSD'  => 'TrueUSD (TUSD)',
                'SUI'   => 'Sui (SUI)',
                'SHIB'  => 'Shiba Inu (SHIB)',
                'SOL'   => 'Solana (SOL)',
                'TRX'   => 'TRON (TRX)',
                'LTC'   => 'Litecoin (LTC)',
                'MATIC' => 'Polygon (MATIC)',
                'XRP'   => 'XRP (XRP)',
                'APE'   => 'ApeCoin (APE)',
                'ADA'   => 'Cardano (ADA)'
            ], 'required' => true],
        ];
    }

    /** @param array<string, mixed> $credentials
     *  @return array{success: bool, message: string} */
    public function testConnection(array $credentials): array
    {
        $apiKey = is_scalar($credentials['merchant_api_key'] ?? null) ? (string) $credentials['merchant_api_key'] : '';
        $apiSecret = is_scalar($credentials['merchant_secret_key'] ?? null) ? (string) $credentials['merchant_secret_key'] : '';
        if ($apiKey === '' || $apiSecret === '') {
            return ['success' => false, 'message' => 'Enter Merchant API Key and Merchant Secret Key before testing the connection.'];
        }

        $merchantTradeNo = 'optestconn' . bin2hex(random_bytes(6));
        $payload = json_encode(['merchantTradeNo' => $merchantTradeNo]);
        if (is_string($payload) === false) {
            return ['success' => false, 'message' => 'Could not encode Binance Pay test request.'];
        }
        $timestamp = (int) round(microtime(true) * 1000);
        $nonce = bin2hex(random_bytes(16));
        $message = $timestamp . "\n" . $nonce . "\n" . $payload . "\n";
        $signature = strtoupper(hash_hmac('SHA512', $message, $apiSecret));

        $ch = curl_init(self::QUERY_URL);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'BinancePay-Timestamp: ' . $timestamp,
                'BinancePay-Nonce: ' . $nonce,
                'BinancePay-Certificate-SN: ' . $apiKey,
                'BinancePay-Signature: ' . $signature,
            ],
            CURLOPT_POSTFIELDS     => $payload,
        ]);
        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false) {
            return ['success' => false, 'message' => 'Could not reach Binance Pay - check the server\'s network connectivity.'];
        }

        $data = json_decode((string) $response, true);
        $errorCode = is_array($data) && is_scalar($data['errorCode'] ?? null) ? (string) $data['errorCode'] : '';
        $status = is_array($data) && is_scalar($data['status'] ?? null) ? (string) $data['status'] : '';

        if ($httpCode === 200 && ($status !== '' || $errorCode !== '')
            && !in_array($errorCode, ['SIGNATURE_INVALID', 'CERTIFICATE_INVALID', 'INVALID_API_KEY', 'UNAUTHORIZED'], true)
        ) {
            return ['success' => true, 'message' => 'Connected successfully to Binance Pay.'];
        }

        $errMsg = is_array($data) && is_scalar($data['errorMessage'] ?? null) ? (string) $data['errorMessage'] : 'Binance Pay rejected the provided API Key/Secret Key.';
        return ['success' => false, 'message' => $errMsg];
    }

    public function initiate(array $params, array $credentials): array
    {
        $apiKey = is_scalar($credentials['merchant_api_key'] ?? null) ? (string) $credentials['merchant_api_key'] : '';
        $apiSecret = is_scalar($credentials['merchant_secret_key'] ?? null) ? (string) $credentials['merchant_secret_key'] : '';
        $currency = $credentials['payment_currency'] ?? 'USDT';
        if (is_scalar($currency) === false) {
            $currency = 'USDT';
        }
        $currency = (string) $currency;

        $merchantTradeNo = bin2hex(random_bytes(16));
        $separator = (strpos($params['redirect_url'], '?') !== false) ? '&' : '?';
        $redirectUrl = (string) $params['redirect_url'];
        $cancelUrl = (string) $params['cancel_url'];
        $trxId = (string) $params['trx_id'];

        $orderData = [
            'env' => [
                'terminalType' => 'WEB',
            ],
            'merchantTradeNo' => $merchantTradeNo,
            'orderAmount'     => $params['amount'],
            'currency'        => $currency,
            'goodsDetails' => [
                [
                    'goodsType'        => '01',
                    'goodsCategory'    => 'D000',
                    'referenceGoodsId' => $trxId,
                    'goodsName'        => 'Payment ' . $trxId,
                ],
            ],
            'description' => 'Payment ' . $trxId,
            'passThroughInfo' => $trxId,
            'returnUrl' => $redirectUrl . $separator . 'status=success&session_id=' . urlencode($merchantTradeNo) . '&trx_id=' . urlencode($trxId),
            'cancelUrl' => $cancelUrl,
        ];

        $payload = json_encode($orderData);
        if (!is_string($payload)) {
            throw new \RuntimeException('Binance Pay order creation failed: could not encode request');
        }

        $timestamp = (int) round(microtime(true) * 1000);
        $nonce = bin2hex(random_bytes(16));
        $message = $timestamp . "\n" . $nonce . "\n" . $payload . "\n";
        $signature = strtoupper(hash_hmac('SHA512', $message, $apiSecret));

        $headers = [
            'Content-Type: application/json',
            'BinancePay-Timestamp: ' . $timestamp,
            'BinancePay-Nonce: ' . $nonce,
            'BinancePay-Certificate-SN: ' . $apiKey,
            'BinancePay-Signature: ' . $signature
        ];

        $ch = curl_init(self::API_URL);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_POSTFIELDS     => $payload,
        ]);

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || is_string($response) === false || $response === '') {
            throw new \RuntimeException('Binance Pay API connection error: HTTP ' . $httpCode);
        }

        $data = json_decode($response, true);
        if (!is_array($data)) {
            throw new \RuntimeException('Binance Pay order creation failed: ' . $response);
        }
        $innerData = $data['data'] ?? null;
        if (($data['status'] ?? '') !== 'SUCCESS' || !is_array($innerData) || empty($innerData['checkoutUrl']) || !is_string($innerData['checkoutUrl'])) {
            throw new \RuntimeException('Binance Pay order creation failed: ' . $response);
        }

        return [
            'redirect_url' => $innerData['checkoutUrl'],
            'session_id'   => $merchantTradeNo,
        ];
    }

    public function verify(array $callbackData, array $credentials): array
    {
        $rawTradeNo = $callbackData['session_id'] ?? '';
        $merchantTradeNo = is_scalar($rawTradeNo) === true ? (string) $rawTradeNo : '';
        if ($merchantTradeNo === '') {
            $rawMt = $callbackData['merchantTradeNo'] ?? '';
            $merchantTradeNo = is_scalar($rawMt) === true ? (string) $rawMt : '';
        }
        if ($merchantTradeNo === '' && isset($callbackData['data']) && is_string($callbackData['data']) === true) {
            $decoded = json_decode($callbackData['data'], true);
            if (is_array($decoded) === true) {
                $rawMt = $decoded['merchantTradeNo'] ?? '';
                $merchantTradeNo = is_scalar($rawMt) === true ? (string) $rawMt : '';
            }
        }
        if ($merchantTradeNo === '') {
            return ['success' => false, 'gateway_trx_id' => '', 'status' => 'failed'];
        }

        $apiKey = is_scalar($credentials['merchant_api_key'] ?? null) ? (string) $credentials['merchant_api_key'] : '';
        $apiSecret = is_scalar($credentials['merchant_secret_key'] ?? null) ? (string) $credentials['merchant_secret_key'] : '';

        $payload = json_encode(['merchantTradeNo' => $merchantTradeNo]);
        if (!is_string($payload)) {
            return ['success' => false, 'gateway_trx_id' => '', 'status' => 'invalid_response'];
        }
        $timestamp = (int) round(microtime(true) * 1000);
        $nonce = bin2hex(random_bytes(16));
        $message = $timestamp . "\n" . $nonce . "\n" . $payload . "\n";
        $signature = strtoupper(hash_hmac('SHA512', $message, $apiSecret));

        $headers = [
            'Content-Type: application/json',
            'BinancePay-Timestamp: ' . $timestamp,
            'BinancePay-Nonce: ' . $nonce,
            'BinancePay-Certificate-SN: ' . $apiKey,
            'BinancePay-Signature: ' . $signature
        ];

        $ch = curl_init(self::QUERY_URL);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_POSTFIELDS     => $payload,
        ]);

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || !is_string($response) || $response === '') {
            return ['success' => false, 'gateway_trx_id' => '', 'status' => 'api_error'];
        }

        $data = json_decode($response, true);
        if (!is_array($data) || ($data['status'] ?? '') !== 'SUCCESS') {
            return ['success' => false, 'gateway_trx_id' => '', 'status' => 'invalid_response'];
        }

        $innerData = $data['data'] ?? null;
        if (!is_array($innerData)) {
            return ['success' => false, 'gateway_trx_id' => '', 'status' => 'invalid_response'];
        }

        $bizStatus = isset($innerData['status']) && is_scalar($innerData['status']) ? (string) $innerData['status'] : '';
        $paid = $bizStatus === 'PAID' || $bizStatus === 'PAY_SUCCESS';

        $transactionId = isset($innerData['transactionId']) && is_scalar($innerData['transactionId']) ? (string) $innerData['transactionId'] : $merchantTradeNo;
        $orderAmount = isset($innerData['orderAmount']) && is_scalar($innerData['orderAmount']) ? (string) $innerData['orderAmount'] : '';
        if ($orderAmount === '') {
            $orderAmount = isset($innerData['totalFee']) && is_scalar($innerData['totalFee']) ? (string) $innerData['totalFee'] : '';
        }

        $refGoodsId = '';
        $passThroughAuthenticated = false;
        $passThrough = $innerData['passThroughInfo'] ?? null;
        if (is_scalar($passThrough) === true && (string) $passThrough !== '') {
            $refGoodsId = (string) $passThrough;
            $passThroughAuthenticated = true;
        }
        if ($refGoodsId === '') {
            $cbTrxId = $callbackData['trx_id'] ?? '';
            $refGoodsId = is_scalar($cbTrxId) ? (string) $cbTrxId : '';
        }

        $result = [
            'success'        => $paid,
            'gateway_trx_id' => $transactionId,
            'amount'         => $orderAmount,
            'status'         => $paid ? 'completed' : 'failed',
            'trx_id'         => $refGoodsId,
        ];
        if ($paid === true && $passThroughAuthenticated === true) {
            $result['verified_trx_id'] = $refGoodsId;
        }
        return $result;
    }

    public function verifyWebhook(string $rawBody, array $headers, array $credentials): bool
    {
        $timestamp = $this->getHeader($headers, 'BinancePay-Timestamp');
        $nonce = $this->getHeader($headers, 'BinancePay-Nonce');
        $signature = $this->getHeader($headers, 'BinancePay-Signature');

        if ($timestamp === '' || $nonce === '' || $signature === '' || $rawBody === '') {
            return false;
        }

        // Reject timestamps outside a short clock-skew window (replay protection).
        if (ctype_digit($timestamp) === false) {
            return false;
        }
        $timestampMs = (int) $timestamp;
        if (abs((int) round(microtime(true) * 1000) - $timestampMs) > 300000) {
            return false;
        }

        $publicKey = $this->resolvePublicKey($credentials);
        if ($publicKey === '') {
            return false;
        }

        $payload = $timestamp . "\n" . $nonce . "\n" . $rawBody . "\n";

        $decodedSignature = base64_decode($signature, true);
        if ($decodedSignature === false) {
            return false;
        }

        $publicKeyPem = $publicKey;
        if (strpos($publicKeyPem, '-----BEGIN PUBLIC KEY-----') === false) {
            $publicKeyPem = "-----BEGIN PUBLIC KEY-----\n"
                . wordwrap($publicKeyPem, 64, "\n", true)
                . "\n-----END PUBLIC KEY-----";
        }

        $pubKeyObj = openssl_pkey_get_public($publicKeyPem);
        if ($pubKeyObj === false) {
            return false;
        }

        return openssl_verify($payload, $decodedSignature, $pubKeyObj, OPENSSL_ALGO_SHA256) === 1;
    }

    /** @param array<string, mixed> $headers */
    private function getHeader(array $headers, string $name): string
    {
        foreach ($headers as $k => $v) {
            if (strcasecmp($k, $name) === 0) {
                return is_scalar($v) === true ? (string) $v : '';
            }
        }
        return '';
    }

    /** @param array<string, mixed> $credentials */
    private function resolvePublicKey(array $credentials): string
    {
        $keys = [
            'binance_public_key',
            'binance_pay_public_key',
            'webhook_public_key',
            'public_key',
            'merchant_public_key',
            'cert_public',
            'binance_pay_cert_public',
        ];
        foreach ($keys as $k) {
            $val = $credentials[$k] ?? null;
            if (is_scalar($val) === true && (string) $val !== '') {
                return (string) $val;
            }
        }
        return '';
    }
}
