<?php
declare(strict_types=1);

namespace OwnPay\Modules\Gateways\MtnMomo;

use OwnPay\Gateway\GatewayAdapterInterface;
use OwnPay\Gateway\GatewayDefaults;
use OwnPay\Plugin\PluginInterface;
use OwnPay\Plugin\Capability;
use OwnPay\Container;
use OwnPay\Event\EventManager;

/**
 * MTN Mobile Money (MoMo) Collection API v1.0 Gateway Adapter.
 */
final class MtnMomoGateway implements PluginInterface, GatewayAdapterInterface
{
    use GatewayDefaults;

    private const SANDBOX_URL = 'https://sandbox.momodeveloper.mtn.com';
    private const LIVE_URL    = 'https://api.momodeveloper.mtn.com';

    public static function metadata(): array
    {
        return [
            'name'        => 'MTN Mobile Money',
            'slug'        => 'mtn-momo',
            'version'     => '1.0.0',
            'description' => 'MTN Mobile Money Collection API Integration',
            'author'      => 'OwnPay Core',
            'type'        => 'gateway',
        ];
    }

    public function slug(): string
    {
        return 'mtn-momo';
    }

    public function name(): string
    {
        return 'MTN Mobile Money';
    }

    public function version(): string
    {
        return '1.0.0';
    }

