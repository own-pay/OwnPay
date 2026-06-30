<?php
declare(strict_types=1);

namespace OwnPay\Modules\Gateways\BinanceMerchantApi;

use OwnPay\Gateway\GatewayAdapterInterface;
use OwnPay\Gateway\GatewayDefaults;
use OwnPay\Plugin\PluginInterface;
use OwnPay\Plugin\Capability;
use OwnPay\Container;
use OwnPay\Event\EventManager;

/**
 * Binance Pay Merchant API gateway - PluginInterface + GatewayAdapterInterface.
 */
final class BinanceMerchantApiGateway implements PluginInterface, GatewayAdapterInterface
{
    use GatewayDefaults;

    private const API_URL = 'https://bpay.binanceapi.com/binancepay/openapi/order';
    private const QUERY_URL = 'https://bpay.binanceapi.com/binancepay/openapi/order/query';

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

    public function initiate(array $params, array $credentials): array
    {
        $apiKey = is_scalar($credentials['merchant_api_key'] ?? null) ? (string) $credentials['merchant_api_key'] : '';
        $apiSecret = is_scalar($credentials['merchant_secret_key'] ?? null) ? (string) $credentials['merchant_secret_key'] : '';
        $currency = $credentials['payment_currency'] ?? 'USDT';

        $merchantTradeNo = uniqid('order_');
        $separator = (strpos($params['redirect_url'], '?') !== false) ? '&' : '?';

        $orderData = [
            'merchantTradeNo' => $merchantTradeNo,
            'orderAmount'     => $params['amount'],
            'currency'        => $currency,
            'goods' => [
                'goodsType'        => '01', // virtual
                'goodsCategory'    => 'D000',
                'referenceGoodsId' => $params['trx_id'],
                'goodsName'        => 'Payment ' . $params['trx_id'],
            ],
            'returnUrl' => $params['redirect_url'] . $separator . 'status=success&session_id=' . urlencode($merchantTradeNo) . '&trx_id=' . urlencode($params['trx_id']),
            'cancelUrl' => $params['cancel_url'],
        ];

        $payload = json_encode($orderData);

        $timestamp = round(microtime(true) * 1000);
        $nonce = bin2hex(random_bytes(16));
        $message = $timestamp . "\n" . $nonce . "\n" . $payload . "\n";
        $signature = hash_hmac('SHA512', $message, $apiSecret);

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
            CURLOPT_POSTFIELDS     => (string) json_encode($orderData),
        ]);

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || !$response) {
            throw new \RuntimeException('Binance Pay API connection error: HTTP ' . $httpCode);
        }

        $data = json_decode((string) $response, true);
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
        $merchantTradeNo = is_scalar($rawTradeNo) ? (string) $rawTradeNo : '';
        if ($merchantTradeNo === '') {
            return ['success' => false, 'gateway_trx_id' => '', 'status' => 'failed'];
        }

        $apiKey = is_scalar($credentials['merchant_api_key'] ?? null) ? (string) $credentials['merchant_api_key'] : '';
        $apiSecret = is_scalar($credentials['merchant_secret_key'] ?? null) ? (string) $credentials['merchant_secret_key'] : '';

        $payload = json_encode(['merchantTradeNo' => $merchantTradeNo]);
        $timestamp = round(microtime(true) * 1000);
        $nonce = bin2hex(random_bytes(16));
        $message = $timestamp . "\n" . $nonce . "\n" . $payload . "\n";
        $signature = hash_hmac('SHA512', $message, $apiSecret);

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
            CURLOPT_POSTFIELDS     => (string) json_encode(['merchantTradeNo' => $merchantTradeNo]),
        ]);

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || !$response) {
            return ['success' => false, 'gateway_trx_id' => '', 'status' => 'api_error'];
        }

        $data = json_decode((string) $response, true);
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
        
        $goods = $innerData['goods'] ?? null;
        $refGoodsId = '';
        if (is_array($goods) && isset($goods['referenceGoodsId']) && is_scalar($goods['referenceGoodsId'])) {
            $refGoodsId = (string) $goods['referenceGoodsId'];
        }
        if ($refGoodsId === '') {
            $cbTrxId = $callbackData['trx_id'] ?? '';
            $refGoodsId = is_scalar($cbTrxId) ? (string) $cbTrxId : '';
        }

        return [
            'success'        => $paid,
            'gateway_trx_id' => $transactionId,
            'amount'         => $orderAmount,
            'status'         => $paid ? 'completed' : 'failed',
            'trx_id'         => $refGoodsId,
        ];
    }
}
