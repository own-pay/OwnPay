<?php
declare(strict_types=1);

namespace OwnPay\Modules\Gateways\TouchNGo;

use OwnPay\Gateway\GatewayAdapterInterface;
use OwnPay\Gateway\GatewayDefaults;
use OwnPay\Plugin\PluginInterface;
use OwnPay\Plugin\Capability;
use OwnPay\Container;
use OwnPay\Event\EventManager;

/**
 * Touch 'n Go eWallet payment gateway adapter using Stripe PaymentIntents.
 */
final class TouchNGoGateway implements PluginInterface, GatewayAdapterInterface
{
    use GatewayDefaults;

    private const API_URL = 'https://api.stripe.com/v1';

    public static function metadata(): array
    {
        return [
            'name' => "Touch 'n Go eWallet",
            'slug' => 'touch-n-go',
            'version' => '1.0.0',
            'description' => "Touch 'n Go eWallet payment integration via Stripe PaymentIntent",
            'author' => 'OwnPay Core',
            'type' => 'gateway',
        ];
    }

    public function slug(): string { return 'touch-n-go'; }
    public function name(): string { return "Touch 'n Go eWallet"; }
    public function version(): string { return '1.0.0'; }
    public function description(): string { return "Touch 'n Go eWallet payment integration via Stripe PaymentIntent"; }

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
            ['name' => 'secret_key', 'label' => 'Stripe Secret Key', 'type' => 'password', 'required' => true],
            ['name' => 'mode', 'label' => 'Mode', 'type' => 'select', 'options' => ['sandbox' => 'sandbox', 'live' => 'live'], 'required' => true],
        ];
    }

    public function initiate(array $params, array $credentials): array
    {
        $secretKey = $this->getString($credentials['secret_key'] ?? '');
        $mode = $this->getString($credentials['mode'] ?? 'sandbox');

        if (empty($secretKey)) {
            throw new \RuntimeException("Touch 'n Go error: Missing Stripe Secret Key.");
        }

        // Live sandbox isolation guard
        if ($mode === 'live') {
            if (str_starts_with($secretKey, 'sk_test') || 
                str_starts_with($params['trx_id'], 'SIM_')) {
                throw new \RuntimeException('Sandbox simulation input/credentials rejected in Live production mode.');
            }
        }

        // Convert amount to smallest subunit (cents/subunits for MYR - 2 decimal places)
        $amountVal = $params['amount'];
        if (!is_numeric($amountVal)) {
            throw new \RuntimeException("Touch 'n Go error: Invalid transaction amount.");
        }
        $amountSubunits = (int) bcmul((string)$amountVal, '100', 0);

        // Initiate a PaymentIntent on Stripe with Touch 'n Go payment method
        $ch = curl_init(self::API_URL . '/payment_intents');
        if ($ch === false) {
            throw new \RuntimeException("Touch 'n Go cURL initialization failed.");
        }

        $payload = [
            'amount'                     => $amountSubunits,
            'currency'                   => strtolower($params['currency']),
            'payment_method_types[0]'    => 'touch_n_go',
            'confirm'                    => 'true',
            'payment_method_data[type]'  => 'touch_n_go',
            'return_url'                 => $params['redirect_url'],
            'metadata[trx_id]'           => $params['trx_id'],
        ];

        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $secretKey,
                'Content-Type: application/x-www-form-urlencoded',
            ],
            CURLOPT_POSTFIELDS     => http_build_query($payload),
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode >= 400 || $response === false) {
            $msg = $response !== false ? $response : 'Connection timeout';
            throw new \RuntimeException("Touch 'n Go payment initiation failed [" . $httpCode . ']: ' . $msg);
        }

        $data = json_decode((string) $response, true);
        if (!is_array($data)) {
            throw new \RuntimeException("Touch 'n Go invalid Stripe API response");
        }

        $paymentIntentId = $this->getString($data['id'] ?? '');
        $nextAction = $this->getArray($data, 'next_action');
        $redirectToUrl = $this->getArray($nextAction, 'redirect_to_url');
        $redirectUrl = $this->getString($redirectToUrl['url'] ?? '');

        if (empty($redirectUrl)) {
            $redirectUrl = $params['redirect_url'];
        }

        return [
            'redirect_url' => $redirectUrl,
            'session_id'   => $paymentIntentId,
        ];
    }

    public function verify(array $callbackData, array $credentials): array
    {
        $secretKey = $this->getString($credentials['secret_key'] ?? '');
        $paymentIntentId = $this->getString($callbackData['payment_intent'] ?? $callbackData['id'] ?? '');

        if (empty($paymentIntentId)) {
            return [
                'success'        => false,
                'gateway_trx_id' => '',
                'status'         => 'failed',
                'error'          => "Touch 'n Go verification error: Missing payment_intent identifier.",
            ];
        }

        $ch = curl_init(self::API_URL . '/payment_intents/' . urlencode($paymentIntentId));
        if ($ch === false) {
            return [
                'success'        => false,
                'gateway_trx_id' => '',
                'status'         => 'failed',
                'error'          => "Touch 'n Go cURL initialization failed during status query.",
            ];
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $secretKey,
            ],
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode >= 400 || $response === false) {
            return [
                'success'        => false,
                'gateway_trx_id' => '',
                'status'         => 'failed',
                'error'          => "Touch 'n Go status lookup failed with HTTP code " . $httpCode,
            ];
        }

        $data = json_decode((string) $response, true);
        if (!is_array($data)) {
            return [
                'success'        => false,
                'gateway_trx_id' => '',
                'status'         => 'failed',
                'error'          => "Touch 'n Go returned invalid JSON status.",
            ];
        }

        $status = $this->getString($data['status'] ?? '');
        $success = $status === 'succeeded';
        $amountRaw = $data['amount'] ?? '';
        $amountVal = is_numeric($amountRaw) ? (string)$amountRaw : '';

        $result = [
            'success'        => $success,
            'gateway_trx_id' => $paymentIntentId,
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
        return ['MYR'];
    }
}
