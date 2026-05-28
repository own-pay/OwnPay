<?php
declare(strict_types=1);

namespace OwnPay\Modules\Gateways\Billplz;

use OwnPay\Gateway\GatewayAdapterInterface;
use OwnPay\Gateway\GatewayDefaults;
use OwnPay\Plugin\PluginInterface;
use OwnPay\Plugin\Capability;
use OwnPay\Container;
use OwnPay\Event\EventManager;

/**
 * Billplz direct debit payment gateway adapter.
 */
final class BillplzGateway implements PluginInterface, GatewayAdapterInterface
{
    use GatewayDefaults;

    private const SANDBOX_URL = 'https://www.billplz-sandbox.com';
    private const LIVE_URL    = 'https://www.billplz.com';

    public static function metadata(): array
    {
        return [
            'name' => 'Billplz',
            'slug' => 'billplz',
            'version' => '1.0.0',
            'description' => 'Billplz Direct Debit payment integration for Malaysia',
            'author' => 'OwnPay Core',
            'type' => 'gateway',
        ];
    }

    public function slug(): string { return 'billplz'; }
    public function name(): string { return 'Billplz'; }
    public function version(): string { return '1.0.0'; }
    public function description(): string { return 'Billplz Direct Debit payment integration for Malaysia'; }

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
            ['name' => 'api_key', 'label' => 'API Key', 'type' => 'password', 'required' => true],
            ['name' => 'signature_key', 'label' => 'X-Signature Key', 'type' => 'password', 'required' => true],
            ['name' => 'collection_id', 'label' => 'Collection ID', 'type' => 'text', 'required' => true],
            ['name' => 'mode', 'label' => 'Mode', 'type' => 'select', 'options' => ['sandbox' => 'sandbox', 'live' => 'live'], 'required' => true],
        ];
    }

    public function initiate(array $params, array $credentials): array
    {
        $apiKey = $this->getString($credentials['api_key'] ?? '');
        $collectionId = $this->getString($credentials['collection_id'] ?? '');
        $mode = $this->getString($credentials['mode'] ?? 'sandbox');

        if (empty($apiKey) || empty($collectionId)) {
            throw new \RuntimeException('Billplz error: Missing API Key or Collection ID.');
        }

        // Live sandbox isolation guard
        if ($mode === 'live') {
            if (str_contains($apiKey, 'sandbox') || 
                str_starts_with($params['trx_id'], 'SIM_')) {
                throw new \RuntimeException('Sandbox simulation input/credentials rejected in Live production mode.');
            }
        }

        $baseUrl = $mode === 'live' ? self::LIVE_URL : self::SANDBOX_URL;

        // Convert amount to cents (MYR uses 2 decimal places)
        $amountVal = $params['amount'];
        if (!is_numeric($amountVal)) {
            throw new \RuntimeException('Billplz error: Invalid transaction amount.');
        }
        $amountCents = (int) bcmul((string)$amountVal, '100', 0);

        $ch = curl_init($baseUrl . '/api/v3/bills');
        if ($ch === false) {
            throw new \RuntimeException('Billplz cURL initialization failed.');
        }

        $payload = [
            'collection_id' => $collectionId,
            'email'         => 'customer@ownpay.test',
            'mobile'        => '60123456789',
            'name'          => 'OwnPay Customer',
            'amount'        => $amountCents,
            'callback_url'  => $params['redirect_url'],
            'redirect_url'  => $params['redirect_url'],
        ];

        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_USERPWD        => $apiKey . ':',
            CURLOPT_POSTFIELDS     => http_build_query($payload),
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode >= 400 || $response === false) {
            $msg = $response !== false ? $response : 'Connection timeout';
            throw new \RuntimeException('Billplz bill creation failed [' . $httpCode . ']: ' . $msg);
        }

        $data = json_decode((string) $response, true);
        if (!is_array($data)) {
            throw new \RuntimeException('Billplz invalid API response');
        }

        $billId = $this->getString($data['id'] ?? '');
        $redirectUrl = $this->getString($data['url'] ?? '');

        if (empty($redirectUrl)) {
            throw new \RuntimeException('Billplz error: Missing billing URL in response.');
        }

        return [
            'redirect_url' => $redirectUrl,
            'session_id'   => $billId,
        ];
    }

    public function verify(array $callbackData, array $credentials): array
    {
        $apiKey = $this->getString($credentials['api_key'] ?? '');
        $mode = $this->getString($credentials['mode'] ?? 'sandbox');
        $billId = $this->getString($callbackData['billplz[id]'] ?? $callbackData['id'] ?? '');

        if (empty($billId)) {
            return [
                'success'        => false,
                'gateway_trx_id' => '',
                'status'         => 'failed',
                'error'          => 'Billplz verification error: Missing bill identifier.',
            ];
        }

        $baseUrl = $mode === 'live' ? self::LIVE_URL : self::SANDBOX_URL;

        $ch = curl_init($baseUrl . '/api/v3/bills/' . urlencode($billId));
        if ($ch === false) {
            return [
                'success'        => false,
                'gateway_trx_id' => '',
                'status'         => 'failed',
                'error'          => 'Billplz cURL initialization failed during status query.',
            ];
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_USERPWD        => $apiKey . ':',
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode >= 400 || $response === false) {
            return [
                'success'        => false,
                'gateway_trx_id' => '',
                'status'         => 'failed',
                'error'          => 'Billplz status lookup failed with HTTP code ' . $httpCode,
            ];
        }

        $data = json_decode((string) $response, true);
        if (!is_array($data)) {
            return [
                'success'        => false,
                'gateway_trx_id' => '',
                'status'         => 'failed',
                'error'          => 'Billplz returned invalid JSON status.',
            ];
        }

        $paid = $this->getString($data['paid'] ?? '');
        $success = $paid === 'true' || $this->getBool($data['paid'] ?? false);
        $amountRaw = $data['amount'] ?? '';
        $amountVal = is_numeric($amountRaw) ? (string)$amountRaw : '';

        $result = [
            'success'        => $success,
            'gateway_trx_id' => $billId,
            'status'         => $success ? 'completed' : 'failed',
        ];

        if ($amountVal !== '') {
            $result['amount'] = bcdiv($amountVal, '100', 2);
        }

        return $result;
    }

    public function verifyWebhook(string $rawBody, array $headers, array $credentials): bool
    {
        $signatureKey = $this->getString($credentials['signature_key'] ?? '');
        if (empty($signatureKey)) {
            return false;
        }

        $providedSig = '';
        foreach ($headers as $key => $val) {
            if (strtolower($key) === 'x-signature') {
                $providedSig = $val;
                break;
            }
        }

        if (empty($providedSig)) {
            return false;
        }

        // Billplz sends dynamic HMAC-SHA256 signature calculated over raw body
        $computedSig = hash_hmac('sha256', $rawBody, $signatureKey);

        return hash_equals($computedSig, $providedSig);
    }

    public function refund(string $gatewayTrxId, string $amount, array $credentials): array
    {
        return ['success' => false, 'error' => 'Refund capability not supported.'];
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
        return ['MYR'];
    }
}
