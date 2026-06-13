<?php
declare(strict_types=1);

namespace OwnPay\Modules\Gateways\Paystack;

use OwnPay\Gateway\GatewayAdapterInterface;
use OwnPay\Gateway\GatewayDefaults;
use OwnPay\Plugin\PluginInterface;
use OwnPay\Plugin\Capability;
use OwnPay\Container;
use OwnPay\Event\EventManager;

/**
 * Paystack Payment Gateway Adapter.
 * 
 * Implements strict type system, PCI-DSS compliance signature checking,
 * and secure backchannel payment status verification.
 */
final class PaystackGateway implements PluginInterface, GatewayAdapterInterface
{
    use GatewayDefaults;

    public static function metadata(): array
    {
        return [
            'name' => 'Paystack',
            'slug' => 'paystack',
            'version' => '1.0.0',
            'description' => 'Paystack payment gateway integration for OwnPay',
            'author' => 'OwnPay Core',
            'type' => 'gateway',
        ];
    }

    public function slug(): string { return 'paystack'; }
    public function name(): string { return 'Paystack'; }
    public function version(): string { return '1.0.0'; }
    public function description(): string { return 'Paystack checkout gateway'; }

    public function register(EventManager $events, Container $container): void {}
    public function boot(Container $container): void {}
    public function deactivate(Container $container): void {}
    public function uninstall(Container $container): void {}
    public function capabilities(): array { return [Capability::GATEWAY]; }

    public function fields(): array
    {
        return [
            ['name' => 'public_key', 'label' => 'Public Key', 'type' => 'text', 'required' => true],
            ['name' => 'secret_key', 'label' => 'Secret Key', 'type' => 'password', 'required' => true],
        ];
    }

    public function initiate(array $params, array $credentials): array
    {
        $secretKey = $this->getString($credentials['secret_key'] ?? null);
        $trxId = $params['trx_id'];
        $amount = $this->toMinorUnits($params['amount']);
        $currency = strtoupper($params['currency']);
        $redirectUrl = $params['redirect_url'];

        $ch = curl_init('https://api.paystack.co/transaction/initialize');
        if (!$ch) {
            return [];
        }

        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $secretKey,
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS     => (string) json_encode([
                'email' => 'customer@ownpay.test',
                'amount' => $amount,
                'currency' => $currency,
                'reference' => $trxId,
                'callback_url' => $redirectUrl,
            ]),
        ]);
        $response = curl_exec($ch);
        curl_close($ch);
        $data = json_decode((string) $response, true);
        if (!is_array($data)) {
            $data = [];
        }

        $resData = $this->getArray($data, 'data');
        $authUrl = $this->getString($resData['authorization_url'] ?? null);
        $ref = $this->getString($resData['reference'] ?? null);

        return [
            'redirect_url' => $authUrl,
            'session_id'   => $ref,
        ];
    }

    public function verify(array $callbackData, array $credentials): array
    {
        $reference = $this->getString($callbackData['reference'] ?? $callbackData['trx_id'] ?? null);
        if ($reference === '') {
            return [
                'success' => false,
                'status'  => 'failed',
            ];
        }

        $secretKey = $this->getString($credentials['secret_key'] ?? null);
        $ch = curl_init('https://api.paystack.co/transaction/verify/' . urlencode($reference));
        if (!$ch) {
            return [
                'success' => false,
                'status'  => 'failed',
            ];
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $secretKey],
        ]);
        $response = curl_exec($ch);
        curl_close($ch);
        $data = json_decode((string) $response, true);
        if (!is_array($data)) {
            $data = [];
        }

        $resData = $this->getArray($data, 'data');
        $status = $this->getString($resData['status'] ?? null);
        $success = $status === 'success';
        $gatewayTrxId = $this->getString($resData['id'] ?? null);
        $amountRaw = $this->getString($resData['amount'] ?? null);
        $amount = bcdiv($amountRaw !== '' ? $amountRaw : '0', '100', 2);

        return [
            'success'        => $success,
            'gateway_trx_id' => $gatewayTrxId,
            'amount'         => $amount,
            'status'         => $success ? 'completed' : 'failed',
            'trx_id'         => $reference,
        ];
    }

    public function verifyWebhook(string $rawBody, array $headers, array $credentials): bool
    {
        $sigHeader = $this->getString($headers['X-Paystack-Signature'] ?? $headers['x-paystack-signature'] ?? null);
        $secretKey = $this->getString($credentials['secret_key'] ?? null);
        $computedSig = hash_hmac('sha512', $rawBody, $secretKey);
        return hash_equals($computedSig, $sigHeader);
    }
}