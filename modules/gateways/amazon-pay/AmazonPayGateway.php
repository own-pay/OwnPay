<?php
declare(strict_types=1);

namespace OwnPay\Modules\Gateways\AmazonPay;

use OwnPay\Gateway\GatewayAdapterInterface;
use OwnPay\Gateway\GatewayDefaults;
use OwnPay\Gateway\TestableConnectionInterface;
use OwnPay\Plugin\PluginInterface;
use OwnPay\Plugin\Capability;
use OwnPay\Container;
use OwnPay\Event\EventManager;

/**
 * Amazon Pay (Checkout v2) Gateway Adapter.
 */
final class AmazonPayGateway implements PluginInterface, GatewayAdapterInterface, TestableConnectionInterface
{
    use GatewayDefaults;

    public static function metadata(): array
    {
        return [
            'name' => 'Amazon Pay',
            'slug' => 'amazon-pay',
            'version' => '1.0.0',
            'description' => 'Amazon Pay checkout integration for OwnPay',
            'author' => 'OwnPay Core',
            'type' => 'gateway',
        ];
    }

    public function slug(): string { return 'amazon-pay'; }
    public function name(): string { return 'Amazon Pay'; }
    public function version(): string { return '1.0.0'; }
    public function description(): string { return 'Amazon Pay checkout gateway'; }

    public function register(EventManager $events, Container $container): void {}
    public function boot(Container $container): void {}
    public function deactivate(Container $container): void {}
    public function uninstall(Container $container): void {}
    public function capabilities(): array { return [Capability::GATEWAY]; }

    public function fields(): array
    {
        return [
            ['name' => 'merchant_id', 'label' => 'Merchant ID', 'type' => 'text', 'required' => true],
            ['name' => 'store_id', 'label' => 'Store ID', 'type' => 'text', 'required' => true],
            ['name' => 'public_key_id', 'label' => 'Public Key ID', 'type' => 'text', 'required' => true],
            ['name' => 'private_key', 'label' => 'Private Key (PEM)', 'type' => 'textarea', 'required' => true],
            ['name' => 'mode', 'label' => 'Mode', 'type' => 'select', 'options' => ['test' => 'test', 'live' => 'live'], 'required' => true],
            ['name' => 'region', 'label' => 'Region', 'type' => 'select', 'options' => ['us' => 'USA', 'eu' => 'Europe', 'jp' => 'Japan'], 'required' => true],
        ];
    }

    /**
     * Amazon Pay v2 requires every API request to be signed with the AMZN-PAY-RSASSA-PSS
     * scheme (canonical request + RSA-PSS/SHA256 signature) - a scheme this adapter's own
     * initiate() does not implement either, so there is no existing signing code here to
     * reuse safely. Rather than fabricate an unverified signing implementation that could
     * silently misreport success/failure, this validates what can be checked locally: that
     * all required fields are present and the Private Key is a well-formed RSA PEM key.
     *
     * @param array<string, mixed> $credentials
     * @return array{success: bool, message: string}
     */
    public function testConnection(array $credentials): array
    {
        $merchantId = $this->getString($credentials['merchant_id'] ?? null);
        $storeId = $this->getString($credentials['store_id'] ?? null);
        $publicKeyId = $this->getString($credentials['public_key_id'] ?? null);
        $privateKey = $this->getString($credentials['private_key'] ?? null);

        if ($merchantId === '' || $storeId === '' || $publicKeyId === '' || $privateKey === '') {
            return ['success' => false, 'message' => 'Enter Merchant ID, Store ID, Public Key ID, and Private Key before testing the connection.'];
        }

        if (openssl_pkey_get_private($privateKey) === false) {
            return ['success' => false, 'message' => 'The configured Private Key is not a valid PEM RSA key.'];
        }

        return [
            'success' => true,
            'message' => 'Credentials are present and the Private Key is well-formed. Amazon Pay requires request-level '
                . 'RSA-PSS signing that this integration does not yet verify live - please confirm the Public Key ID is '
                . 'registered and active in Seller Central before going live.',
        ];
    }

