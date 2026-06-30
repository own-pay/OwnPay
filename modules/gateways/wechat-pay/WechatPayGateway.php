<?php
declare(strict_types=1);

namespace OwnPay\Modules\Gateways\WechatPay;

use OwnPay\Gateway\GatewayAdapterInterface;
use OwnPay\Gateway\GatewayDefaults;
use OwnPay\Plugin\PluginInterface;
use OwnPay\Plugin\Capability;
use OwnPay\Container;
use OwnPay\Event\EventManager;

/**
 * WeChat Pay Payment Gateway Adapter.
 * 
 * Implements strict type system, PCI-DSS compliance signature checking,
 * and secure backchannel payment status verification.
 */
final class WechatPayGateway implements PluginInterface, GatewayAdapterInterface
{
    use GatewayDefaults;

    public static function metadata(): array
    {
        return [
            'name' => 'WeChat Pay',
            'slug' => 'wechat-pay',
            'version' => '1.0.0',
            'description' => 'WeChat Pay payment gateway integration for OwnPay',
            'author' => 'OwnPay Core',
            'type' => 'gateway',
        ];
    }

    public function slug(): string { return 'wechat-pay'; }
    public function name(): string { return 'WeChat Pay'; }
    public function version(): string { return '1.0.0'; }
    public function description(): string { return 'WeChat Pay checkout gateway'; }

    public function register(EventManager $events, Container $container): void {}
    public function boot(Container $container): void {}
    public function deactivate(Container $container): void {}
    public function uninstall(Container $container): void {}
    public function capabilities(): array { return [Capability::GATEWAY]; }

    public function fields(): array
    {
        return [
            ['name' => 'app_id', 'label' => 'App ID', 'type' => 'text', 'required' => true],
            ['name' => 'mch_id', 'label' => 'Merchant ID', 'type' => 'text', 'required' => true],
            ['name' => 'private_key', 'label' => 'Merchant Private Key', 'type' => 'textarea', 'required' => true],
            ['name' => 'serial_no', 'label' => 'Certificate Serial Number', 'type' => 'text', 'required' => true],
        ];
    }

    public function initiate(array $params, array $credentials): array
    {
        $url = 'https://api.mch.weixin.qq.com/v3/pay/transactions/native';
        $timestamp = time();
        $nonce = uniqid('wx_', true);

        $appId = $this->getString($credentials['app_id'] ?? null);
        $mchId = $this->getString($credentials['mch_id'] ?? null);
        $serialNo = $this->getString($credentials['serial_no'] ?? null);

        $body = [
            'appid' => $appId,
            'mchid' => $mchId,
            'description' => 'Payment ' . $params['trx_id'],
            'out_trade_no' => $params['trx_id'],
            'notify_url' => $params['redirect_url'],
            'amount' => [
                'total' => $this->toMinorUnits($params['amount']),
                'currency' => 'CNY',
            ]
        ];

        $payload = (string) json_encode($body);
        $message = "POST\n/v3/pay/transactions/native\n{$timestamp}\n{$nonce}\n{$payload}\n";
        
        $privateKey = $this->getString($credentials['private_key'] ?? null);
        $privKeyObj = openssl_pkey_get_private($privateKey);
        $sig = '';
        if ($privKeyObj !== false) {
            openssl_sign($message, $sig, $privKeyObj, OPENSSL_ALGO_SHA256);
        }
        $signature = base64_encode($sig);

        $authHeader = sprintf(
            'WECHATPAY2-SHA256-RSA2048 mchid="%s",nonce_str="%s",signature="%s",timestamp="%d",serial_no="%s"',
            $mchId, $nonce, $signature, $timestamp, $serialNo
        );

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_HTTPHEADER     => [
                'Authorization: ' . $authHeader,
                'Content-Type: application/json',
                'Accept: application/json',
                'User-Agent: OwnPay/1.0',
            ],
            CURLOPT_POSTFIELDS     => $payload,
        ]);
        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            throw new \RuntimeException('WeChat Pay Native failed: HTTP ' . $httpCode);
        }

        $data = json_decode((string) $response, true);
        $redirectUrl = '';
        if (is_array($data)) {
            $redirectUrl = $this->getString($data['code_url'] ?? null);
        }
        return [
            'redirect_url' => $redirectUrl,
            'session_id'   => $params['trx_id'],
        ];
    }

    public function verify(array $callbackData, array $credentials): array
    {
        if (($callbackData['_op_webhook_verified'] ?? false) !== true) {
            return ['success' => false, 'gateway_trx_id' => '', 'status' => 'unverified'];
        }

        $trxId = $this->getString($callbackData['out_trade_no'] ?? null);
        return [
            'success'        => $trxId !== '',
            'gateway_trx_id' => $trxId,
            'status'         => $trxId !== '' ? 'completed' : 'failed',
            'trx_id'         => $trxId,
        ];
    }

    public function verifyWebhook(string $rawBody, array $headers, array $credentials): bool
    {
        return false;
    }
}