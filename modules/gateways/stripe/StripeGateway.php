<?php
declare(strict_types=1);

namespace OwnPay\Modules\Gateways\Stripe;

use OwnPay\Gateway\GatewayAdapterInterface;
use OwnPay\Gateway\GatewayDefaults;
use OwnPay\Gateway\MinimumAmountAwareInterface;
use OwnPay\Gateway\TestableConnectionInterface;
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
final class StripeGateway implements PluginInterface, GatewayAdapterInterface, MinimumAmountAwareInterface, TestableConnectionInterface
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
            'description' => 'Stripe payment gateway - cards, wallets, international payments',
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
            ['name' => 'mode', 'label' => 'Mode', 'type' => 'select', 'options' => ['test' => 'test', 'live' => 'live'], 'required' => true],
            [
                'name' => 'minimum_amount', 'label' => 'Minimum Transaction Amount', 'type' => 'number', 'required' => false,
                'help' => 'Lowest amount (in the transaction currency) this gateway will accept. Stripe rejects charges '
                    . 'below its own per-currency floor (e.g. $0.50 USD, €0.50 EUR, 50 JPY). Leave blank to use the '
                    . 'built-in default for the transaction currency.',
            ],
        ];
    }

    /**
     * Stripe's minimum charge amount per lowercase ISO currency code, in that currency's major
     * units (matching the decimal amount format `initiate()` receives, before minor-unit conversion).
     *
     * Values for usd/eur/gbp/jpy/inr are Stripe's officially published floors
     * (docs.stripe.com/currencies - "Minimum charge amount by currency"). Stripe does not publish a
     * floor for bdt/cny because they aren't supported settlement currencies - Stripe converts them to
     * the merchant's settlement currency and enforces the equivalent of $0.50 USD there. Those two
     * defaults are a conservative estimate (0.50 USD converted at this platform's seeded exchange
     * rate, rounded up) meant to fail fast locally; admins should override via the "Minimum
     * Transaction Amount" setting once they know their account's actual settlement currency and rate.
     */
    private const DEFAULT_MINIMUMS = [
        'usd' => '0.50',
        'eur' => '0.50',
        'gbp' => '0.30',
        'jpy' => '50',
        'inr' => '0.50',
        'cny' => '4.00',
        'bdt' => '65.00',
    ];

    /**
     * Resolves the effective minimum chargeable amount for a currency: the merchant's configured
     * override when present and valid, otherwise the built-in default for that currency.
     *
     * Also the MinimumAmountAwareInterface implementation - lets GatewayBridge report this up
     * front (e.g. to hide Stripe from the checkout gateway list for under-minimum transactions)
     * instead of only surfacing it as an API error after the customer has already selected it.
     *
     * @param string $currencyLower Lowercase ISO 4217 currency code.
     * @param array{secret_key: string, publishable_key: string, webhook_secret?: string, mode: string, minimum_amount?: string} $credentials
     * @return string|null The minimum amount as a decimal string, or null if none is known for this currency.
     */
    public function minimumChargeAmount(string $currencyLower, array $credentials): ?string
    {
        $configured = $credentials['minimum_amount'] ?? '';
        if ($configured !== '' && is_numeric($configured) && (float) $configured > 0) {
            return $configured;
        }
        return self::DEFAULT_MINIMUMS[$currencyLower] ?? null;
    }

    /**
     * Verifies the configured secret key actually authenticates against Stripe, without moving
     * any money - GET /v1/balance is a free, read-only call that any valid key can make.
     *
     * @param array<string, mixed> $credentials Decrypted (or freshly-submitted, unsaved) credentials.
     * @return array{success: bool, message: string}
     */
    public function testConnection(array $credentials): array
    {
        $secretKey = is_scalar($credentials['secret_key'] ?? null) ? (string) $credentials['secret_key'] : '';
        if ($secretKey === '') {
            return ['success' => false, 'message' => 'Enter a Secret Key before testing the connection.'];
        }

        $ch = curl_init('https://api.stripe.com/v1/balance');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_USERPWD        => $secretKey . ':',
        ]);
        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false) {
            return ['success' => false, 'message' => 'Could not reach Stripe - check the server\'s network connectivity.'];
        }

        $data = json_decode((string) $response, true);

        if ($httpCode === 200) {
            $mode = str_starts_with($secretKey, 'sk_live_') ? 'live' : 'test';
            return ['success' => true, 'message' => "Connected successfully to Stripe ({$mode} mode)."];
        }

        $errMsg = is_array($data) && is_array($data['error'] ?? null) && is_scalar($data['error']['message'] ?? null)
            ? (string) $data['error']['message']
            : 'Stripe rejected the provided credentials.';
        return ['success' => false, 'message' => $errMsg];
    }

    /**
     * Initiates a payment checkout session with Stripe.
     *
     * @param array{amount: string, currency: string, trx_id: string, redirect_url: string, cancel_url: string, metadata?: array<string, mixed>} $params Core transaction parameters.
     * @param array{secret_key: string, publishable_key: string, webhook_secret?: string, mode: string, minimum_amount?: string} $credentials Decrypted, merchant-configured gateway credentials.
     * @return array{redirect_url: string|null, session_id: string|null} Payment response containing redirect details.
     * @throws \RuntimeException If the amount is below the configured/default minimum, or the Stripe API returns a non-200 HTTP code.
     */
    public function initiate(array $params, array $credentials): array
    {
        $secretKey = $credentials['secret_key'];
        $amount = $this->toMinorUnits($params['amount']); // Stripe uses cents
        $currency = strtolower($params['currency']);

        $minimum = $this->minimumChargeAmount($currency, $credentials);
        if ($minimum !== null && is_numeric($minimum) && is_numeric($params['amount']) && bccomp($params['amount'], $minimum, 8) < 0) {
            throw new \RuntimeException(sprintf(
                'Stripe API error: amount %s %s is below the minimum chargeable amount of %s %s for this gateway.',
                $params['amount'],
                strtoupper($currency),
                $minimum,
                strtoupper($currency)
            ));
        }

        $ch = curl_init('https://api.stripe.com/v1/checkout/sessions');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_USERPWD        => $secretKey . ':',
            CURLOPT_POSTFIELDS     => http_build_query([
                'line_items[0][price_data][currency]' => $currency,
                'line_items[0][price_data][product_data][name]' => 'Payment ' . $params['trx_id'],
                'line_items[0][price_data][unit_amount]' => $amount,
                'line_items[0][quantity]' => 1,
                'mode' => 'payment',
                'success_url' => $params['redirect_url'] . '?session_id={CHECKOUT_SESSION_ID}',
                'cancel_url'  => $params['cancel_url'],
                'metadata[trx_id]' => $params['trx_id'],
            ]),
        ]);

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            throw new \RuntimeException('Stripe API error: request failed' . ($curlError !== '' ? ' - ' . $curlError : ''));
        }

        $data = json_decode((string) $response, true);

        if ($httpCode !== 200) {
            throw new \RuntimeException('Stripe API error: HTTP ' . $httpCode . ' - ' . $this->extractStripeErrorMessage($data));
        }

        if (!is_array($data)) {
            throw new \RuntimeException('Stripe API error: Invalid response format');
        }

        $redirectUrl = $data['url'] ?? null;
        $redirectUrlStr = is_scalar($redirectUrl) ? (string) $redirectUrl : null;
        $sessionId = $data['id'] ?? null;
        $sessionIdStr = is_scalar($sessionId) ? (string) $sessionId : null;

        return [
            'redirect_url' => $redirectUrlStr,
            'session_id'   => $sessionIdStr,
        ];
    }

    /**
     * Extracts the human-readable error message from a decoded Stripe API error response.
     *
     * Stripe error bodies look like {"error": {"message": "...", "type": "...", "param": "..."}}.
     *
     * @param mixed $data The json_decode()'d response body, or null/non-array on decode failure.
     * @return string The Stripe-provided error message, or a fallback when unavailable.
     */
    private function extractStripeErrorMessage(mixed $data): string
    {
        if (!is_array($data)) {
            return 'no response body';
        }
        $error = $data['error'] ?? null;
        if (!is_array($error)) {
            return 'unknown error';
        }
        $message = $error['message'] ?? null;
        $param = $error['param'] ?? null;
        $messageStr = is_scalar($message) ? (string) $message : 'unknown error';
        if (is_scalar($param) && (string) $param !== '') {
            $messageStr .= ' (param: ' . (string) $param . ')';
        }
        return $messageStr;
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
        $rawSessionId = $callbackData['session_id'] ?? '';
        $sessionId = is_scalar($rawSessionId) ? (string) $rawSessionId : '';

        $dataObject = $callbackData['data'] ?? null;
        $object = is_array($dataObject) ? ($dataObject['object'] ?? null) : null;
        $objectId = is_array($object) ? ($object['id'] ?? null) : null;

        if ($sessionId === '' && is_scalar($objectId)) {
            $eventType = $callbackData['type'] ?? '';
            $eventTypeStr = is_scalar($eventType) ? (string) $eventType : '';
            if (str_starts_with($eventTypeStr, 'checkout.session.')) {
                $sessionId = (string) $objectId;
            }
        }

        // Do NOT fall back to using data.object.id as a session ID for other event
        // types (charge.updated, payment_intent.succeeded, etc.) - those ids are
        // Charge/PaymentIntent ids, not Checkout Session ids, and looking them up
        // against the sessions API always 404s, misreporting a genuinely completed
        // payment as "Verification failed". Only checkout.session.* events (or an
        // explicit session_id) are actionable here; anything else has nothing to verify.
        if ($sessionId === '') {
            return ['success' => false, 'gateway_trx_id' => '', 'status' => 'failed'];
        }

        // ALWAYS verify server-side via Stripe API - never trust inbound payload
        $secretKey = $credentials['secret_key'];

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

        $data = json_decode((string) $response, true);
        if (!is_array($data)) {
            return ['success' => false, 'gateway_trx_id' => '', 'status' => 'invalid_response'];
        }

        $paid = ($data['payment_status'] ?? '') === 'paid';

        $paymentIntent = $data['payment_intent'] ?? '';
        $paymentIntentStr = is_scalar($paymentIntent) ? (string) $paymentIntent : '';

        $amountTotal = $data['amount_total'] ?? null;
        $amountTotalStr = is_scalar($amountTotal) ? (string) $amountTotal : null;

        $metadata = $data['metadata'] ?? null;
        $trxIdVal = is_array($metadata) ? ($metadata['trx_id'] ?? '') : '';
        $trxIdStr = is_scalar($trxIdVal) ? (string) $trxIdVal : '';

        return [
            'success'        => $paid,
            'gateway_trx_id' => $paymentIntentStr,
            'amount'         => $amountTotalStr !== null ? bcdiv($amountTotalStr, '100', 2) : null,
            'status'         => $paid ? 'completed' : 'failed',
            'trx_id'         => $trxIdStr,
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
            // No webhook secret configured - fail closed
            return false;
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
        $secretKey = $credentials['secret_key'];
        $amountCents = $this->toMinorUnits($amount);

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
        $data = json_decode((string) $response, true);
        if (!is_array($data)) {
            return [
                'success'   => false,
                'refund_id' => null,
                'error'     => 'Invalid response format',
            ];
        }

        $status = $data['status'] ?? '';
        $statusStr = is_scalar($status) ? (string) $status : '';

        $id = $data['id'] ?? null;
        $idStr = is_scalar($id) ? (string) $id : null;

        $errorObj = $data['error'] ?? null;
        $errorMessage = is_array($errorObj) ? ($errorObj['message'] ?? null) : null;
        $errorMessageStr = is_scalar($errorMessage) ? (string) $errorMessage : null;

        return [
            'success'   => $statusStr === 'succeeded',
            'refund_id' => $idStr,
            'error'     => $errorMessageStr,
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
