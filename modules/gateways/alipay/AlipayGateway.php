<?php
declare(strict_types=1);

namespace OwnPay\Modules\Gateways\Alipay;

use OwnPay\Gateway\GatewayAdapterInterface;
use OwnPay\Gateway\GatewayDefaults;
use OwnPay\Plugin\PluginInterface;
use OwnPay\Plugin\Capability;
use OwnPay\Container;
use OwnPay\Event\EventManager;

/**
 * Alipay Global Payment Gateway Adapter.
 *
 * Implements strict type system, PCI-DSS compliance signature checking,
 * and secure backchannel payment status verification.
 *
 * Security: This adapter FAILS CLOSED on signature verification. Any callback
 * that cannot be cryptographically verified with the configured Alipay RSA
 * public key is rejected unconditionally. There is no fallback or test-mode
 * bypass - an unsigned or unverifiable notification MUST NOT complete a payment.
 *
 * Remediation for GHSA-f9vm-jrm6-wcvq:
 * - Removed the insecure fallback else-branch in verify() that returned
 *   $verified = true when sign was absent or alipay_public_key was not configured.
 * - Made alipay_public_key a required field so a live configuration always has
 *   the key needed to verify notifications.
 * - Implemented real RSA signature verification in verifyWebhook() instead of
 *   returning true unconditionally.
 */
final class AlipayGateway implements PluginInterface, GatewayAdapterInterface
{
    use GatewayDefaults;

    public static function metadata(): array
    {
        return [
            'name'        => 'Alipay Global',
            'slug'        => 'alipay',
            'version'     => '1.0.1',
            'description' => 'Alipay Global payment gateway integration for OwnPay',
            'author'      => 'OwnPay Core',
            'type'        => 'gateway',
        ];
    }

    public function slug(): string { return 'alipay'; }
    public function name(): string { return 'Alipay Global'; }
    public function version(): string { return '1.0.1'; }
    public function description(): string { return 'Alipay Global checkout gateway'; }

    public function register(EventManager $events, Container $container): void {}
    public function boot(Container $container): void {}
    public function deactivate(Container $container): void {}
    public function uninstall(Container $container): void {}
    public function capabilities(): array { return [Capability::GATEWAY]; }

    public function fields(): array
    {
        return [
            ['name' => 'app_id',           'label' => 'App ID (Partner ID)',  'type' => 'text',     'required' => true],
            ['name' => 'private_key',      'label' => 'Private Key',          'type' => 'textarea', 'required' => true],
            // Required: without the Alipay RSA public key, payment notifications
            // cannot be cryptographically verified and ALL callbacks would be rejected.
            ['name' => 'alipay_public_key', 'label' => 'Alipay Public Key',  'type' => 'textarea', 'required' => true],
        ];
    }

    public function initiate(array $params, array $credentials): array
    {
        $url = 'https://openapi.alipay.com/gateway.do';

        $bizContent = [
            'subject'      => 'Payment ' . $params['trx_id'],
            'out_trade_no' => $params['trx_id'],
            'total_amount' => number_format((float)$params['amount'], 2, '.', ''),
            'product_code' => 'FAST_INSTANT_TRADE_PAY',
        ];

        $sysParams = [
            'app_id'      => $this->getString($credentials['app_id'] ?? null),
            'method'      => 'alipay.trade.page.pay',
            'charset'     => 'utf-8',
            'sign_type'   => 'RSA2',
            'timestamp'   => date('Y-m-d H:i:s'),
            'version'     => '1.0',
            'return_url'  => $params['redirect_url'],
            'notify_url'  => $params['redirect_url'],
            'biz_content' => (string) json_encode($bizContent),
        ];

        // Sign logic
        ksort($sysParams);
        $queryArr = [];
        foreach ($sysParams as $k => $v) {
            if ($v !== '') {
                $queryArr[] = "{$k}={$v}";
            }
        }
        $queryStr = implode('&', $queryArr);

        $privateKey = $this->getString($credentials['private_key'] ?? null);
        $privKeyObj = openssl_pkey_get_private($privateKey);
        $sig = '';
        if ($privKeyObj !== false) {
            openssl_sign($queryStr, $sig, $privKeyObj, OPENSSL_ALGO_SHA256);
        }
        $sysParams['sign'] = base64_encode($sig);

        $redirectUrl = $url . '?' . http_build_query($sysParams);

        return [
            'redirect_url' => $redirectUrl,
            'session_id'   => $params['trx_id'],
        ];
    }

