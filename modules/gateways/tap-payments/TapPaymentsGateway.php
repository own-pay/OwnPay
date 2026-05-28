<?php
declare(strict_types=1);

namespace OwnPay\Modules\Gateways\TapPayments;

use OwnPay\Gateway\GatewayAdapterInterface;
use OwnPay\Gateway\GatewayDefaults;
use OwnPay\Plugin\PluginInterface;
use OwnPay\Plugin\Capability;
use OwnPay\Container;
use OwnPay\Event\EventManager;

/**
 * Tap Payments (goSell Charges v2) Gateway Adapter.
 */
final class TapPaymentsGateway implements PluginInterface, GatewayAdapterInterface
{
    use GatewayDefaults;

    private const API_URL = 'https://api.tap.company/v2/charges';

    public static function metadata(): array
    {
        return [
            'name'        => 'Tap Payments',
            'slug'        => 'tap-payments',
            'version'     => '1.0.0',
            'description' => 'Tap Payments (goSell API v2) Checkout Integration',
            'author'      => 'OwnPay Core',
            'type'        => 'gateway',
        ];
    }

    public function slug(): string
    {
        return 'tap-payments';
    }

    public function name(): string
    {
        return 'Tap Payments';
    }

    public function version(): string
    {
        return '1.0.0';
    }

