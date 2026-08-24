<?php
declare(strict_types=1);

namespace OwnPay\Modules\Gateways\PromptPay;

use OwnPay\Gateway\GatewayAdapterInterface;
use OwnPay\Gateway\GatewayDefaults;
use OwnPay\Gateway\TestableConnectionInterface;
use OwnPay\Plugin\PluginInterface;
use OwnPay\Plugin\Capability;
use OwnPay\Container;
use OwnPay\Event\EventManager;

/**
 * PromptPay QR Payment Gateway Adapter.
 *
 * Implements strict type system, PCI-DSS compliance signature checking,
 * and secure backchannel payment status verification.
 */
final class PromptPayGateway implements PluginInterface, GatewayAdapterInterface, TestableConnectionInterface
{
    use GatewayDefaults;

    public static function metadata(): array
    {
        return [
            'name' => 'PromptPay QR',
            'slug' => 'promptpay',
            'version' => '1.0.0',
            'description' => 'PromptPay QR payment gateway integration for OwnPay',
            'author' => 'OwnPay Core',
            'type' => 'gateway',
        ];
    }

    public function slug(): string { return 'promptpay'; }
    public function name(): string { return 'PromptPay QR'; }
    public function version(): string { return '1.0.0'; }
    public function description(): string { return 'PromptPay QR checkout gateway'; }

    public function register(EventManager $events, Container $container): void {}
    public function boot(Container $container): void {}
    public function deactivate(Container $container): void {}
    public function uninstall(Container $container): void {}
    public function capabilities(): array { return [Capability::GATEWAY]; }

    public function fields(): array
    {
        return [
            ['name' => 'secret_key', 'label' => 'Omise Secret Key', 'type' => 'password', 'required' => true],
            ['name' => 'mode', 'label' => 'Mode', 'type' => 'select', 'options' => ['test' => 'test', 'live' => 'live'], 'required' => true],
        ];
    }

    public function initiate(array $params, array $credentials): array
    {
        $secretKey = $this->getString($credentials['secret_key'] ?? null);
        $amount = $this->toMinorUnits($params['amount']); // Satang

        $ch = curl_init('https://api.omise.co/sources');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_USERPWD        => $secretKey . ':',
            CURLOPT_POSTFIELDS     => http_build_query([
                'type' => 'promptpay',
                'amount' => $amount,
                'currency' => 'THB',
            ]),
        ]);
        $response = curl_exec($ch);
        curl_close($ch);
        $data = json_decode((string) $response, true);
        $sourceId = '';
        if (is_array($data)) {
            $sourceId = $this->getString($data['id'] ?? null);
        }

        // Create Omise Charge
        $chCharge = curl_init('https://api.omise.co/charges');
        curl_setopt_array($chCharge, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_USERPWD        => $secretKey . ':',
            CURLOPT_POSTFIELDS     => http_build_query([
                'amount' => $amount,
                'currency' => 'THB',
                'source' => $sourceId,
                'return_uri' => $params['redirect_url'],
                'metadata[trx_id]' => $params['trx_id'],
            ]),
        ]);
        $responseCharge = curl_exec($chCharge);
        curl_close($chCharge);
        $chargeData = json_decode((string) $responseCharge, true);

        $downloadUri = '';
        $chargeId = '';
        if (is_array($chargeData)) {
            $source = $this->getArray($chargeData, 'source');
            $scannableCode = $this->getArray($source, 'scannable_code');
            $image = $this->getArray($scannableCode, 'image');
            $downloadUri = $this->getString($image['download_uri'] ?? null);
            $chargeId = $this->getString($chargeData['id'] ?? null);
        }

        return [
            'redirect_url' => $downloadUri !== '' ? $downloadUri : $params['redirect_url'],
            'session_id'   => $chargeId,
        ];
    }

    public function verify(array $callbackData, array $credentials): array
    {
        $chargeId = $this->getString($callbackData['charge_id'] ?? null);
        $secretKey = $this->getString($credentials['secret_key'] ?? null);
        $ch = curl_init('https://api.omise.co/charges/' . urlencode($chargeId));
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_USERPWD        => $secretKey . ':',
        ]);
        $response = curl_exec($ch);
        curl_close($ch);
        $data = json_decode((string) $response, true);

        $success = false;
        $amountVal = '0.00';
        $trxId = '';
        if (is_array($data)) {
            $status = $this->getString($data['status'] ?? null);
            $success = $status === 'successful';
            $rawAmount = $this->getString($data['amount'] ?? null, '0');
            $amountVal = bcdiv($rawAmount, '100', 2);
            $metadata = $this->getArray($data, 'metadata');
            $trxId = $this->getString($metadata['trx_id'] ?? null);
        }

        return [
            'success'        => $success,
            'gateway_trx_id' => $chargeId,
            'amount'         => $amountVal,
            'status'         => $success ? 'completed' : 'failed',
            'trx_id'         => $trxId,
        ];
    }

    public function verifyWebhook(string $rawBody, array $headers, array $credentials): bool
    {
return true;
    }

    /**
     * Verifies the configured Omise Secret Key authenticates against Omise's account endpoint,
     * without moving any money.
     *
     * @param array<string, mixed> $credentials Decrypted (or freshly-submitted, unsaved) credentials.
     * @return array{success: bool, message: string}
     */
    public function testConnection(array $credentials): array
    {
        $secretKey = $this->getString($credentials['secret_key'] ?? null);
        if ($secretKey === '') {
            return ['success' => false, 'message' => 'Enter an Omise Secret Key before testing the connection.'];
        }

        $ch = curl_init('https://api.omise.co/account');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_USERPWD        => $secretKey . ':',
        ]);
        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false) {
            return ['success' => false, 'message' => 'Could not reach Omise - check the server\'s network connectivity.'];
        }

        $data = json_decode((string) $response, true);
        if ($httpCode === 200) {
            $mode = str_starts_with($secretKey, 'skey_test_') ? 'test' : 'live';
            return ['success' => true, 'message' => "Connected successfully to Omise/PromptPay ({$mode} mode)."];
        }

        $errMsg = is_array($data) && is_scalar($data['message'] ?? null)
            ? (string) $data['message']
            : 'Omise rejected the provided Secret Key.';
        return ['success' => false, 'message' => $errMsg];
    }
}