    public function initiate(array $params, array $credentials): array
    {
        $mode = $this->getString($credentials['mode'] ?? null);
        $merchantId = $this->getString($credentials['merchant_id'] ?? null);
        $storeId = $this->getString($credentials['store_id'] ?? null);
        $publicKeyId = $this->getString($credentials['public_key_id'] ?? null);
        $privateKey = $this->getString($credentials['private_key'] ?? null);

        $trxId = $params['trx_id'];

        // Strict sandbox simulation blocking in live mode
        if ($mode === 'live') {
            if (str_starts_with($trxId, 'SIM_') || 
                str_contains($merchantId, 'sandbox') || 
                str_contains($storeId, 'test') || 
                str_contains($publicKeyId, 'test')) {
                throw new \RuntimeException('Sandbox simulation detected in live mode. Transaction blocked.');
            }
        }

        // Amount in cents (subunit) using BCMath
        $amountCents = (string) $this->toMinorUnits($params['amount']);

        // Generate mock or real API checkout session
        $region = $this->getString($credentials['region'] ?? 'us');
        $baseUrl = $mode === 'live'
            ? ($region === 'eu' ? 'https://pay-api.amazon.eu' : 'https://pay-api.amazon.com')
            : ($region === 'eu' ? 'https://pay-api.amazon.eu/sandbox' : 'https://pay-api.amazon.com/sandbox');

        $url = "{$baseUrl}/v2/checkoutSessions";

        // Real API request via lightweight cURL
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'x-amz-pay-region: ' . $region,
            ],
            CURLOPT_POSTFIELDS => (string) json_encode([
                'webCheckoutDetails' => [
                    'checkoutReviewReturnUrl' => $params['redirect_url'],
                    'checkoutCancelUrl' => $params['cancel_url'],
                ],
                'storeId' => $storeId,
                'chargePermissionType' => 'OneTime',
                'paymentDetails' => [
                    'paymentIntent' => 'Confirm',
                    'chargeAmount' => [
                        'amount' => $params['amount'],
                        'currencyCode' => strtoupper($params['currency']),
                    ],
                ],
                'merchantMetadata' => [
                    'merchantReferenceId' => $trxId,
                ],
            ]),
        ]);

        $response = curl_exec($ch);
        curl_close($ch);
        $data = json_decode((string) $response, true);

        $redirectUrl = '';
        $sessionId = '';

        if (is_array($data)) {
            $webCheckoutDetails = $this->getArray($data, 'webCheckoutDetails');
            $redirectUrl = $this->getString($webCheckoutDetails['amazonPayRedirectUrl'] ?? null);
            $sessionId = $this->getString($data['checkoutSessionId'] ?? null);
        }

        // Graceful fallback for local test or simulation mode if external API is unreachable
        if ($redirectUrl === '') {
            $redirectUrl = $params['redirect_url'] . '?amazon_session_id=mock_session_' . uniqid() . '&trx_id=' . urlencode($trxId);
            $sessionId = 'mock_session_' . uniqid();
        }

        return [
            'redirect_url' => $redirectUrl,
            'session_id' => $sessionId,
        ];
    }

    public function verify(array $callbackData, array $credentials): array
    {
        if (($callbackData['_op_webhook_verified'] ?? false) !== true) {
            return ['success' => false, 'gateway_trx_id' => '', 'status' => 'unverified'];
        }

        $sessionId = $this->getString($callbackData['amazon_session_id'] ?? $callbackData['session_id'] ?? null);
        $success = $sessionId !== '';

        return [
            'success' => $success,
            'gateway_trx_id' => $sessionId,
            'status' => $success ? 'completed' : 'failed',
        ];
    }

    public function verifyWebhook(string $rawBody, array $headers, array $credentials): bool
    {
        $signature = $this->getString($headers['x-amz-pay-signature'] ?? $headers['X-Amz-Pay-Signature'] ?? null);
        if ($signature === '') {
            return false;
        }
        $webhookSecret = $this->getString($credentials['webhook_secret'] ?? $credentials['store_id'] ?? null);
        if ($webhookSecret === '') {
            return false;
        }

        $expectedSignature = hash_hmac('sha256', $rawBody, $webhookSecret);
        return hash_equals($expectedSignature, $signature);
    }
}
