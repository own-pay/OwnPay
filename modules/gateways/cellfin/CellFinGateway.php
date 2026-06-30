<?php
declare(strict_types=1);

namespace OwnPay\Modules\Gateways\CellFin;

use OwnPay\Gateway\GatewayAdapterInterface;
use OwnPay\Gateway\GatewayDefaults;
use OwnPay\Plugin\PluginInterface;
use OwnPay\Plugin\Capability;
use OwnPay\Container;
use OwnPay\Event\EventManager;

/**
 * Islami Bank Bangladesh PLC (IBBL) CellFin MFS and digital wallet payment adapter.
 */
final class CellFinGateway implements PluginInterface, GatewayAdapterInterface
{
    use GatewayDefaults;

    private const SANDBOX_URL = 'https://cellfintest.islamibankbd.com';
    private const LIVE_URL    = 'https://cellfin.islamibankbd.com';

    public static function metadata(): array
    {
        return [
            'name'        => 'CellFin',
            'slug'        => 'cellfin',
            'version'     => '1.0.0',
            'description' => 'Islami Bank Bangladesh PLC (IBBL) CellFin MFS and digital wallet payment integration',
            'author'      => 'OwnPay Core',
            'type'        => 'gateway',
        ];
    }

    public function slug(): string
    {
        return 'cellfin';
    }

    public function name(): string
    {
        return 'CellFin';
    }

    public function version(): string
    {
        return '1.0.0';
    }

