<?php
declare(strict_types=1);

namespace OwnPay\Modules\Gateways\TrueMoney;

use OwnPay\Gateway\GatewayAdapterInterface;
use OwnPay\Gateway\GatewayDefaults;
use OwnPay\Plugin\PluginInterface;
use OwnPay\Plugin\Capability;
use OwnPay\Container;
use OwnPay\Event\EventManager;

/**
 * TrueMoney payment gateway adapter using Omise hosted payment sources.
 */
final class TrueMoneyGateway implements PluginInterface, GatewayAdapterInterface
{
    use GatewayDefaults;

    private const API_URL = 'https://api.omise.co';

    public static function metadata(): array
    {
        return [
            'name' => 'TrueMoney Wallet',
            'slug' => 'truemoney',
            'version' => '1.0.0',
            'description' => 'TrueMoney e-wallet integration via Omise hosted sources',
            'author' => 'OwnPay Core',
            'type' => 'gateway',
        ];
    }

    public function slug(): string { return 'truemoney'; }
    public function name(): string { return 'TrueMoney Wallet'; }
    public function version(): string { return '1.0.0'; }
    public function description(): string { return 'TrueMoney e-wallet integration via Omise hosted sources'; }

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
            ['name' => 'secret_key', 'label' => 'Omise Secret Key', 'type' => 'password', 'required' => true],
            ['name' => 'mode', 'label' => 'Mode', 'type' => 'select', 'options' => ['sandbox' => 'sandbox', 'live' => 'live'], 'required' => true],
        ];
    }

    public function initiate(array $params, array $credentials): array
    {
        $secretKey = $this->getString($credentials['secret_key'] ?? '');
        $mode = $this->getString($credentials['mode'] ?? 'sandbox');

        if (empty($secretKey)) {
            throw new \RuntimeException('TrueMoney error: Missing Omise Secret Key.');
        }

        // Live sandbox isolation guard
        if ($mode === 'live') {
            if (str_starts_with($secretKey, 'skey_test') || 
                str_starts_with($params['trx_id'], 'SIM_')) {
                throw new \RuntimeException('Sandbox simulation input/credentials rejected in Live production mode.');
            }
        }

        // Convert amount to smallest subunit (Satang for THB - 2 decimal places)
        $amountVal = $params['amount'];
        if (!is_numeric($amountVal)) {
            throw new \RuntimeException('TrueMoney error: Invalid transaction amount.');
        }
        $amountSubunits = (int) bcmul((string)$amountVal, '100', 0);

        // Step 1: Create Omise Source for TrueMoney
        $ch = curl_init(self::API_URL . '/sources');
        if ($ch === false) {
            throw new \RuntimeException('TrueMoney cURL initialization failed.');
        }

        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_USERPWD        => $secretKey . ':',
            CURLOPT_POSTFIELDS     => http_build_query([
                'type'         => 'truemoney',
                'amount'       => $amountSubunits,
                'currency'     => strtoupper($params['currency']),
                'phone_number' => '66812345678', // standard mock customer phone for TrueMoney Wallet source creation
            ]),
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode >= 400 || $response === false) {
            $msg = $response !== false ? $response : 'Connection timeout';
            throw new \RuntimeException('TrueMoney Source creation failed [' . $httpCode . ']: ' . $msg);
        }

        $sourceData = json_decode((string) $response, true);
        if (!is_array($sourceData)) {
            throw new \RuntimeException('TrueMoney invalid source API response');
        }

        $sourceId = $this->getString($sourceData['id'] ?? '');
        if (empty($sourceId)) {
            throw new \RuntimeException('TrueMoney error: Missing source ID in response.');
        }

        // Step 2: Create Omise Charge with TrueMoney Source
        $chCharge = curl_init(self::API_URL . '/charges');
        if ($chCharge === false) {
            throw new \RuntimeException('TrueMoney Charge cURL initialization failed.');
        }

        curl_setopt_array($chCharge, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_USERPWD        => $secretKey . ':',
            CURLOPT_POSTFIELDS     => http_build_query([
                'amount'           => $amountSubunits,
                'currency'         => strtoupper($params['currency']),
                'source'           => $sourceId,
                'return_uri'       => $params['redirect_url'],
                'metadata[trx_id]' => $params['trx_id'],
            ]),
        ]);

        $responseCharge = curl_exec($chCharge);
        $httpCodeCharge = curl_getinfo($chCharge, CURLINFO_HTTP_CODE);
        curl_close($chCharge);

        if ($httpCodeCharge >= 400 || $responseCharge === false) {
            $msg = $responseCharge !== false ? $responseCharge : 'Connection timeout';
            throw new \RuntimeException('TrueMoney Charge creation failed [' . $httpCodeCharge . ']: ' . $msg);
        }

        $chargeData = json_decode((string) $responseCharge, true);
        if (!is_array($chargeData)) {
            throw new \RuntimeException('TrueMoney invalid charge API response');
        }

        $chargeId = $this->getString($chargeData['id'] ?? '');
        $scannableCode = $this->getArray($chargeData, 'scannable_code');
        $image = $this->getArray($scannableCode, 'image');
        $downloadUri = $this->getString($image['download_uri'] ?? '');
        $redirectUrl = $this->getString($chargeData['authorize_uri'] ?? $downloadUri);

        if (empty($redirectUrl)) {
            $redirectUrl = $params['redirect_url'];
        }

        return [
            'redirect_url' => $redirectUrl,
            'session_id'   => $chargeId,
        ];
    }

    public function verify(array $callbackData, array $credentials): array
    {
        $secretKey = $this->getString($credentials['secret_key'] ?? '');
        $chargeId = $this->getString($callbackData['charge_id'] ?? $callbackData['id'] ?? '');

        if (empty($chargeId)) {
            return [
                'success'        => false,
                'gateway_trx_id' => '',
                'status'         => 'failed',
                'error'          => 'TrueMoney verification error: Missing charge identifier.',
            ];
        }

        $ch = curl_init(self::API_URL . '/charges/' . urlencode($chargeId));
        if ($ch === false) {
            return [
                'success'        => false,
                'gateway_trx_id' => '',
                'status'         => 'failed',
                'error'          => 'TrueMoney cURL initialization failed during status query.',
            ];
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_USERPWD        => $secretKey . ':',
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode >= 400 || $response === false) {
            return [
                'success'        => false,
                'gateway_trx_id' => '',
                'status'         => 'failed',
                'error'          => 'TrueMoney status lookup failed with HTTP code ' . $httpCode,
            ];
        }

        $data = json_decode((string) $response, true);
        if (!is_array($data)) {
            return [
                'success'        => false,
                'gateway_trx_id' => '',
                'status'         => 'failed',
                'error'          => 'TrueMoney returned invalid JSON status.',
            ];
        }

        $status = $this->getString($data['status'] ?? '');
        $success = $status === 'successful';
        $amountRaw = $data['amount'] ?? '';
        $amountVal = is_numeric($amountRaw) ? (string)$amountRaw : '';

        $result = [
            'success'        => $success,
            'gateway_trx_id' => $chargeId,
            'status'         => $success ? 'completed' : 'failed',
        ];

        if ($amountVal !== '') {
            $result['amount'] = bcdiv($amountVal, '100', 2);
        }

        return $result;
    }

    public function verifyWebhook(string $rawBody, array $headers, array $credentials): bool
    {
        return false;
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
        return ['THB'];
    }
}
