<?php
declare(strict_types=1);

namespace OwnPay\Modules\Gateways\Payu;

use OwnPay\Gateway\GatewayAdapterInterface;
use OwnPay\Gateway\GatewayDefaults;
use OwnPay\Plugin\PluginInterface;
use OwnPay\Plugin\Capability;
use OwnPay\Container;
use OwnPay\Event\EventManager;

/**
 * PayU India payment gateway adapter implementing the secure hosted checkout redirection.
 */
final class PayuGateway implements PluginInterface, GatewayAdapterInterface
{
    use GatewayDefaults;

    private const SANDBOX_URL = 'https://test.payu.in/_payment';
    private const LIVE_URL    = 'https://secure.payu.in/_payment';

    /**
     * Returns the plugin metadata array.
     */
    public static function metadata(): array
    {
        return [
            'name' => 'PayU India',
            'slug' => 'payu',
            'version' => '1.0.0',
            'description' => 'PayU India secure checkout integration',
            'author' => 'OwnPay Core',
            'type' => 'gateway',
        ];
    }

    public function slug(): string { return 'payu'; }
    public function name(): string { return 'PayU India'; }
    public function version(): string { return '1.0.0'; }
    public function description(): string { return 'PayU India secure checkout integration'; }

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
            ['name' => 'merchant_key', 'label' => 'Merchant Key', 'type' => 'text', 'required' => true],
            ['name' => 'salt', 'label' => 'Merchant Salt', 'type' => 'password', 'required' => true],
            ['name' => 'mode', 'label' => 'Mode', 'type' => 'select', 'options' => ['sandbox' => 'sandbox', 'live' => 'live'], 'required' => true],
        ];
    }

    /**
     * Initiates a payment process with PayU India by rendering a secure auto-submitting POST form.
     */
    public function initiate(array $params, array $credentials): array
    {
        $key = $this->getString($credentials['merchant_key'] ?? '');
        $salt = $this->getString($credentials['salt'] ?? '');
        $mode = $this->getString($credentials['mode'] ?? 'sandbox');

        if (empty($key) || empty($salt)) {
            throw new \RuntimeException('PayU error: Missing Merchant Key or Salt credentials.');
        }

        // Live sandbox isolation guard
        if ($mode === 'live') {
            if (str_starts_with($key, 'TEST') || 
                str_starts_with($salt, 'TEST') || 
                str_starts_with($params['trx_id'], 'SIM_')) {
                throw new \RuntimeException('Sandbox simulation input/credentials rejected in Live production mode.');
            }
        }

        $checkoutUrl = $mode === 'live' ? self::LIVE_URL : self::SANDBOX_URL;

        // Use standard INR decimal formatting
        $amount = $params['amount'];
        if (!is_numeric($amount)) {
            throw new \RuntimeException('PayU error: Invalid transaction amount format.');
        }
        $formattedAmount = number_format((float)$amount, 2, '.', '');

        $productInfo = 'Order Payment ' . $params['trx_id'];
        $firstname = 'OwnPay Customer';
        $email = 'customer@ownpay.test';
        $phone = '9999999999';

        $udf1 = '';
        $udf2 = '';
        $udf3 = '';
        $udf4 = '';
        $udf5 = '';

        // Signature Hash Formula:
        // sha512(key|txnid|amount|productinfo|firstname|email|udf1|udf2|udf3|udf4|udf5||||||salt)
        $hashString = "{$key}|{$params['trx_id']}|{$formattedAmount}|{$productInfo}|{$firstname}|{$email}|{$udf1}|{$udf2}|{$udf3}|{$udf4}|{$udf5}||||||{$salt}";
        $hash = strtolower(hash('sha512', $hashString));

        $formHtml = '
        <form id="payu_checkout_form" method="post" action="' . htmlspecialchars($checkoutUrl) . '">
            <input type="hidden" name="key" value="' . htmlspecialchars($key) . '" />
            <input type="hidden" name="txnid" value="' . htmlspecialchars($params['trx_id']) . '" />
            <input type="hidden" name="amount" value="' . htmlspecialchars($formattedAmount) . '" />
            <input type="hidden" name="productinfo" value="' . htmlspecialchars($productInfo) . '" />
            <input type="hidden" name="firstname" value="' . htmlspecialchars($firstname) . '" />
            <input type="hidden" name="email" value="' . htmlspecialchars($email) . '" />
            <input type="hidden" name="phone" value="' . htmlspecialchars($phone) . '" />
            <input type="hidden" name="surl" value="' . htmlspecialchars($params['redirect_url']) . '" />
            <input type="hidden" name="furl" value="' . htmlspecialchars($params['redirect_url']) . '" />
            <input type="hidden" name="udf1" value="' . htmlspecialchars($udf1) . '" />
            <input type="hidden" name="udf2" value="' . htmlspecialchars($udf2) . '" />
            <input type="hidden" name="udf3" value="' . htmlspecialchars($udf3) . '" />
            <input type="hidden" name="udf4" value="' . htmlspecialchars($udf4) . '" />
            <input type="hidden" name="udf5" value="' . htmlspecialchars($udf5) . '" />
            <input type="hidden" name="hash" value="' . htmlspecialchars($hash) . '" />
        </form>
        <script type="text/javascript">
            document.getElementById("payu_checkout_form").submit();
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
        $salt = $this->getString($credentials['salt'] ?? '');

        $status = $this->getString($callbackData['status'] ?? '');
        $amount = $this->getString($callbackData['amount'] ?? '');
        $txnid = $this->getString($callbackData['txnid'] ?? '');
        $key = $this->getString($callbackData['key'] ?? '');
        $productinfo = $this->getString($callbackData['productinfo'] ?? '');
        $firstname = $this->getString($callbackData['firstname'] ?? '');
        $email = $this->getString($callbackData['email'] ?? '');
        $udf1 = $this->getString($callbackData['udf1'] ?? '');
        $udf2 = $this->getString($callbackData['udf2'] ?? '');
        $udf3 = $this->getString($callbackData['udf3'] ?? '');
        $udf4 = $this->getString($callbackData['udf4'] ?? '');
        $udf5 = $this->getString($callbackData['udf5'] ?? '');
        $additionalCharges = $this->getString($callbackData['additionalCharges'] ?? '');
        $hashProvided = $this->getString($callbackData['hash'] ?? '');

        if (empty($txnid)) {
            return [
                'success'        => false,
                'gateway_trx_id' => '',
                'status'         => 'failed',
                'error'          => 'PayU verification error: Missing txnid parameter.',
            ];
        }

        // Reverse Hashing check formula:
        // With additionalCharges: sha512(additionalCharges|salt|status||||||udf5|udf4|udf3|udf2|udf1|email|firstname|productinfo|amount|txnid|key)
        // Without: sha512(salt|status||||||udf5|udf4|udf3|udf2|udf1|email|firstname|productinfo|amount|txnid|key)
        if ($additionalCharges !== '') {
            $hashString = "{$additionalCharges}|{$salt}|{$status}||||||{$udf5}|{$udf4}|{$udf3}|{$udf2}|{$udf1}|{$email}|{$firstname}|{$productinfo}|{$amount}|{$txnid}|{$key}";
        } else {
            $hashString = "{$salt}|{$status}||||||{$udf5}|{$udf4}|{$udf3}|{$udf2}|{$udf1}|{$email}|{$firstname}|{$productinfo}|{$amount}|{$txnid}|{$key}";
        }

        $hashCalculated = strtolower(hash('sha512', $hashString));
        $isSignatureValid = hash_equals($hashCalculated, $hashProvided);

        if (!$isSignatureValid) {
            return [
                'success'        => false,
                'gateway_trx_id' => '',
                'status'         => 'failed',
                'error'          => 'PayU verification error: Invalid checksum reverse signature.',
            ];
        }

        $success = $status === 'success';
        $mihpayid = $this->getString($callbackData['mihpayid'] ?? '');

        $formattedAmount = null;
        if (is_numeric($amount)) {
            $formattedAmount = number_format((float)$amount, 2, '.', '');
        }

        $result = [
            'success'        => $success,
            'gateway_trx_id' => $mihpayid ?: $txnid,
            'status'         => $success ? 'completed' : 'failed',
        ];

        if ($formattedAmount !== null) {
            $result['amount'] = $formattedAmount;
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
}