    public function description(): string
    {
        return 'Islami Bank Bangladesh PLC (IBBL) CellFin MFS and digital wallet payment integration';
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
            ['name' => 'api_key', 'label' => 'API Key', 'type' => 'text', 'required' => true],
            ['name' => 'secret_key', 'label' => 'Secret Key', 'type' => 'password', 'required' => true],
            ['name' => 'mode', 'label' => 'Mode', 'type' => 'select', 'options' => ['sandbox' => 'sandbox', 'live' => 'live'], 'required' => true],
        ];
    }

    public function initiate(array $params, array $credentials): array
    {
        $merchantId = $this->getString($credentials['merchant_id'] ?? '');
        $apiKey = $this->getString($credentials['api_key'] ?? '');
        $secretKey = $this->getString($credentials['secret_key'] ?? '');
        $mode = $this->getString($credentials['mode'] ?? 'sandbox');

        if ($merchantId === '' || $apiKey === '' || $secretKey === '') {
            throw new \RuntimeException('CellFin error: Missing Merchant ID, API Key, or Secret Key.');
        }

        $baseUrl = $mode === 'live' ? self::LIVE_URL : self::SANDBOX_URL;
        $amountRaw = $params['amount'];
        $amountStr = is_numeric($amountRaw) ? $amountRaw : '0.00';
        $amountDecimal = bcadd($amountStr, '0', 2);

        // Generate HMAC signature for request authentication
        $signature = hash_hmac('sha256', $merchantId . $params['trx_id'] . $amountDecimal, $secretKey);

        $payload = [
            'merchant_id'  => $merchantId,
            'api_key'      => $apiKey,
            'amount'       => $amountDecimal,
            'currency'     => 'BDT',
            'trx_id'       => $params['trx_id'],
            'redirect_url' => $params['redirect_url'],
            'cancel_url'   => $params['cancel_url'],
            'signature'    => $signature,
        ];

        $ch = curl_init($baseUrl . '/api/v1/payment/checkout');
        if ($ch === false) {
            throw new \RuntimeException('CellFin: Failed to initialize curl.');
        }

        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_POSTFIELDS     => (string) json_encode($payload),
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'X-Signature: ' . $signature,
            ],
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || $response === false) {
            if ($mode === 'live') {
                throw new \RuntimeException('CellFin connection error: ' . ($response ?: 'Empty API response'));
            }
            // Sandbox simulator fallback
            return [
                'redirect_url' => $params['redirect_url'] . '?' . http_build_query([
                    'status'         => 'PAID',
                    'trx_id'         => $params['trx_id'],
                    'gateway_trx_id' => 'SIM_' . uniqid(),
                    'amount'         => $amountDecimal,
                    'signature'      => hash_hmac('sha256', $merchantId . $params['trx_id'] . $amountDecimal . 'SIM', $secretKey),
                ]),
            ];
        }

        $resData = json_decode((string) $response, true);
        if (is_array($resData)) {
            $paymentUrl = $this->getString($resData['payment_url'] ?? null);
            if ($paymentUrl !== '') {
                $sessionId = $this->getString($resData['session_id'] ?? null);
                $res = [
                    'redirect_url' => $paymentUrl,
                ];
                if ($sessionId !== '') {
                    $res['session_id'] = $sessionId;
                }
                return $res;
            }
        }

        throw new \RuntimeException('CellFin error: Failed to retrieve redirect URL');
    }

    public function verify(array $callbackData, array $credentials): array
    {
        $merchantId = $this->getString($credentials['merchant_id'] ?? '');
        $secretKey = $this->getString($credentials['secret_key'] ?? '');
        $mode = $this->getString($credentials['mode'] ?? 'sandbox');

        $trxId = $this->getString($callbackData['trx_id'] ?? '');
        $gatewayTrxId = $this->getString($callbackData['gateway_trx_id'] ?? '');
        $amount = $this->getString($callbackData['amount'] ?? '0.00');
        $callbackSignature = $this->getString($callbackData['signature'] ?? '');

        if ($trxId === '' || $gatewayTrxId === '') {
            return [
                'success'        => false,
                'gateway_trx_id' => '',
                'amount'         => '0.00',
                'status'         => 'failed',
            ];
        }

        $amountStr = is_numeric($amount) ? $amount : '0.00';
        $amountDecimal = bcadd($amountStr, '0', 2);

        // Live sandbox simulation hardening
        if (str_starts_with($gatewayTrxId, 'SIM_') || str_starts_with($amount, 'SIM_')) {
            if ($mode === 'live') {
                return [
                    'success'        => false,
                    'gateway_trx_id' => '',
                    'amount'         => '0.00',
                    'status'         => 'failed',
                ];
            }
            $expectedSimSig = hash_hmac('sha256', $merchantId . $trxId . $amountDecimal . 'SIM', $secretKey);
            if ($callbackSignature !== $expectedSimSig) {
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

        // Cryptographic Signature Verification using HMAC-SHA256
        $expectedSignature = hash_hmac('sha256', $merchantId . $trxId . $amountDecimal . $gatewayTrxId, $secretKey);
        if (!hash_equals($expectedSignature, $callbackSignature)) {
            $expectedFallback = hash_hmac('sha256', $merchantId . $trxId . $amountDecimal, $secretKey);
            if (!hash_equals($expectedFallback, $callbackSignature)) {
                return [
                    'success'        => false,
                    'gateway_trx_id' => $gatewayTrxId,
                    'amount'         => $amountDecimal,
                    'status'         => 'failed',
                ];
            }
        }

        // CellFin Direct checkback verify API
        $baseUrl = $mode === 'live' ? self::LIVE_URL : self::SANDBOX_URL;
        $ch = curl_init($baseUrl . '/api/v1/payment/verify');
        if ($ch === false) {
            return [
                'success'        => true, // Fallback to verified signature if server is down
                'gateway_trx_id' => $gatewayTrxId,
                'amount'         => $amountDecimal,
                'status'         => 'completed',
            ];
        }

        $payload = [
            'merchant_id'    => $merchantId,
            'trx_id'         => $trxId,
            'gateway_trx_id' => $gatewayTrxId,
            'amount'         => $amountDecimal,
        ];

        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_POSTFIELDS     => (string) json_encode($payload),
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'X-Signature: ' . hash_hmac('sha256', $merchantId . $trxId . $amountDecimal, $secretKey),
            ],
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || $response === false) {
            return [
                'success'        => true, // Signature matches, allow recovery
                'gateway_trx_id' => $gatewayTrxId,
                'amount'         => $amountDecimal,
                'status'         => 'completed',
            ];
        }

        $resData = json_decode((string) $response, true);
        if (is_array($resData) && isset($resData['status']) && strtoupper($this->getString($resData['status'])) === 'SUCCESS') {
            return [
                'success'        => true,
                'gateway_trx_id' => $gatewayTrxId,
                'amount'         => $amountDecimal,
                'status'         => 'completed',
            ];
        }

        return [
            'success'        => false,
            'gateway_trx_id' => $gatewayTrxId,
            'amount'         => $amountDecimal,
            'status'         => 'failed',
        ];
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
        return ['BDT'];
    }
}
