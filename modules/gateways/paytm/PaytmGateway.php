<?php
declare(strict_types=1);

namespace OwnPay\Modules\Gateways\Paytm;

use OwnPay\Gateway\GatewayAdapterInterface;
use OwnPay\Gateway\GatewayDefaults;
use OwnPay\Plugin\PluginInterface;
use OwnPay\Plugin\Capability;
use OwnPay\Container;
use OwnPay\Event\EventManager;

/**
 * Paytm payment gateway adapter implementing the secure Redirection Flow using Transaction Tokens.
 */
final class PaytmGateway implements PluginInterface, GatewayAdapterInterface
{
    use GatewayDefaults;

    private const SANDBOX_URL = 'https://securegw-stage.paytm.in';
    private const LIVE_URL    = 'https://securegw.paytm.in';

    private const SANDBOX_REDIRECT = 'https://securestage.paytmpayments.com/theia/api/v1/showPaymentPage';
    private const LIVE_REDIRECT    = 'https://securegw.paytm.in/theia/api/v1/showPaymentPage';

    private static string $iv = "@@@@&&&&####$$$$";

    /**
     * Returns the plugin metadata array.
     */
    public static function metadata(): array
    {
        return [
            'name' => 'Paytm',
            'slug' => 'paytm',
            'version' => '1.0.0',
            'description' => 'Paytm secure transaction token showPaymentPage integration',
            'author' => 'OwnPay Core',
            'type' => 'gateway',
        ];
    }