    public function description(): string
    {
        return 'Tap Payments (goSell API v2) Checkout Integration';
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
            ['name' => 'secret_key', 'label' => 'Secret Key (sk_...)', 'type' => 'password', 'required' => true],
            ['name' => 'webhook_secret', 'label' => 'Webhook Shared Secret', 'type' => 'password', 'required' => false],
            ['name' => 'mode', 'label' => 'Mode', 'type' => 'select', 'options' => ['sandbox' => 'sandbox', 'live' => 'live'], 'required' => true],
        ];
    }

    public function initiate(array $params, array $credentials): array
    {
        $secretKey = $this->getString($credentials['secret_key'] ?? '');
        $mode = $this->getString($credentials['mode'] ?? 'sandbox');

        if ($secretKey === '') {
            throw new \RuntimeException('Tap Payments error: Missing Secret Key.');
        }

        $amountRaw = $params['amount'];
        /** @var numeric-string $amountNum */
        $amountNum = is_numeric($amountRaw) ? (string) $amountRaw : '0.00';
        $amountDecimal = bcadd($amountNum, '0', 2);

        // Simulation bypass checks in live environment
        if (str_starts_with($params['trx_id'], 'SIM_') || str_starts_with($secretKey, 'sk_test') || str_starts_with($secretKey, 'sandbox')) {
            if ($mode === 'live') {
                throw new \RuntimeException('Sandbox simulation credentials / transaction ID used in live mode.');
            }
            return [
                'redirect_url' => $params['redirect_url'] . '?' . http_build_query([
                    'status'         => 'CAPTURED',
                    'trx_id'         => $params['trx_id'],
                    'gateway_trx_id' => 'SIM_' . uniqid(),
                    'amount'         => $amountDecimal,
                ]),
                'session_id'   => $params['trx_id'],
            ];
        }

        $metadataParams = $params['metadata'] ?? [];
        $customerName = $this->getString($metadataParams['customer_name'] ?? 'OwnPay Customer');
        $customerEmail = $this->getString($metadataParams['customer_email'] ?? 'customer@ownpay.test');

        $payload = [
            'amount'       => (float) $amountDecimal,
            'currency'     => strtoupper($params['currency']),
            'threeDSecure' => true,
            'save_card'    => false,
            'description'  => 'OwnPay Transaction ' . $params['trx_id'],
            'customer'     => [
                'first_name' => $customerName,
                'email'      => $customerEmail,
            ],
            'source'       => [
                'id' => 'src_all',
            ],
            'redirect'     => [
                'url' => $params['redirect_url'],
            ],
            'post'         => [
                'url' => $params['redirect_url'] . '?webhook=1',
            ],
            'metadata'     => [
                'trx_id' => $params['trx_id'],
            ],
        ];

        $ch = curl_init(self::API_URL);
        if ($ch === false) {
            throw new \RuntimeException('Tap Payments: Failed to initialize curl.');
        }

        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_POSTFIELDS     => (string) json_encode($payload),
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $secretKey,
                'Content-Type: application/json',
                'lang_code: en',
            ],
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 && $httpCode !== 201) {
            throw new \RuntimeException('Tap Payments initiation failed: HTTP ' . $httpCode . ' ' . ($response ?: ''));
        }

        $resData = json_decode((string) $response, true);
        if (is_array($resData)) {
            $transactionId = $this->getString($resData['id'] ?? null);
            $transaction = $resData['transaction'] ?? null;
            if (is_array($transaction)) {
                $redirectUrl = $this->getString($transaction['url'] ?? null);
                if ($redirectUrl !== '') {
                    return [
                        'redirect_url' => $redirectUrl,
                        'session_id'   => $transactionId,
                    ];
                }
            }
        }

        throw new \RuntimeException('Tap Payments error: Failed to retrieve redirect URL.');
    }

    public function verify(array $callbackData, array $credentials): array
    {
        $secretKey = $this->getString($credentials['secret_key'] ?? '');
        $mode = $this->getString($credentials['mode'] ?? 'sandbox');

        $trxId = $this->getString($callbackData['trx_id'] ?? '');
        $gatewayTrxId = $this->getString($callbackData['gateway_trx_id'] ?? $callbackData['tap_id'] ?? '');
        $amount = $this->getString($callbackData['amount'] ?? '0.00');

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

        // Programmatic backchannel server-to-server check
        $ch = curl_init(self::API_URL . '/' . urlencode($gatewayTrxId));
        if ($ch === false) {
            return [
                'success'        => false,
                'gateway_trx_id' => $gatewayTrxId,
                'amount'         => $amountDecimal,
                'status'         => 'failed',
                'description'    => 'Failed to initialize cURL request'
            ];
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $secretKey,
                'Content-Type: application/json',
            ],
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 200 && $response !== false) {
            $resData = json_decode((string) $response, true);
            if (is_array($resData)) {
                $status = strtoupper($this->getString($resData['status'] ?? ''));
                $resAmount = $this->getString($resData['amount'] ?? '0.00');
                /** @var numeric-string $resAmountNum */
                $resAmountNum = is_numeric($resAmount) ? $resAmount : '0.00';
                $resAmountDecimal = bcadd($resAmountNum, '0', 2);

                if ($status === 'CAPTURED') {
                    return [
                        'success'        => true,
                        'gateway_trx_id' => $gatewayTrxId,
                        'amount'         => $resAmountDecimal,
                        'status'         => 'completed',
                    ];
                }
            }
        }

        return [
            'success'        => false,
            'gateway_trx_id' => $gatewayTrxId,
            'amount'         => $amountDecimal,
            'status'         => 'failed',
        ];
    }

    public function verifyWebhook(string $rawBody, array $headers, array $credentials): bool
    {
        $webhookSecret = $this->getString($credentials['webhook_secret'] ?? '');
        if ($webhookSecret === '') {
            return true;
        }

        // Retrieve the signature header (case-insensitive checking)
        $receivedSignature = $headers['X-Tap-Sign'] ?? $headers['x-tap-sign'] ?? '';
        if ($receivedSignature === '') {
            return false;
        }

        // Tap Payments uses standard HMAC-SHA256 signature calculated over the raw JSON payload
        $computedSig = hash_hmac('sha256', $rawBody, $webhookSecret);

        return hash_equals($computedSig, $receivedSignature);
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
        return ['KWD', 'SAR', 'AED', 'BHD', 'OMR', 'QAR', 'EGP', 'USD', 'GBP', 'EUR'];
    }
}
