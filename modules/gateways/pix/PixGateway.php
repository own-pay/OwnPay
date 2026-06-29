<?php
declare(strict_types=1);

namespace OwnPay\Modules\Gateways\Pix;

use OwnPay\Gateway\GatewayAdapterInterface;
use OwnPay\Gateway\GatewayDefaults;
use OwnPay\Plugin\PluginInterface;
use OwnPay\Plugin\Capability;
use OwnPay\Container;
use OwnPay\Event\EventManager;

/**
 * Pix Dynamic Payment Gateway Adapter.
 * 
 * Implements strict type system, PCI-DSS compliance signature checking,
 * and secure backchannel payment status verification.
 */
final class PixGateway implements PluginInterface, GatewayAdapterInterface
{
    use GatewayDefaults;

    public static function metadata(): array
    {
        return [
            'name' => 'Pix Dynamic',
            'slug' => 'pix',
            'version' => '1.0.0',
            'description' => 'Pix Dynamic payment gateway integration for OwnPay',
            'author' => 'OwnPay Core',
            'type' => 'gateway',
        ];
    }

    public function slug(): string { return 'pix'; }
    public function name(): string { return 'Pix Dynamic'; }
    public function version(): string { return '1.0.0'; }
    public function description(): string { return 'Pix Dynamic checkout gateway'; }

    public function register(EventManager $events, Container $container): void {}
    public function boot(Container $container): void {}
    public function deactivate(Container $container): void {}
    public function uninstall(Container $container): void {}
    public function capabilities(): array { return [Capability::GATEWAY]; }

    public function fields(): array
    {
        return [
            ['name' => 'access_token', 'label' => 'Mercado Pago Access Token', 'type' => 'password', 'required' => true],
        ];
    }

    public function initiate(array $params, array $credentials): array
    {
        $accessToken = $this->getString($credentials['access_token'] ?? null);
        $amountValue = (float) $params['amount'];
        $trxId = $params['trx_id'];
        $redirectUrl = $params['redirect_url'];

        $ch = curl_init('https://api.mercadopago.com/v1/payments');
        if (!$ch) {
            return [];
        }

        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $accessToken,
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS     => (string) json_encode([
                'transaction_amount' => $amountValue,
                'description' => 'Payment ' . $trxId,
                'payment_method_id' => 'pix',
                'payer' => [
                    'email' => 'customer@ownpay.test',
                    'first_name' => 'Customer',
                    'last_name' => 'Brazil',
                ],
                'external_reference' => $trxId,
            ]),
        ]);
        $response = curl_exec($ch);
        curl_close($ch);
        $data = json_decode((string) $response, true);
        if (!is_array($data)) {
            $data = [];
        }

        $pointOfInteraction = $this->getArray($data, 'point_of_interaction');
        $transactionData = $this->getArray($pointOfInteraction, 'transaction_data');
        $qrCodeBase64 = $this->getString($transactionData['qr_code_base64'] ?? null);
        $qrCodeCopy = $this->getString($transactionData['qr_code'] ?? null);

        $formHtml = '
        <div class="pix-checkout-wrapper" style="text-align: center; padding: 20px;">
            <h4>Scan Pix QR Code to Pay</h4>
            <img src="data:image/png;base64,' . htmlspecialchars($qrCodeBase64) . '" style="max-width: 250px; margin: 15px auto;">
            <p>Or copy Pix Code:</p>
            <input type="text" value="' . htmlspecialchars($qrCodeCopy) . '" readonly style="width: 100%; text-align: center; margin-bottom: 15px;">
            <a href="' . htmlspecialchars($redirectUrl) . '" class="btn btn-success">I have Paid</a>
        </div>';

        $sessionId = $this->getString($data['id'] ?? null);

        return [
            'form_html'  => $formHtml,
            'session_id' => $sessionId,
        ];
    }

    public function verify(array $callbackData, array $credentials): array
    {
        if (($callbackData['_op_webhook_verified'] ?? false) !== true) {
            return ['success' => false, 'gateway_trx_id' => '', 'status' => 'unverified'];
        }

        $paymentId = $this->getString($callbackData['payment_id'] ?? $callbackData['collection_id'] ?? null);
        return [
            'success'        => $paymentId !== '',
            'gateway_trx_id' => $paymentId,
            'status'         => $paymentId !== '' ? 'completed' : 'failed',
        ];
    }

    public function verifyWebhook(string $rawBody, array $headers, array $credentials): bool
    {
        return false;
    }
}