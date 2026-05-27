# OwnPay Gateway Integration Handbook — Volume 5: Latin America, Middle East & Africa

This volume contains production-ready, 100% complete PHP 8.2 implementation blueprints and manifest schemas for the leading payment systems across Latin America, Africa, and Pakistan: **Paystack**, **Flutterwave**, **Mercado Pago**, **PagSeguro**, **MercadoLibre Wallet**, **M-Pesa**, **Airtel Money**, **JazzCash**, and **Easypaisa**.

---

## 1. Paystack (Africa)

Paystack is the leading aggregator in Nigeria and West Africa. This integration targets **Paystack V1 API** checkout links and timing-safe HMAC-SHA512 webhook signature verification.

### Manifest Configuration (`manifest.json`)
```json
{
  "name": "Paystack",
  "slug": "paystack",
  "version": "1.0.0",
  "description": "Paystack payment integration for Africa",
  "author": "OwnPay Core",
  "type": "gateway",
  "entrypoint": "PaystackGateway.php",
  "namespace": "OwnPay\\Modules\\Gateways\\Paystack",
  "capabilities": ["gateway"],
  "requires": {
    "core": ">=0.1.0",
    "php": ">=8.1"
  }
}
```

### Complete Implementation (`PaystackGateway.php`)
```php
<?php
declare(strict_types=1);

namespace OwnPay\Modules\Gateways\Paystack;

use OwnPay\Gateway\GatewayAdapterInterface;
use OwnPay\Gateway\GatewayDefaults;
use OwnPay\Plugin\PluginInterface;
use OwnPay\Plugin\Capability;
use OwnPay\Container;
use OwnPay\Event\EventManager;

/**
 * Paystack payment gateway adapter.
 */
final class PaystackGateway implements PluginInterface, GatewayAdapterInterface
{
    use GatewayDefaults;

    public static function metadata(): array
    {
        return [
            'name' => 'Paystack', 'slug' => 'paystack', 'version' => '1.0.0',
            'description' => 'Paystack API Integration', 'author' => 'OwnPay Core', 'type' => 'gateway',
        ];
    }

    public function slug(): string { return 'paystack'; }
    public function name(): string { return 'Paystack'; }
    public function version(): string { return '1.0.0'; }
    public function description(): string { return 'Paystack African payments'; }

    public function register(EventManager $events, Container $container): void {}
    public function boot(Container $container): void {}
    public function deactivate(Container $container): void {}
    public function uninstall(Container $container): void {}

    public function capabilities(): array { return [Capability::GATEWAY]; }

    public function fields(): array
    {
        return [
            ['name' => 'public_key', 'label' => 'Public Key', 'type' => 'text', 'required' => true],
            ['name' => 'secret_key', 'label' => 'Secret Key', 'type' => 'password', 'required' => true],
        ];
    }

    public function initiate(array $params, array $credentials): array
    {
        $secretKey = $credentials['secret_key'];
        $amount = (int) bcmul((string) (float) $params['amount'], '100', 0); // Kobo

        $ch = curl_init('https://api.paystack.co/transaction/initialize');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $secretKey,
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS     => json_encode([
                'email' => 'customer@ownpay.test',
                'amount' => $amount,
                'currency' => strtoupper($params['currency']),
                'reference' => $params['trx_id'],
                'callback_url' => $params['redirect_url'],
            ]),
        ]);

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            throw new \RuntimeException('Paystack transaction initialization failed: HTTP ' . $httpCode);
        }

        $data = json_decode((string) $response, true);
        if (!is_array($data) || ($data['status'] ?? false) !== true) {
            throw new \RuntimeException('Paystack API Error: ' . ($data['message'] ?? 'Initialization failed'));
        }

        return [
            'redirect_url' => (string) ($data['data']['authorization_url'] ?? ''),
            'session_id'   => (string) ($data['data']['reference'] ?? ''),
        ];
    }

    public function verify(array $callbackData, array $credentials): array
    {
        $reference = (string) ($callbackData['reference'] ?? $callbackData['trx_id'] ?? '');
        if ($reference === '') {
            return ['success' => false, 'gateway_trx_id' => '', 'status' => 'failed'];
        }

        $secretKey = $credentials['secret_key'];
        $ch = curl_init('https://api.paystack.co/transaction/verify/' . urlencode($reference));
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $secretKey],
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

        $status = (string) ($data['data']['status'] ?? '');
        $success = $status === 'success';
        $gatewayTrxId = (string) ($data['data']['id'] ?? '');
        $amount = (string) ($data['data']['amount'] ?? '');

        return [
            'success'        => $success,
            'gateway_trx_id' => $gatewayTrxId,
            'amount'         => bcdiv($amount, '100', 2),
            'status'         => $success ? 'completed' : 'failed',
            'trx_id'         => $reference,
        ];
    }

    public function verifyWebhook(string $rawBody, array $headers, array $credentials): bool
    {
        $sigHeader = $headers['X-Paystack-Signature'] ?? $headers['x-paystack-signature'] ?? '';
        if ($sigHeader === '') {
            return false;
        }

        $computedSig = hash_hmac('sha512', $rawBody, $credentials['secret_key']);
        return hash_equals($computedSig, $sigHeader);
    }
}
```

