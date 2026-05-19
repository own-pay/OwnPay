<?php
declare(strict_types=1);

namespace OwnPay\Modules\Gateways\Stripe;

use OwnPay\Gateway\GatewayAdapterInterface;
use OwnPay\Gateway\GatewayDefaults;
use OwnPay\Plugin\PluginInterface;
use OwnPay\Plugin\Capability;
use OwnPay\Container;
use OwnPay\Event\EventManager;

/**
 * Stripe gateway plugin — PluginInterface + GatewayAdapterInterface.
 */
final class StripeGateway implements PluginInterface, GatewayAdapterInterface
{
    use GatewayDefaults;

    public static function metadata(): array
    {
        return [
            'name' => 'Stripe', 'slug' => 'stripe', 'version' => '1.0.0',
            'description' => 'Stripe payment gateway — cards, wallets, international payments',
            'author' => 'OwnPay Core', 'type' => 'gateway',
        ];
    }

    public function slug(): string { return 'stripe'; }
    public function name(): string { return 'Stripe'; }
    public function version(): string { return '1.0.0'; }
    public function description(): string { return 'Stripe payment gateway integration'; }

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
        // Resolve session ID from multiple payload formats:
        // - Redirect return: top-level session_id query param
        // - Stripe webhook: nested at data.object.id for checkout.session.* events
        $sessionId = $callbackData['session_id'] ?? '';

        if ($sessionId === '' && isset($callbackData['data']['object']['id'])) {
            $eventType = $callbackData['type'] ?? '';
            if (str_starts_with($eventType, 'checkout.session.')) {
                $sessionId = $callbackData['data']['object']['id'];
            }
        }

        // SECURITY FIX: NEVER trust webhook payload fields for payment decisions.
        // Even if we have the data.object, we MUST verify with Stripe API.
        // Extract session ID from webhook object if not found yet.
        if ($sessionId === '' && isset($callbackData['data']['object']['id'])) {
            $sessionId = $callbackData['data']['object']['id'];
        }

        if ($sessionId === '') {
            return ['success' => false, 'gateway_trx_id' => '', 'status' => 'failed'];
        }

        // ALWAYS verify server-side via Stripe API — never trust inbound payload
        $secretKey = $credentials['secret_key'] ?? '';

        $ch = curl_init('https://api.stripe.com/v1/checkout/sessions/' . urlencode($sessionId));
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_USERPWD        => $secretKey . ':',
        ]);

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        // If API call fails, do NOT fall back to webhook payload
        if ($httpCode !== 200 || $response === false) {
            return ['success' => false, 'gateway_trx_id' => '', 'status' => 'api_error'];
        }

        $data = json_decode($response, true);
        if (!is_array($data)) {
            return ['success' => false, 'gateway_trx_id' => '', 'status' => 'invalid_response'];
        }

        $paid = ($data['payment_status'] ?? '') === 'paid';

        return [
            'success'        => $paid,
            'gateway_trx_id' => $data['payment_intent'] ?? '',
            'amount'         => isset($data['amount_total']) ? bcdiv((string) $data['amount_total'], '100', 2) : null,
            'status'         => $paid ? 'completed' : 'failed',
            'trx_id'         => $data['metadata']['trx_id'] ?? '',
        ];
    }

    /**
     * AUD-G6: Stripe webhook signature verification.
     * Uses HMAC-SHA256 via Stripe-Signature header + webhook_secret credential.
     */
    public function verifyWebhook(string $rawBody, array $headers, array $credentials): bool
    {
        $webhookSecret = $credentials['webhook_secret'] ?? '';
        if ($webhookSecret === '') {
            // No webhook secret configured — skip verification (backward compat)
            return true;
        }

        // Stripe sends signature in 'Stripe-Signature' header
        $sigHeader = $headers['Stripe-Signature'] ?? $headers['stripe-signature'] ?? '';
        if ($sigHeader === '') {
            return false;
        }

        // Parse Stripe-Signature: t=timestamp,v1=signature[,v0=legacy_signature]
        $parts = [];
        foreach (explode(',', $sigHeader) as $item) {
            $kv = explode('=', $item, 2);
            if (count($kv) === 2) {
                $parts[trim($kv[0])] = trim($kv[1]);
            }
        }

        $timestamp = $parts['t'] ?? '';
        $expectedSig = $parts['v1'] ?? '';

        if ($timestamp === '' || $expectedSig === '') {
            return false;
        }

        // Replay protection: reject timestamps older than 5 minutes
        if (abs(time() - (int) $timestamp) > 300) {
            return false;
        }

        // Compute expected signature: HMAC-SHA256 of "timestamp.rawBody"
        $signedPayload = $timestamp . '.' . $rawBody;
        $computedSig = hash_hmac('sha256', $signedPayload, $webhookSecret);

        return hash_equals($computedSig, $expectedSig);
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