    public function verify(array $callbackData, array $credentials): array
    {
        $trxId           = $this->getString($callbackData['out_trade_no'] ?? null);
        $amount          = $this->getString($callbackData['total_amount'] ?? null);
        $tradeNo         = $this->getString($callbackData['trade_no'] ?? null);
        $sign            = $this->getString($callbackData['sign'] ?? null);
        $signType        = $this->getString($callbackData['sign_type'] ?? 'RSA2');
        $alipayPublicKey = $this->getString($credentials['alipay_public_key'] ?? null);

        if ($trxId === '') {
            return [
                'success'        => false,
                'gateway_trx_id' => '',
                'status'         => 'failed',
            ];
        }

        // Fail closed: an Alipay notification whose RSA signature cannot be
        // cryptographically verified (missing sign field, or the merchant has
        // not configured alipay_public_key) is untrusted and MUST NOT complete
        // a payment. Never treat an unsigned or unverifiable callback as verified.
        $verified = $this->verifyRsaSignature($callbackData, $sign, $signType, $alipayPublicKey);

        $tradeStatus = $this->getString($callbackData['trade_status'] ?? '');
        $success = $verified && ($tradeStatus === 'TRADE_SUCCESS' || $tradeStatus === 'TRADE_FINISHED');

        $res = [
            'success'        => $success,
            'gateway_trx_id' => $tradeNo !== '' ? $tradeNo : $trxId,
            'status'         => $success ? 'completed' : 'failed',
            'trx_id'         => $trxId,
        ];
        if ($amount !== '') {
            $res['amount'] = $amount;
        }
        return $res;
    }

    /**
     * Verifies the RSA signature on an incoming Alipay payment notification.
     *
     * This method FAILS CLOSED: it returns false whenever:
     * - The sign field is absent or empty.
     * - The alipay_public_key credential is not configured.
     * - The public key cannot be loaded by openssl.
     * - The RSA signature verification fails.
     *
     * There is NO fallback or test-mode bypass. Every Alipay notification must
     * carry a valid RSA2 (SHA-256) or RSA (SHA-1) signature to be trusted.
     *
     * @param array<string, mixed> $callbackData The full notification payload.
     * @param string $sign                        The base64-encoded signature from the payload.
     * @param string $signType                    'RSA2' (SHA-256) or 'RSA' (SHA-1).
     * @param string $alipayPublicKey             The configured Alipay RSA public key (PEM or bare base64).
     * @return bool True only when the RSA signature is cryptographically valid.
     */
    private function verifyRsaSignature(
        array $callbackData,
        string $sign,
        string $signType,
        string $alipayPublicKey
    ): bool {
        // Reject immediately if either the signature or the public key is absent.
        if ($sign === '' || $alipayPublicKey === '') {
            return false;
        }

        // Build the canonical query string: all params except sign and sign_type,
        // sorted by key, concatenated as key=value pairs separated by '&'.
        $paramsToVerify = [];
        foreach ($callbackData as $k => $v) {
            if ($k !== 'sign' && $k !== 'sign_type' && $v !== '') {
                $paramsToVerify[$k] = $v;
            }
        }
        ksort($paramsToVerify);
        $queryArr = [];
        foreach ($paramsToVerify as $k => $v) {
            $vStr = is_scalar($v) ? (string) $v : '';
            $queryArr[] = "{$k}={$vStr}";
        }
        $queryStr = implode('&', $queryArr);

        // Normalize the public key into PEM format if it arrived as a bare base64 string.
        $publicKeyPem = $alipayPublicKey;
        if (strpos($publicKeyPem, '-----BEGIN PUBLIC KEY-----') === false) {
            $publicKeyPem = "-----BEGIN PUBLIC KEY-----\n"
                . wordwrap($publicKeyPem, 64, "\n", true)
                . "\n-----END PUBLIC KEY-----";
        }

        $pubKeyObj = openssl_pkey_get_public($publicKeyPem);
        if ($pubKeyObj === false) {
            return false;
        }

        $algo = ($signType === 'RSA2') ? OPENSSL_ALGO_SHA256 : OPENSSL_ALGO_SHA1;
        $decodedSign = base64_decode($sign, true);
        if ($decodedSign === false) {
            return false;
        }

        return openssl_verify($queryStr, $decodedSign, $pubKeyObj, $algo) === 1;
    }

    /**
     * Verifies the authenticity of an incoming Alipay webhook notification.
     *
     * Parses the raw body (JSON or form-encoded), extracts the sign field,
     * and delegates to verifyRsaSignature() which fails closed. This ensures
     * the controller-level signature gate also rejects forged unsigned callbacks.
     *
     * {@inheritdoc}
     */
    public function verifyWebhook(string $rawBody, array $headers, array $credentials): bool
    {
        // Attempt JSON parse first; on failure fall back to form-encoded.
        // parse_str() always produces an array, so $data is always array<mixed>.
        // An empty raw body will produce an empty array, which verifyRsaSignature
        // will reject because sign will be absent.
        $jsonDecoded = json_decode($rawBody, true);
        if (is_array($jsonDecoded)) {
            $data = $jsonDecoded;
        } else {
            $data = [];
            parse_str($rawBody, $data);
        }

        $sign            = $this->getString($data['sign'] ?? null);
        $signType        = $this->getString($data['sign_type'] ?? 'RSA2');
        $alipayPublicKey = $this->getString($credentials['alipay_public_key'] ?? null);

        return $this->verifyRsaSignature($data, $sign, $signType, $alipayPublicKey);
    }
}