    public function description(): string
    {
        return 'MTN Mobile Money Collection API Integration';
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
            ['name' => 'api_user_id', 'label' => 'API User ID (UUID)', 'type' => 'text', 'required' => true],
            ['name' => 'api_key', 'label' => 'API Key', 'type' => 'password', 'required' => true],
            ['name' => 'subscription_key', 'label' => 'Subscription Key (Ocp-Apim-Subscription-Key)', 'type' => 'password', 'required' => true],
            ['name' => 'mode', 'label' => 'Mode', 'type' => 'select', 'options' => ['sandbox' => 'sandbox', 'live' => 'live'], 'required' => true],
        ];
    }

    /**
     * Authenticate and retrieve OAuth2 Bearer Access Token.
     *
     * @param array<string, mixed> $credentials
     */
    private function getAccessToken(array $credentials): string
    {
        $apiUserId = $this->getString($credentials['api_user_id'] ?? '');
        $apiKey = $this->getString($credentials['api_key'] ?? '');
        $subKey = $this->getString($credentials['subscription_key'] ?? '');
        $mode = $this->getString($credentials['mode'] ?? 'sandbox');

        $baseUrl = $mode === 'live' ? self::LIVE_URL : self::SANDBOX_URL;

        $ch = curl_init($baseUrl . '/collection/token/');
        if ($ch === false) {
            throw new \RuntimeException('MTN MoMo: Failed to initialize curl for token.');
        }

        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_USERPWD        => $apiUserId . ':' . $apiKey,
            CURLOPT_HTTPHEADER     => [
                'Ocp-Apim-Subscription-Key: ' . $subKey,
                'Content-Type: application/x-www-form-urlencoded',
            ],
            CURLOPT_POSTFIELDS     => 'grant_type=client_credentials',
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || $response === false) {
            throw new \RuntimeException('MTN MoMo OAuth failure: HTTP ' . $httpCode . ' ' . ($response ?: 'Empty API response'));
        }

        $resData = json_decode((string) $response, true);
        if (is_array($resData)) {
            $token = $this->getString($resData['access_token'] ?? null);
            if ($token !== '') {
                return $token;
            }
        }

        throw new \RuntimeException('MTN MoMo OAuth error: Failed to parse access token.');
    }

    public function initiate(array $params, array $credentials): array
    {
        $apiUserId = $this->getString($credentials['api_user_id'] ?? '');
        $subKey = $this->getString($credentials['subscription_key'] ?? '');
        $mode = $this->getString($credentials['mode'] ?? 'sandbox');

        if ($apiUserId === '' || $subKey === '') {
            throw new \RuntimeException('MTN MoMo error: Missing configuration.');
        }

        $amountRaw = $params['amount'];
        /** @var numeric-string $amountNum */
        $amountNum = is_numeric($amountRaw) ? (string) $amountRaw : '0.00';
        $amountDecimal = bcadd($amountNum, '0', 2);

        // UAT Live Sandbox simulator bypass hardening
        if (str_starts_with($params['trx_id'], 'SIM_') || str_starts_with($apiUserId, 'sandbox') || str_starts_with($subKey, 'test')) {
            if ($mode === 'live') {
                throw new \RuntimeException('Sandbox simulation credentials / transaction ID used in live mode.');
            }
            return [
                'redirect_url' => $params['redirect_url'] . '?' . http_build_query([
                    'status'         => 'SUCCESSFUL',
                    'trx_id'         => $params['trx_id'],
                    'gateway_trx_id' => 'SIM_' . uniqid(),
                    'amount'         => $amountDecimal,
                ]),
                'session_id'   => $params['trx_id'],
            ];
        }

        $token = $this->getAccessToken($credentials);
        $baseUrl = $mode === 'live' ? self::LIVE_URL : self::SANDBOX_URL;

        // X-Reference-Id MUST be a UUID v4. If the trx_id is not a UUID, generate one.
        $uuid = $this->isUuid($params['trx_id']) ? $params['trx_id'] : $this->generateUuid();

        $metadata = $params['metadata'] ?? [];
        $payerPhone = $this->getString($metadata['payer_phone'] ?? '242068511358');

        $payload = [
            'amount'   => $amountDecimal,
            'currency' => strtoupper($params['currency']),
            'externalId' => $params['trx_id'],
            'payer'    => [
                'partyIdType' => 'MSISDN',
                'partyId'     => $payerPhone,
            ],
            'payerMessage' => 'Payment for transaction ' . $params['trx_id'],
            'payeeNote'    => 'OwnPay transaction collection',
        ];

        $ch = curl_init($baseUrl . '/collection/v1_0/requesttopay');
        if ($ch === false) {
            throw new \RuntimeException('MTN MoMo: Failed to initialize curl.');
        }

        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_POSTFIELDS     => (string) json_encode($payload),
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $token,
                'X-Reference-Id: ' . $uuid,
                'X-Target-Environment: ' . ($mode === 'live' ? 'live' : 'sandbox'),
                'Ocp-Apim-Subscription-Key: ' . $subKey,
                'Content-Type: application/json',
            ],
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 202) {
            throw new \RuntimeException('MTN MoMo initiation failed: HTTP ' . $httpCode . ' ' . ($response ?: ''));
        }

        return [
            'redirect_url' => $params['redirect_url'] . '?' . http_build_query([
                'trx_id'         => $params['trx_id'],
                'gateway_trx_id' => $uuid,
                'amount'         => $amountDecimal,
            ]),
            'session_id'   => $uuid,
        ];
    }

    public function verify(array $callbackData, array $credentials): array
    {
        $mode = $this->getString($credentials['mode'] ?? 'sandbox');
        $subKey = $this->getString($credentials['subscription_key'] ?? '');

        $trxId = $this->getString($callbackData['trx_id'] ?? '');
        $gatewayTrxId = $this->getString($callbackData['gateway_trx_id'] ?? '');
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

        try {
            $token = $this->getAccessToken($credentials);
            $baseUrl = $mode === 'live' ? self::LIVE_URL : self::SANDBOX_URL;

            $ch = curl_init($baseUrl . '/collection/v1_0/requesttopay/' . urlencode($gatewayTrxId));
            if ($ch === false) {
                return [
                    'success'        => false,
                    'gateway_trx_id' => $gatewayTrxId,
                    'amount'         => $amountDecimal,
                    'status'         => 'failed',
                ];
            }

            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 15,
                CURLOPT_HTTPHEADER     => [
                    'Authorization: Bearer ' . $token,
                    'X-Target-Environment: ' . ($mode === 'live' ? 'live' : 'sandbox'),
                    'Ocp-Apim-Subscription-Key: ' . $subKey,
                ],
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode !== 200 || $response === false) {
                return [
                    'success'        => false,
                    'gateway_trx_id' => $gatewayTrxId,
                    'amount'         => $amountDecimal,
                    'status'         => 'failed',
                ];
            }

            $resData = json_decode((string) $response, true);
            if (is_array($resData)) {
                $status = strtoupper($this->getString($resData['status'] ?? ''));
                $resAmount = $this->getString($resData['amount'] ?? '0.00');
                /** @var numeric-string $resAmountNum */
                $resAmountNum = is_numeric($resAmount) ? $resAmount : '0.00';
                $resAmountDecimal = bcadd($resAmountNum, '0', 2);

                if ($status === 'SUCCESSFUL') {
                    return [
                        'success'        => true,
                        'gateway_trx_id' => $gatewayTrxId,
                        'amount'         => $resAmountDecimal,
                        'status'         => 'completed',
                    ];
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

    private function isUuid(string $uuid): bool
    {
        return preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/i', $uuid) === 1;
    }

    private function generateUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
