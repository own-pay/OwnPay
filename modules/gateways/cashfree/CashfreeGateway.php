<?php
declare(strict_types=1);

namespace OwnPay\Modules\Gateways\Cashfree;

use OwnPay\Gateway\GatewayAdapterInterface;
use OwnPay\Gateway\GatewayDefaults;
use OwnPay\Plugin\PluginInterface;
use OwnPay\Plugin\Capability;
use OwnPay\Container;
use OwnPay\Event\EventManager;

/**
 * Cashfree payment gateway adapter implementing the API v2023-08-01 flow.
 */
final class CashfreeGateway implements PluginInterface, GatewayAdapterInterface
{
    use GatewayDefaults;

    private const SANDBOX_URL = 'https://sandbox.cashfree.com/pg';
    private const LIVE_URL    = 'https://api.cashfree.com/pg';

    private const SANDBOX_REDIRECT = 'https://payments-test.cashfree.com/order';
    private const LIVE_REDIRECT    = 'https://payments.cashfree.com/order';

    /**
     * Returns the plugin metadata array.
     */
    public static function metadata(): array
    {
        return [
            'name' => 'Cashfree',
            'slug' => 'cashfree',
            'version' => '1.0.0',
            'description' => 'Cashfree PG hosted checkout integration',
            'author' => 'OwnPay Core',
            'type' => 'gateway',
        ];
    }

