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
 * Stripe payment gateway integration implementing cards, wallets, and international payments.
 * 
 * Handles payment session initialization, server-side callback/webhook verification,
 * and refunds via the Stripe API.
 */
final class StripeGateway implements PluginInterface, GatewayAdapterInterface
{
    use GatewayDefaults;

    /**
     * Returns the plugin metadata array.
     *
     * @return array{name: string, slug: string, version: string, description: string, author: string, type: string} Plugin metadata.
     */
    public static function metadata(): array
    {
        return [
            'name' => 'Stripe', 'slug' => 'stripe', 'version' => '1.0.0',
            'description' => 'Stripe payment gateway — cards, wallets, international payments',
            'author' => 'OwnPay Core', 'type' => 'gateway',
        ];
    }

    /**
     * Returns the unique slug identifying the gateway adapter.
     *
     * @return string Unique slug identifier.
     */
    public function slug(): string { return 'stripe'; }

    /**
     * Returns the descriptive name of the gateway.
     *
     * @return string Descriptive name.
     */
    public function name(): string { return 'Stripe'; }

    /**
     * Returns the version of this gateway adapter.
     *
     * @return string Version string.
     */
    public function version(): string { return '1.0.0'; }

    /**
     * Returns the description of this gateway adapter.
     *
     * @return string Description string.
     */
    public function description(): string { return 'Stripe payment gateway integration'; }

    /**
     * Registers plugin event listeners and hooks.
     *
     * @param EventManager $events Hook/filter event manager.
     * @param Container $container DI service container.
     * @return void
     */
    public function register(EventManager $events, Container $container): void {}

    /**
     * Boots the plugin during application startup.
     *
     * @param Container $container DI service container.
     * @return void
     */
    public function boot(Container $container): void {}

    /**
     * Runs cleanup routine on plugin deactivation.
     *
     * @param Container $container DI service container.
     * @return void
     */
    public function deactivate(Container $container): void {}

    /**
     * Runs database and file cleanup on plugin uninstallation.
     *
     * @param Container $container DI service container.
     * @return void
     */
    public function uninstall(Container $container): void {}

    /**
     * Returns the capability set registered by this plugin.
     *
     * @return array<int, Capability> List of capabilities.
     */
    public function capabilities(): array
    {
        return [Capability::GATEWAY];
    }

    /**
     * Defines configuration fields required to set up the gateway in the admin interface.
     *
     * @return array<int, array{name: string, label: string, type: string, required: bool, options?: array<string, string>}> Configuration schema arrays.
     */
    public function fields(): array
    {
        return [
            ['name' => 'publishable_key', 'label' => 'Publishable Key', 'type' => 'text', 'required' => true],
            ['name' => 'secret_key', 'label' => 'Secret Key', 'type' => 'password', 'required' => true],
            ['name' => 'webhook_secret', 'label' => 'Webhook Secret', 'type' => 'password', 'required' => false],
            ['name' => 'mode', 'label' => 'Mode', 'type' => 'select', 'options' => ['test', 'live'], 'required' => true],
        ];
    }

    /**
     * Initiates a payment checkout session with Stripe.
     *
     * @param array{amount: string, currency: string, trx_id: string, redirect_url: string, cancel_url: string, metadata?: array<string, mixed>} $params Core transaction parameters.
     * @param array{secret_key: string, publishable_key: string, webhook_secret?: string, mode: string} $credentials Decrypted, merchant-configured gateway credentials.
     * @return array{redirect_url: string|null, session_id: string|null} Payment response containing redirect details.
     * @throws \RuntimeException If the Stripe API returns a non-200 HTTP code.
     */
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

    /**
     * Verifies the checkout session status with Stripe via server-side check.
     *
     * @param array<string, mixed> $callbackData Raw callback or webhook query parameters/JSON payload.
     * @param array{secret_key: string, publishable_key: string, webhook_secret?: string, mode: string} $credentials Decrypted, merchant-configured gateway credentials.
     * @return array{success: bool, gateway_trx_id: string, amount?: string|null, status: string, trx_id?: string} Verification outcome.
     */
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
     * Verifies the authenticity of Stripe webhook payloads using HMAC-SHA256.
     *
     * Protects against replay attacks by verifying the Stripe-Signature timestamp is within 5 minutes.
     *
     * @param string $rawBody Raw JSON payload from the request body.
     * @param array<string, string> $headers HTTP request headers (case-insensitive keys).
     * @param array{secret_key: string, publishable_key: string, webhook_secret?: string, mode: string} $credentials Decrypted, merchant-configured gateway credentials.
     * @return bool True if signature matches and is fresh, false otherwise.
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

    /**
     * Processes a payment refund request with Stripe.
     *
     * @param string $gatewayTrxId The original Stripe Payment Intent ID (`payment_intent`).
     * @param string $amount Refund amount.
     * @param array{secret_key: string, publishable_key: string, webhook_secret?: string, mode: string} $credentials Decrypted, merchant-configured gateway credentials.
     * @return array{success: bool, refund_id: string|null, error: string|null} Refund execution status.
     */
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

    /**
     * Checks if the gateway adapter supports a given optional payment feature.
     *
     * @param string $feature Feature key (e.g. 'refund', 'recurring', 'verification').
     * @return bool True if feature is supported, false otherwise.
     */
    public function supports(string $feature): bool
    {
        return match ($feature) {
            'refund', 'recurring', 'verification' => true,
            default => false,
        };
    }
}
