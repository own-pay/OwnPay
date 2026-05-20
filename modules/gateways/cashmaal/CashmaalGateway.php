<?php
declare(strict_types=1);

namespace OwnPay\Modules\Gateways\Cashmaal;

use OwnPay\Gateway\GatewayAdapterInterface;
use OwnPay\Gateway\GatewayDefaults;
use OwnPay\Plugin\PluginInterface;
use OwnPay\Plugin\Capability;
use OwnPay\Container;
use OwnPay\Event\EventManager;

/**
 * CashMaal payment gateway — PluginInterface + GatewayAdapterInterface.
 */
final class CashmaalGateway implements PluginInterface, GatewayAdapterInterface
{
    use GatewayDefaults;

    private ?Container $container = null;

    public static function metadata(): array
    {
        return [
            'name' => 'CashMaal', 'slug' => 'cashmaal', 'version' => '1.0.0',
            'description' => 'CashMaal payment gateway integration',
            'author' => 'OwnPay Core', 'type' => 'gateway',
        ];
    }

    public function slug(): string { return 'cashmaal'; }
    public function name(): string { return 'CashMaal'; }
    public function version(): string { return '1.0.0'; }
    public function description(): string { return 'CashMaal payment gateway integration'; }

    public function register(EventManager $events, Container $container): void
    {
        $events->addAction('checkout.before', [$this, 'handleCheckoutBefore']);
    }

    public function boot(Container $container): void
    {
        $this->container = $container;
    }

    public function deactivate(Container $container): void {}
    public function uninstall(Container $container): void {}

    public function capabilities(): array
    {
        return [Capability::GATEWAY];
    }

    public function fields(): array
    {
        return [
            ['name' => 'web_id', 'label' => 'Website ID (web_id)', 'type' => 'text', 'required' => true],
        ];
    }

    public function initiate(array $params, array $credentials): array
    {
        // For CashMaal, since it is a form-based POST gateway, we redirect the user
        // back to the checkout page with a redirect_to parameter.
        // The checkout page will fire the checkout.before hook, where we intercept
        // and render the self-submitting POST form.
        $checkoutUrl = str_replace('/status', '', $params['redirect_url']);
        $separator = (strpos($checkoutUrl, '?') !== false) ? '&' : '?';

        return [
            'redirect_url' => $checkoutUrl . $separator . 'redirect_to=cashmaal',
            'session_id'   => $params['trx_id'] ?? '',
        ];
    }

    public function verify(array $callbackData, array $credentials): array
    {
        $trxId = $callbackData['trx_id'] ?? $callbackData['order_id'] ?? '';
        $cmTid = $callbackData['CM_TID'] ?? '';

        if ($cmTid === '') {
            return ['success' => false, 'gateway_trx_id' => '', 'status' => 'failed'];
        }

        $webId = $credentials['web_id'] ?? '';

        $url = 'https://api.cmaal.com/verify_v2?CM_TID=' . urlencode($cmTid) . '&web_id=' . urlencode($webId);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || !$response) {
            return ['success' => false, 'gateway_trx_id' => '', 'status' => 'api_error'];
        }

        $data = json_decode($response, true);
        if (!is_array($data)) {
            return ['success' => false, 'gateway_trx_id' => '', 'status' => 'invalid_response'];
        }

        // status = 1 is Successful
        $paid = isset($data['status']) && (string)$data['status'] === '1';

        return [
            'success'        => $paid,
            'gateway_trx_id' => $data['transaction_id'] ?? $cmTid,
            'amount'         => $data['USD_amount'] ?? null,
            'status'         => $paid ? 'completed' : 'failed',
            'trx_id'         => $data['order_id'] ?? $trxId,
        ];
    }

    public function handleCheckoutBefore(array $txn): void
    {
        if (isset($_GET['redirect_to']) && $_GET['redirect_to'] === 'cashmaal' && $this->container !== null) {
            $mid = (int) $txn['merchant_id'];

            $configs = $this->container->get(\OwnPay\Repository\GatewayConfigRepository::class);
            $encryptor = $this->container->get(\OwnPay\Security\FieldEncryptor::class);

            $credentialsEnc = $configs->forTenant($mid)->findCredentialsBySlug('cashmaal');
            $credentials = [];
            if ($credentialsEnc) {
                $decrypted = $encryptor->decrypt($credentialsEnc);
                $credentials = json_decode($decrypted, true) ?: [];
            }

            $webId = $credentials['web_id'] ?? '';
            $amount = $txn['amount'];
            $currency = $txn['currency'] ?? 'USD';

            $meta = json_decode($txn['metadata'] ?? '{}', true);
            $clientEmail = $txn['customer_email'] ?? $meta['customer_email'] ?? 'customer@example.com';

            $baseUrl = (empty($_SERVER['HTTPS']) ? 'http' : 'https') . '://' . $_SERVER['HTTP_HOST'];
            $statusUrl = $baseUrl . '/checkout/' . $txn['trx_id'] . '/status';

            // CashMaal will redirect the client back to succes_url/cancel_url.
            // On success, we append paymentID to trigger callback verification.
            $successUrl = $statusUrl . '?status=success&paymentID=' . urlencode($txn['trx_id']) . '&trx_id=' . urlencode($txn['trx_id']);
            $cancelUrl = $statusUrl . '?status=cancel';

            echo '
            <!DOCTYPE html>
            <html>
            <head>
                <title>Redirecting to CashMaal...</title>
                <style>
                    body { font-family: sans-serif; text-align: center; padding: 50px; background: #f8fafc; color: #1e293b; }
                    .loader { border: 4px solid #f3f3f3; border-top: 4px solid #1f95f4; border-radius: 50%; width: 40px; height: 40px; animation: spin 1s linear infinite; margin: 20px auto; }
                    @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
                </style>
            </head>
            <body>
                <div class="loader"></div>
                <p>Redirecting to CashMaal payment gateway, please wait...</p>
                <form id="cashmaalForm" action="https://cmaal.com/Pay/" method="POST">
                    <input type="hidden" name="pay_method" value="">
                    <input type="hidden" name="amount" value="' . htmlspecialchars((string)$amount) . '">
                    <input type="hidden" name="currency" value="' . htmlspecialchars($currency) . '">
                    <input type="hidden" name="succes_url" value="' . htmlspecialchars($successUrl) . '">
                    <input type="hidden" name="cancel_url" value="' . htmlspecialchars($cancelUrl) . '">
                    <input type="hidden" name="client_email" value="' . htmlspecialchars($clientEmail) . '">
                    <input type="hidden" name="web_id" value="' . htmlspecialchars($webId) . '">
                    <input type="hidden" name="order_id" value="' . htmlspecialchars($txn['trx_id']) . '">
                    <input type="hidden" name="addi_info" value="Payment ' . htmlspecialchars($txn['trx_id']) . '">
                </form>
                <script>
                    document.getElementById("cashmaalForm").submit();
                </script>
            </body>
            </html>';
            exit;
        }
    }
}
