<?php
declare(strict_types=1);

namespace OwnPay\Modules\Gateways\Opay;

use OwnPay\Gateway\GatewayAdapterInterface;
use OwnPay\Gateway\GatewayDefaults;
use OwnPay\Plugin\PluginInterface;
use OwnPay\Plugin\Capability;
use OwnPay\Container;
use OwnPay\Event\EventManager;

/**
 * OPay Cashier Checkout Gateway Adapter.
 */
final class OpayGateway implements PluginInterface, GatewayAdapterInterface
{
    use GatewayDefaults;

    private const SANDBOX_URL = 'https://testapi.opaycheckout.com';
    private const LIVE_URL    = 'https://api.opaycheckout.com';

    public static function metadata(): array
    {
        return [
            'name'        => 'OPay',
            'slug'        => 'opay',
            'version'     => '1.0.0',
            'description' => 'OPay Cashier Checkout and Digital Wallet Integration',
            'author'      => 'OwnPay Core',
            'type'        => 'gateway',
        ];
    }

    public function slug(): string
    {
        return 'opay';
    }

    public function name(): string
    {
        return 'OPay';
    }

    public function version(): string
    {
        return '1.0.0';
    }

    public function description(): string
    {
        return 'OPay Cashier Checkout and Digital Wallet Integration';
    }

    public function register(EventManager $events, Container $container): void
    {
    }

    public function boot(Container $container): void
    {
    }

    public function deactivate(Container $container): void
    {
    }

    public function uninstall(Container $container): void
    {
    }

    public function capabilities(): array
    {
        return [Capability::GATEWAY];
    }

    public function fields(): array
    {
        return [
            ['name' => 'merchant_id', 'label' => 'Merchant ID', 'type' => 'text', 'required' => true],
            ['name' => 'public_key', 'label' => 'Public Key (Bearer token)', 'type' => 'text', 'required' => true],
            ['name' => 'secret_key', 'label' => 'Secret Key (Webhook Signature key)', 'type' => 'password', 'required' => true],
            ['name' => 'mode', 'label' => 'Mode', 'type' => 'select', 'options' => ['sandbox' => 'sandbox', 'live' => 'live'], 'required' => true],
        ];
    }

