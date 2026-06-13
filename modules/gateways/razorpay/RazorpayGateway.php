<?php
declare(strict_types=1);

namespace OwnPay\Modules\Gateways\Razorpay;

use OwnPay\Gateway\GatewayAdapterInterface;
use OwnPay\Gateway\GatewayDefaults;
use OwnPay\Plugin\PluginInterface;
use OwnPay\Plugin\Capability;
use OwnPay\Container;
use OwnPay\Event\EventManager;

/**
 * Razorpay Payment Gateway Adapter.
 * 
 * Implements strict type system, PCI-DSS compliance signature checking,
 * and secure backchannel payment status verification.
 */
final class RazorpayGateway implements PluginInterface, GatewayAdapterInterface
{
    use GatewayDefaults;

    public static function metadata(): array
    {
        return [
            'name' => 'Razorpay',
            'slug' => 'razorpay',
            'version' => '1.0.0',
            'description' => 'Razorpay payment gateway integration for OwnPay',
            'author' => 'OwnPay Core',
            'type' => 'gateway',
        ];
    }

    public function slug(): string { return 'razorpay'; }
    public function name(): string { return 'Razorpay'; }
    public function version(): string { return '1.0.0'; }
    public function description(): string { return 'Razorpay checkout gateway'; }

    public function register(EventManager $events, Container $container): void {}
    public function boot(Container $container): void {}
    public function deactivate(Container $container): void {}
    public function uninstall(Container $container): void {}
    public function capabilities(): array { return [Capability::GATEWAY]; }

    public function fields(): array
    {
        return [
            ['name' => 'key_id', 'label' => 'Key ID', 'type' => 'text', 'required' => true],
            ['name' => 'key_secret', 'label' => 'Key Secret', 'type' => 'password', 'required' => true],
            ['name' => 'webhook_secret', 'label' => 'Webhook Secret', 'type' => 'password', 'required' => false],
        ];
    }

    public function initiate(array $params, array $credentials): array
    {
        $keyId = $this->getString($credentials['key_id'] ?? null);
        $keySecret = $this->getString($credentials['key_secret'] ?? null);
        $amount = $this->toMinorUnits($params['amount']);

        $ch = curl_init('https://api.razorpay.com/v1/orders');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_USERPWD        => $keyId . ':' . $keySecret,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS     => (string) json_encode([
                'amount' => $amount,
                'currency' => strtoupper($params['currency']),
                'receipt' => $params['trx_id'],
                'payment_capture' => 1,
            ]),
        ]);
        $response = curl_exec($ch);
        curl_close($ch);
        $data = json_decode((string) $response, true);
        $orderId = '';
        if (is_array($data)) {
            $orderId = $this->getString($data['id'] ?? null);
        }

        $formHtml = '
        <form action="' . htmlspecialchars($params['redirect_url']) . '" method="POST" id="razorpay-form">
            <script src="https://checkout.razorpay.com/v1/checkout.js"
                data-key="' . htmlspecialchars($keyId) . '"
                data-amount="' . htmlspecialchars((string) $amount) . '"
                data-currency="' . htmlspecialchars(strtoupper($params['currency'])) . '"
                data-order_id="' . htmlspecialchars($orderId) . '"
                data-buttontext="Pay with Razorpay"
                data-name="OwnPay Merchant"
                data-theme.color="#1890FF">
            </script>
            <input type="hidden" name="razorpay_order_id" value="' . htmlspecialchars($orderId) . '">
            <input type="hidden" name="trx_id" value="' . htmlspecialchars($params['trx_id']) . '">
        </form>
        <script>document.getElementById("razorpay-form").submit();</script>';

        return [
            'form_html' => $formHtml,
            'session_id' => $orderId,
        ];
    }

    public function verify(array $callbackData, array $credentials): array
    {
        $orderId = $this->getString($callbackData['razorpay_order_id'] ?? null);
        $paymentId = $this->getString($callbackData['razorpay_payment_id'] ?? null);
        $signature = $this->getString($callbackData['razorpay_signature'] ?? null);

        $keySecret = $this->getString($credentials['key_secret'] ?? null);
        $generatedSig = hash_hmac('sha256', $orderId . '|' . $paymentId, $keySecret);
        $success = hash_equals($generatedSig, $signature);

        return [
            'success'        => $success,
            'gateway_trx_id' => $paymentId,
            'status'         => $success ? 'completed' : 'failed',
            'trx_id'         => $this->getString($callbackData['trx_id'] ?? null),
        ];
    }

    public function verifyWebhook(string $rawBody, array $headers, array $credentials): bool
    {
        $webhookSecret = $this->getString($credentials['webhook_secret'] ?? null);
        if ($webhookSecret === '') return false;
        $sigHeader = $this->getString($headers['X-Razorpay-Signature'] ?? $headers['x-razorpay-signature'] ?? null);
        $computedSig = hash_hmac('sha256', $rawBody, $webhookSecret);
        return hash_equals($computedSig, $sigHeader);
    }
}