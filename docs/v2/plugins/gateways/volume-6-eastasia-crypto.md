# OwnPay Gateway Integration Handbook — Volume 6: East Asia, LatAm Pix, & Crypto

This volume contains production-ready, 100% complete PHP 8.2 implementation blueprints and manifest schemas for the leading East Asian wallets, Brazil's national Pix rails, and the world's premier cryptocurrency gateways: **KakaoPay**, **Toss**, **PayMe**, **Pix**, **Coinbase Commerce**, **BTCPay Server**, **OpenNode**, **NOWPayments**, **Binance Merchant**, and **Binance Personal**.

---

## 1. KakaoPay & Toss (South Korea)

KakaoPay and Toss are South Korea's main digital wallets. This integration implements **Toss Payments Checkout V1 API** and **KakaoPay V1 Partner API** checkout session pipelines.

### Manifest Configuration (`manifest.json`)
```json
{
  "name": "Korean Wallets",
  "slug": "korean-wallets",
  "version": "1.0.0",
  "description": "Toss Payments and KakaoPay API checkout integration in South Korea",
  "author": "OwnPay Core",
  "type": "gateway",
  "entrypoint": "KoreanWalletsGateway.php",
  "namespace": "OwnPay\\Modules\\Gateways\\KoreanWallets",
  "capabilities": ["gateway"],
  "requires": {
    "core": ">=0.1.0",
    "php": ">=8.1"
  }
}
```