    public function slug(): string { return 'paytm'; }
    public function name(): string { return 'Paytm'; }
    public function version(): string { return '1.0.0'; }
    public function description(): string { return 'Paytm secure transaction token showPaymentPage integration'; }

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
            ['name' => 'mid', 'label' => 'Merchant ID (MID)', 'type' => 'text', 'required' => true],
            ['name' => 'merchant_key', 'label' => 'Merchant Key', 'type' => 'password', 'required' => true],
            ['name' => 'website', 'label' => 'Website (e.g. WEBSTAGING or DEFAULT)', 'type' => 'text', 'required' => true],
            ['name' => 'mode', 'label' => 'Mode', 'type' => 'select', 'options' => ['sandbox' => 'sandbox', 'live' => 'live'], 'required' => true],
        ];
    }

    /**
     * Initiates a payment process with Paytm.
     */
    public function initiate(array $params, array $credentials): array
    {
        $mid = $this->getString($credentials['mid'] ?? '');
        $merchantKey = $this->getString($credentials['merchant_key'] ?? '');
        $website = $this->getString($credentials['website'] ?? 'WEBSTAGING');
        $mode = $this->getString($credentials['mode'] ?? 'sandbox');

        if (empty($mid) || empty($merchantKey)) {
            throw new \RuntimeException('Paytm error: Missing Merchant ID (MID) or Merchant Key.');
        }

        // Live sandbox isolation guard
        if ($mode === 'live') {
            if (str_starts_with($mid, 'TEST') || 
                str_starts_with($merchantKey, 'TEST') || 
                str_starts_with($params['trx_id'], 'SIM_')) {
                throw new \RuntimeException('Sandbox simulation input/credentials rejected in Live production mode.');
            }
        }

        $baseUrl = $mode === 'live' ? self::LIVE_URL : self::SANDBOX_URL;
        $redirectUrl = $mode === 'live' ? self::LIVE_REDIRECT : self::SANDBOX_REDIRECT;

        // Paytm accepts standard INR decimal format. We cast string amount using formatting.
        $amount = $params['amount'];
        if (!is_numeric($amount)) {
            throw new \RuntimeException('Paytm error: Invalid transaction amount format.');
        }
        $formattedAmount = number_format((float)$amount, 2, '.', '');

        $body = [
            'requestType' => 'Payment',
            'mid'         => $mid,
            'websiteName' => $website,
            'orderId'     => $params['trx_id'],
            'txnAmount'   => [
                'value'    => $formattedAmount,
                'currency' => 'INR',
            ],
            'userInfo'    => [
                'custId' => 'cust_' . $params['trx_id'],
                'email'  => 'user@example.com',
            ],
            'callbackUrl' => $params['redirect_url'],
        ];

        // Generate checksum signature over stringified JSON body
        $bodyString = (string) json_encode($body);
        $signature = self::generateSignature($bodyString, $merchantKey);

        $payload = [
            'body' => $body,
            'head' => [
                'signature' => $signature,
            ],
        ];

        $ch = curl_init($baseUrl . '/theia/api/v1/initiateTransaction?mid=' . urlencode($mid) . '&orderId=' . urlencode($params['trx_id']));
        if ($ch === false) {
            throw new \RuntimeException('Paytm cURL initialization failed.');
        }

        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS => (string) json_encode($payload),
        ]);

        $response = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            throw new \RuntimeException('Paytm API connection error: ' . ($err ?: 'Unknown'));
        }

        $data = json_decode((string) $response, true);
        if (!is_array($data)) {
            throw new \RuntimeException('Paytm error: Invalid API response payload.');
        }

        $resInfo = $this->getArray($data, 'body', 'resultInfo');
        $status = $this->getString($resInfo['resultStatus'] ?? '');
        if ($status !== 'S') {
            $msg = $this->getString($resInfo['resultMsg'] ?? 'Unknown error');
            throw new \RuntimeException('Paytm API transaction initiation failed: ' . $msg);
        }

        $txnToken = $this->getString($this->getArray($data, 'body')['txnToken'] ?? '');
        if (empty($txnToken)) {
            throw new \RuntimeException('Paytm error: Missing transaction token (txnToken) in response.');
        }

        // Return auto-submitting HTML form because Paytm requires a POST redirection for hosted Show Payment Page
        $formHtml = '
        <form id="paytm_checkout_form" method="post" action="' . htmlspecialchars($redirectUrl . '?mid=' . urlencode($mid) . '&orderId=' . urlencode($params['trx_id'])) . '">
            <input type="hidden" name="mid" value="' . htmlspecialchars($mid) . '" />
            <input type="hidden" name="orderId" value="' . htmlspecialchars($params['trx_id']) . '" />
            <input type="hidden" name="txnToken" value="' . htmlspecialchars($txnToken) . '" />
        </form>
        <script type="text/javascript">
            document.getElementById("paytm_checkout_form").submit();
        </script>
        ';

        return [
            'form_html'  => $formHtml,
            'session_id' => $txnToken,
        ];
    }

    /**
     * Verifies the payment status from a callback or webhook.
     */
    public function verify(array $callbackData, array $credentials): array
    {
        $mid = $this->getString($credentials['mid'] ?? '');
        $merchantKey = $this->getString($credentials['merchant_key'] ?? '');
        $mode = $this->getString($credentials['mode'] ?? 'sandbox');

        $orderIdRaw = $callbackData['ORDERID'] ?? '';
        $orderId = is_scalar($orderIdRaw) ? (string) $orderIdRaw : '';
        $checksum = $this->getString($callbackData['CHECKSUMHASH'] ?? '');

        if (empty($orderId)) {
            return [
                'success'        => false,
                'gateway_trx_id' => '',
                'status'         => 'failed',
                'error'          => 'Paytm verification error: Missing ORDERID parameter.',
            ];
        }

        // 1. Validate Checksum Signature
        $isValid = self::verifySignature($callbackData, $merchantKey, $checksum);
        if (!$isValid) {
            return [
                'success'        => false,
                'gateway_trx_id' => '',
                'status'         => 'failed',
                'error'          => 'Paytm verification error: Invalid checksum signature.',
            ];
        }

        // 2. Query Transaction Status from Paytm API
        $body = [
            'mid'     => $mid,
            'orderId' => $orderId,
        ];
        $bodyString = (string) json_encode($body);
        $signature = self::generateSignature($bodyString, $merchantKey);

        $payload = [
            'body' => $body,
            'head' => [
                'signature' => $signature,
            ],
        ];

        $baseUrl = $mode === 'live' ? self::LIVE_URL : self::SANDBOX_URL;

        $ch = curl_init($baseUrl . '/v3/transactionStatus');
        if ($ch === false) {
            return [
                'success'        => false,
                'gateway_trx_id' => '',
                'status'         => 'failed',
                'error'          => 'Paytm cURL initialization failed during status query.',
            ];
        }

        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS => (string) json_encode($payload),
        ]);

        $response = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            return [
                'success'        => false,
                'gateway_trx_id' => '',
                'status'         => 'failed',
                'error'          => 'Paytm Status API connection error: ' . ($err ?: 'Unknown'),
            ];
        }

        $data = json_decode((string) $response, true);
        if (!is_array($data)) {
            return [
                'success'        => false,
                'gateway_trx_id' => '',
                'status'         => 'failed',
                'error'          => 'Paytm Status API returned invalid JSON.',
            ];
        }

        $resInfo = $this->getArray($data, 'body', 'resultInfo');
        $status = $this->getString($resInfo['resultStatus'] ?? '');
        $success = $status === 'TXN_SUCCESS';

        $bodyData = $this->getArray($data, 'body');
        $gatewayTrxId = $this->getString($bodyData['txnId'] ?? '');
        $txnAmount = null;
        if (isset($bodyData['txnAmount']) && is_numeric($bodyData['txnAmount'])) {
            $txnAmount = number_format((float)$bodyData['txnAmount'], 2, '.', '');
        }

        $result = [
            'success'        => $success,
            'gateway_trx_id' => $gatewayTrxId ?: $orderId,
            'status'         => $success ? 'completed' : 'failed',
        ];

        if ($txnAmount !== null) {
            $result['amount'] = $txnAmount;
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

    // --- Native PaytmChecksum Implementation ---

    public static function encrypt(string $input, string $key): string
    {
        $key = html_entity_decode($key);
        if (function_exists('openssl_encrypt')) {
            $data = openssl_encrypt($input, "AES-128-CBC", $key, 0, self::$iv);
            if ($data === false) {
                throw new \RuntimeException('Paytm error: Encryption failed.');
            }
            return $data;
        }
        throw new \RuntimeException('OpenSSL extension is not available.');
    }

    public static function decrypt(string $encrypted, string $key): string
    {
        $key = html_entity_decode($key);
        if (function_exists('openssl_decrypt')) {
            $data = openssl_decrypt($encrypted, "AES-128-CBC", $key, 0, self::$iv);
            if ($data === false) {
                throw new \RuntimeException('Paytm error: Decryption failed.');
            }
            return $data;
        }
        throw new \RuntimeException('OpenSSL extension is not available.');
    }

    /**
     * @param array<string, mixed>|string $params
     * @param string $key
     * @return string
     */
    public static function generateSignature(array|string $params, string $key): string
    {
        if (is_array($params)) {
            $params = self::getStringByParams($params);
        }
        $salt = self::generateRandomString(4);
        return self::calculateChecksum($params, $key, $salt);
    }

    /**
     * @param array<string, mixed>|string $params
     * @param string $key
     * @param string $checksum
     * @return bool
     */
    public static function verifySignature(array|string $params, string $key, string $checksum): bool
    {
        if (is_array($params)) {
            if (isset($params['CHECKSUMHASH'])) {
                unset($params['CHECKSUMHASH']);
            }
            $params = self::getStringByParams($params);
        }
        try {
            $hashString = self::decrypt($checksum, $key);
            if (strlen($hashString) < 64) {
                return false;
            }
            $salt = substr($hashString, 64);
            $hashVal = substr($hashString, 0, 64);
            $calculatedHash = hash("sha256", $params . "|" . $salt);
            return hash_equals($calculatedHash, $hashVal);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * @param array<string, mixed> $params
     * @return string
     */
    private static function getStringByParams(array $params): string
    {
        ksort($params);
        $params = array_map(function ($value) {
            $strVal = is_scalar($value) ? (string)$value : '';
            return ($value !== null && strtolower($strVal) !== "null") ? $strVal : "";
        }, $params);
        return implode("|", $params);
    }

    private static function calculateChecksum(string $params, string $key, string $salt): string
    {
        $hashString = self::calculateHash($params, $salt);
        return self::encrypt($hashString, $key);
    }

    private static function calculateHash(string $params, string $salt): string
    {
        $finalString = $params . "|" . $salt;
        $hash = hash("sha256", $finalString);
        return $hash . $salt;
    }

    private static function generateRandomString(int $length): string
    {
        $chars = "ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789";
        $str = "";
        $charsLength = strlen($chars);
        for ($i = 0; $i < $length; $i++) {
            $str .= $chars[random_int(0, $charsLength - 1)];
        }
        return $str;
    }
}