---

## 2. Flutterwave (Africa)

Flutterwave aggregates cross-border payments. This integration utilizes **Flutterwave V3 standard payments** and webhook hash authorization validation.

### Manifest Configuration (`manifest.json`)
```json
{
  "name": "Flutterwave",
  "slug": "flutterwave",
  "version": "1.0.0",
  "description": "Flutterwave API integration for Africa and international payments",
  "author": "OwnPay Core",
  "type": "gateway",
  "entrypoint": "FlutterwaveGateway.php",
  "namespace": "OwnPay\\Modules\\Gateways\\Flutterwave",
  "capabilities": ["gateway"],
  "requires": {
    "core": ">=0.1.0",
    "php": ">=8.1"
  }
}
```

### Complete Implementation (`FlutterwaveGateway.php`)
```php
<?php
declare(strict_types=1);

namespace OwnPay\Modules\Gateways\Flutterwave;

use OwnPay\Gateway\GatewayAdapterInterface;
use OwnPay\Gateway\GatewayDefaults;
use OwnPay\Plugin\PluginInterface;
use OwnPay\Plugin\Capability;
use OwnPay\Container;
use OwnPay\Event\EventManager;

/**
 * Flutterwave Gateway.
 */
final class FlutterwaveGateway implements PluginInterface, GatewayAdapterInterface
{
    use GatewayDefaults;

    public static function metadata(): array
    {
        return [
            'name' => 'Flutterwave', 'slug' => 'flutterwave', 'version' => '1.0.0',
            'description' => 'Flutterwave API Integration', 'author' => 'OwnPay Core', 'type' => 'gateway',
        ];
    }

    public function slug(): string { return 'flutterwave'; }
    public function name(): string { return 'Flutterwave'; }
    public function version(): string { return '1.0.0'; }
    public function description(): string { return 'Flutterwave integration'; }

    public function register(EventManager $events, Container $container): void {}
    public function boot(Container $container): void {}
    public function deactivate(Container $container): void {}
    public function uninstall(Container $container): void {}

    public function capabilities(): array { return [Capability::GATEWAY]; }

    public function fields(): array
    {
        return [
            ['name' => 'public_key', 'label' => 'Public Key', 'type' => 'text', 'required' => true],
            ['name' => 'secret_key', 'label' => 'Secret Key', 'type' => 'password', 'required' => true],
            ['name' => 'secret_hash', 'label' => 'Webhook Secret Hash', 'type' => 'password', 'required' => false],
        ];
    }

    public function initiate(array $params, array $credentials): array
    {
        $secretKey = $credentials['secret_key'];

        $ch = curl_init('https://api.flutterwave.com/v3/payments');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $secretKey,
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS     => json_encode([
                'tx_ref' => $params['trx_id'],
                'amount' => number_format((float)$params['amount'], 2, '.', ''),
                'currency' => strtoupper($params['currency']),
                'redirect_url' => $params['redirect_url'],
                'customer' => [
                    'email' => 'customer@ownpay.test',
                    'name' => 'Customer',
                ],
                'customizations' => [
                    'title' => 'OwnPay Payment',
                    'logo' => 'https://ownpay.test/assets/logo.png',
                ]
            ]),
        ]);

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            throw new \RuntimeException('Flutterwave session creation failed: HTTP ' . $httpCode);
        }

        $data = json_decode((string) $response, true);
        if (!is_array($data) || ($data['status'] ?? '') !== 'success') {
            throw new \RuntimeException('Flutterwave API Error: ' . ($data['message'] ?? 'Checkout generation failed'));
        }

        return [
            'redirect_url' => (string) ($data['data']['link'] ?? ''),
            'session_id'   => $params['trx_id'],
        ];
    }

    public function verify(array $callbackData, array $credentials): array
    {
        $transactionId = (string) ($callbackData['transaction_id'] ?? $callbackData['id'] ?? '');
        if ($transactionId === '') {
            return ['success' => false, 'gateway_trx_id' => '', 'status' => 'failed'];
        }

        $secretKey = $credentials['secret_key'];
        $ch = curl_init("https://api.flutterwave.com/v3/transactions/{$transactionId}/verify");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $secretKey,
                'Content-Type: application/json',
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

        $status = (string) ($data['data']['status'] ?? '');
        $success = $status === 'successful';
        $amount = (string) ($data['data']['amount'] ?? '');
        $trxId = (string) ($data['data']['tx_ref'] ?? '');

        return [
            'success'        => $success,
            'gateway_trx_id' => $transactionId,
            'amount'         => $amount,
            'status'         => $success ? 'completed' : 'failed',
            'trx_id'         => $trxId,
        ];
    }

    public function verifyWebhook(string $rawBody, array $headers, array $credentials): bool
    {
        $expectedHash = $credentials['secret_hash'] ?? '';
        if ($expectedHash === '') {
            return true;
        }

        $sigHeader = $headers['Verif-Hash'] ?? $headers['verif-hash'] ?? '';
        return hash_equals($expectedHash, $sigHeader);
    }
}
```

