<?php
declare(strict_types=1);

namespace OwnPay\Modules\Gateways\NowPayments;

use OwnPay\Gateway\GatewayAdapterInterface;
use OwnPay\Gateway\GatewayDefaults;
use OwnPay\Plugin\PluginInterface;
use OwnPay\Plugin\Capability;
use OwnPay\Container;
use OwnPay\Event\EventManager;

/**
 * NOWPayments Crypto Gateway - PluginInterface + GatewayAdapterInterface.
 */
final class NowPaymentsGateway implements PluginInterface, GatewayAdapterInterface
{
    use GatewayDefaults;

    public static function metadata(): array
    {
        return [
            'name'        => 'NowPayments Crypto',
            'slug'        => 'now-payments',
            'version'     => '1.0.0',
            'description' => 'Accept NowPayments Crypto payments directly from customers.',
            'author'      => 'OwnPay Core',
            'type'        => 'gateway',
        ];
    }

    public function slug(): string { return 'now-payments'; }
    public function name(): string { return 'NowPayments Crypto'; }
    public function version(): string { return '1.0.0'; }
    public function description(): string { return 'Accept NowPayments Crypto payments directly from customers.'; }

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
            [
                'name'     => 'now_payment_api_key',
                'label'    => 'NOWPayments API Key',
                'type'     => 'text',
                'required' => true
            ],
            [
                'name'     => 'now_payment_ipn_secret',
                'label'    => 'NOWPayments IPN Secret Key',
                'type'     => 'text',
                'required' => true
            ],
            [
                'name'     => 'now_payment_mode',
                'label'    => 'Mode',
                'type'     => 'select',
                'options'  => ['sandbox' => 'sandbox', 'live' => 'live'],
                'required' => true
            ],
        ];
    }

    public function initiate(array $params, array $credentials): array
    {
        $mode = $credentials['now_payment_mode'] ?? 'sandbox';
        $baseUrl = $mode === 'live' ? 'https://api.nowpayments.io/v1/' : 'https://api-sandbox.nowpayments.io/v1/';
        $url = $baseUrl . 'invoice';

        $apiKeyRaw = $credentials['now_payment_api_key'] ?? '';
        $apiKey = is_scalar($apiKeyRaw) ? (string) $apiKeyRaw : '';
        $trxId = $params['trx_id'];

        $redirectUrl = $params['redirect_url'];
        $cancelUrl = $params['cancel_url'];

        // Construct webhook/IPN URL dynamically from the redirect URL
        $parsed = parse_url($redirectUrl);
        $scheme = $parsed['scheme'] ?? 'http';
        $host = $parsed['host'] ?? '';
        $port = isset($parsed['port']) ? ':' . $parsed['port'] : '';
        $webhookUrl = "{$scheme}://{$host}{$port}/webhook/now-payments";

        $postData = [
            'price_amount'      => (float) $params['amount'],
            'price_currency'    => strtolower($params['currency']),
            'pay_currency'      => null, // Customer selects coin on NOWPayments hosted checkout
            'order_id'          => $trxId,
            'order_description' => 'Payment for Order #' . $trxId,
            'ipn_callback_url'  => $webhookUrl,
            'success_url'       => $redirectUrl,
            'cancel_url'        => $cancelUrl,
        ];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER     => [
                'x-api-key: ' . $apiKey,
                'Content-Type: application/json'
            ],
            CURLOPT_POSTFIELDS => (string) json_encode($postData),
        ]);

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $responseStr = is_string($response) ? $response : '';
        if (($httpCode !== 201 && $httpCode !== 200) || $responseStr === '') {
            $errData = json_decode($responseStr, true);
            $errMsg = is_array($errData) && isset($errData['message']) && is_scalar($errData['message']) ? (string) $errData['message'] : 'HTTP ' . $httpCode;
            throw new \RuntimeException('NOWPayments API Error: ' . $errMsg);
        }

        $result = json_decode($responseStr, true);
        if (!is_array($result)) {
            throw new \RuntimeException('NOWPayments response has invalid JSON');
        }

        $invoiceUrlVal = $result['invoice_url'] ?? '';
        $invoiceUrl = is_scalar($invoiceUrlVal) ? (string) $invoiceUrlVal : '';

        if ($invoiceUrl === '') {
            throw new \RuntimeException('NOWPayments response has missing invoice URL');
        }

        $idVal = $result['id'] ?? '';
        $idStr = is_scalar($idVal) ? (string) $idVal : '';

        return [
            'redirect_url' => $invoiceUrl,
            'session_id'   => $idStr,
        ];
    }

    public function verify(array $callbackData, array $credentials): array
    {
        if (($callbackData['_op_webhook_verified'] ?? false) !== true) {
            return ['success' => false, 'gateway_trx_id' => '', 'status' => 'unverified'];
        }

        $paymentStatus = $callbackData['payment_status'] ?? '';
        $paymentStatusStr = is_scalar($paymentStatus) ? (string) $paymentStatus : '';

        $paymentId = $callbackData['payment_id'] ?? '';
        $paymentIdStr = is_scalar($paymentId) ? (string) $paymentId : '';

        $orderId = $callbackData['order_id'] ?? '';
        $orderIdStr = is_scalar($orderId) ? (string) $orderId : '';

        $priceAmount = $callbackData['price_amount'] ?? null;

        // If paymentStatus is present, it's an IPN/webhook callback
        if ($paymentStatusStr !== '') {
            $isPaid = in_array(strtolower($paymentStatusStr), ['finished', 'sending', 'confirmed'], true);
            $res = [
                'success'        => $isPaid,
                'gateway_trx_id' => $paymentIdStr,
                'status'         => $isPaid ? 'completed' : 'failed',
                'trx_id'         => $orderIdStr,
            ];
            if ($priceAmount !== null) {
                $res['amount'] = is_scalar($priceAmount) ? (string) $priceAmount : '';
            }
            return $res;
        }

        // Return pending status if no webhook payload is present (e.g. initial redirect)
        $trxId = $callbackData['trx_id'] ?? $callbackData['paymentID'] ?? '';
        $trxIdStr = is_scalar($trxId) ? (string) $trxId : '';
        return [
            'success'        => false,
            'gateway_trx_id' => '',
            'status'         => 'pending',
            'trx_id'         => $trxIdStr,
        ];
    }

    public function verifyWebhook(string $rawBody, array $headers, array $credentials): bool
    {
        $receivedSignatureStr = $headers['x-nowpayments-sig'] ?? '';
        if ($receivedSignatureStr === '') {
            return false;
        }

        $requestData = json_decode($rawBody, true);
        if (!is_array($requestData)) {
            return false;
        }

        ksort($requestData);
        $sortedRequestJson = json_encode($requestData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($sortedRequestJson === false) {
            return false;
        }

        $ipnSecret = $credentials['now_payment_ipn_secret'] ?? '';
        $ipnSecretStr = is_scalar($ipnSecret) ? (string) $ipnSecret : '';
        if ($ipnSecretStr === '') {
            return false;
        }

        $expectedSignature = hash_hmac('sha512', $sortedRequestJson, trim($ipnSecretStr));

        return hash_equals($receivedSignatureStr, $expectedSignature);
    }
}
