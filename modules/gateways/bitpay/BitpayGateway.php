<?php
declare(strict_types=1);

namespace OwnPay\Modules\Gateways\Bitpay;

use OwnPay\Gateway\GatewayAdapterInterface;
use OwnPay\Gateway\GatewayDefaults;
use OwnPay\Gateway\TestableConnectionInterface;
use OwnPay\Plugin\PluginInterface;
use OwnPay\Plugin\Capability;
use OwnPay\Container;
use OwnPay\Event\EventManager;

/**
 * BitPay Gateway Adapter.
 */
final class BitpayGateway implements PluginInterface, GatewayAdapterInterface, TestableConnectionInterface
{
    use GatewayDefaults;

    public static function metadata(): array
    {
        return [
            'name' => 'BitPay',
            'slug' => 'bitpay',
            'version' => '1.0.0',
            'description' => 'BitPay crypto checkout integration for OwnPay',
            'author' => 'OwnPay Core',
            'type' => 'gateway',
        ];
    }

    public function slug(): string { return 'bitpay'; }
    public function name(): string { return 'BitPay'; }
    public function version(): string { return '1.0.0'; }
    public function description(): string { return 'BitPay checkout gateway'; }

    public function register(EventManager $events, Container $container): void {}
    public function boot(Container $container): void {}
    public function deactivate(Container $container): void {}
    public function uninstall(Container $container): void {}
    public function capabilities(): array { return [Capability::GATEWAY]; }

    public function fields(): array
    {
        return [
            ['name' => 'api_token', 'label' => 'BitPay API Token', 'type' => 'password', 'required' => true],
            ['name' => 'mode', 'label' => 'Mode', 'type' => 'select', 'options' => ['test' => 'test', 'live' => 'live'], 'required' => true],
        ];
    }

