<?php
declare(strict_types=1);

namespace OwnPay\Modules\Gateways\PaypalCheckout;

use OwnPay\Gateway\GatewayAdapterInterface;
use OwnPay\Gateway\GatewayDefaults;
use OwnPay\Plugin\PluginInterface;
use OwnPay\Plugin\Capability;
use OwnPay\Container;
use OwnPay\Event\EventManager;

/**
 * PayPal Checkout Gateway — PluginInterface + GatewayAdapterInterface.
 */
final class PaypalCheckoutGateway implements PluginInterface, GatewayAdapterInterface
{
    use GatewayDefaults;

    public static function metadata(): array
    {
        return [
            'name'        => 'PayPal Checkout',
            'slug'        => 'paypal-checkout',
            'version'     => '1.0.0',
            'description' => 'Accept PayPal Checkout payments directly from customers.',
            'author'      => 'OwnPay Core',
            'type'        => 'gateway',
        ];
    }

    public function slug(): string { return 'paypal-checkout'; }
    public function name(): string { return 'PayPal Checkout'; }
    public function version(): string { return '1.0.0'; }
    public function description(): string { return 'Accept PayPal Checkout payments directly from customers.'; }

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
                'name'     => 'paypal_client_id',
                'label'    => 'PayPal Client ID',
                'type'     => 'text',
                'required' => true
            ],
            [
                'name'     => 'paypal_secret',
                'label'    => 'PayPal Secret',
                'type'     => 'text',
                'required' => true
            ],
            [
                'name'     => 'paypal_mode',
                'label'    => 'Mode',
                'type'     => 'select',
                'options'  => ['sandbox' => 'sandbox', 'live' => 'live'],
                'required' => true
            ],
        ];
    }

    public function initiate(array $params, array $credentials): array
    {
        $clientId = $credentials['paypal_client_id'] ?? '';
        $secret = $credentials['paypal_secret'] ?? '';
        $mode = $credentials['paypal_mode'] ?? 'sandbox';

        $accessToken = $this->getAccessToken($clientId, $secret, $mode);
        if (!$accessToken) {
            throw new \RuntimeException('PayPal Authentication failed: Unable to retrieve access token.');
        }

        $baseUrl = $mode === 'live' ? 'https://api-m.paypal.com' : 'https://api-m.sandbox.paypal.com';
        $url = $baseUrl . '/v2/checkout/orders';

        $redirectUrl = $params['redirect_url'] ?? '';
        $cancelUrl = $params['cancel_url'] ?? '';
        $amount = number_format((float) $params['amount'], 2, '.', '');
        $currency = strtoupper($params['currency'] ?? 'USD');

        $orderData = [
            'intent' => 'CAPTURE',
            'purchase_units' => [
                [
                    'amount' => [
                        'currency_code' => $currency,
                        'value' => $amount
                    ]
                ]
            ],
            'application_context' => [
                'return_url' => $redirectUrl,
                'cancel_url' => $cancelUrl
            ]
        ];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $accessToken
            ],
            CURLOPT_POSTFIELDS => json_encode($orderData),
        ]);

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if (($httpCode !== 200 && $httpCode !== 201) || !$response) {
            $errData = json_decode((string) $response, true);
            $errMsg = $errData['message'] ?? 'HTTP ' . $httpCode;
            throw new \RuntimeException('PayPal Order Creation Error: ' . $errMsg);
        }

        $result = json_decode($response, true);
        $orderId = $result['id'] ?? '';
        
        $approvalUrl = '';
        if (isset($result['links']) && is_array($result['links'])) {
            foreach ($result['links'] as $link) {
                if (($link['rel'] ?? '') === 'approve') {
                    $approvalUrl = $link['href'] ?? '';
                    break;
                }
            }
        }

        if (empty($approvalUrl)) {
            throw new \RuntimeException('PayPal Response has missing approval URL');
        }

        return [
            'redirect_url' => $approvalUrl,
            'session_id'   => $orderId,
        ];
    }

    public function verify(array $callbackData, array $credentials): array
    {
        $token = $callbackData['token'] ?? null;
        if (empty($token)) {
            return [
                'success'        => false,
                'gateway_trx_id' => null,
                'amount'         => null,
                'status'         => 'pending',
                'order_id'       => null,
            ];
        }

        $clientId = $credentials['paypal_client_id'] ?? '';
        $secret = $credentials['paypal_secret'] ?? '';
        $mode = $credentials['paypal_mode'] ?? 'sandbox';

        $accessToken = $this->getAccessToken($clientId, $secret, $mode);
        if (!$accessToken) {
            return [
                'success'        => false,
                'gateway_trx_id' => null,
                'amount'         => null,
                'status'         => 'failed',
                'order_id'       => $token,
            ];
        }

        $baseUrl = $mode === 'live' ? 'https://api-m.paypal.com' : 'https://api-m.sandbox.paypal.com';
        
        // 1. Attempt to capture the order
        $url = $baseUrl . '/v2/checkout/orders/' . urlencode($token) . '/capture';
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $accessToken
            ],
            CURLOPT_POSTFIELDS => '',
        ]);

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $result = json_decode((string) $response, true);
        $status = $result['status'] ?? '';

        // 2. If capture failed/already captured, query the order status to verify
        if (($httpCode !== 200 && $httpCode !== 201) || $status !== 'COMPLETED') {
            $queryUrl = $baseUrl . '/v2/checkout/orders/' . urlencode($token);
            $ch = curl_init($queryUrl);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 15,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false,
                CURLOPT_HTTPHEADER     => [
                    'Content-Type: application/json',
                    'Authorization: Bearer ' . $accessToken
                ],
            ]);
            $response = curl_exec($ch);
            curl_close($ch);

            $result = json_decode((string) $response, true);
            $status = $result['status'] ?? '';
        }

        if ($status === 'COMPLETED') {
            $capture = null;
            if (isset($result['purchase_units'][0]['payments']['captures'][0])) {
                $capture = $result['purchase_units'][0]['payments']['captures'][0];
            }

            $gatewayTrxId = $capture['id'] ?? $token;
            $amount = $capture['amount']['value'] ?? null;

            return [
                'success'        => true,
                'gateway_trx_id' => (string) $gatewayTrxId,
                'amount'         => $amount !== null ? (string) $amount : null,
                'status'         => 'completed',
                'order_id'       => $token,
            ];
        }

        return [
            'success'        => false,
            'gateway_trx_id' => null,
            'amount'         => null,
            'status'         => 'failed',
            'order_id'       => $token,
        ];
    }

    public function verifyWebhook(string $rawBody, array $headers, array $credentials): bool
    {
        // PayPal Webhook validation typically involves standard verification or query,
        // but IPNs are handled primarily via redirect capture in this integration.
        return false;
    }

    private function getAccessToken(string $clientId, string $secret, string $mode): ?string
    {
        $baseUrl = $mode === 'live' ? 'https://api-m.paypal.com' : 'https://api-m.sandbox.paypal.com';
        $url = $baseUrl . '/v1/oauth2/token';

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_USERPWD        => $clientId . ':' . $secret,
            CURLOPT_POSTFIELDS     => 'grant_type=client_credentials',
            CURLOPT_HTTPHEADER     => [
                'Accept: application/json',
                'Accept-Language: en_US'
            ],
        ]);

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || !$response) {
            return null;
        }

        $result = json_decode($response, true);
        return $result['access_token'] ?? null;
    }
}
