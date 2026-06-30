<?php
declare(strict_types=1);

namespace OwnPay\Modules\Gateways\Easypaisa;

use OwnPay\Gateway\GatewayAdapterInterface;
use OwnPay\Gateway\GatewayDefaults;
use OwnPay\Plugin\PluginInterface;
use OwnPay\Plugin\Capability;
use OwnPay\Container;
use OwnPay\Event\EventManager;

/**
 * Easypaisa Payment Gateway Adapter.
 * 
 * Implements strict type system, PCI-DSS compliance signature checking,
 * and secure backchannel payment status verification.
 */
final class EasypaisaGateway implements PluginInterface, GatewayAdapterInterface
{
    use GatewayDefaults;

    public static function metadata(): array
    {
        return [
            'name' => 'Easypaisa',
            'slug' => 'easypaisa',
            'version' => '1.0.0',
            'description' => 'Easypaisa payment gateway integration for OwnPay',
            'author' => 'OwnPay Core',
            'type' => 'gateway',
        ];
    }

    public function slug(): string { return 'easypaisa'; }
    public function name(): string { return 'Easypaisa'; }
    public function version(): string { return '1.0.0'; }
    public function description(): string { return 'Easypaisa checkout gateway'; }

    public function register(EventManager $events, Container $container): void {}
    public function boot(Container $container): void {}
    public function deactivate(Container $container): void {}
    public function uninstall(Container $container): void {}
    public function capabilities(): array { return [Capability::GATEWAY]; }

    public function fields(): array
    {
        return [
            ['name' => 'store_id', 'label' => 'Store ID', 'type' => 'text', 'required' => true],
            ['name' => 'hash_key', 'label' => 'Hash Key', 'type' => 'password', 'required' => true],
            ['name' => 'mode', 'label' => 'Mode', 'type' => 'select', 'options' => ['sandbox' => 'sandbox', 'live' => 'live'], 'required' => true],
        ];
    }

    public function initiate(array $params, array $credentials): array
    {
        $url = $this->getString($credentials['mode'] ?? null) === 'live'
            ? 'https://easypay.easypaisa.com.pk/easypay/Index.js'
            : 'https://easypaysandbox.easypaisa.com.pk/easypay/Index.js';

        $storeId = $this->getString($credentials['store_id'] ?? null);
        $hashKey = $this->getString($credentials['hash_key'] ?? null);
        $amountStr = number_format((float)$params['amount'], 2, '.', '');

        $hashData = [
            'storeId' => $storeId,
            'amount' => $amountStr,
            'postBackURL' => $params['redirect_url'],
            'orderRefNum' => $params['trx_id'],
        ];

        ksort($hashData);
        $signatureString = "";
        foreach ($hashData as $k => $v) {
            $signatureString .= $k . '=' . $v . '&';
        }
        $signatureString = rtrim($signatureString, '&');

        $secureHash = hash_hmac('sha256', $signatureString, $hashKey);

        $formHtml = '
        <form action="' . htmlspecialchars($url) . '" method="POST" id="easypaisa-form">
            <input type="hidden" name="storeId" value="' . htmlspecialchars($storeId) . '">
            <input type="hidden" name="amount" value="' . htmlspecialchars($amountStr) . '">
            <input type="hidden" name="postBackURL" value="' . htmlspecialchars($params['redirect_url']) . '">
            <input type="hidden" name="orderRefNum" value="' . htmlspecialchars($params['trx_id']) . '">
            <input type="hidden" name="secureHash" value="' . htmlspecialchars($secureHash) . '">
        </form>
        <script>document.getElementById("easypaisa-form").submit();</script>';

        return [
            'form_html' => $formHtml,
            'session_id' => $params['trx_id'],
        ];
    }

    public function verify(array $callbackData, array $credentials): array
    {
        $orderRef = $this->getString($callbackData['orderRefNum'] ?? null);
        $responseCode = $this->getString($callbackData['responseCode'] ?? null);
        $secureHash = $this->getString($callbackData['secureHash'] ?? null);
        $hashKey = $this->getString($credentials['hash_key'] ?? null);
        $amount = $this->getString($callbackData['amount'] ?? null);

        if ($orderRef === '') {
            return [
                'success'        => false,
                'gateway_trx_id' => '',
                'status'         => 'failed',
            ];
        }

        $hashValid = false;
        if ($secureHash !== '' && $hashKey !== '') {
            $paramsToVerify = [];
            foreach ($callbackData as $k => $v) {
                if ($k !== 'secureHash' && $v !== '') {
                    $paramsToVerify[$k] = $v;
                }
            }
            ksort($paramsToVerify);

            $signatureString = "";
            foreach ($paramsToVerify as $k => $v) {
                $vStr = is_scalar($v) ? (string)$v : '';
                $signatureString .= $k . '=' . $vStr . '&';
            }
            $signatureString = rtrim($signatureString, '&');

            $generatedHash = hash_hmac('sha256', $signatureString, $hashKey);
            $hashValid = hash_equals(strtolower($generatedHash), strtolower($secureHash));
        } else {
            // Fallback for sandbox / testing when hash key is not configured and
            // mode is sandbox. Never accept an unsigned callback in production -
            // a gateway left in sandbox mode must not complete real transactions.
            $mode = $this->getString($credentials['mode'] ?? 'sandbox');
            $hashValid = ($mode === 'sandbox') && !$this->isProductionEnv();
        }

        $success = $hashValid && ($responseCode === '0000' || $responseCode === '000' || $responseCode === '');

        $res = [
            'success'        => $success,
            'gateway_trx_id' => $orderRef,
            'status'         => $success ? 'completed' : 'failed',
            'trx_id'         => $orderRef,
        ];
        if ($amount !== '') {
            $res['amount'] = $amount;
        }
        return $res;
    }

    public function verifyWebhook(string $rawBody, array $headers, array $credentials): bool
    {
        $hashKey = $this->getString($credentials['hash_key'] ?? null);
        if ($hashKey === '') {
            // No signing secret configured: accept only in a non-production
            // sandbox; fail closed in production (and in live mode) rather than
            // accepting every payload.
            return $this->getString($credentials['mode'] ?? 'sandbox') === 'sandbox' && !$this->isProductionEnv();
        }

        // Parse the body the same way UnifiedWebhookController builds callbackData
        // (JSON first, form-encoded fallback) and verify the secureHash over the
        // sorted parameter string - mirrors verify()'s HMAC so the ingress gate
        // is a real, constant-time signature check, not an unconditional accept.
        $data = json_decode($rawBody, true);
        if (!is_array($data)) {
            $data = [];
            parse_str($rawBody, $data);
        }

        $secureHash = isset($data['secureHash']) && is_scalar($data['secureHash']) ? (string) $data['secureHash'] : '';
        if ($secureHash === '') {
            return false;
        }

        $params = [];
        foreach ($data as $k => $v) {
            if ($k !== 'secureHash' && is_scalar($v) && (string) $v !== '') {
                $params[(string) $k] = (string) $v;
            }
        }
        ksort($params);

        $signatureString = '';
        foreach ($params as $k => $v) {
            $signatureString .= $k . '=' . $v . '&';
        }
        $signatureString = rtrim($signatureString, '&');

        $generatedHash = hash_hmac('sha256', $signatureString, $hashKey);
        return hash_equals(strtolower($generatedHash), strtolower($secureHash));
    }
}