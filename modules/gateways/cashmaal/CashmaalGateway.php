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
 * CashMaal payment gateway - PluginInterface + GatewayAdapterInterface.
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
            'session_id'   => $params['trx_id'],
        ];
    }

    public function verify(array $callbackData, array $credentials): array
    {
        $rawTrxId = $callbackData['trx_id'] ?? $callbackData['order_id'] ?? '';
        $trxId = is_scalar($rawTrxId) ? (string) $rawTrxId : '';
        $cmTidRaw = $callbackData['CM_TID'] ?? '';
        $cmTid = is_scalar($cmTidRaw) ? (string) $cmTidRaw : '';

        if ($cmTid === '') {
            return ['success' => false, 'gateway_trx_id' => '', 'status' => 'failed'];
        }

        $webIdRaw = $credentials['web_id'] ?? '';
        $webId = is_scalar($webIdRaw) ? (string) $webIdRaw : '';

        $url = 'https://api.cmaal.com/verify_v2?CM_TID=' . urlencode($cmTid) . '&web_id=' . urlencode($webId);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || !$response) {
            return ['success' => false, 'gateway_trx_id' => '', 'status' => 'api_error'];
        }

        $data = json_decode((string) $response, true);
        if (!is_array($data)) {
            return ['success' => false, 'gateway_trx_id' => '', 'status' => 'invalid_response'];
        }

        // status = 1 is Successful
        $statusVal = isset($data['status']) && is_scalar($data['status']) ? (string) $data['status'] : '';
        $paid = $statusVal === '1';

        $transactionId = isset($data['transaction_id']) && is_scalar($data['transaction_id']) ? (string) $data['transaction_id'] : $cmTid;
        $usdAmount = isset($data['USD_amount']) && is_scalar($data['USD_amount']) ? (string) $data['USD_amount'] : '';
        $orderId = isset($data['order_id']) && is_scalar($data['order_id']) ? (string) $data['order_id'] : (string) $trxId;

        return [
            'success'        => $paid,
            'gateway_trx_id' => $transactionId,
            'amount'         => $usdAmount,
            'status'         => $paid ? 'completed' : 'failed',
            'trx_id'         => $orderId,
        ];
    }

    /** @param array<string, mixed> $txn */
    public function handleCheckoutBefore(array $txn): void
    {
        if (isset($_GET['redirect_to']) && $_GET['redirect_to'] === 'cashmaal' && $this->container !== null) {
            $mid = is_scalar($txn['merchant_id'] ?? null) ? (int) $txn['merchant_id'] : 0;

            $configs = $this->container->get(\OwnPay\Repository\GatewayConfigRepository::class);
            $encryptor = $this->container->get(\OwnPay\Security\FieldEncryptor::class);
            if (!$configs instanceof \OwnPay\Repository\GatewayConfigRepository || !$encryptor instanceof \OwnPay\Security\FieldEncryptor) {
                return;
            }

            $credentialsEnc = $configs->forTenant($mid)->findCredentialsBySlug('cashmaal');
            $credentials = [];
            if (is_string($credentialsEnc) && $credentialsEnc !== '') {
                $decrypted = $encryptor->decrypt($credentialsEnc);
                $decoded = json_decode($decrypted, true);
                $credentials = is_array($decoded) ? $decoded : [];
            }

            $webId = isset($credentials['web_id']) && is_scalar($credentials['web_id']) ? (string) $credentials['web_id'] : '';
            $amount = $txn['amount'];
            $currency = is_scalar($txn['currency'] ?? null) ? (string) $txn['currency'] : 'USD';

            $metadataStr = is_string($txn['metadata'] ?? null) ? $txn['metadata'] : '{}';
            $meta = json_decode($metadataStr, true);
            if (!is_array($meta)) {
                $meta = [];
            }
            $custEmail = is_scalar($txn['customer_email'] ?? null) ? (string) $txn['customer_email'] : '';
            $clientEmail = $custEmail !== '' ? $custEmail : (isset($meta['customer_email']) && is_scalar($meta['customer_email']) ? (string) $meta['customer_email'] : 'customer@example.com');

            $httpHost = is_string($_SERVER['HTTP_HOST'] ?? null) ? $_SERVER['HTTP_HOST'] : 'localhost';
            $baseUrl = (empty($_SERVER['HTTPS']) ? 'http' : 'https') . '://' . $httpHost;
            $trxIdStr = is_scalar($txn['trx_id'] ?? null) ? (string) $txn['trx_id'] : '';
            $statusUrl = $baseUrl . '/checkout/' . $trxIdStr . '/status';

            // CashMaal will redirect the client back to succes_url/cancel_url.
            // On success, we append paymentID to trigger callback verification.
            $successUrl = $statusUrl . '?status=success&paymentID=' . urlencode($trxIdStr) . '&trx_id=' . urlencode($trxIdStr);
            $cancelUrl = $statusUrl . '?status=cancel';

            $amountStr = is_scalar($amount) ? (string) $amount : '';
            $currencyStr = (string) $currency;

            $nonceVal = $this->container->has('csp_nonce') ? $this->container->get('csp_nonce') : '';
            $nonceAttr = is_string($nonceVal) && $nonceVal !== '' ? ' nonce="' . htmlspecialchars($nonceVal, ENT_QUOTES, 'UTF-8') . '"' : '';

            echo '
            <!DOCTYPE html>
            <html>
            <head>
                <title>Redirecting to CashMaal...</title>
                <style' . $nonceAttr . '>
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
                    <input type="hidden" name="amount" value="' . htmlspecialchars($amountStr) . '">
                    <input type="hidden" name="currency" value="' . htmlspecialchars($currencyStr) . '">
                    <input type="hidden" name="succes_url" value="' . htmlspecialchars($successUrl) . '">
                    <input type="hidden" name="cancel_url" value="' . htmlspecialchars($cancelUrl) . '">
                    <input type="hidden" name="client_email" value="' . htmlspecialchars($clientEmail) . '">
                    <input type="hidden" name="web_id" value="' . htmlspecialchars($webId) . '">
                    <input type="hidden" name="order_id" value="' . htmlspecialchars($trxIdStr) . '">
                    <input type="hidden" name="addi_info" value="Payment ' . htmlspecialchars($trxIdStr) . '">
                </form>
                <script' . $nonceAttr . '>
                    document.getElementById("cashmaalForm").submit();
                </script>
            </body>
            </html>';
            exit;
        }
    }
}