    public function initiate(array $params, array $credentials): array
    {
        $merchantId = $this->getString($credentials['merchant_id'] ?? '');
        $publicKey = $this->getString($credentials['public_key'] ?? '');
        $mode = $this->getString($credentials['mode'] ?? 'sandbox');

        if ($merchantId === '' || $publicKey === '') {
            throw new \RuntimeException('OPay error: Missing Merchant ID or Public Key.');
        }

        $amountRaw = $params['amount'];
        $amountStr = is_numeric($amountRaw) ? (string) $amountRaw : '0.00';
        // OPay amount total is parsed as subunits/cents (e.g. converting to Kobo using 100 multiplier)
        $amountCents = (int) bcmul($amountStr, '100', 0);

        // Simulation isolation check in live mode
        if (str_starts_with($params['trx_id'], 'SIM_') || str_starts_with($publicKey, 'sandbox') || str_starts_with($publicKey, 'test')) {
            if ($mode === 'live') {
                throw new \RuntimeException('Sandbox simulation credentials / transaction ID used in live mode.');
            }
            return [
                'redirect_url' => $params['redirect_url'] . '?' . http_build_query([
                    'status'         => 'SUCCESS',
                    'trx_id'         => $params['trx_id'],
                    'gateway_trx_id' => 'SIM_' . uniqid(),
                    'amount'         => $amountStr,
                ]),
                'session_id'   => $params['trx_id'],
            ];
        }

        $baseUrl = $mode === 'live' ? self::LIVE_URL : self::SANDBOX_URL;

        $metadata = $params['metadata'] ?? [];
        $country = $this->getString($metadata['country'] ?? 'NG');

        $payload = [
            'country'   => strtoupper($country),
            'reference' => $params['trx_id'],
            'amount'    => [
                'total'    => $amountCents,
                'currency' => strtoupper($params['currency']),
            ],
            'returnUrl' => $params['redirect_url'],
            'cancelUrl' => $params['cancel_url'],
            'product'   => [
                'name'        => 'Payment ' . $params['trx_id'],
                'description' => 'OwnPay Secure Checkout',
            ],
        ];

        $ch = curl_init($baseUrl . '/api/v1/international/cashier/create');
        if ($ch === false) {
            throw new \RuntimeException('OPay: Failed to initialize curl.');
        }

        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_POSTFIELDS     => (string) json_encode($payload),
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $publicKey,
                'MerchantId: ' . $merchantId,
                'Content-Type: application/json',
                'Accept: application/json',
            ],
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || $response === false) {
            throw new \RuntimeException('OPay cashier creation failed: HTTP ' . $httpCode . ' ' . ($response ?: ''));
        }

        $resData = json_decode((string) $response, true);
        if (is_array($resData) && ($resData['code'] ?? '') === '00000') {
            $data = $resData['data'] ?? null;
            if (is_array($data)) {
                $cashierUrl = $this->getString($data['cashierUrl'] ?? null);
                if ($cashierUrl !== '') {
                    return [
                        'redirect_url' => $cashierUrl,
                        'session_id'   => $params['trx_id'],
                    ];
                }
            }
        }

        $msg = is_array($resData) ? $this->getString($resData['message'] ?? 'Unknown Error') : 'Unknown Error';
        throw new \RuntimeException('OPay error: Failed to retrieve cashier redirect URL. ' . $msg);
    }

    public function verify(array $callbackData, array $credentials): array
    {
        if (($callbackData['_op_webhook_verified'] ?? false) !== true) {
            return ['success' => false, 'gateway_trx_id' => '', 'status' => 'unverified'];
        }

        $mode = $this->getString($credentials['mode'] ?? 'sandbox');

        $trxId = $this->getString($callbackData['trx_id'] ?? '');
        $gatewayTrxId = $this->getString($callbackData['gateway_trx_id'] ?? '');
        $amount = $this->getString($callbackData['amount'] ?? '0.00');
        $status = strtoupper($this->getString($callbackData['status'] ?? ''));

        if ($trxId === '' || $gatewayTrxId === '') {
            return [
                'success'        => false,
                'gateway_trx_id' => '',
                'amount'         => '0.00',
                'status'         => 'failed',
            ];
        }

        /** @var numeric-string $amountNum */
        $amountNum = is_numeric($amount) ? $amount : '0.00';
        $amountDecimal = bcadd($amountNum, '0', 2);

        // Simulation bypass checks in live environments
        if (str_starts_with($gatewayTrxId, 'SIM_') || str_starts_with($trxId, 'SIM_')) {
            if ($mode === 'live') {
                return [
                    'success'        => false,
                    'gateway_trx_id' => '',
                    'amount'         => '0.00',
                    'status'         => 'failed',
                ];
            }
            return [
                'success'        => true,
                'gateway_trx_id' => $gatewayTrxId,
                'amount'         => $amountDecimal,
                'status'         => 'completed',
            ];
        }

        $success = in_array($status, ['SUCCESS', 'PAID', 'COMPLETED']);

        return [
            'success'        => $success,
            'gateway_trx_id' => $gatewayTrxId,
            'amount'         => $amountDecimal,
            'status'         => $success ? 'completed' : 'failed',
        ];
    }

    public function verifyWebhook(string $rawBody, array $headers, array $credentials): bool
    {
        $secretKey = $this->getString($credentials['secret_key'] ?? '');
        if ($secretKey === '') {
            return false;
        }

        // Retrieve signature from headers (check case-insensitive variants)
        $receivedSignature = $headers['X-OPay-Signature'] ?? $headers['x-opay-signature'] ?? $headers['Signature'] ?? $headers['signature'] ?? '';
        if ($receivedSignature === '') {
            return false;
        }

        // HMAC-SHA512 calculated over the raw POST body using the merchant's secret key
        $expectedSignature = hash_hmac('sha512', $rawBody, $secretKey);

        return hash_equals($expectedSignature, $receivedSignature);
    }

    public function supports(string $feature): bool
    {
        return match ($feature) {
            'verification' => true,
            default        => false,
        };
    }

    public function supportedCurrencies(): array
    {
        return ['NGN', 'EGP'];
    }
}
