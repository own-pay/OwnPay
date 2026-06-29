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
 * PayPal Checkout Gateway - PluginInterface + GatewayAdapterInterface.
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
        $clientIdRaw = $credentials['paypal_client_id'] ?? '';
        $clientId = is_scalar($clientIdRaw) ? (string) $clientIdRaw : '';
        $secretRaw = $credentials['paypal_secret'] ?? '';
        $secret = is_scalar($secretRaw) ? (string) $secretRaw : '';
        $modeRaw = $credentials['paypal_mode'] ?? 'sandbox';
        $mode = is_scalar($modeRaw) ? (string) $modeRaw : 'sandbox';

        $accessToken = $this->getAccessToken($clientId, $secret, $mode);
        if (!$accessToken) {
            throw new \RuntimeException('PayPal Authentication failed: Unable to retrieve access token.');
        }

        $baseUrl = $mode === 'live' ? 'https://api-m.paypal.com' : 'https://api-m.sandbox.paypal.com';
        $url = $baseUrl . '/v2/checkout/orders';

        $redirectUrl = $params['redirect_url'];
        $cancelUrl = $params['cancel_url'];
        $amount = number_format((float) $params['amount'], 2, '.', '');
        $currency = strtoupper($params['currency']);

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
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: ' . $accessToken
            ],
            CURLOPT_POSTFIELDS => (string) json_encode($orderData),
        ]);

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if (($httpCode !== 200 && $httpCode !== 201) || !$response) {
            $errData = json_decode((string) $response, true);
            $errMsg = (is_array($errData) && isset($errData['message']) && is_scalar($errData['message'])) ? (string) $errData['message'] : 'HTTP ' . $httpCode;
            throw new \RuntimeException('PayPal Order Creation Error: ' . $errMsg);
        }

        $result = json_decode((string) $response, true);
        if (!is_array($result)) {
            throw new \RuntimeException('PayPal Order Creation Error: Invalid Response');
        }
        $orderId = isset($result['id']) && is_scalar($result['id']) ? (string) $result['id'] : '';
        
        $approvalUrl = '';
        if (isset($result['links']) && is_array($result['links'])) {
            foreach ($result['links'] as $link) {
                if (is_array($link) && isset($link['rel']) && is_scalar($link['rel']) && (string)$link['rel'] === 'approve') {
                    $approvalUrl = isset($link['href']) && is_scalar($link['href']) ? (string)$link['href'] : '';
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
        $tokenRaw = $callbackData['token'] ?? null;
        $token = is_scalar($tokenRaw) ? (string)$tokenRaw : '';
        if (empty($token)) {
            return [
                'success'        => false,
                'gateway_trx_id' => '',
                'status'         => 'pending',
                'order_id'       => null,
            ];
        }

        $clientIdRaw = $credentials['paypal_client_id'] ?? '';
        $clientId = is_scalar($clientIdRaw) ? (string) $clientIdRaw : '';
        $secretRaw = $credentials['paypal_secret'] ?? '';
        $secret = is_scalar($secretRaw) ? (string) $secretRaw : '';
        $modeRaw = $credentials['paypal_mode'] ?? 'sandbox';
        $mode = is_scalar($modeRaw) ? (string) $modeRaw : 'sandbox';

        $accessToken = $this->getAccessToken($clientId, $secret, $mode);
        if (!$accessToken) {
            return [
                'success'        => false,
                'gateway_trx_id' => '',
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
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
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
        $status = '';
        if (is_array($result) && isset($result['status']) && is_scalar($result['status'])) {
            $status = (string) $result['status'];
        }

        // 2. If capture failed/already captured, query the order status to verify
        if (($httpCode !== 200 && $httpCode !== 201) || $status !== 'COMPLETED') {
            $queryUrl = $baseUrl . '/v2/checkout/orders/' . urlencode($token);
            $ch = curl_init($queryUrl);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 15,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_HTTPHEADER     => [
                    'Content-Type: application/json',
                    'Authorization: Bearer ' . $accessToken
                ],
            ]);
            $response = curl_exec($ch);
            curl_close($ch);

            $result = json_decode((string) $response, true);
            $status = '';
            if (is_array($result) && isset($result['status']) && is_scalar($result['status'])) {
                $status = (string) $result['status'];
            }
        }

        if ($status === 'COMPLETED' && is_array($result)) {
            $purchaseUnits = $result['purchase_units'] ?? null;
            $firstPurchaseUnit = is_array($purchaseUnits) ? ($purchaseUnits[0] ?? null) : null;
            $payments = is_array($firstPurchaseUnit) ? ($firstPurchaseUnit['payments'] ?? null) : null;
            $captures = is_array($payments) ? ($payments['captures'] ?? null) : null;
            $capture = is_array($captures) ? ($captures[0] ?? null) : null;

            $gatewayTrxId = (is_array($capture) && isset($capture['id']) && is_scalar($capture['id'])) ? (string)$capture['id'] : $token;
            $amount = null;
            if (is_array($capture) && isset($capture['amount']) && is_array($capture['amount']) && isset($capture['amount']['value']) && is_scalar($capture['amount']['value'])) {
                $amount = (string)$capture['amount']['value'];
            }

            $res = [
                'success'        => true,
                'gateway_trx_id' => (string) $gatewayTrxId,
                'status'         => 'completed',
                'order_id'       => $token,
            ];
            if ($amount !== null) {
                $res['amount'] = (string) $amount;
            }
            return $res;
        }

        return [
            'success'        => false,
            'gateway_trx_id' => '',
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
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
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

        $result = json_decode((string) $response, true);
        $accessToken = '';
        if (is_array($result) && isset($result['access_token']) && is_scalar($result['access_token'])) {
            $accessToken = (string) $result['access_token'];
        }
        return $accessToken !== '' ? $accessToken : null;
    }
}