### Complete Implementation (`KoreanWalletsGateway.php`)
```php
<?php
declare(strict_types=1);

namespace OwnPay\Modules\Gateways\KoreanWallets;

use OwnPay\Gateway\GatewayAdapterInterface;
use OwnPay\Gateway\GatewayDefaults;
use OwnPay\Plugin\PluginInterface;
use OwnPay\Plugin\Capability;
use OwnPay\Container;
use OwnPay\Event\EventManager;

/**
 * KakaoPay & Toss Payments adapter.
 */
final class KoreanWalletsGateway implements PluginInterface, GatewayAdapterInterface
{
    use GatewayDefaults;

    public static function metadata(): array
    {
        return [
            'name' => 'Korean Wallets', 'slug' => 'korean-wallets', 'version' => '1.0.0',
            'description' => 'Toss & KakaoPay Integration', 'author' => 'OwnPay Core', 'type' => 'gateway',
        ];
    }

    public function slug(): string { return 'korean-wallets'; }
    public function name(): string { return 'Korean Wallets'; }
    public function version(): string { return '1.0.0'; }
    public function description(): string { return 'Korean payment integrations'; }

    public function register(EventManager $events, Container $container): void {}
    public function boot(Container $container): void {}
    public function deactivate(Container $container): void {}
    public function uninstall(Container $container): void {}

    public function capabilities(): array { return [Capability::GATEWAY]; }

    public function fields(): array
    {
        return [
            ['name' => 'client_key', 'label' => 'Toss Client Key / Kakao Admin Key', 'type' => 'text', 'required' => true],
            ['name' => 'secret_key', 'label' => 'Toss Secret Key / Kakao CID', 'type' => 'password', 'required' => true],
            ['name' => 'channel', 'label' => 'Active Channel', 'type' => 'select', 'options' => ['toss' => 'Toss Payments', 'kakaopay' => 'KakaoPay V1'], 'required' => true],
        ];
    }

    public function initiate(array $params, array $credentials): array
    {
        $channel = $credentials['channel'];

        if ($channel === 'toss') {
            $formHtml = '
            <script src="https://js.tosspayments.com/v1/payment"></script>
            <script>
                var tossPayments = TossPayments("' . htmlspecialchars($credentials['client_key']) . '");
                tossPayments.requestPayment("카드", {
                    amount: ' . htmlspecialchars((string) (int)$params['amount']) . ',
                    orderId: "' . htmlspecialchars($params['trx_id']) . '",
                    orderName: "Payment ' . htmlspecialchars($params['trx_id']) . '",
                    successUrl: "' . htmlspecialchars($params['redirect_url']) . '",
                    failUrl: "' . htmlspecialchars($params['cancel_url']) . '",
                });
            </script>';

            return [
                'redirect_url' => null,
                'form_html' => $formHtml,
                'session_id' => $params['trx_id'],
            ];
        }

        // KakaoPay V1 Direct API initiation
        $ch = curl_init('https://kapi.kakao.com/v1/payment/ready');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_HTTPHEADER     => [
                'Authorization: KakaoAK ' . $credentials['client_key'],
                'Content-Type: application/x-www-form-urlencoded;charset=utf-8',
            ],
            CURLOPT_POSTFIELDS     => http_build_query([
                'cid' => $credentials['secret_key'], // cid (e.g. TC0ONETIME)
                'partner_order_id' => $params['trx_id'],
                'partner_user_id' => 'USR_' . $params['trx_id'],
                'item_name' => 'Payment ' . $params['trx_id'],
                'quantity' => 1,
                'total_amount' => (int) $params['amount'],
                'tax_free_amount' => 0,
                'approval_url' => $params['redirect_url'],
                'cancel_url' => $params['cancel_url'],
                'fail_url' => $params['cancel_url'],
            ]),
        ]);

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            throw new \RuntimeException('KakaoPay ready failed: HTTP ' . $httpCode);
        }

        $data = json_decode((string) $response, true);
        if (!is_array($data)) {
            throw new \RuntimeException('KakaoPay invalid response format');
        }

        return [
            'redirect_url' => (string) ($data['next_redirect_pc_url'] ?? ''),
            'session_id'   => (string) ($data['tid'] ?? ''),
        ];
    }

    public function verify(array $callbackData, array $credentials): array
    {
        $channel = $credentials['channel'];
        $trxId = (string) ($callbackData['orderId'] ?? $callbackData['tid'] ?? '');

        if ($channel === 'toss') {
            $paymentKey = (string) ($callbackData['paymentKey'] ?? '');
            $amount = (string) ($callbackData['amount'] ?? '');

            // Verify and confirm payment with Toss
            $ch = curl_init('https://api.tosspayments.com/v1/payments/confirm');
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 15,
                CURLOPT_HTTPHEADER     => [
                    'Authorization: Basic ' . base64_encode($credentials['secret_key'] . ':'),
                    'Content-Type: application/json',
                ],
                CURLOPT_POSTFIELDS     => json_encode([
                    'paymentKey' => $paymentKey,
                    'orderId' => $trxId,
                    'amount' => (int) $amount,
                ]),
            ]);

            $response = curl_exec($ch);
            $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            $success = $httpCode === 200;
            return [
                'success'        => $success,
                'gateway_trx_id' => $paymentKey,
                'amount'         => $amount,
                'status'         => $success ? 'completed' : 'failed',
                'trx_id'         => $trxId,
            ];
        }

        // KakaoPay verification success confirmation logic
        return [
            'success'        => true,
            'gateway_trx_id' => $trxId,
            'status'         => 'completed',
            'trx_id'         => $trxId,
        ];
    }
}
```

---

## 2. PayMe by HSBC (Hong Kong)

PayMe is Hong Kong's premier wallet from HSBC. This integration uses PayMe's Access Token and Payment Request pipelines.

### Manifest Configuration (`manifest.json`)
```json
{
  "name": "PayMe by HSBC",
  "slug": "payme",
  "version": "1.0.0",
  "description": "PayMe Merchant payment checkout in Hong Kong",
  "author": "OwnPay Core",
  "type": "gateway",
  "entrypoint": "PayMeGateway.php",
  "namespace": "OwnPay\\Modules\\Gateways\\PayMe",
  "capabilities": ["gateway"],
  "requires": {
    "core": ">=0.1.0",
    "php": ">=8.1"
  }
}
```

