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
 * NOWPayments Crypto Gateway — PluginInterface + GatewayAdapterInterface.
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

        $apiKey = $credentials['now_payment_api_key'] ?? '';
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
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_HTTPHEADER     => [
                'x-api-key: ' . $apiKey,
                'Content-Type: application/json'
            ],
            CURLOPT_POSTFIELDS => (string) json_encode($postData),
        ]);

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if (($httpCode !== 201 && $httpCode !== 200) || !$response) {
            $errData = json_decode((string) $response, true);
            $errMsg = $errData['message'] ?? 'HTTP ' . $httpCode;
            throw new \RuntimeException('NOWPayments API Error: ' . $errMsg);
        }

        $result = json_decode((string) $response, true);
        if (empty($result['invoice_url'])) {
            throw new \RuntimeException('NOWPayments response has missing invoice URL');
        }

        return [
            'redirect_url' => $result['invoice_url'],
            'session_id'   => (string) ($result['id'] ?? ''),
        ];
    }

    public function verify(array $callbackData, array $credentials): array
    {
        $paymentStatus = $callbackData['payment_status'] ?? '';
        $paymentId = $callbackData['payment_id'] ?? '';
        $orderId = $callbackData['order_id'] ?? '';
        $priceAmount = $callbackData['price_amount'] ?? null;

        // If paymentStatus is present, it's an IPN/webhook callback
        if ($paymentStatus !== '') {
            $isPaid = in_array(strtolower($paymentStatus), ['finished', 'sending', 'confirmed'], true);
            $res = [
                'success'        => $isPaid,
                'gateway_trx_id' => (string) $paymentId,
                'status'         => $isPaid ? 'completed' : 'failed',
                'order_id'       => $orderId,
            ];
            if ($priceAmount !== null) {
                $res['amount'] = (string) $priceAmount;
            }
            return $res;
        }

        // Return pending status if no webhook payload is present (e.g. initial redirect)
        $trxId = $callbackData['trx_id'] ?? $callbackData['paymentID'] ?? '';
        return [
            'success'        => false,
            'gateway_trx_id' => '',
            'status'         => 'pending',
            'trx_id'         => $trxId,
        ];
    }

    public function verifyWebhook(string $rawBody, array $headers, array $credentials): bool
    {
        $receivedSignature = $headers['x-nowpayments-sig'] ?? '';
        if (empty($receivedSignature)) {
            return false;
        }

        $requestData = json_decode($rawBody, true);
        if ($requestData === null) {
            return false;
        }

        ksort($requestData);
        $sortedRequestJson = json_encode($requestData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $ipnSecret = $credentials['now_payment_ipn_secret'] ?? '';
        if (empty($ipnSecret)) {
            return false;
        }

        $expectedSignature = hash_hmac('sha512', (string) $sortedRequestJson, trim($ipnSecret));

        return hash_equals($receivedSignature, $expectedSignature);
    }
}
