<?php
declare(strict_types=1);

namespace OwnPay\Modules\Gateways\Shurjopay;

use OwnPay\Gateway\GatewayAdapterInterface;
use OwnPay\Gateway\GatewayDefaults;
use OwnPay\Plugin\PluginInterface;
use OwnPay\Plugin\Capability;
use OwnPay\Container;
use OwnPay\Event\EventManager;

/**
 * shurjoPay Gateway - PluginInterface + GatewayAdapterInterface.
 */
final class ShurjopayGateway implements PluginInterface, GatewayAdapterInterface
{
    use GatewayDefaults;

    public static function metadata(): array
    {
        return [
            'name'        => 'shurjoPay',
            'slug'        => 'shurjopay',
            'version'     => '1.0.0',
            'description' => 'Accept shurjoPay payments directly from customers.',
            'author'      => 'OwnPay Core',
            'type'        => 'gateway',
        ];
    }

    public function slug(): string { return 'shurjopay'; }
    public function name(): string { return 'shurjoPay'; }
    public function version(): string { return '1.0.0'; }
    public function description(): string { return 'Accept shurjoPay payments directly from customers.'; }

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
                'name'     => 'prefix',
                'label'    => 'Transaction Prefix',
                'type'     => 'text',
                'required' => true
            ],
            [
                'name'     => 'username',
                'label'    => 'Username',
                'type'     => 'text',
                'required' => true
            ],
            [
                'name'     => 'password',
                'label'    => 'Password',
                'type'     => 'text',
                'required' => true
            ],
            [
                'name'     => 'store_mode',
                'label'    => 'Mode',
                'type'     => 'select',
                'options'  => ['sandbox' => 'sandbox', 'live' => 'live'],
                'required' => true
            ],
        ];
    }

    public function initiate(array $params, array $credentials): array
    {
        $mode = $credentials['store_mode'] ?? 'sandbox';
        $baseUrl = $mode === 'live' ? 'https://engine.shurjopayment.com' : 'https://sandbox.shurjopayment.com';

        $username = is_scalar($credentials['username'] ?? null) ? (string) $credentials['username'] : '';
        $password = is_scalar($credentials['password'] ?? null) ? (string) $credentials['password'] : '';
        $prefix = is_scalar($credentials['prefix'] ?? null) ? (string) $credentials['prefix'] : '';

        $tokenData = $this->getToken($username, $password, $baseUrl);
        $tokenRaw = $tokenData['token'] ?? '';
        $token = is_scalar($tokenRaw) ? (string) $tokenRaw : '';
        if ($token === '') {
            throw new \RuntimeException('shurjoPay Authentication failed: Unable to retrieve token.');
        }

        $storeIdRaw = $tokenData['store_id'] ?? '';
        $storeId = is_scalar($storeIdRaw) ? (string) $storeIdRaw : '';

        $trxId = $params['trx_id'];
        $amount = number_format((float) $params['amount'], 2, '.', '');
        $redirectUrl = $params['redirect_url'];
        $cancelUrl = $params['cancel_url'];

        $separator = (strpos($redirectUrl, '?') !== false) ? '&' : '?';
        $shurjopayReturnUrl = $redirectUrl . $separator . 'status=success';
        $shurjopayCancelUrl = $cancelUrl . $separator . 'status=cancel';

        $postFields = [
            'prefix'                => $prefix,
            'token'                 => $token,
            'return_url'            => $shurjopayReturnUrl,
            'cancel_url'            => $shurjopayCancelUrl,
            'store_id'              => $storeId,
            'amount'                => $amount,
            'order_id'              => $trxId,
            'currency'              => 'BDT',
            'customer_name'         => $params['metadata']['customer_name'] ?? 'Customer',
            'customer_address'      => 'Bangladesh',
            'customer_phone'        => $params['metadata']['customer_phone'] ?? '01700000000',
            'customer_city'         => 'Dhaka',
            'client_ip'             => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
            'discount_amount'       => '0',
            'disc_percent'          => '0',
            'customer_email'        => $params['metadata']['customer_email'] ?? 'customer@example.com',
            'customer_state'        => 'Dhaka',
            'customer_postcode'     => '1000',
            'customer_country'      => 'BD',
            'shipping_address'      => '',
            'shipping_city'         => '',
            'shipping_country'      => '',
            'received_person_name'  => '',
            'shipping_phone_number' => $params['metadata']['customer_phone'] ?? '01700000000'
        ];

        $ch = curl_init($baseUrl . '/api/secret-pay');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $token
            ],
            CURLOPT_POSTFIELDS     => $postFields,
        ]);

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if (($httpCode !== 200 && $httpCode !== 201) || !$response) {
            throw new \RuntimeException('shurjoPay API Error: HTTP ' . $httpCode);
        }

        $result = json_decode((string) $response, true);
        if (!is_array($result) || empty($result['checkout_url']) || !is_string($result['checkout_url'])) {
            $errMsg = (is_array($result) && isset($result['message']) && is_scalar($result['message'])) ? (string) $result['message'] : 'Missing checkout URL';
            throw new \RuntimeException('shurjoPay Initiation Error: ' . $errMsg);
        }

        return [
            'redirect_url' => $result['checkout_url'],
            'session_id'   => $trxId,
        ];
    }

    public function verify(array $callbackData, array $credentials): array
    {
        $statusRaw = isset($callbackData['status']) && is_scalar($callbackData['status']) ? (string) $callbackData['status'] : '';
        $orderIdRaw = null;
        $status = $statusRaw;

        // Shurjopay appends "?order_id=..." or similar to the returned query parameter
        if (strpos($statusRaw, '?order_id=') !== false) {
            list($status, $orderIdRaw) = explode('?order_id=', $statusRaw, 2);
        } elseif (isset($callbackData['order_id'])) {
            $orderIdRaw = $callbackData['order_id'];
        }

        if (empty($orderIdRaw)) {
            // Also check standard raw GET parameter from Shurjopay redirection
            $orderIdRaw = $_GET['order_id'] ?? null;
        }

        $orderId = is_scalar($orderIdRaw) ? (string) $orderIdRaw : '';

        if ($orderId === '') {
            return [
                'success'        => false,
                'gateway_trx_id' => '',
                'status'         => 'pending',
                'order_id'       => null,
            ];
        }

        if ($status !== 'success') {
            return [
                'success'        => false,
                'gateway_trx_id' => '',
                'status'         => 'failed',
                'order_id'       => $orderId,
            ];
        }

        $mode = $credentials['store_mode'] ?? 'sandbox';
        $baseUrl = $mode === 'live' ? 'https://engine.shurjopayment.com' : 'https://sandbox.shurjopayment.com';

        $username = is_scalar($credentials['username'] ?? null) ? (string) $credentials['username'] : '';
        $password = is_scalar($credentials['password'] ?? null) ? (string) $credentials['password'] : '';

        $tokenData = $this->getToken($username, $password, $baseUrl);
        $tokenRaw = $tokenData['token'] ?? '';
        $token = is_scalar($tokenRaw) ? (string) $tokenRaw : '';
        if ($token === '') {
            return [
                'success'        => false,
                'gateway_trx_id' => '',
                'status'         => 'failed',
                'order_id'       => $orderId,
            ];
        }

        $ch = curl_init($baseUrl . '/api/verification');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $token,
                'Content-Type: application/json'
            ],
            CURLOPT_POSTFIELDS     => (string) json_encode(['order_id' => $orderId]),
        ]);

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || !$response) {
            return [
                'success'        => false,
                'gateway_trx_id' => '',
                'status'         => 'failed',
                'order_id'       => $orderId,
            ];
        }

        $resultList = json_decode((string) $response, true);
        $data = null;
        if (is_array($resultList)) {
            $data = $resultList[0] ?? null;
        }

        if (is_array($data) && isset($data['bank_status']) && is_scalar($data['bank_status']) && strtolower((string)$data['bank_status']) === 'success') {
            $gatewayTrxId = isset($data['bank_trx_id']) && is_scalar($data['bank_trx_id']) ? (string) $data['bank_trx_id'] : $orderId;
            $amount = $data['amount'] ?? null;

            $res = [
                'success'        => true,
                'gateway_trx_id' => (string) $gatewayTrxId,
                'status'         => 'completed',
                'order_id'       => $orderId,
            ];
            if ($amount !== null && is_scalar($amount)) {
                $res['amount'] = (string) $amount;
            }
            return $res;
        }

        return [
            'success'        => false,
            'gateway_trx_id' => '',
            'status'         => 'failed',
            'order_id'       => $orderId,
        ];
    }

    public function verifyWebhook(string $rawBody, array $headers, array $credentials): bool
    {
        return false;
    }

    /** @return array<string, mixed>|null */
    private function getToken(string $username, string $password, string $baseUrl): ?array
    {
        $ch = curl_init($baseUrl . '/api/get_token');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json'
            ],
            CURLOPT_POSTFIELDS     => (string) json_encode([
                'username' => $username,
                'password' => $password
            ]),
        ]);

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || !$response) {
            return null;
        }

        $decoded = json_decode((string) $response, true);
        if (!is_array($decoded)) {
            return null;
        }
        $tokenData = [];
        foreach ($decoded as $key => $value) {
            $tokenData[(string) $key] = $value;
        }
        return $tokenData;
    }
}
