<?php
declare(strict_types=1);

namespace OwnPay\Modules\Gateways\ApplePay;

use OwnPay\Gateway\GatewayAdapterInterface;
use OwnPay\Gateway\GatewayDefaults;
use OwnPay\Plugin\PluginInterface;
use OwnPay\Plugin\Capability;
use OwnPay\Container;
use OwnPay\Event\EventManager;

/**
 * Apple Pay Express Checkout Gateway Adapter.
 *
 * Implements standard checkout initiation, secure simulation callback redirect,
 * and verified double-entry bookkeeping support.
 */
final class ApplePayGateway implements PluginInterface, GatewayAdapterInterface
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
            'name'        => 'Apple Pay',
            'slug'        => 'apple-pay',
            'version'     => '1.0.0',
            'description' => 'Apple Pay Express Checkout gateway plugin',
            'author'      => 'OwnPay Core',
            'type'        => 'gateway',
        ];
    }

    /**
     * Returns the unique slug identifying the gateway adapter.
     */
    public function slug(): string { return 'apple-pay'; }

    /**
     * Returns the descriptive name of the gateway.
     */
    public function name(): string { return 'Apple Pay'; }

    /**
     * Returns the version of this gateway adapter.
     */
    public function version(): string { return '1.0.0'; }

    /**
     * Returns the description of this gateway adapter.
     */
    public function description(): string { return 'Apple Pay Express Checkout integration'; }

    public function register(EventManager $events, Container $container): void {}
    public function boot(Container $container): void {}
    public function deactivate(Container $container): void {}
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
     * Defines configuration fields required to set up the gateway.
     */
    public function fields(): array
    {
        return [
            ['name' => 'publishable_key', 'label' => 'Stripe Publishable Key', 'type' => 'text', 'required' => false],
            ['name' => 'secret_key', 'label' => 'Stripe Secret Key', 'type' => 'password', 'required' => false],
            ['name' => 'webhook_secret', 'label' => 'Stripe Webhook Secret', 'type' => 'password', 'required' => false],
            ['name' => 'mode', 'label' => 'Mode', 'type' => 'select', 'options' => ['test' => 'test', 'live' => 'live'], 'required' => true],
        ];
    }

    /**
     * Initiates the Stripe Apple Pay checkout session.
     */
    public function initiate(array $params, array $credentials): array
    {
        /** @var array<string, mixed> $params */
        $mode = $this->getString($credentials['mode'] ?? 'test');
        $secretKey = $this->getString($credentials['secret_key'] ?? null);

        if ($secretKey === '') {
            if ($mode === 'live') {
                throw new \RuntimeException('Stripe Secret Key is required for live mode.');
            }
            // Simulated payment redirect for testing
            $redirectUrl = $this->getString($params['redirect_url'] ?? '');
            $separator = str_contains($redirectUrl, '?') ? '&' : '?';
            $mockPaymentId = 'APAY_MOCK_' . bin2hex(random_bytes(8));
            
            $res = [];
            if ($redirectUrl !== '') {
                $res['redirect_url'] = $redirectUrl . $separator . 'paymentID=' . urlencode($mockPaymentId) . '&status=success';
            }
            $res['session_id'] = $mockPaymentId;
            return $res;
        }

        // Real Stripe payment session initiation
        $amountRaw = isset($params['amount']) ? $params['amount'] : null;
        $amountFloat = is_numeric($amountRaw) ? (float) $amountRaw : 0.0;
        $amount = (int) bcmul((string) $amountFloat, '100', 0); // Stripe uses cents
        $currency = strtolower($this->getString(isset($params['currency']) ? $params['currency'] : 'USD'));

        $trxId = isset($params['trx_id']) && is_scalar($params['trx_id']) ? (string)$params['trx_id'] : '';
        $redirectUrl = isset($params['redirect_url']) && is_scalar($params['redirect_url']) ? (string)$params['redirect_url'] : '';
        $cancelUrl = isset($params['cancel_url']) && is_scalar($params['cancel_url']) ? (string)$params['cancel_url'] : '';

        $ch = curl_init('https://api.stripe.com/v1/checkout/sessions');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_USERPWD        => $secretKey . ':',
            CURLOPT_POSTFIELDS     => http_build_query([
                'line_items[0][price_data][currency]' => $currency,
                'line_items[0][price_data][product_data][name]' => 'Apple Pay - Payment ' . $trxId,
                'line_items[0][price_data][unit_amount]' => $amount,
                'line_items[0][quantity]' => 1,
                'mode' => 'payment',
                'success_url' => $redirectUrl . '?session_id={CHECKOUT_SESSION_ID}',
                'cancel_url'  => $cancelUrl,
                'metadata[trx_id]' => $trxId,
            ]),
        ]);

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            throw new \RuntimeException('Stripe API error: HTTP ' . $httpCode);
        }

        $data = json_decode((string) $response, true);
        if (!is_array($data)) {
            throw new \RuntimeException('Stripe API error: Invalid response format');
        }

        $redirectUrl = $data['url'] ?? null;
        $redirectUrlStr = is_scalar($redirectUrl) ? (string) $redirectUrl : null;
        $sessionId = $data['id'] ?? null;
        $sessionIdStr = is_scalar($sessionId) ? (string) $sessionId : null;

        $res = [];
        if ($redirectUrlStr !== null && $redirectUrlStr !== '') {
            $res['redirect_url'] = $redirectUrlStr;
        }
        if ($sessionIdStr !== null && $sessionIdStr !== '') {
            $res['session_id'] = $sessionIdStr;
        }
        return $res;
    }

    /**
     * Verifies the Apple Pay checkout session status.
     */
    public function verify(array $callbackData, array $credentials): array
    {
        $mode = $this->getString($credentials['mode'] ?? 'test');
        $paymentId = $callbackData['paymentID'] ?? $callbackData['session_id'] ?? '';
        $paymentIdStr = is_scalar($paymentId) ? (string) $paymentId : '';

        // Check if it's a simulated mock payment
        if (str_starts_with($paymentIdStr, 'APAY_MOCK_')) {
            if ($mode === 'live') {
                return [
                    'success'        => false,
                    'gateway_trx_id' => '',
                    'status'         => 'failed',
                ];
            }
            $res = [
                'success'        => true,
                'gateway_trx_id' => 'APAY_TRX_' . bin2hex(random_bytes(12)),
                'status'         => 'success',
            ];
            if (isset($callbackData['amount']) && is_scalar($callbackData['amount'])) {
                $res['amount'] = (string)$callbackData['amount'];
            }
            return $res;
        }

        if ($paymentIdStr === '') {
            return ['success' => false, 'gateway_trx_id' => '', 'status' => 'failed'];
        }

        // Real Stripe Checkout verification
        $secretKey = $this->getString($credentials['secret_key'] ?? null);
        if ($secretKey === '') {
            return ['success' => false, 'gateway_trx_id' => '', 'status' => 'failed'];
        }

        $ch = curl_init('https://api.stripe.com/v1/checkout/sessions/' . urlencode($paymentIdStr));
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_USERPWD        => $secretKey . ':',
        ]);

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

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

        $res = [
            'success'        => $paid,
            'gateway_trx_id' => $paymentIntentStr,
            'status'         => $paid ? 'completed' : 'failed',
            'trx_id'         => $trxIdStr,
        ];
        if ($amountTotalStr !== null) {
            $res['amount'] = bcdiv($amountTotalStr, '100', 2);
        }
        return $res;
    }

    public function verifyWebhook(string $rawBody, array $headers, array $credentials): bool
    {
        $webhookSecret = $this->getString($credentials['webhook_secret'] ?? '');
        if ($webhookSecret === '') {
            return true;
        }

        $sigHeader = $headers['Stripe-Signature'] ?? $headers['stripe-signature'] ?? '';
        if ($sigHeader === '') {
            return false;
        }

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

        if (abs(time() - (int) $timestamp) > 300) {
            return false;
        }

        $signedPayload = $timestamp . '.' . $rawBody;
        $computedSig = hash_hmac('sha256', $signedPayload, $webhookSecret);

        return hash_equals($computedSig, $expectedSig);
    }
}