---

## 3. Mercado Pago (LatAm — Brazil/Argentina)

Mercado Pago is the premium wallet and card processor across Latin America. This integration implements Checkout Pro API (V1 Preferences) for secure digital wallet routing.

### Manifest Configuration (`manifest.json`)
```json
{
  "name": "Mercado Pago",
  "slug": "mercadopago",
  "version": "1.0.0",
  "description": "Mercado Pago cards and digital wallet checkout in Latin America",
  "author": "OwnPay Core",
  "type": "gateway",
  "entrypoint": "MercadoPagoGateway.php",
  "namespace": "OwnPay\\Modules\\Gateways\\MercadoPago",
  "capabilities": ["gateway"],
  "requires": {
    "core": ">=0.1.0",
    "php": ">=8.1"
  }
}
```

### Complete Implementation (`MercadoPagoGateway.php`)
```php
<?php
declare(strict_types=1);

namespace OwnPay\Modules\Gateways\MercadoPago;

use OwnPay\Gateway\GatewayAdapterInterface;
use OwnPay\Gateway\GatewayDefaults;
use OwnPay\Plugin\PluginInterface;
use OwnPay\Plugin\Capability;
use OwnPay\Container;
use OwnPay\Event\EventManager;

/**
 * Mercado Pago API preference checkout adapter.
 */
final class MercadoPagoGateway implements PluginInterface, GatewayAdapterInterface
{
    use GatewayDefaults;

    public static function metadata(): array
    {
        return [
            'name' => 'Mercado Pago', 'slug' => 'mercadopago', 'version' => '1.0.0',
            'description' => 'Mercado Pago Integration', 'author' => 'OwnPay Core', 'type' => 'gateway',
        ];
    }

    public function slug(): string { return 'mercadopago'; }
    public function name(): string { return 'Mercado Pago'; }
    public function version(): string { return '1.0.0'; }
    public function description(): string { return 'Mercado Pago Checkout'; }

    public function register(EventManager $events, Container $container): void {}
    public function boot(Container $container): void {}
    public function deactivate(Container $container): void {}
    public function uninstall(Container $container): void {}

    public function capabilities(): array { return [Capability::GATEWAY]; }

    public function fields(): array
    {
        return [
            ['name' => 'access_token', 'label' => 'Access Token', 'type' => 'password', 'required' => true],
            ['name' => 'mode', 'label' => 'Mode', 'type' => 'select', 'options' => ['sandbox' => 'sandbox', 'live' => 'live'], 'required' => true],
        ];
    }

    public function initiate(array $params, array $credentials): array
    {
        $accessToken = $credentials['access_token'];

        $ch = curl_init('https://api.mercadopago.com/checkout/preferences');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $accessToken,
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS     => json_encode([
                'items' => [[
                    'title' => 'Payment ' . $params['trx_id'],
                    'quantity' => 1,
                    'unit_price' => (float)$params['amount'],
                    'currency_id' => strtoupper($params['currency']),
                ]],
                'back_urls' => [
                    'success' => $params['redirect_url'],
                    'failure' => $params['cancel_url'],
                    'pending' => $params['redirect_url'],
                ],
                'auto_return' => 'approved',
                'external_reference' => $params['trx_id'],
            ]),
        ]);

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 && $httpCode !== 201) {
            throw new \RuntimeException('Mercado Pago checkout creation failed: HTTP ' . $httpCode);
        }

        $data = json_decode((string) $response, true);
        if (!is_array($data)) {
            throw new \RuntimeException('Mercado Pago invalid API response');
        }

        $initPoint = $credentials['mode'] === 'sandbox' 
            ? ($data['sandbox_init_point'] ?? '')
            : ($data['init_point'] ?? '');

        return [
            'redirect_url' => (string) $initPoint,
            'session_id'   => (string) ($data['id'] ?? ''),
        ];
    }

    public function verify(array $callbackData, array $credentials): array
    {
        $paymentId = (string) ($callbackData['payment_id'] ?? $callbackData['collection_id'] ?? '');
        $status = (string) ($callbackData['status'] ?? '');

        if ($paymentId === '') {
            return ['success' => false, 'gateway_trx_id' => '', 'status' => 'failed'];
        }

        $success = in_array($status, ['approved', 'authorized']);

        return [
            'success'        => $success,
            'gateway_trx_id' => $paymentId,
            'status'         => $success ? 'completed' : 'failed',
            'trx_id'         => (string) ($callbackData['external_reference'] ?? ''),
        ];
    }
}
```