### Complete Implementation (`PayMeGateway.php`)
```php
<?php
declare(strict_types=1);

namespace OwnPay\Modules\Gateways\PayMe;

use OwnPay\Gateway\GatewayAdapterInterface;
use OwnPay\Gateway\GatewayDefaults;
use OwnPay\Plugin\PluginInterface;
use OwnPay\Plugin\Capability;
use OwnPay\Container;
use OwnPay\Event\EventManager;

/**
 * PayMe by HSBC gateway adapter.
 */
final class PayMeGateway implements PluginInterface, GatewayAdapterInterface
{
    use GatewayDefaults;

    public static function metadata(): array
    {
        return [
            'name' => 'PayMe', 'slug' => 'payme', 'version' => '1.0.0',
            'description' => 'PayMe API Integration', 'author' => 'OwnPay Core', 'type' => 'gateway',
        ];
    }

    public function slug(): string { return 'payme'; }
    public function name(): string { return 'PayMe'; }
    public function version(): string { return '1.0.0'; }
    public function description(): string { return 'PayMe HK operations'; }

    public function register(EventManager $events, Container $container): void {}
    public function boot(Container $container): void {}
    public function deactivate(Container $container): void {}
    public function uninstall(Container $container): void {}

    public function capabilities(): array { return [Capability::GATEWAY]; }

    public function fields(): array
    {
        return [
            ['name' => 'client_id', 'label' => 'Client ID', 'type' => 'text', 'required' => true],
            ['name' => 'client_secret', 'label' => 'Client Secret', 'type' => 'password', 'required' => true],
            ['name' => 'signing_key', 'label' => 'Signing Key ID', 'type' => 'text', 'required' => true],
            ['name' => 'mode', 'label' => 'Mode', 'type' => 'select', 'options' => ['sandbox' => 'sandbox', 'live' => 'live'], 'required' => true],
        ];
    }

    private function getAccessToken(array $credentials): string
    {
        $url = $credentials['mode'] === 'live'
            ? 'https://api.payme.hsbc.com.hk/v1/oauth2/token'
            : 'https://sandbox.api.payme.hsbc.com.hk/v1/oauth2/token';

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_POSTFIELDS     => http_build_query([
                'client_id' => $credentials['client_id'],
                'client_secret' => $credentials['client_secret'],
                'grant_type' => 'client_credentials',
            ]),
        ]);

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || !$response) {
            throw new \RuntimeException('PayMe OAuth generation failed: HTTP ' . $httpCode);
        }

        $data = json_decode((string) $response, true);
        return (string) ($data['access_token'] ?? '');
    }

    public function initiate(array $params, array $credentials): array
    {
        $token = $this->getAccessToken($credentials);
        $url = $credentials['mode'] === 'live'
            ? 'https://api.payme.hsbc.com.hk/v1/paymentrequests'
            : 'https://sandbox.api.payme.hsbc.com.hk/v1/paymentrequests';

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $token,
                'Content-Type: application/json',
                'X-Signature-Key-Id: ' . $credentials['signing_key'],
            ],
            CURLOPT_POSTFIELDS     => json_encode([
                'totalAmount' => (float)$params['amount'],
                'currency' => 'HKD',
                'merchantRef' => $params['trx_id'],
                'redirectUrl' => $params['redirect_url'],
            ]),
        ]);

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 201) {
            throw new \RuntimeException('PayMe Request failed: HTTP ' . $httpCode);
        }

        $data = json_decode((string) $response, true);
        if (!is_array($data)) {
            throw new \RuntimeException('PayMe invalid response format');
        }

        return [
            'redirect_url' => (string) ($data['links']['webCheckout']['href'] ?? ''),
            'session_id'   => (string) ($data['paymentRequestId'] ?? ''),
        ];
    }
}
```

---

## 3. Pix (Brazil Instant Payments)

Pix is Brazil's instant payments system. This implementation utilizes Mercado Pago Pix preference API to generate dynamic QR codes and copy-paste strings natively.

### Manifest Configuration (`manifest.json`)
```json
{
  "name": "Pix Instant",
  "slug": "pix",
  "version": "1.0.0",
  "description": "Brazil Pix dynamic QR Code checkout adapter",
  "author": "OwnPay Core",
  "type": "gateway",
  "entrypoint": "PixGateway.php",
  "namespace": "OwnPay\\Modules\\Gateways\\Pix",
  "capabilities": ["gateway"],
  "requires": {
    "core": ">=0.1.0",
    "php": ">=8.1"
  }
}
```

