<?php
declare(strict_types=1);

namespace OwnPay\Modules\Gateways\NagadMerchantApi;

use OwnPay\Gateway\GatewayAdapterInterface;
use OwnPay\Gateway\GatewayDefaults;
use OwnPay\Plugin\PluginInterface;
use OwnPay\Plugin\Capability;
use OwnPay\Container;
use OwnPay\Event\EventManager;

/**
 * Nagad Merchant API gateway — PluginInterface + GatewayAdapterInterface.
 */
final class NagadMerchantApiGateway implements PluginInterface, GatewayAdapterInterface
{
    use GatewayDefaults;

    private const SANDBOX_URL = 'http://sandbox.mynagad.com:10080/remote-payment-gateway-1.0/api/dfs/';
    private const LIVE_URL    = 'https://api.mynagad.com/api/dfs/';

    public static function metadata(): array
    {
        return [
            'name' => 'Nagad Merchant API', 'slug' => 'nagad-merchant-api', 'version' => '1.0.0',
            'description' => 'Nagad Merchant API payment gateway integration',
            'author' => 'OwnPay Core', 'type' => 'gateway',
        ];
    }

    public function slug(): string { return 'nagad-merchant-api'; }
    public function name(): string { return 'Nagad Merchant API'; }
    public function version(): string { return '1.0.0'; }
    public function description(): string { return 'Nagad Merchant API payment gateway integration'; }

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
            ['name' => 'nagad_app_account', 'label' => 'Nagad App Account (phone)', 'type' => 'text', 'required' => true],
            ['name' => 'nagad_merchant_id', 'label' => 'Nagad Merchant ID', 'type' => 'text', 'required' => true],
            ['name' => 'nagad_private_key', 'label' => 'Merchant Private Key', 'type' => 'textarea', 'required' => true],
            ['name' => 'nagad_public_key', 'label' => 'Nagad PG Public Key', 'type' => 'textarea', 'required' => true],
            ['name' => 'nagad_mode', 'label' => 'Mode', 'type' => 'select', 'options' => ['sandbox' => 'sandbox', 'live' => 'live'], 'required' => true],
        ];
    }

    public function initiate(array $params, array $credentials): array
    {
        $mode = $credentials['nagad_mode'] ?? 'sandbox';
        $baseUrl = $mode === 'live' ? self::LIVE_URL : self::SANDBOX_URL;

        $merchantId = $credentials['nagad_merchant_id'] ?? '';
        $trxId = $params['trx_id'] ?? '';
        
        // Nagad invoice length must be <= 20 chars
        $invoice = substr($trxId, 0, 20);

        // Step 1: Initialize Payment request to Nagad
        $initializeUrl = $baseUrl . 'check-out/initialize/' . $merchantId . '/' . $invoice;

        $sensitiveData = [
            'merchantId' => $merchantId,
            'datetime'   => date('YmdHis'),
            'orderId'    => $invoice,
            'challenge'  => $this->generateRandomString(40)
        ];

        $sensitiveJson = json_encode($sensitiveData);
        $encryptedSensitiveData = $this->encryptWithPublicKey($sensitiveJson, $credentials['nagad_public_key']);
        $signature = $this->signWithPrivateKey($sensitiveJson, $credentials['nagad_private_key']);

        $postData = [
            'accountNumber' => $credentials['nagad_app_account'] ?? '',
            'dateTime'      => date('YmdHis'),
            'sensitiveData' => $encryptedSensitiveData,
            'signature'     => $signature
        ];

        $ch = curl_init($initializeUrl);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'X-KM-Api-Version: v-0.2.0',
                'X-KM-IP-V4: ' . ($this->getClientIp()),
                'X-KM-Client-Type: PC_WEB'
            ],
            CURLOPT_POSTFIELDS => json_encode($postData),
        ]);

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || !$response) {
            throw new \RuntimeException('Nagad API Error: HTTP ' . $httpCode);
        }

        $initData = json_decode($response, true);
        if (empty($initData['sensitiveData'])) {
            $reason = $initData['message'] ?? 'Unknown error';
            throw new \RuntimeException('Nagad initialization failed: ' . $reason);
        }

        // Decrypt step 1 response
        $decryptedSensitive = $this->decryptWithPrivateKey($initData['sensitiveData'], $credentials['nagad_private_key']);
        $decryptedData = json_decode($decryptedSensitive, true);

        if (empty($decryptedData['paymentReferenceId']) || empty($decryptedData['challenge'])) {
            throw new \RuntimeException('Nagad decrypted response has missing data');
        }

        $paymentReferenceId = $decryptedData['paymentReferenceId'];
        $challenge = $decryptedData['challenge'];

        // Step 2: Complete Payment request to Nagad
        $completeUrl = $baseUrl . 'check-out/complete/' . $paymentReferenceId;

        $sensitiveDataOrder = [
            'merchantId'   => $merchantId,
            'orderId'      => $invoice,
            'currencyCode' => '050', // BDT is 050
            'amount'       => (string)$params['amount'],
            'challenge'    => $challenge
        ];

        $orderJson = json_encode($sensitiveDataOrder);
        $encryptedOrderData = $this->encryptWithPublicKey($orderJson, $credentials['nagad_public_key']);
        $signatureOrder = $this->signWithPrivateKey($orderJson, $credentials['nagad_private_key']);

        // Success redirect callback: we want to append paymentID/trx_id to trigger status callback
        $separator = (strpos($params['redirect_url'], '?') !== false) ? '&' : '?';
        $callbackUrl = $params['redirect_url'] . $separator . 'paymentID=' . urlencode($trxId) . '&method=nagad';

        $postDataOrder = [
            'sensitiveData'       => $encryptedOrderData,
            'signature'           => $signatureOrder,
            'merchantCallbackURL' => $callbackUrl
        ];

        $ch = curl_init($completeUrl);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'X-KM-Api-Version: v-0.2.0',
                'X-KM-IP-V4: ' . ($this->getClientIp()),
                'X-KM-Client-Type: PC_WEB'
            ],
            CURLOPT_POSTFIELDS => json_encode($postDataOrder),
        ]);

        $responseOrder = curl_exec($ch);
        $httpCodeOrder = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCodeOrder !== 200 || !$responseOrder) {
            throw new \RuntimeException('Nagad Order API Error: HTTP ' . $httpCodeOrder);
        }

        $orderResult = json_decode($responseOrder, true);
        if (empty($orderResult['callBackUrl']) || ($orderResult['status'] ?? '') !== 'Success') {
            $reason = $orderResult['message'] ?? 'Unknown error';
            throw new \RuntimeException('Nagad complete failed: ' . $reason);
        }

        return [
            'redirect_url' => $orderResult['callBackUrl'],
            'session_id'   => $paymentReferenceId,
        ];
    }

    public function verify(array $callbackData, array $credentials): array
    {
        $status = $callbackData['status'] ?? '';
        $paymentRefId = $callbackData['payment_ref_id'] ?? '';
        $trxId = $callbackData['trx_id'] ?? $callbackData['paymentID'] ?? '';

        if (strtolower($status) !== 'success' || $paymentRefId === '') {
            return ['success' => false, 'gateway_trx_id' => '', 'status' => 'failed'];
        }

        $mode = $credentials['nagad_mode'] ?? 'sandbox';
        $baseUrl = $mode === 'live' ? self::LIVE_URL : self::SANDBOX_URL;

        $url = $baseUrl . 'verify/payment/' . urlencode($paymentRefId);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'
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

        $paid = isset($data['status']) && strtolower($data['status']) === 'success';

        return [
            'success'        => $paid,
            'gateway_trx_id' => $data['issuerPaymentRefNo'] ?? $paymentRefId,
            'amount'         => $data['amount'] ?? null,
            'status'         => $paid ? 'completed' : 'failed',
            'trx_id'         => $trxId,
        ];
    }

    private function encryptWithPublicKey(string $data, string $rawKey): string
    {
        $publicKey = $this->cleanPublicKey($rawKey);
        $keyResource = openssl_get_publickey($publicKey);
        if (!$keyResource) {
            throw new \RuntimeException('Invalid Nagad Public Key configuration.');
        }
        $status = openssl_public_encrypt($data, $cryptoText, $keyResource);
        if ($status) {
            return base64_encode($cryptoText);
        }
        throw new \RuntimeException('Nagad public key encryption failed.');
    }

    private function decryptWithPrivateKey(string $cryptoText, string $rawKey): string
    {
        $privateKey = $this->cleanPrivateKey($rawKey);
        $keyResource = openssl_get_privatekey($privateKey);
        if (!$keyResource) {
            throw new \RuntimeException('Invalid Merchant Private Key configuration.');
        }
        $status = openssl_private_decrypt(base64_decode($cryptoText), $plainText, $keyResource);
        if ($status) {
            return $plainText;
        }
        throw new \RuntimeException('Nagad private key decryption failed.');
    }

    private function signWithPrivateKey(string $data, string $rawKey): string
    {
        $privateKey = $this->cleanPrivateKey($rawKey);
        $keyResource = openssl_get_privatekey($privateKey);
        if (!$keyResource) {
            throw new \RuntimeException('Invalid Merchant Private Key configuration.');
        }
        $status = openssl_sign($data, $signature, $keyResource, OPENSSL_ALGO_SHA256);
        if ($status) {
            return base64_encode($signature);
        }
        throw new \RuntimeException('Nagad signing failed.');
    }

    private function cleanPrivateKey(string $key): string
    {
        $key = trim($key);
        $key = str_replace([
            '-----BEGIN RSA PRIVATE KEY-----', '-----END RSA PRIVATE KEY-----',
            '-----BEGIN PRIVATE KEY-----', '-----END PRIVATE KEY-----',
            "\r", "\n", " "
        ], '', $key);
        return "-----BEGIN RSA PRIVATE KEY-----\n" . chunk_split($key, 64, "\n") . "-----END RSA PRIVATE KEY-----";
    }

    private function cleanPublicKey(string $key): string
    {
        $key = trim($key);
        $key = str_replace([
            '-----BEGIN PUBLIC KEY-----', '-----END PUBLIC KEY-----',
            "\r", "\n", " "
        ], '', $key);
        return "-----BEGIN PUBLIC KEY-----\n" . chunk_split($key, 64, "\n") . "-----END PUBLIC KEY-----";
    }

    private function generateRandomString(int $length = 40): string
    {
        $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $charactersLength = strlen($characters);
        $randomString = '';
        for ($i = 0; $i < $length; $i++) {
            $randomString .= $characters[rand(0, $charactersLength - 1)];
        }
        return $randomString;
    }

    private function getClientIp(): string
    {
        return $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    }
}
