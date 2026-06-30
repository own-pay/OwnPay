<?php
declare(strict_types=1);

namespace OwnPay\Modules\Gateways\Instamojo;

use OwnPay\Gateway\GatewayAdapterInterface;
use OwnPay\Gateway\GatewayDefaults;
use OwnPay\Plugin\PluginInterface;
use OwnPay\Plugin\Capability;
use OwnPay\Container;
use OwnPay\Event\EventManager;

/**
 * Instamojo payment gateway adapter implementing the OAuth2 and Payment Requests API.
 */
final class InstamojoGateway implements PluginInterface, GatewayAdapterInterface
{
    use GatewayDefaults;

    private const SANDBOX_URL = 'https://test.instamojo.com';
    private const LIVE_URL    = 'https://api.instamojo.com';

    /**
     * @var array<string, array{token: string, expires_at: int}>
     */
    private static array $tokenCache = [];

    /**
     * Returns the plugin metadata array.
     */
    public static function metadata(): array
    {
        return [
            'name' => 'Instamojo',
            'slug' => 'instamojo',
            'version' => '1.0.0',
            'description' => 'Instamojo secure payment request API integration',
            'author' => 'OwnPay Core',
            'type' => 'gateway',
        ];
    }

    public function slug(): string { return 'instamojo'; }
    public function name(): string { return 'Instamojo'; }
    public function version(): string { return '1.0.0'; }
    public function description(): string { return 'Instamojo secure payment request API integration'; }

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
            ['name' => 'salt', 'label' => 'Secret Salt (Salt from Developer Profile)', 'type' => 'password', 'required' => true],
            ['name' => 'mode', 'label' => 'Mode', 'type' => 'select', 'options' => ['sandbox' => 'sandbox', 'live' => 'live'], 'required' => true],
        ];
    }

    /**
     * Initiates a payment process with Instamojo.
     */
    public function initiate(array $params, array $credentials): array
    {
        $clientId = $this->getString($credentials['client_id'] ?? '');
        $clientSecret = $this->getString($credentials['client_secret'] ?? '');
        $mode = $this->getString($credentials['mode'] ?? 'sandbox');

        if (empty($clientId) || empty($clientSecret)) {
            throw new \RuntimeException('Instamojo error: Missing Client ID or Client Secret.');
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
        $token = $this->getAccessToken($baseUrl, $clientId, $clientSecret);

        // INR formatted amount string
        $amount = $params['amount'];
        if (!is_numeric($amount)) {
            throw new \RuntimeException('Instamojo error: Invalid transaction amount format.');
        }
        $formattedAmount = number_format((float)$amount, 2, '.', '');

        $ch = curl_init($baseUrl . '/v2/payment-requests/');
        if ($ch === false) {
            throw new \RuntimeException('Instamojo cURL initialization failed.');
        }

        $payload = [
            'amount'                  => $formattedAmount,
            'purpose'                 => 'Order Payment ' . $params['trx_id'],
            'buyer_name'              => 'OwnPay Customer',
            'email'                   => 'customer@ownpay.test',
            'phone'                   => '9999999999',
            'redirect_url'            => $params['redirect_url'],
            'webhook'                 => $params['redirect_url'],
            'allow_repeated_payments' => false,
        ];

        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $token,
            ],
            CURLOPT_POSTFIELDS => (string) json_encode($payload),
        ]);

        $response = curl_exec($ch);
        $err = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false) {
            throw new \RuntimeException('Instamojo API connection error: ' . ($err ?: 'Unknown'));
        }

        $data = json_decode((string) $response, true);
        if (!is_array($data)) {
            throw new \RuntimeException('Instamojo error: Invalid API response payload.');
        }

        if ($httpCode >= 400) {
            $msg = $this->getString($data['message'] ?? 'Unknown API error');
            if (isset($data['error']) && is_scalar($data['error'])) {
                $msg = (string) $data['error'];
            }
            throw new \RuntimeException('Instamojo API error [' . $httpCode . ']: ' . $msg);
        }

        $checkoutUrl = $this->getString($data['longurl'] ?? '');
        $requestId = $this->getString($data['id'] ?? '');

        if (empty($checkoutUrl)) {
            throw new \RuntimeException('Instamojo error: Missing longurl redirect in response.');
        }

        return [
            'redirect_url' => $checkoutUrl,
            'session_id'   => $requestId,
        ];
    }

    /**
     * Verifies the payment status from a callback or webhook.
     */
    public function verify(array $callbackData, array $credentials): array
    {
        $clientId = $this->getString($credentials['client_id'] ?? '');
        $clientSecret = $this->getString($credentials['client_secret'] ?? '');
        $salt = $this->getString($credentials['salt'] ?? '');
        $mode = $this->getString($credentials['mode'] ?? 'sandbox');

        $paymentIdRaw = $callbackData['payment_id'] ?? '';
        $paymentId = is_scalar($paymentIdRaw) ? (string) $paymentIdRaw : '';
        $mac = $this->getString($callbackData['mac'] ?? '');

        if (empty($paymentId)) {
            return [
                'success'        => false,
                'gateway_trx_id' => '',
                'status'         => 'failed',
                'error'          => 'Instamojo verification error: Missing payment_id parameter.',
            ];
        }

        // 1. If webhook MAC is present, we check signature first
        if ($mac !== '' && $salt !== '') {
            $webhookData = $callbackData;
            unset($webhookData['mac']);

            // ksort alphabetically case-insensitive
            ksort($webhookData, SORT_STRING | SORT_FLAG_CASE);
            $stringValues = array_map(fn($v) => is_scalar($v) ? (string)$v : '', $webhookData);
            $message = implode('|', $stringValues);
            $macCalculated = hash_hmac('sha1', $message, $salt);

            if (!hash_equals($macCalculated, $mac)) {
                return [
                    'success'        => false,
                    'gateway_trx_id' => '',
                    'status'         => 'failed',
                    'error'          => 'Instamojo verification error: Webhook MAC signature mismatch.',
                ];
            }
        }

        // 2. Lookup payment details via Server-to-Server API
        $baseUrl = $mode === 'live' ? self::LIVE_URL : self::SANDBOX_URL;
        try {
            $token = $this->getAccessToken($baseUrl, $clientId, $clientSecret);
        } catch (\Throwable $e) {
            return [
                'success'        => false,
                'gateway_trx_id' => '',
                'status'         => 'failed',
                'error'          => 'Instamojo token acquisition failed: ' . $e->getMessage(),
            ];
        }

        $ch = curl_init($baseUrl . '/v2/payments/' . urlencode($paymentId) . '/');
        if ($ch === false) {
            return [
                'success'        => false,
                'gateway_trx_id' => '',
                'status'         => 'failed',
                'error'          => 'Instamojo cURL initialization failed during verification.',
            ];
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $token,
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
                'error'          => 'Instamojo API connection error: ' . ($err ?: 'Unknown'),
            ];
        }

        $data = json_decode((string) $response, true);
        if (!is_array($data) || $httpCode >= 400) {
            $msg = is_array($data) ? $this->getString($data['message'] ?? 'Unknown error') : 'Invalid payload';
            return [
                'success'        => false,
                'gateway_trx_id' => '',
                'status'         => 'failed',
                'error'          => 'Instamojo API error [' . $httpCode . ']: ' . $msg,
            ];
        }

        $statusStr = $this->getString($data['status'] ?? '');
        $amountStr = $this->getString($data['amount'] ?? '');
        $success = $statusStr === 'Credit' || $statusStr === 'completed' || ($this->getBool($data['success'] ?? false) && $statusStr !== 'Failed');

        $formattedAmount = null;
        if (is_numeric($amountStr)) {
            $formattedAmount = number_format((float)$amountStr, 2, '.', '');
        }

        $result = [
            'success'        => $success,
            'gateway_trx_id' => $paymentId,
            'status'         => $success ? 'completed' : 'failed',
        ];

        if ($formattedAmount !== null) {
            $result['amount'] = $formattedAmount;
        }

        return $result;
    }

    /**
     * Webhook validation MAC signature verification helper.
     */
    public function verifyWebhook(string $rawBody, array $headers, array $credentials): bool
    {
        $salt = $this->getString($credentials['salt'] ?? '');
        if (empty($salt)) {
            return false;
        }

        // Decode standard urlencoded or JSON webhook parameters from Instamojo
        parse_str($rawBody, $data);
        if (empty($data) || !isset($data['mac'])) {
            $data = json_decode($rawBody, true);
            if (!is_array($data) || !isset($data['mac'])) {
                return false;
            }
        }

        $providedMac = $this->getString($data['mac']);
        unset($data['mac']);

        // Alphabetical ksort sorting, case-insensitive
        ksort($data, SORT_STRING | SORT_FLAG_CASE);
        $stringValues = array_map(fn($v) => is_scalar($v) ? (string)$v : '', $data);
        $message = implode('|', $stringValues);
        $macCalculated = hash_hmac('sha1', $message, $salt);

        return hash_equals($macCalculated, $providedMac);
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

    /**
     * Handles dynamic access token generation and caching.
     */
    private function getAccessToken(string $baseUrl, string $clientId, string $clientSecret): string
    {
        $cacheKey = $baseUrl . ':' . $clientId;
        if (isset(self::$tokenCache[$cacheKey])) {
            $cached = self::$tokenCache[$cacheKey];
            if ($cached['expires_at'] > time()) {
                return $cached['token'];
            }
            unset(self::$tokenCache[$cacheKey]);
        }

        $ch = curl_init($baseUrl . '/oauth2/token/');
        if ($ch === false) {
            throw new \RuntimeException('Instamojo OAuth2 cURL initialization failed.');
        }

        $postfields = 'grant_type=client_credentials&client_id=' . urlencode($clientId) . '&client_secret=' . urlencode($clientSecret);

        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/x-www-form-urlencoded',
            ],
            CURLOPT_POSTFIELDS => $postfields,
        ]);

        $response = curl_exec($ch);
        $err = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false) {
            throw new \RuntimeException('Instamojo OAuth2 Token connection error: ' . ($err ?: 'Unknown'));
        }

        $data = json_decode((string) $response, true);
        if (!is_array($data) || $httpCode >= 400) {
            $msg = is_array($data) ? $this->getString($data['error_description'] ?? 'Unknown OAuth error') : 'Invalid payload';
            throw new \RuntimeException('Instamojo OAuth2 Error [' . $httpCode . ']: ' . $msg);
        }

        $token = $this->getString($data['access_token'] ?? '');
        $expires = $this->getInt($data['expires_in'] ?? 36000);

        if (empty($token)) {
            throw new \RuntimeException('Instamojo OAuth2 Error: Missing access_token in response.');
        }

        self::$tokenCache[$cacheKey] = [
            'token'      => $token,
            'expires_at' => time() + $expires - 60, // Safety margin
        ];

        return $token;
    }
}