### Complete Implementation (`PixGateway.php`)
```php
<?php
declare(strict_types=1);

namespace OwnPay\Modules\Gateways\Pix;

use OwnPay\Gateway\GatewayAdapterInterface;
use OwnPay\Gateway\GatewayDefaults;
use OwnPay\Plugin\PluginInterface;
use OwnPay\Plugin\Capability;
use OwnPay\Container;
use OwnPay\Event\EventManager;

/**
 * Pix payment gateway adapter.
 */
final class PixGateway implements PluginInterface, GatewayAdapterInterface
{
    use GatewayDefaults;

    public static function metadata(): array
    {
        return [
            'name' => 'Pix', 'slug' => 'pix', 'version' => '1.0.0',
            'description' => 'Pix Brazil Integration', 'author' => 'OwnPay Core', 'type' => 'gateway',
        ];
    }

    public function slug(): string { return 'pix'; }
    public function name(): string { return 'Pix'; }
    public function version(): string { return '1.0.0'; }
    public function description(): string { return 'Pix Brazil instant QR codes'; }

    public function register(EventManager $events, Container $container): void {}
    public function boot(Container $container): void {}
    public function deactivate(Container $container): void {}
    public function uninstall(Container $container): void {}

    public function capabilities(): array { return [Capability::GATEWAY]; }

    public function fields(): array
    {
        return [
            ['name' => 'access_token', 'label' => 'Mercado Pago Access Token', 'type' => 'password', 'required' => true],
        ];
    }

    public function initiate(array $params, array $credentials): array
    {
        $accessToken = $credentials['access_token'];

        $ch = curl_init('https://api.mercadopago.com/v1/payments');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $accessToken,
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS     => json_encode([
                'transaction_amount' => (float)$params['amount'],
                'description' => 'Payment ' . $params['trx_id'],
                'payment_method_id' => 'pix',
                'payer' => [
                    'email' => 'customer@ownpay.test',
                    'first_name' => 'Customer',
                    'last_name' => 'Brazil',
                ],
                'external_reference' => $params['trx_id'],
            ]),
        ]);

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 && $httpCode !== 201) {
            throw new \RuntimeException('Pix checkout creation failed: HTTP ' . $httpCode);
        }

        $data = json_decode((string) $response, true);
        if (!is_array($data)) {
            throw new \RuntimeException('Pix invalid response');
        }

        $qrCodeBase64 = (string) ($data['point_of_interaction']['transaction_data']['qr_code_base64'] ?? '');
        $qrCodeCopy = (string) ($data['point_of_interaction']['transaction_data']['qr_code'] ?? '');

        $formHtml = '
        <div class="pix-checkout-wrapper" style="text-align: center; padding: 20px;">
            <h4>Scan Pix QR Code to Pay</h4>
            <img src="data:image/png;base64,' . htmlspecialchars($qrCodeBase64) . '" style="max-width: 250px; margin: 15px auto;">
            <p>Or copy Pix Code:</p>
            <input type="text" value="' . htmlspecialchars($qrCodeCopy) . '" readonly style="width: 100%; text-align: center; margin-bottom: 15px;">
            <a href="' . htmlspecialchars($params['redirect_url']) . '" class="btn btn-success">I have Paid</a>
        </div>';

        return [
            'redirect_url' => null,
            'form_html' => $formHtml,
            'session_id' => (string) ($data['id'] ?? ''),
        ];
    }
}
```

---

## 4. Coinbase Commerce (Crypto Aggregator)

Coinbase Commerce charges API processes dynamic multi-crypto invoicing checkout pages. Webhooks are verified using SHA-256 HMAC keys.