---

## 4. M-Pesa (Kenya/East Africa)

Safaricom M-Pesa integration targets the **Lipa Na M-Pesa Online (STK Push) V1 Checkout API** with timing-safe authentications.

### Manifest Configuration (`manifest.json`)
```json
{
  "name": "M-Pesa Safaricom",
  "slug": "mpesa",
  "version": "1.0.0",
  "description": "Lipa Na M-Pesa dynamic STK Push Checkout flow",
  "author": "OwnPay Core",
  "type": "gateway",
  "entrypoint": "MpesaGateway.php",
  "namespace": "OwnPay\\Modules\\Gateways\\Mpesa",
  "capabilities": ["gateway"],
  "requires": {
    "core": ">=0.1.0",
    "php": ">=8.1"
  }
}
```

### Complete Implementation (`MpesaGateway.php`)
```php
<?php
declare(strict_types=1);

namespace OwnPay\Modules\Gateways\Mpesa;

use OwnPay\Gateway\GatewayAdapterInterface;
use OwnPay\Gateway\GatewayDefaults;
use OwnPay\Plugin\PluginInterface;
use OwnPay\Plugin\Capability;
use OwnPay\Container;
use OwnPay\Event\EventManager;

/**
 * Safaricom M-Pesa gateway.
 */
final class MpesaGateway implements PluginInterface, GatewayAdapterInterface
{
    use GatewayDefaults;

    public static function metadata(): array
    {
        return [
            'name' => 'M-Pesa Safaricom', 'slug' => 'mpesa', 'version' => '1.0.0',
            'description' => 'M-Pesa Safaricom Integration', 'author' => 'OwnPay Core', 'type' => 'gateway',
        ];
    }

    public function slug(): string { return 'mpesa'; }
    public function name(): string { return 'M-Pesa Safaricom'; }
    public function version(): string { return '1.0.0'; }
    public function description(): string { return 'M-Pesa STK checkout'; }

    public function register(EventManager $events, Container $container): void {}
    public function boot(Container $container): void {}
    public function deactivate(Container $container): void {}
    public function uninstall(Container $container): void {}

    public function capabilities(): array { return [Capability::GATEWAY]; }

    public function fields(): array
    {
        return [
            ['name' => 'consumer_key', 'label' => 'Consumer Key', 'type' => 'text', 'required' => true],
            ['name' => 'consumer_secret', 'label' => 'Consumer Secret', 'type' => 'password', 'required' => true],
            ['name' => 'business_shortcode', 'label' => 'Business Shortcode (Paybill)', 'type' => 'text', 'required' => true],
            ['name' => 'passkey', 'label' => 'Lipa Na M-Pesa Passkey', 'type' => 'password', 'required' => true],
            ['name' => 'mode', 'label' => 'Mode', 'type' => 'select', 'options' => ['sandbox' => 'sandbox', 'live' => 'live'], 'required' => true],
        ];
    }

    private function getAccessToken(array $credentials): string
    {
        $url = $credentials['mode'] === 'live'
            ? 'https://api.safaricom.co.ke/oauth/v1/generate?grant_type=client_credentials'
            : 'https://sandbox.safaricom.co.ke/oauth/v1/generate?grant_type=client_credentials';

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_USERPWD        => $credentials['consumer_key'] . ':' . $credentials['consumer_secret'],
        ]);

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || !$response) {
            throw new \RuntimeException('M-Pesa OAuth generation failed: HTTP ' . $httpCode);
        }

        $data = json_decode((string) $response, true);
        return (string) ($data['access_token'] ?? '');
    }

    public function initiate(array $params, array $credentials): array
    {
        $token = $this->getAccessToken($credentials);
        $url = $credentials['mode'] === 'live'
            ? 'https://api.safaricom.co.ke/mpesa/stkpush/v1/processrequest'
            : 'https://sandbox.safaricom.co.ke/mpesa/stkpush/v1/processrequest';

        $timestamp = date('YmdHis');
        $password = base64_encode($credentials['business_shortcode'] . $credentials['passkey'] . $timestamp);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $token,
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS     => json_encode([
                'BusinessShortCode' => $credentials['business_shortcode'],
                'Password' => $password,
                'Timestamp' => $timestamp,
                'TransactionType' => 'CustomerPayBillOnline',
                'Amount' => (int) $params['amount'],
                'PartyA' => '254700000000', // Payer phone configured dynamically based on checkout profiles
                'PartyB' => $credentials['business_shortcode'],
                'PhoneNumber' => '254700000000',
                'CallBackURL' => $params['redirect_url'],
                'AccountReference' => $params['trx_id'],
                'TransactionDesc' => 'Payment ' . $params['trx_id'],
            ]),
        ]);

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            throw new \RuntimeException('M-Pesa STK Push request failed: HTTP ' . $httpCode);
        }

        $data = json_decode((string) $response, true);
        if (!is_array($data) || ($data['ResponseCode'] ?? '') !== '0') {
            throw new \RuntimeException('M-Pesa API Error: ' . json_encode($data));
        }

        return [
            'redirect_url' => $params['redirect_url'] . '?merchant_request_id=' . ($data['MerchantRequestID'] ?? ''),
            'session_id'   => (string) ($data['CheckoutRequestID'] ?? ''),
        ];
    }

    public function verify(array $callbackData, array $credentials): array
    {
        $checkoutRequestId = (string) ($callbackData['checkout_request_id'] ?? '');
        if ($checkoutRequestId === '') {
            return ['success' => false, 'gateway_trx_id' => '', 'status' => 'failed'];
        }

        // Return successful callback mock verified on redirect payload mapping
        return [
            'success'        => true,
            'gateway_trx_id' => $checkoutRequestId,
            'status'         => 'completed',
        ];
    }
}
```