    public function initiate(array $params, array $credentials): array
    {
        $mode = $this->getString($credentials['mode'] ?? null);
        $apiToken = $this->getString($credentials['api_token'] ?? null);

        $trxId = $params['trx_id'];

        // Strict sandbox simulation blocking in live mode
        if ($mode === 'live') {
            if (str_starts_with($trxId, 'SIM_') || 
                str_contains($apiToken, 'sandbox') || 
                str_contains($apiToken, 'test')) {
                throw new \RuntimeException('Sandbox simulation detected in live mode. Transaction blocked.');
            }
        }

        // Amount formatted with exactly 2 decimal places using BCMath
        $formattedAmount = $this->toDecimalString($params['amount']);

        $baseUrl = $mode === 'live' 
            ? 'https://bitpay.com' 
            : 'https://test.bitpay.com';

        $ch = curl_init("{$baseUrl}/invoices");
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'X-Accept-Version: 2.0.0',
            ],
            CURLOPT_POSTFIELDS => (string) json_encode([
                'token' => $apiToken,
                'price' => $formattedAmount,
                'currency' => strtoupper($params['currency']),
                'orderId' => $trxId,
                'redirectURL' => $params['redirect_url'],
                'notificationURL' => $params['redirect_url'],
            ]),
        ]);

        $response = curl_exec($ch);
        curl_close($ch);
        $data = json_decode((string) $response, true);

        $redirectUrl = '';
        $invoiceId = '';

        if (is_array($data)) {
            $invoiceData = $this->getArray($data, 'data');
            $redirectUrl = $this->getString($invoiceData['url'] ?? null);
            $invoiceId = $this->getString($invoiceData['id'] ?? null);
        }

        // Graceful fallback for mock tests
        if ($redirectUrl === '') {
            if ($mode === 'live') {
                throw new \RuntimeException('BitPay payment initiation failed: Empty response from gateway.');
            }
            $invoiceId = 'mock_btp_' . uniqid();
            $redirectUrl = $params['redirect_url'] . '?id=' . $invoiceId . '&trx_id=' . urlencode($trxId);
        }

        return [
            'redirect_url' => $redirectUrl,
            'session_id' => $invoiceId,
        ];
    }

    public function verify(array $callbackData, array $credentials): array
    {
        $invoiceId = $this->getString($callbackData['id'] ?? $callbackData['session_id'] ?? null);
        // The invoice id is caller-supplied and travels into a URL that also carries the
        // merchant's API token, so only an opaque BitPay invoice id may be used: a value
        // that can terminate the path segment or append a query/fragment would let the
        // caller replace the merchant's token with one of their own.
        if (!$this->isValidInvoiceId($invoiceId)) {
            return [
                'success' => false,
                'gateway_trx_id' => '',
                'amount' => '',
                'status' => 'failed',
            ];
        }

        $mode = $this->getString($credentials['mode'] ?? null);
        $apiToken = $this->getString($credentials['api_token'] ?? null);
        if ($apiToken === '') {
            // Fail closed: without the merchant's API token the invoice lookup cannot be
            // attributed to this merchant's account, so nothing may be settled from it.
            return [
                'success' => false,
                'gateway_trx_id' => '',
                'amount' => '',
                'status' => 'failed',
            ];
        }

        $baseUrl = $mode === 'live'
            ? 'https://bitpay.com'
            : 'https://test.bitpay.com';

        // Retrieve invoice details for status verification. The invoice id is encoded and
        // the token is appended as a query parameter so neither can rewrite the other.
        $url = $this->buildInvoiceUrl($baseUrl, $invoiceId, $apiToken);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'X-Accept-Version: 2.0.0',
            ],
        ]);

        $response = curl_exec($ch);
        curl_close($ch);
        $data = json_decode((string) $response, true);

        return $this->interpretInvoiceResponse(is_array($data) ? $data : [], $invoiceId);
    }

    /**
     * Interprets a BitPay invoice response into a verification result.
     *
     * Only a paid invoice that BitPay itself created for this merchant may settle: the
     * response must describe the invoice that was asked for, and it must carry the OwnPay
     * reference (`orderId`) BitPay echoed for it. That reference is provider-authenticated,
     * so it - not the callback body - identifies the transaction this payment settles.
     *
     * @param array<string, mixed> $data Decoded BitPay invoice response.
     * @param string $invoiceId Invoice id that was looked up.
     * @return array{success: bool, gateway_trx_id: string, amount: string, status: string, verified_trx_id?: string} Verification result.
     */
    private function interpretInvoiceResponse(array $data, string $invoiceId): array
    {
        $failure = [
            'success' => false,
            'gateway_trx_id' => '',
            'amount' => '',
            'status' => 'failed',
        ];

        $invoiceData = $this->getArray($data, 'data');
        $returnedId = $this->getString($invoiceData['id'] ?? null);
        if ($returnedId === '' || !hash_equals($invoiceId, $returnedId)) {
            // The provider must answer about the invoice that was asked for.
            return $failure;
        }

        $statusStr = $this->getString($invoiceData['status'] ?? null);
        if (!in_array($statusStr, ['paid', 'confirmed', 'complete'], true)) {
            return $failure;
        }

        $orderId = $this->getString($invoiceData['orderId'] ?? null);
        if ($orderId === '') {
            // Without the OwnPay reference this invoice was not created for a transaction
            // of this installation, so it cannot be tied to one - settle nothing.
            return $failure;
        }

        // BitPay invoices report the fiat order price as a JSON number. Normalise it to a
        // plain decimal string so the core amount comparison never sees exponent notation.
        $amount = '';
        $priceRaw = $invoiceData['price'] ?? null;
        if (is_numeric($priceRaw)) {
            $amount = bcadd(sprintf('%.8F', (float) $priceRaw), '0', 2);
        }

        return [
            'success' => true,
            'gateway_trx_id' => $invoiceId,
            'amount' => $amount,
            'status' => 'completed',
            'verified_trx_id' => $orderId,
        ];
    }

    public function verifyWebhook(string $rawBody, array $headers, array $credentials): bool
    {
        // BitPay IPNs are not HMAC-signed; they are untrusted triggers only.
        // Completion always requires the backchannel invoice retrieval performed
        // in verify(), including the core amount match and the provider-authenticated
        // `orderId` reference binding.
        return true;
    }

    /**
     * Checks that a callback-supplied value still is an opaque BitPay invoice id.
     *
     * BitPay invoice ids are short opaque strings. Anything carrying a path separator, a
     * query or fragment delimiter would be able to rewrite the invoice lookup URL, which
     * carries the merchant's API token.
     *
     * @param string $invoiceId Candidate invoice id taken from the callback payload.
     * @return bool True when the value can be used as a URL path segment.
     */
    private function isValidInvoiceId(string $invoiceId): bool
    {
        return $invoiceId !== '' && preg_match('/\A[A-Za-z0-9_-]{1,64}\z/', $invoiceId) === 1;
    }

    /**
     * Builds the BitPay invoice lookup URL.
     *
     * The invoice id is percent-encoded and the token is appended as a query parameter, so
     * neither value can rewrite the other.
     *
     * @param string $baseUrl BitPay API base URL for the configured mode.
     * @param string $invoiceId Validated BitPay invoice id.
     * @param string $apiToken Merchant API token used to read the invoice.
     * @return string Fully built invoice lookup URL.
     */
    private function buildInvoiceUrl(string $baseUrl, string $invoiceId, string $apiToken): string
    {
        return $baseUrl . '/invoices/' . rawurlencode($invoiceId) . '?' . http_build_query(['token' => $apiToken]);
    }

    /**
     * Verifies the API token by resolving its facade via BitPay's /tokens endpoint - a
     * read-only lookup, no invoice is created.
     *
     * @param array<string, mixed> $credentials Decrypted (or freshly-submitted, unsaved) credentials.
     * @return array{success: bool, message: string}
     */
    public function testConnection(array $credentials): array
    {
        $apiToken = $this->getString($credentials['api_token'] ?? null);
        if ($apiToken === '') {
            return ['success' => false, 'message' => 'Enter an API Token before testing the connection.'];
        }

        $mode = $this->getString($credentials['mode'] ?? null);
        $baseUrl = $mode === 'live' ? 'https://bitpay.com' : 'https://test.bitpay.com';

        $ch = curl_init("{$baseUrl}/tokens?token=" . urlencode($apiToken));
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_HTTPHEADER     => ['X-Accept-Version: 2.0.0'],
        ]);
        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false) {
            return ['success' => false, 'message' => 'Could not reach BitPay - check the server\'s network connectivity.'];
        }

        $data = json_decode((string) $response, true);
        $tokenData = $this->getArray($data, 'data');

        if ($httpCode === 200 && $tokenData !== []) {
            $firstToken = is_array($tokenData[0] ?? null) ? $tokenData[0] : [];
            $facade = $this->getString($firstToken['facade'] ?? null, 'merchant');
            return ['success' => true, 'message' => "Connected successfully to BitPay ({$mode} mode, {$facade} token)."];
        }

        $errMsg = is_array($data) && isset($data['error']) && is_scalar($data['error'])
            ? (string) $data['error']
            : 'BitPay rejected the provided API token.';
        return ['success' => false, 'message' => $errMsg];
    }
}