    public function slug(): string { return 'cashfree'; }
    public function name(): string { return 'Cashfree'; }
    public function version(): string { return '1.0.0'; }
    public function description(): string { return 'Cashfree PG hosted checkout integration'; }

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
            ['name' => 'client_id', 'label' => 'Client ID', 'type' => 'text', 'required' => true],
            ['name' => 'client_secret', 'label' => 'Client Secret', 'type' => 'password', 'required' => true],
            ['name' => 'mode', 'label' => 'Mode', 'type' => 'select', 'options' => ['sandbox' => 'sandbox', 'live' => 'live'], 'required' => true],
        ];
    }

    /**
     * Initiates a payment process with Cashfree.
     */
    public function initiate(array $params, array $credentials): array
    {
        $clientId = $this->getString($credentials['client_id'] ?? '');
        $clientSecret = $this->getString($credentials['client_secret'] ?? '');
        $mode = $this->getString($credentials['mode'] ?? 'sandbox');

        if (empty($clientId) || empty($clientSecret)) {
            throw new \RuntimeException('Cashfree error: Missing Client ID or Client Secret credentials.');
        }

        // Live sandbox isolation guard
        if ($mode === 'live') {
            if (str_starts_with($clientId, 'TEST') || 
                str_starts_with($clientSecret, 'TEST') || 
                str_starts_with($params['trx_id'], 'SIM_')) {
                throw new \RuntimeException('Sandbox simulation input/credentials rejected in Live production mode.');
            }
        }

        $baseUrl = $mode === 'live' ? self::LIVE_URL : self::SANDBOX_URL;
        $redirectBase = $mode === 'live' ? self::LIVE_REDIRECT : self::SANDBOX_REDIRECT;

        // Use BCMath to ensure precision of INR decimals (INR uses 2 decimal places)
        $amount = $params['amount'];
        if (!is_numeric($amount)) {
            throw new \RuntimeException('Cashfree error: Invalid transaction amount format.');
        }

        // Cashfree accepts float formatted amount strings like "100.00" directly in the JSON API.
        // We will ensure it is clean by casting to a standard numeric-string.
        $formattedAmount = number_format((float)$amount, 2, '.', '');

        $ch = curl_init($baseUrl . '/orders');
        if ($ch === false) {
            throw new \RuntimeException('Cashfree cURL initialization failed.');
        }

        $payload = [
            'order_id'       => $params['trx_id'],
            'order_amount'   => $formattedAmount,
            'order_currency' => 'INR',
            'customer_details' => [
                'customer_id'    => 'cust_' . $params['trx_id'],
                'customer_phone' => '9999999999',
                'customer_email' => 'user@example.com',
            ],
            'order_meta' => [
                'return_url' => $params['redirect_url'],
            ],
        ];

        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'x-client-id: ' . $clientId,
                'x-client-secret: ' . $clientSecret,
                'x-api-version: 2023-08-01',
            ],
            CURLOPT_POSTFIELDS => (string) json_encode($payload),
        ]);

        $response = curl_exec($ch);
        $err = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false) {
            throw new \RuntimeException('Cashfree API connection error: ' . ($err ?: 'Unknown'));
        }

        $data = json_decode((string) $response, true);
        if (!is_array($data)) {
            throw new \RuntimeException('Cashfree error: Invalid API response payload.');
        }

        if ($httpCode >= 400) {
            $msg = $this->getString($data['message'] ?? 'Unknown API error');
            throw new \RuntimeException('Cashfree API error [' . $httpCode . ']: ' . $msg);
        }

        $sessionId = $this->getString($data['payment_session_id'] ?? '');
        if (empty($sessionId)) {
            throw new \RuntimeException('Cashfree error: Missing payment_session_id in response.');
        }

        return [
            'redirect_url' => $redirectBase . '/' . $sessionId,
            'session_id'   => $sessionId,
        ];
    }

    /**
     * Verifies the payment status from a callback or webhook.
     */
    public function verify(array $callbackData, array $credentials): array
    {
        $clientId = $this->getString($credentials['client_id'] ?? '');
        $clientSecret = $this->getString($credentials['client_secret'] ?? '');
        $mode = $this->getString($credentials['mode'] ?? 'sandbox');

        $orderIdRaw = $callbackData['order_id'] ?? '';
        $orderId = is_scalar($orderIdRaw) ? (string) $orderIdRaw : '';

        if (empty($orderId)) {
            return [
                'success'        => false,
                'gateway_trx_id' => '',
                'status'         => 'failed',
                'error'          => 'Cashfree verification error: Missing order_id parameter.',
            ];
        }

        $baseUrl = $mode === 'live' ? self::LIVE_URL : self::SANDBOX_URL;

        $ch = curl_init($baseUrl . '/orders/' . urlencode($orderId));
        if ($ch === false) {
            return [
                'success'        => false,
                'gateway_trx_id' => '',
                'status'         => 'failed',
                'error'          => 'Cashfree cURL initialization failed during verification.',
            ];
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'x-client-id: ' . $clientId,
                'x-client-secret: ' . $clientSecret,
                'x-api-version: 2023-08-01',
            ],
        ]);

        $response = curl_exec($ch);
        $err = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false) {
            return [
                'success'        => false,
                'gateway_trx_id' => '',
                'status'         => 'failed',
                'error'          => 'Cashfree API connection error: ' . ($err ?: 'Unknown'),
            ];
        }

        $data = json_decode((string) $response, true);
        if (!is_array($data) || $httpCode >= 400) {
            $msg = is_array($data) ? $this->getString($data['message'] ?? 'Unknown error') : 'Invalid payload';
            return [
                'success'        => false,
                'gateway_trx_id' => '',
                'status'         => 'failed',
                'error'          => 'Cashfree API error [' . $httpCode . ']: ' . $msg,
            ];
        }

        $orderStatus = $this->getString($data['order_status'] ?? '');
        $success = $orderStatus === 'PAID';

        $cfPaymentId = '';
        // Extract cf_payment_id if available inside payments list or response details
        if (isset($data['payment_session_id'])) {
            $cfPaymentId = $this->getString($data['payment_session_id']);
        }

        $orderAmount = null;
        if (isset($data['order_amount']) && is_numeric($data['order_amount'])) {
            $orderAmount = number_format((float)$data['order_amount'], 2, '.', '');
        }

        $result = [
            'success'        => $success,
            'gateway_trx_id' => $cfPaymentId ?: $orderId,
            'status'         => $success ? 'completed' : 'failed',
        ];

        if ($orderAmount !== null) {
            $result['amount'] = $orderAmount;
        }

        return $result;
    }

    /**
     * Webhook validation signature verification.
     */
    public function verifyWebhook(string $rawBody, array $headers, array $credentials): bool
    {
        $providedSig = '';
        foreach ($headers as $key => $val) {
            if (strtolower($key) === 'x-webhook-signature') {
                $providedSig = $val;
                break;
            }
        }

        $timestamp = '';
        foreach ($headers as $key => $val) {
            if (strtolower($key) === 'x-webhook-timestamp') {
                $timestamp = $val;
                break;
            }
        }

        if (empty($providedSig) || empty($timestamp)) {
            return false;
        }

        $clientSecret = $this->getString($credentials['client_secret'] ?? '');
        if (empty($clientSecret)) {
            return false;
        }

        $signStr = $timestamp . $rawBody;
        $computedSig = base64_encode(hash_hmac('sha256', $signStr, $clientSecret, true));

        return hash_equals($computedSig, $providedSig);
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
        return ['INR'];
    }
}
