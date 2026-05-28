<?php
declare(strict_types=1);

namespace OwnPay\Modules\Gateways\OrangeMoney;

use OwnPay\Gateway\GatewayAdapterInterface;
use OwnPay\Gateway\GatewayDefaults;
use OwnPay\Plugin\PluginInterface;
use OwnPay\Plugin\Capability;
use OwnPay\Container;
use OwnPay\Event\EventManager;

/**
 * Orange Money Web Payment API Gateway Adapter.
 */
final class OrangeMoneyGateway implements PluginInterface, GatewayAdapterInterface
{
    use GatewayDefaults;

    private const BASE_URL = 'https://api.orange.com';

    public static function metadata(): array
    {
        return [
            'name'        => 'Orange Money',
            'slug'        => 'orange-money',
            'version'     => '1.0.0',
            'description' => 'Orange Money Web Payment API Integration',
            'author'      => 'OwnPay Core',
            'type'        => 'gateway',
        ];
    }

    public function slug(): string
    {
        return 'orange-money';
    }

    public function name(): string
    {
        return 'Orange Money';
    }

    public function version(): string
    {
        return '1.0.0';
    }

    public function description(): string
    {
        return 'Orange Money Web Payment API Integration';
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
            ['name' => 'client_id', 'label' => 'Consumer Key (Client ID)', 'type' => 'text', 'required' => true],
            ['name' => 'client_secret', 'label' => 'Consumer Secret (Client Secret)', 'type' => 'password', 'required' => true],
            ['name' => 'merchant_key', 'label' => 'Merchant Key', 'type' => 'password', 'required' => true],
            ['name' => 'mode', 'label' => 'Mode', 'type' => 'select', 'options' => ['sandbox' => 'sandbox', 'live' => 'live'], 'required' => true],
        ];
    }

    /**
     * Retrieve OAuth2 Bearer Access Token.
     *
     * @param array<string, mixed> $credentials
     */
    private function getAccessToken(array $credentials): string
    {
        $clientId = $this->getString($credentials['client_id'] ?? '');
        $clientSecret = $this->getString($credentials['client_secret'] ?? '');

        $ch = curl_init(self::BASE_URL . '/oauth/v2/token');
        if ($ch === false) {
            throw new \RuntimeException('Orange Money: Failed to initialize curl for token.');
        }

        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_USERPWD        => $clientId . ':' . $clientSecret,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/x-www-form-urlencoded',
            ],
            CURLOPT_POSTFIELDS     => 'grant_type=client_credentials',
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || $response === false) {
            throw new \RuntimeException('Orange Money OAuth failure: HTTP ' . $httpCode);
        }

        $resData = json_decode((string) $response, true);
        if (is_array($resData)) {
            $token = $this->getString($resData['access_token'] ?? null);
            if ($token !== '') {
                return $token;
            }
        }

        throw new \RuntimeException('Orange Money OAuth error: Failed to parse access token.');
    }

    public function initiate(array $params, array $credentials): array
    {
        $merchantKey = $this->getString($credentials['merchant_key'] ?? '');
        $mode = $this->getString($credentials['mode'] ?? 'sandbox');

        if ($merchantKey === '') {
            throw new \RuntimeException('Orange Money error: Missing Merchant Key.');
        }

        $amountRaw = $params['amount'];
        /** @var numeric-string $amountNum */
        $amountNum = is_numeric($amountRaw) ? (string) $amountRaw : '0.00';
        $amountDecimal = bcadd($amountNum, '0', 2);

        // Sandbox simulator bypass checks in live environments
        if (str_starts_with($params['trx_id'], 'SIM_') || str_starts_with($merchantKey, 'sandbox') || str_starts_with($merchantKey, 'test')) {
            if ($mode === 'live') {
                throw new \RuntimeException('Sandbox simulation credentials / transaction ID used in live mode.');
            }
            return [
                'redirect_url' => $params['redirect_url'] . '?' . http_build_query([
                    'status'         => 'SUCCESS',
                    'trx_id'         => $params['trx_id'],
                    'gateway_trx_id' => 'SIM_' . uniqid(),
                    'amount'         => $amountDecimal,
                    'pay_token'      => 'SIM_TOKEN_' . uniqid(),
                ]),
                'session_id'   => 'SIM_TOKEN_' . uniqid(),
            ];
        }

        $token = $this->getAccessToken($credentials);

        $payload = [
            'merchant_key' => $merchantKey,
            'currency'     => strtoupper($params['currency']),
            'order_id'     => $params['trx_id'],
            'amount'       => (float) $amountDecimal,
            'return_url'   => $params['redirect_url'],
            'cancel_url'   => $params['cancel_url'],
            'notif_url'    => $params['redirect_url'] . '?webhook=1',
            'lang'         => 'fr',
            'reference'    => 'OwnPay Transaction ' . $params['trx_id'],
        ];

        $endpoint = self::BASE_URL . '/orange-money-webpay/dev/v1/webpayment';
        if ($mode === 'live') {
            $country = strtolower(substr($params['currency'], 0, 3));
            $endpoint = self::BASE_URL . '/orange-money-webpay/' . $country . '/v1/webpayment';
        }

        $ch = curl_init($endpoint);
        if ($ch === false) {
            throw new \RuntimeException('Orange Money: Failed to initialize curl.');
        }

        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_POSTFIELDS     => (string) json_encode($payload),
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $token,
                'Content-Type: application/json',
                'Accept: application/json',
            ],
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 && $httpCode !== 201) {
            throw new \RuntimeException('Orange Money initiation failed: HTTP ' . $httpCode . ' ' . ($response ?: ''));
        }

        $resData = json_decode((string) $response, true);
        if (is_array($resData)) {
            $paymentUrl = $this->getString($resData['payment_url'] ?? null);
            $payToken = $this->getString($resData['pay_token'] ?? null);

            if ($paymentUrl !== '' && $payToken !== '') {
                return [
                    'redirect_url' => $paymentUrl,
                    'session_id'   => $payToken,
                ];
            }
        }

        throw new \RuntimeException('Orange Money error: Failed to parse initiation response.');
    }

    public function verify(array $callbackData, array $credentials): array
    {
        $mode = $this->getString($credentials['mode'] ?? 'sandbox');

        $trxId = $this->getString($callbackData['trx_id'] ?? '');
        $gatewayTrxId = $this->getString($callbackData['gateway_trx_id'] ?? '');
        $amount = $this->getString($callbackData['amount'] ?? '0.00');
        $payToken = $this->getString($callbackData['pay_token'] ?? '');

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

        if ($payToken === '') {
            return [
                'success'        => false,
                'gateway_trx_id' => $gatewayTrxId,
                'amount'         => $amountDecimal,
                'status'         => 'failed',
            ];
        }

        try {
            $token = $this->getAccessToken($credentials);

            $endpoint = self::BASE_URL . '/orange-money-webpay/dev/v1/transactionstatus';
            if ($mode === 'live') {
                $country = strtolower(substr($this->getString($callbackData['currency'] ?? 'civ'), 0, 3));
                $endpoint = self::BASE_URL . '/orange-money-webpay/' . $country . '/v1/transactionstatus';
            }

            $payload = [
                'order_id'  => $trxId,
                'amount'    => (float) $amountDecimal,
                'pay_token' => $payToken,
            ];

            $ch = curl_init($endpoint);
            if ($ch === false) {
                return [
                    'success'        => false,
                    'gateway_trx_id' => $gatewayTrxId,
                    'amount'         => $amountDecimal,
                    'status'         => 'failed',
                ];
            }

            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 15,
                CURLOPT_POSTFIELDS     => (string) json_encode($payload),
                CURLOPT_HTTPHEADER     => [
                    'Authorization: Bearer ' . $token,
                    'Content-Type: application/json',
                    'Accept: application/json',
                ],
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode === 200 && $response !== false) {
                $resData = json_decode((string) $response, true);
                if (is_array($resData)) {
                    $status = strtoupper($this->getString($resData['status'] ?? ''));
                    if ($status === 'SUCCESS') {
                        return [
                            'success'        => true,
                            'gateway_trx_id' => $gatewayTrxId,
                            'amount'         => $amountDecimal,
                            'status'         => 'completed',
                        ];
                    }
                }
            }
        } catch (\Throwable $e) {
            // Log verify error
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
        return true;
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
        return [];
    }
}