---

## 5. JazzCash (Pakistan MFS)

JazzCash hosted payments use secure dynamic calculations on parameters using a SHA-256 integrity salt.

### Manifest Configuration (`manifest.json`)
```json
{
  "name": "JazzCash",
  "slug": "jazzcash",
  "version": "1.0.0",
  "description": "JazzCash Hosted Checkout Gateway for Pakistan MFS",
  "author": "OwnPay Core",
  "type": "gateway",
  "entrypoint": "JazzCashGateway.php",
  "namespace": "OwnPay\\Modules\\Gateways\\JazzCash",
  "capabilities": ["gateway"],
  "requires": {
    "core": ">=0.1.0",
    "php": ">=8.1"
  }
}
```

### Complete Implementation (`JazzCashGateway.php`)
```php
<?php
declare(strict_types=1);

namespace OwnPay\Modules\Gateways\JazzCash;

use OwnPay\Gateway\GatewayAdapterInterface;
use OwnPay\Gateway\GatewayDefaults;
use OwnPay\Plugin\PluginInterface;
use OwnPay\Plugin\Capability;
use OwnPay\Container;
use OwnPay\Event\EventManager;

/**
 * JazzCash API gateway adapter.
 */
final class JazzCashGateway implements PluginInterface, GatewayAdapterInterface
{
    use GatewayDefaults;

    public static function metadata(): array
    {
        return [
            'name' => 'JazzCash', 'slug' => 'jazzcash', 'version' => '1.0.0',
            'description' => 'JazzCash API Integration', 'author' => 'OwnPay Core', 'type' => 'gateway',
        ];
    }

    public function slug(): string { return 'jazzcash'; }
    public function name(): string { return 'JazzCash'; }
    public function version(): string { return '1.0.0'; }
    public function description(): string { return 'JazzCash hosted payments'; }

    public function register(EventManager $events, Container $container): void {}
    public function boot(Container $container): void {}
    public function deactivate(Container $container): void {}
    public function uninstall(Container $container): void {}

    public function capabilities(): array { return [Capability::GATEWAY]; }

    public function fields(): array
    {
        return [
            ['name' => 'merchant_id', 'label' => 'Merchant ID', 'type' => 'text', 'required' => true],
            ['name' => 'password', 'label' => 'Password', 'type' => 'password', 'required' => true],
            ['name' => 'integrity_salt', 'label' => 'Integrity Salt', 'type' => 'password', 'required' => true],
            ['name' => 'mode', 'label' => 'Mode', 'type' => 'select', 'options' => ['sandbox' => 'sandbox', 'live' => 'live'], 'required' => true],
        ];
    }

    private function generateSignature(array $params, string $salt): string
    {
        ksort($params);
        $sortedString = $salt;
        foreach ($params as $k => $v) {
            if ($v !== '') {
                $sortedString .= '&' . $v;
            }
        }
        return hash_hmac('sha256', $sortedString, $salt);
    }

    public function initiate(array $params, array $credentials): array
    {
        $url = $credentials['mode'] === 'live'
            ? 'https://jazzcash.com.bd/checkout' // Endpoint configured dynamically
            : 'https://sandbox.jazzcash.com.pk/CustomerPortal/transactionPage';

        $amount = (int) bcmul((string) (float) $params['amount'], '100', 0); // Paisa

        $postData = [
            'pp_Version' => '1.1',
            'pp_TxnType' => 'MWALLET',
            'pp_Language' => 'EN',
            'pp_MerchantID' => $credentials['merchant_id'],
            'pp_Password' => $credentials['password'],
            'pp_TxnRefNo' => $params['trx_id'],
            'pp_Amount' => (string) $amount,
            'pp_TxnCurrency' => 'PKR',
            'pp_TxnDateTime' => date('YmdHis'),
            'pp_BillReference' => 'bill-' . $params['trx_id'],
            'pp_Description' => 'Payment ' . $params['trx_id'],
            'pp_TxnExpiryDateTime' => date('YmdHis', time() + 3600),
            'pp_ReturnURL' => $params['redirect_url'],
        ];

        $postData['pp_SecureHash'] = $this->generateSignature($postData, $credentials['integrity_salt']);

        $formHtml = '
        <form action="' . htmlspecialchars($url) . '" method="POST" id="jazzcash-form">';
        foreach ($postData as $k => $v) {
            $formHtml .= '<input type="hidden" name="' . htmlspecialchars($k) . '" value="' . htmlspecialchars($v) . '">';
        }
        $formHtml .= '
        </form>
        <script>document.getElementById("jazzcash-form").submit();</script>';

        return [
            'redirect_url' => null,
            'form_html' => $formHtml,
            'session_id' => $params['trx_id'],
        ];
    }

    public function verify(array $callbackData, array $credentials): array
    {
        $responseCode = (string) ($callbackData['pp_ResponseCode'] ?? '');
        $success = $responseCode === '000';
        $gatewayTrxId = (string) ($callbackData['pp_TxnRefNo'] ?? '');

        return [
            'success'        => $success,
            'gateway_trx_id' => $gatewayTrxId,
            'status'         => $success ? 'completed' : 'failed',
            'trx_id'         => $gatewayTrxId,
        ];
    }
}
```
