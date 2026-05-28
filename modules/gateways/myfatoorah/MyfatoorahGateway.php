<?php
declare(strict_types=1);

namespace OwnPay\Modules\Gateways\Myfatoorah;

use OwnPay\Gateway\GatewayAdapterInterface;
use OwnPay\Gateway\GatewayDefaults;
use OwnPay\Plugin\PluginInterface;
use OwnPay\Plugin\Capability;
use OwnPay\Container;
use OwnPay\Event\EventManager;

/**
 * MyFatoorah V2 API Gateway Adapter.
 */
final class MyfatoorahGateway implements PluginInterface, GatewayAdapterInterface
{
    use GatewayDefaults;

    private const SANDBOX_URL = 'https://apitest.myfatoorah.com';
    private const LIVE_URL    = 'https://api.myfatoorah.com';

    public static function metadata(): array
    {
        return [
            'name'        => 'MyFatoorah',
            'slug'        => 'myfatoorah',
            'version'     => '1.0.0',
            'description' => 'MyFatoorah V2 Invoice and Payment Gateway Integration',
            'author'      => 'OwnPay Core',
            'type'        => 'gateway',
        ];
    }

    public function slug(): string
    {
        return 'myfatoorah';
    }

    public function name(): string
    {
        return 'MyFatoorah';
    }

    public function version(): string
    {
        return '1.0.0';
    }

    public function description(): string
    {
        return 'MyFatoorah V2 Invoice and Payment Gateway Integration';
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
            ['name' => 'api_key', 'label' => 'API Token (Bearer)', 'type' => 'password', 'required' => true],
            ['name' => 'webhook_secret', 'label' => 'Webhook Secret Key', 'type' => 'password', 'required' => false],
            ['name' => 'mode', 'label' => 'Mode', 'type' => 'select', 'options' => ['sandbox' => 'sandbox', 'live' => 'live'], 'required' => true],
        ];
    }

    public function initiate(array $params, array $credentials): array
    {
        $apiKey = $this->getString($credentials['api_key'] ?? '');
        $mode = $this->getString($credentials['mode'] ?? 'sandbox');

        if ($apiKey === '') {
            throw new \RuntimeException('MyFatoorah error: Missing API Token.');
        }

        $amountRaw = $params['amount'];
        /** @var numeric-string $amountNum */
        $amountNum = is_numeric($amountRaw) ? (string) $amountRaw : '0.00';
        $amountDecimal = bcadd($amountNum, '0', 2);

        // Simulation mode bypass hardening
        if (str_starts_with($params['trx_id'], 'SIM_') || str_starts_with($apiKey, 'sandbox') || str_starts_with($apiKey, 'test')) {
            if ($mode === 'live') {
                throw new \RuntimeException('Sandbox simulation credentials / transaction ID used in live mode.');
            }
            return [
                'redirect_url' => $params['redirect_url'] . '?' . http_build_query([
                    'status'         => 'PAID',
                    'trx_id'         => $params['trx_id'],
                    'gateway_trx_id' => 'SIM_' . uniqid(),
                    'amount'         => $amountDecimal,
                ]),
                'session_id'   => $params['trx_id'],
            ];
        }

        $baseUrl = $mode === 'live' ? self::LIVE_URL : self::SANDBOX_URL;

        $metadata = $params['metadata'] ?? [];
        $customerName = $this->getString($metadata['customer_name'] ?? 'OwnPay Customer');
        $customerEmail = $this->getString($metadata['customer_email'] ?? 'customer@ownpay.test');

        $payload = [
            'CustomerName'       => $customerName,
            'InvoiceValue'       => (float) $amountDecimal,
            'DisplayCurrencyIso' => strtoupper($params['currency']),
            'NotificationOption' => 'LNK', // Generate hosted link
            'CallBackUrl'        => $params['redirect_url'],
            'ErrorUrl'           => $params['cancel_url'],
            'CustomerEmail'      => $customerEmail,
        ];

        $ch = curl_init($baseUrl . '/v2/SendPayment');
        if ($ch === false) {
            throw new \RuntimeException('MyFatoorah: Failed to initialize curl.');
        }

        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_POSTFIELDS     => (string) json_encode($payload),
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $apiKey,
                'Content-Type: application/json',
                'Accept: application/json',
            ],
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || $response === false) {
            throw new \RuntimeException('MyFatoorah invoice creation failed: HTTP ' . $httpCode . ' ' . ($response ?: ''));
        }

        $resData = json_decode((string) $response, true);
        if (is_array($resData) && ($resData['IsSuccess'] ?? false) === true) {
            $data = $resData['Data'] ?? null;
            if (is_array($data)) {
                $invoiceUrl = $this->getString($data['InvoiceURL'] ?? null);
                $invoiceId = $this->getString($data['InvoiceId'] ?? null);

                if ($invoiceUrl !== '') {
                    return [
                        'redirect_url' => $invoiceUrl,
                        'session_id'   => $invoiceId,
                    ];
                }
            }
        }

        $msg = is_array($resData) ? $this->getString($resData['Message'] ?? 'Unknown Error') : 'Unknown Error';
        throw new \RuntimeException('MyFatoorah error: Failed to parse invoice URL. ' . $msg);
    }

    public function verify(array $callbackData, array $credentials): array
    {
        $apiKey = $this->getString($credentials['api_key'] ?? '');
        $mode = $this->getString($credentials['mode'] ?? 'sandbox');

        $trxId = $this->getString($callbackData['trx_id'] ?? '');
        $gatewayTrxId = $this->getString($callbackData['gateway_trx_id'] ?? $callbackData['paymentId'] ?? '');
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

        $baseUrl = $mode === 'live' ? self::LIVE_URL : self::SANDBOX_URL;

        // Perform programmatic backchannel verification status lookup
        $payload = [
            'Key'     => $gatewayTrxId,
            'KeyType' => 'PaymentId',
        ];

        $ch = curl_init($baseUrl . '/v2/GetPaymentStatus');
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
                'Authorization: Bearer ' . $apiKey,
                'Content-Type: application/json',
                'Accept: application/json',
            ],
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 200 && $response !== false) {
            $resData = json_decode((string) $response, true);
            if (is_array($resData) && ($resData['IsSuccess'] ?? false) === true) {
                $data = $resData['Data'] ?? null;
                if (is_array($data)) {
                    $invoiceStatus = strtoupper($this->getString($data['InvoiceStatus'] ?? ''));
                    if ($invoiceStatus === 'PAID') {
                        $invoiceValue = $this->getString($data['InvoiceValue'] ?? '0.00');
                        /** @var numeric-string $invoiceValueNum */
                        $invoiceValueNum = is_numeric($invoiceValue) ? $invoiceValue : '0.00';
                        return [
                            'success'        => true,
                            'gateway_trx_id' => $gatewayTrxId,
                            'amount'         => bcadd($invoiceValueNum, '0', 2),
                            'status'         => 'completed',
                        ];
                    }
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

        $receivedSignature = $headers['MyFatoorah-Signature'] ?? $headers['myfatoorah-signature'] ?? '';
        if ($receivedSignature === '') {
            return false;
        }

        // MyFatoorah webhook signature is base64(HMAC-SHA256(rawBody, secretKey, raw_binary=true))
        $computedSig = base64_encode(hash_hmac('sha256', $rawBody, $webhookSecret, true));

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
        return ['KWD', 'SAR', 'AED', 'BHD', 'OMR', 'QAR', 'EGP', 'USD', 'EUR'];
    }
}