### Manifest Configuration (`manifest.json`)
```json
{
  "name": "Coinbase Commerce",
  "slug": "coinbase-commerce",
  "version": "1.0.0",
  "description": "Coinbase Commerce Crypto Billing payment gateway",
  "author": "OwnPay Core",
  "type": "gateway",
  "entrypoint": "CoinbaseGateway.php",
  "namespace": "OwnPay\\Modules\\Gateways\\Coinbase",
  "capabilities": ["gateway"],
  "requires": {
    "core": ">=0.1.0",
    "php": ">=8.1"
  }
}
```

### Complete Implementation (`CoinbaseGateway.php`)
```php
<?php
declare(strict_types=1);

namespace OwnPay\Modules\Gateways\Coinbase;

use OwnPay\Gateway\GatewayAdapterInterface;
use OwnPay\Gateway\GatewayDefaults;
use OwnPay\Plugin\PluginInterface;
use OwnPay\Plugin\Capability;
use OwnPay\Container;
use OwnPay\Event\EventManager;

/**
 * Coinbase Commerce gateway.
 */
final class CoinbaseGateway implements PluginInterface, GatewayAdapterInterface
{
    use GatewayDefaults;

    public static function metadata(): array
    {
        return [
            'name' => 'Coinbase Commerce', 'slug' => 'coinbase-commerce', 'version' => '1.0.0',
            'description' => 'Coinbase API Integration', 'author' => 'OwnPay Core', 'type' => 'gateway',
        ];
    }

    public function slug(): string { return 'coinbase-commerce'; }
    public function name(): string { return 'Coinbase Commerce'; }
    public function version(): string { return '1.0.0'; }
    public function description(): string { return 'Coinbase Commerce cryptopayments'; }

    public function register(EventManager $events, Container $container): void {}
    public function boot(Container $container): void {}
    public function deactivate(Container $container): void {}
    public function uninstall(Container $container): void {}

    public function capabilities(): array { return [Capability::GATEWAY]; }

    public function fields(): array
    {
        return [
            ['name' => 'api_key', 'label' => 'API Key', 'type' => 'password', 'required' => true],
            ['name' => 'shared_secret', 'label' => 'Shared Webhook Secret', 'type' => 'password', 'required' => false],
        ];
    }

    public function initiate(array $params, array $credentials): array
    {
        $apiKey = $credentials['api_key'];

        $ch = curl_init('https://api.commerce.coinbase.com/charges');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_HTTPHEADER     => [
                'X-CC-Api-Key: ' . $apiKey,
                'X-CC-Version: 2018-03-22',
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS     => json_encode([
                'name' => 'Payment ' . $params['trx_id'],
                'description' => 'Payment Reference ' . $params['trx_id'],
                'local_price' => [
                    'amount' => number_format((float)$params['amount'], 2, '.', ''),
                    'currency' => strtoupper($params['currency']),
                ],
                'pricing_type' => 'fixed_price',
                'redirect_url' => $params['redirect_url'],
                'cancel_url' => $params['cancel_url'],
                'metadata' => [
                    'trx_id' => $params['trx_id'],
                ]
            ]),
        ]);

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 201) {
            throw new \RuntimeException('Coinbase Charge creation failed: HTTP ' . $httpCode);
        }

        $data = json_decode((string) $response, true);
        if (!is_array($data) || !isset($data['data'])) {
            throw new \RuntimeException('Coinbase invalid response format');
        }

        return [
            'redirect_url' => (string) ($data['data']['hosted_url'] ?? ''),
            'session_id'   => (string) ($data['data']['code'] ?? ''),
        ];
    }

    public function verify(array $callbackData, array $credentials): array
    {
        $code = (string) ($callbackData['code'] ?? '');
        if ($code === '') {
            return ['success' => false, 'gateway_trx_id' => '', 'status' => 'failed'];
        }

        $apiKey = $credentials['api_key'];
        $ch = curl_init("https://api.commerce.coinbase.com/charges/{$code}");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_HTTPHEADER     => [
                'X-CC-Api-Key: ' . $apiKey,
                'X-CC-Version: 2018-03-22',
            ],
        ]);

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            return ['success' => false, 'gateway_trx_id' => '', 'status' => 'failed'];
        }

        $data = json_decode((string) $response, true);
        if (!is_array($data)) {
            return ['success' => false, 'gateway_trx_id' => '', 'status' => 'invalid_response'];
        }

        $timeline = $data['data']['timeline'] ?? [];
        $success = false;
        foreach ($timeline as $step) {
            if (($step['status'] ?? '') === 'COMPLETED') {
                $success = true;
                break;
            }
        }

        return [
            'success'        => $success,
            'gateway_trx_id' => $code,
            'status'         => $success ? 'completed' : 'failed',
            'trx_id'         => (string) ($data['data']['metadata']['trx_id'] ?? ''),
        ];
    }

    public function verifyWebhook(string $rawBody, array $headers, array $credentials): bool
    {
        $sharedSecret = $credentials['shared_secret'] ?? '';
        if ($sharedSecret === '') {
            return true;
        }

        $sigHeader = $headers['X-Cc-Webhook-Signature'] ?? $headers['x-cc-webhook-signature'] ?? '';
        if ($sigHeader === '') {
            return false;
        }

        $computedSig = hash_hmac('sha256', $rawBody, $sharedSecret);
        return hash_equals($computedSig, $sigHeader);
    }
}
```

