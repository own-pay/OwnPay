<?php
declare(strict_types=1);

namespace OwnPay\Modules\Gateways\Stripe;

use OwnPay\Gateway\GatewayAdapterInterface;
use OwnPay\Gateway\GatewayDefaults;
use OwnPay\Plugin\PluginInterface;

/**
 * Stripe gateway plugin — PluginInterface + GatewayAdapterInterface.
 */
final class StripeGateway implements PluginInterface, GatewayAdapterInterface
{
    use GatewayDefaults;

    public function slug(): string { return 'stripe'; }
    public function name(): string { return 'Stripe'; }
    public function version(): string { return '1.0.0'; }
    public function description(): string { return 'Stripe payment gateway integration'; }

    public function boot(): void {}
    public function activate(): void {}
    public function deactivate(): void {}
    public function uninstall(): void {}

    public function capabilities(): array
    {
        return [\OwnPay\Plugin\Capability::GATEWAY];
    }

    public function fields(): array
    {
        return [
            ['name' => 'publishable_key', 'label' => 'Publishable Key', 'type' => 'text', 'required' => true],
            ['name' => 'secret_key', 'label' => 'Secret Key', 'type' => 'password', 'required' => true],
            ['name' => 'webhook_secret', 'label' => 'Webhook Secret', 'type' => 'password', 'required' => false],
            ['name' => 'mode', 'label' => 'Mode', 'type' => 'select', 'options' => ['test', 'live'], 'required' => true],
        ];
    }

    public function initiate(array $params, array $credentials): array
    {
        $secretKey = $credentials['secret_key'] ?? '';
        $amount = (int) bcmul($params['amount'], '100', 0); // Stripe uses cents
        $currency = strtolower($params['currency']);

        $ch = curl_init('https://api.stripe.com/v1/checkout/sessions');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_USERPWD        => $secretKey . ':',
            CURLOPT_POSTFIELDS     => http_build_query([
                'payment_method_types[]' => 'card',
                'line_items[0][price_data][currency]' => $currency,
                'line_items[0][price_data][product_data][name]' => 'Payment ' . ($params['trx_id'] ?? ''),
                'line_items[0][price_data][unit_amount]' => $amount,
                'line_items[0][quantity]' => 1,
                'mode' => 'payment',
                'success_url' => ($params['redirect_url'] ?? '') . '?session_id={CHECKOUT_SESSION_ID}',
                'cancel_url'  => $params['cancel_url'] ?? '',
                'metadata[trx_id]' => $params['trx_id'] ?? '',
            ]),
        ]);

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            throw new \RuntimeException('Stripe API error: HTTP ' . $httpCode);
        }

        $data = json_decode($response, true);

        return [
            'redirect_url' => $data['url'] ?? null,
            'session_id'   => $data['id'] ?? null,
        ];
    }

    public function verify(array $callbackData, array $credentials): array
    {
        $sessionId = $callbackData['session_id'] ?? '';
        $secretKey = $credentials['secret_key'] ?? '';

        $ch = curl_init('https://api.stripe.com/v1/checkout/sessions/' . urlencode($sessionId));
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_USERPWD        => $secretKey . ':',
        ]);

        $response = curl_exec($ch);
        curl_close($ch);

        $data = json_decode($response, true);
        $paid = ($data['payment_status'] ?? '') === 'paid';

        return [
            'success'        => $paid,
            'gateway_trx_id' => $data['payment_intent'] ?? '',
            'amount'         => isset($data['amount_total']) ? bcdiv((string) $data['amount_total'], '100', 2) : null,
            'status'         => $paid ? 'completed' : 'failed',
        ];
    }

    public function refund(string $gatewayTrxId, string $amount, array $credentials): array
    {
        $secretKey = $credentials['secret_key'] ?? '';
        $amountCents = (int) bcmul($amount, '100', 0);

        $ch = curl_init('https://api.stripe.com/v1/refunds');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_USERPWD        => $secretKey . ':',
            CURLOPT_POSTFIELDS     => http_build_query([
                'payment_intent' => $gatewayTrxId,
                'amount'         => $amountCents,
            ]),
        ]);

        $response = curl_exec($ch);
        curl_close($ch);
        $data = json_decode($response, true);

        return [
            'success'   => ($data['status'] ?? '') === 'succeeded',
            'refund_id' => $data['id'] ?? null,
            'error'     => $data['error']['message'] ?? null,
        ];
    }

    public function supports(string $feature): bool
    {
        return match ($feature) {
            'refund', 'recurring', 'verification' => true,
            default => false,
        };
    }
}
