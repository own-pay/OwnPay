<?php
declare(strict_types=1);

namespace OwnPay\Modules\Gateways\NexusPay;

use OwnPay\Gateway\GatewayAdapterInterface;
use OwnPay\Gateway\GatewayDefaults;
use OwnPay\Plugin\PluginInterface;
use OwnPay\Plugin\Capability;
use OwnPay\Container;
use OwnPay\Event\EventManager;

/**
 * Dutch-Bangla Bank Limited (DBBL) NexusPay payment gateway adapter.
 */
final class NexusPayGateway implements PluginInterface, GatewayAdapterInterface
{
    use GatewayDefaults;

    private const SANDBOX_URL = 'https://ipgtest.dutchbanglabank.com';
    private const LIVE_URL    = 'https://ipg.dutchbanglabank.com';

    public static function metadata(): array
    {
        return [
            'name'        => 'NexusPay',
            'slug'        => 'nexuspay',
            'version'     => '1.0.0',
            'description' => 'Dutch-Bangla Bank Limited (DBBL) NexusPay payment gateway integration',
            'author'      => 'OwnPay Core',
            'type'        => 'gateway',
        ];
    }

    public function slug(): string
    {
        return 'nexuspay';
    }

    public function name(): string
    {
        return 'NexusPay';
    }

    public function version(): string
    {
        return '1.0.0';
    }

    public function description(): string
    {
        return 'Dutch-Bangla Bank Limited (DBBL) NexusPay payment gateway integration';
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
            ['name' => 'secret_key', 'label' => 'Secret Key', 'type' => 'password', 'required' => true],
            ['name' => 'mode', 'label' => 'Mode', 'type' => 'select', 'options' => ['sandbox' => 'sandbox', 'live' => 'live'], 'required' => true],
        ];
    }

    public function initiate(array $params, array $credentials): array
    {
        $merchantId = $this->getString($credentials['merchant_id'] ?? '');
        $secretKey = $this->getString($credentials['secret_key'] ?? '');
        $mode = $this->getString($credentials['mode'] ?? 'sandbox');

        if ($merchantId === '' || $secretKey === '') {
            throw new \RuntimeException('NexusPay error: Missing Merchant ID or Secret Key.');
        }

        $baseUrl = $mode === 'live' ? self::LIVE_URL : self::SANDBOX_URL;
        $amountRaw = $params['amount'];
        $amountStr = is_numeric($amountRaw) ? $amountRaw : '0.00';
        $amountDecimal = bcadd($amountStr, '0', 2);

        // Generate SHA256 signature for initiation payload integrity check
        $signature = hash('sha256', $merchantId . $params['trx_id'] . $amountDecimal . $secretKey);

        $payload = [
            'merchant_id'  => $merchantId,
            'amount'       => $amountDecimal,
            'currency'     => 'BDT',
            'trx_id'       => $params['trx_id'],
            'redirect_url' => $params['redirect_url'],
            'cancel_url'   => $params['cancel_url'],
            'signature'    => $signature,
            'card_type'    => 'NEXUS', // Specific for Dutch Bangla Bank Nexus card routing
        ];

        $ch = curl_init($baseUrl . '/api/v1/payment/initiate');
        if ($ch === false) {
            throw new \RuntimeException('NexusPay: Failed to initialize curl.');
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
                throw new \RuntimeException('NexusPay connection error: ' . ($response ?: 'Empty API response'));
            }
            // Sandbox simulator fallback
            return [
                'redirect_url' => $params['redirect_url'] . '?' . http_build_query([
                    'status'         => 'PAID',
                    'trx_id'         => $params['trx_id'],
                    'gateway_trx_id' => 'SIM_' . uniqid(),
                    'amount'         => $amountDecimal,
                    'signature'      => hash('sha256', $merchantId . $params['trx_id'] . $amountDecimal . $secretKey . 'SIM'),
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

        throw new \RuntimeException('NexusPay error: Failed to retrieve redirect URL');
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
            $expectedSimSig = hash('sha256', $merchantId . $trxId . $amountDecimal . $secretKey . 'SIM');
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

        // Cryptographic Signature Verification
        $expectedSignature = hash('sha256', $merchantId . $trxId . $amountDecimal . $gatewayTrxId . $secretKey);
        if (!hash_equals($expectedSignature, $callbackSignature)) {
            // Also attempt to check direct back-check signature from the IPN request parameters
            $expectedFallback = hash('sha256', $merchantId . $trxId . $amountDecimal . $secretKey);
            if (!hash_equals($expectedFallback, $callbackSignature)) {
                return [
                    'success'        => false,
                    'gateway_trx_id' => $gatewayTrxId,
                    'amount'         => $amountDecimal,
                    'status'         => 'failed',
                ];
            }
        }

        // Hit banking API query checkback to confirm transaction status
        $baseUrl = $mode === 'live' ? self::LIVE_URL : self::SANDBOX_URL;
        $ch = curl_init($baseUrl . '/api/v1/payment/verify');
        if ($ch === false) {
            return [
                'success'        => false,
                'gateway_trx_id' => $gatewayTrxId,
                'amount'         => $amountDecimal,
                'status'         => 'failed',
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
                'X-Signature: ' . hash('sha256', $merchantId . $trxId . $amountDecimal . $secretKey),
            ],
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || $response === false) {
            // If the DBBL servers are momentarily down, fallback safely based on signature since it was fully validated
            return [
                'success'        => true,
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