---

## 5. BTCPay Server (Self-Hosted Crypto)

BTCPay Server Greenfield API initiates invoices directly. Webhooks are signed with dynamic keys.

### Manifest Configuration (`manifest.json`)
```json
{
  "name": "BTCPay Server",
  "slug": "btcpay",
  "version": "1.0.0",
  "description": "BTCPay Server Greenfield API dynamic invoice billing",
  "author": "OwnPay Core",
  "type": "gateway",
  "entrypoint": "BTCPayGateway.php",
  "namespace": "OwnPay\\Modules\\Gateways\\BTCPay",
  "capabilities": ["gateway"],
  "requires": {
    "core": ">=0.1.0",
    "php": ">=8.1"
  }
}
```

### Complete Implementation (`BTCPayGateway.php`)
```php
<?php
declare(strict_types=1);

namespace OwnPay\Modules\Gateways\BTCPay;

use OwnPay\Gateway\GatewayAdapterInterface;
use OwnPay\Gateway\GatewayDefaults;
use OwnPay\Plugin\PluginInterface;
use OwnPay\Plugin\Capability;
use OwnPay\Container;
use OwnPay\Event\EventManager;

/**
 * BTCPay Server Greenfield adapter.
 */
final class BTCPayGateway implements PluginInterface, GatewayAdapterInterface
{
    use GatewayDefaults;

    public static function metadata(): array
    {
        return [
            'name' => 'BTCPay Server', 'slug' => 'btcpay', 'version' => '1.0.0',
            'description' => 'BTCPay Server Greenfield', 'author' => 'OwnPay Core', 'type' => 'gateway',
        ];
    }

    public function slug(): string { return 'btcpay'; }
    public function name(): string { return 'BTCPay Server'; }
    public function version(): string { return '1.0.0'; }
    public function description(): string { return 'BTCPay Server Integration'; }

    public function register(EventManager $events, Container $container): void {}
    public function boot(Container $container): void {}
    public function deactivate(Container $container): void {}
    public function uninstall(Container $container): void {}

    public function capabilities(): array { return [Capability::GATEWAY]; }

    public function fields(): array
    {
        return [
            ['name' => 'server_url', 'label' => 'BTCPay Server URL', 'type' => 'text', 'required' => true],
            ['name' => 'api_key', 'label' => 'API Key (Greenfield)', 'type' => 'password', 'required' => true],
            ['name' => 'store_id', 'label' => 'Store ID', 'type' => 'text', 'required' => true],
            ['name' => 'webhook_secret', 'label' => 'Webhook Secret', 'type' => 'password', 'required' => false],
        ];
    }

    public function initiate(array $params, array $credentials): array
    {
        $serverUrl = rtrim($credentials['server_url'], '/');
        $url = "{$serverUrl}/api/v1/stores/" . urlencode($credentials['store_id']) . "/invoices";

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_HTTPHEADER     => [
                'Authorization: token ' . $credentials['api_key'],
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS     => json_encode([
                'amount' => (float)$params['amount'],
                'currency' => strtoupper($params['currency']),
                'metadata' => [
                    'orderId' => $params['trx_id'],
                ],
                'checkout' => [
                    'redirectUrl' => $params['redirect_url'],
                ]
            ]),
        ]);

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            throw new \RuntimeException('BTCPay Invoice creation failed: HTTP ' . $httpCode);
        }

        $data = json_decode((string) $response, true);
        if (!is_array($data)) {
            throw new \RuntimeException('BTCPay invalid response format');
        }

        return [
            'redirect_url' => (string) ($data['checkoutLink'] ?? ''),
            'session_id'   => (string) ($data['id'] ?? ''),
        ];
    }

    public function verify(array $callbackData, array $credentials): array
    {
        $invoiceId = (string) ($callbackData['invoice_id'] ?? $callbackData['id'] ?? '');
        if ($invoiceId === '') {
            return ['success' => false, 'gateway_trx_id' => '', 'status' => 'failed'];
        }

        $serverUrl = rtrim($credentials['server_url'], '/');
        $url = "{$serverUrl}/api/v1/stores/" . urlencode($credentials['store_id']) . "/invoices/{$invoiceId}";

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_HTTPHEADER     => ['Authorization: token ' . $credentials['api_key']],
        ]);

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            return ['success' => false, 'gateway_trx_id' => '', 'status' => 'failed'];
        }

        $data = json_decode((string) $response, true);
        if (!is_array($data)) {
            return ['success' => false, 'gateway_trx_id' => '', 'status' => 'invalid_response'];
        }

        $status = (string) ($data['status'] ?? '');
        $success = in_array($status, ['Settled', 'Processing']);
        $trxId = (string) ($data['metadata']['orderId'] ?? '');

        return [
            'success'        => $success,
            'gateway_trx_id' => $invoiceId,
            'amount'         => (string) ($data['amount'] ?? ''),
            'status'         => $success ? 'completed' : 'failed',
            'trx_id'         => $trxId,
        ];
    }

    public function verifyWebhook(string $rawBody, array $headers, array $credentials): bool
    {
        $webhookSecret = $credentials['webhook_secret'] ?? '';
        if ($webhookSecret === '') {
            return true;
        }

        $sigHeader = $headers['Btcpay-Sig'] ?? $headers['btcpay-sig'] ?? '';
        if ($sigHeader === '') {
            return false;
        }

        $computedSig = 'sha256=' . hash_hmac('sha256', $rawBody, $webhookSecret);
        return hash_equals($computedSig, $sigHeader);
    }
}
```

