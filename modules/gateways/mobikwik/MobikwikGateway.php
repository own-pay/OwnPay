<?php
declare(strict_types=1);

namespace OwnPay\Modules\Gateways\Mobikwik;

use OwnPay\Gateway\GatewayAdapterInterface;
use OwnPay\Gateway\GatewayDefaults;
use OwnPay\Plugin\PluginInterface;
use OwnPay\Plugin\Capability;
use OwnPay\Container;
use OwnPay\Event\EventManager;

/**
 * MobiKwik payment gateway adapter implementing the Zaakpay hosted checkout redirection flow.
 */
final class MobikwikGateway implements PluginInterface, GatewayAdapterInterface
{
    use GatewayDefaults;

    private const SANDBOX_URL = 'https://zaakstaging.zaakpay.com/api/paymentTransact/v8';
    private const LIVE_URL    = 'https://api.zaakpay.com/api/paymentTransact/v8';

    /**
     * Returns the plugin metadata array.
     */
    public static function metadata(): array
    {
        return [
            'name' => 'MobiKwik',
            'slug' => 'mobikwik',
            'version' => '1.0.0',
            'description' => 'MobiKwik Zaakpay hosted checkout integration',
            'author' => 'OwnPay Core',
            'type' => 'gateway',
        ];
    }

    public function slug(): string { return 'mobikwik'; }
    public function name(): string { return 'MobiKwik'; }
    public function version(): string { return '1.0.0'; }
    public function description(): string { return 'MobiKwik Zaakpay hosted checkout integration'; }

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
            ['name' => 'merchant_id', 'label' => 'Merchant Identifier (MID)', 'type' => 'text', 'required' => true],
            ['name' => 'secret_key', 'label' => 'Secret Key', 'type' => 'password', 'required' => true],
            ['name' => 'mode', 'label' => 'Mode', 'type' => 'select', 'options' => ['sandbox' => 'sandbox', 'live' => 'live'], 'required' => true],
        ];
    }

    /**
     * Initiates a payment process with MobiKwik (Zaakpay).
     */
    public function initiate(array $params, array $credentials): array
    {
        $merchantId = $this->getString($credentials['merchant_id'] ?? '');
        $secretKey = $this->getString($credentials['secret_key'] ?? '');
        $mode = $this->getString($credentials['mode'] ?? 'sandbox');

        if (empty($merchantId) || empty($secretKey)) {
            throw new \RuntimeException('MobiKwik error: Missing Merchant Identifier (MID) or Secret Key.');
        }

        // Live sandbox isolation guard
        if ($mode === 'live') {
            if (str_starts_with($merchantId, 'TEST') || 
                str_starts_with($secretKey, 'TEST') || 
                str_starts_with($params['trx_id'], 'SIM_')) {
                throw new \RuntimeException('Sandbox simulation input/credentials rejected in Live production mode.');
            }
        }

        $checkoutUrl = $mode === 'live' ? self::LIVE_URL : self::SANDBOX_URL;

        // Zaakpay takes amount in subunits/cents!
        // We cast parameters and calculate with BCMath to ensure precision of subunits
        $amount = $params['amount'];
        if (!is_numeric($amount)) {
            throw new \RuntimeException('MobiKwik error: Invalid transaction amount format.');
        }

        // Convert INR amount to cents (1 INR = 100 subunits) using bcmul
        $amountCents = bcmul((string)$amount, '100', 0);

        $payload = [
            'merchantIdentifier' => $merchantId,
            'orderId'            => $params['trx_id'],
            'amount'             => $amountCents,
            'currency'           => 'INR',
            'returnUrl'          => $params['redirect_url'],
            'buyerEmail'         => 'customer@ownpay.test',
            'buyerPhone'         => '9999999999',
            'buyerName'          => 'OwnPay Customer',
        ];

        // Generate checksum over sorted fields
        $checksum = self::generateChecksum($payload, $secretKey);
        $payload['checksum'] = $checksum;

        $formHtml = '<form id="mobikwik_checkout_form" method="post" action="' . htmlspecialchars($checkoutUrl) . '">';
        foreach ($payload as $key => $value) {
            $formHtml .= '<input type="hidden" name="' . htmlspecialchars($key) . '" value="' . htmlspecialchars((string)$value) . '" />';
        }
        $formHtml .= '</form>
        <script type="text/javascript">
            document.getElementById("mobikwik_checkout_form").submit();
        </script>
        ';

        return [
            'form_html'  => $formHtml,
            'session_id' => $params['trx_id'],
        ];
    }

    /**
     * Verifies the payment status from a callback or webhook.
     */
    public function verify(array $callbackData, array $credentials): array
    {
        $secretKey = $this->getString($credentials['secret_key'] ?? '');

        $orderIdRaw = $callbackData['orderId'] ?? '';
        $orderId = is_scalar($orderIdRaw) ? (string) $orderIdRaw : '';
        $checksumProvided = $this->getString($callbackData['checksum'] ?? '');
        $responseCode = $this->getString($callbackData['responseCode'] ?? '');
        $amountCents = $this->getString($callbackData['amount'] ?? '');

        if (empty($orderId)) {
            return [
                'success'        => false,
                'gateway_trx_id' => '',
                'status'         => 'failed',
                'error'          => 'MobiKwik verification error: Missing orderId parameter.',
            ];
        }

        // Validate Checksum Signature
        $verifyData = $callbackData;
        unset($verifyData['checksum']);

        $checksumCalculated = self::generateChecksum($verifyData, $secretKey);

        if (!hash_equals($checksumCalculated, $checksumProvided)) {
            return [
                'success'        => false,
                'gateway_trx_id' => '',
                'status'         => 'failed',
                'error'          => 'MobiKwik verification error: Invalid checksum signature.',
            ];
        }

        // Zaakpay returns responseCode 100 for successful transaction
        $success = $responseCode === '100';

        // Convert cents back to INR amount (using bcdiv)
        $amountVal = null;
        if ($amountCents !== '' && is_numeric($amountCents)) {
            $amountVal = bcdiv($amountCents, '100', 2);
        }

        $result = [
            'success'        => $success,
            'gateway_trx_id' => $orderId,
            'status'         => $success ? 'completed' : 'failed',
        ];

        if ($amountVal !== null) {
            $result['amount'] = $amountVal;
        }

        return $result;
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
        return ['INR'];
    }

    /**
     * Generates Zaakpay SHA-256 checksum based on sorted parameters.
     *
     * @param array<string, mixed> $data
     * @param string $secretKey
     * @return string
     */
    private static function generateChecksum(array $data, string $secretKey): string
    {
        // 1. Sort alphabetically by key
        ksort($data);

        // 2. Build the parameter query string
        $all = "";
        foreach ($data as $key => $value) {
            if ($value !== "" && $value !== null && is_scalar($value)) {
                $all .= $key . "=" . (string)$value . "&";
            }
        }

        // 3. Append the secret key
        $all .= "secret=" . $secretKey;

        // 4. Generate the SHA-256 hash
        return hash('sha256', $all);
    }
}