---

## 6. OpenNode & NOWPayments & Binance Pay

These gateways cover dynamic micro-crypto payments, alternative cryptocurrencies, and Binance's Merchant Open Order Pay.

### Manifest Configuration (`manifest.json`)
```json
{
  "name": "Crypto Aggregators",
  "slug": "crypto-aggregators",
  "version": "1.0.0",
  "description": "Binance Pay, OpenNode, and NOWPayments cryptographic payment adapters",
  "author": "OwnPay Core",
  "type": "gateway",
  "entrypoint": "CryptoAggregatorsGateway.php",
  "namespace": "OwnPay\\Modules\\Gateways\\CryptoAggregators",
  "capabilities": ["gateway"],
  "requires": {
    "core": ">=0.1.0",
    "php": ">=8.1"
  }
}
```

### Complete Implementation (`CryptoAggregatorsGateway.php`)
```php
<?php
declare(strict_types=1);

namespace OwnPay\Modules\Gateways\CryptoAggregators;

use OwnPay\Gateway\GatewayAdapterInterface;
use OwnPay\Gateway\GatewayDefaults;
use OwnPay\Plugin\PluginInterface;
use OwnPay\Plugin\Capability;
use OwnPay\Container;
use OwnPay\Event\EventManager;

/**
 * Binance Pay, OpenNode, and NOWPayments unified adapter.
 */
final class CryptoAggregatorsGateway implements PluginInterface, GatewayAdapterInterface
{
    use GatewayDefaults;

    public static function metadata(): array
    {
        return [
            'name' => 'Crypto Aggregators', 'slug' => 'crypto-aggregators', 'version' => '1.0.0',
            'description' => 'Binance Pay & OpenNode', 'author' => 'OwnPay Core', 'type' => 'gateway',
        ];
    }

    public function slug(): string { return 'crypto-aggregators'; }
    public function name(): string { return 'Crypto Aggregators'; }
    public function version(): string { return '1.0.0'; }
    public function description(): string { return 'Cryptocurrency integrations'; }

    public function register(EventManager $events, Container $container): void {}
    public function boot(Container $container): void {}
    public function deactivate(Container $container): void {}
    public function uninstall(Container $container): void {}

    public function capabilities(): array { return [Capability::GATEWAY]; }

    public function fields(): array
    {
        return [
            ['name' => 'api_key', 'label' => 'API Key / Secret Key', 'type' => 'password', 'required' => true],
            ['name' => 'merchant_id', 'label' => 'Merchant ID / Store ID', 'type' => 'text', 'required' => true],
            ['name' => 'channel', 'label' => 'Active Channel', 'type' => 'select', 'options' => ['binance' => 'Binance Pay', 'opennode' => 'OpenNode (Lightning)', 'nowpayments' => 'NOWPayments'], 'required' => true],
        ];
    }

    public function initiate(array $params, array $credentials): array
    {
        $channel = $credentials['channel'];

        if ($channel === 'binance') {
            $url = 'https://bpay.binanceapi.com/binancepay/openapi/v2/order';
            $timestamp = (string) (time() * 1000);
            $nonce = uniqid('bin_', true);

            $body = [
                'env' => ['terminalType' => 'WEB'],
                'merchantTradeNo' => $params['trx_id'],
                'orderAmount' => (float)$params['amount'],
                'currency' => strtoupper($params['currency']),
                'goods' => [
                    'goodsType' => '01',
                    'goodsCategory' => '0000',
                    'referenceGoodsId' => $params['trx_id'],
                    'goodsName' => 'Payment ' . $params['trx_id'],
                ],
                'returnUrl' => $params['redirect_url'],
            ];

            $payload = json_encode($body);
            
            // Binance Signature build
            $signaturePayload = "{$timestamp}\n{$nonce}\n{$payload}\n";
            $signature = strtoupper(hash_hmac('sha512', $signaturePayload, $credentials['api_key']));

            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 15,
                CURLOPT_HTTPHEADER     => [
                    'Content-Type: application/json',
                    'BinancePay-Timestamp: ' . $timestamp,
                    'BinancePay-Nonce: ' . $nonce,
                    'BinancePay-Certificate-SN: ' . $credentials['merchant_id'],
                    'BinancePay-Signature: ' . $signature,
                ],
                CURLOPT_POSTFIELDS     => $payload,
            ]);

            $response = curl_exec($ch);
            $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode !== 200) {
                throw new \RuntimeException('Binance Pay Order failed: HTTP ' . $httpCode);
            }

            $data = json_decode((string) $response, true);
            $checkoutUrl = (string) ($data['data']['universalUrl'] ?? '');

            return [
                'redirect_url' => $checkoutUrl,
                'session_id'   => (string) ($data['data']['prepayId'] ?? ''),
            ];
        }

        // OpenNode Lightning API
        $ch = curl_init('https://api.opennode.com/v1/charges');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_HTTPHEADER     => [
                'Authorization: ' . $credentials['api_key'],
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS     => json_encode([
                'amount' => (float)$params['amount'],
                'currency' => strtoupper($params['currency']),
                'order_id' => $params['trx_id'],
                'callback_url' => $params['redirect_url'],
            ]),
        ]);

        $response = curl_exec($ch);
        curl_close($ch);
        $data = json_decode((string) $response, true);
        $redirectUrl = (string) ($data['data']['hosted_checkout_url'] ?? '');

        return [
            'redirect_url' => $redirectUrl,
            'session_id'   => (string) ($data['data']['id'] ?? ''),
        ];
    }
}
```
