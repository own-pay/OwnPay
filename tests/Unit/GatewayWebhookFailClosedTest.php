<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/modules/gateways/myfatoorah/MyfatoorahGateway.php';
require_once dirname(__DIR__, 2) . '/modules/gateways/google-pay/GooglePayGateway.php';
require_once dirname(__DIR__, 2) . '/modules/gateways/flutterwave/FlutterwaveGateway.php';
require_once dirname(__DIR__, 2) . '/modules/gateways/apple-pay/ApplePayGateway.php';
require_once dirname(__DIR__, 2) . '/modules/gateways/btcpay/BTCPayGateway.php';
require_once dirname(__DIR__, 2) . '/modules/gateways/checkout-com/CheckoutComGateway.php';
require_once dirname(__DIR__, 2) . '/modules/gateways/coinbase-commerce/CoinbaseGateway.php';
require_once dirname(__DIR__, 2) . '/modules/gateways/tap-payments/TapPaymentsGateway.php';

use OwnPay\Modules\Gateways\Myfatoorah\MyfatoorahGateway;
use OwnPay\Modules\Gateways\GooglePay\GooglePayGateway;
use OwnPay\Modules\Gateways\Flutterwave\FlutterwaveGateway;
use OwnPay\Modules\Gateways\ApplePay\ApplePayGateway;
use OwnPay\Modules\Gateways\BTCPay\BTCPayGateway;
use OwnPay\Modules\Gateways\CheckoutCom\CheckoutComGateway;
use OwnPay\Modules\Gateways\Coinbase\CoinbaseGateway;
use OwnPay\Modules\Gateways\TapPayments\TapPaymentsGateway;

final class GatewayWebhookFailClosedTest extends TestCase
{
    private const SECRET = 'super-secret-webhook-key';
    private const BODY = '{"event":"payment.completed","amount":"10.00"}';

    // MyFatoorah
    public function testMyfatoorahFailsClosedWithoutSecret(): void
    {
        $gw = new MyfatoorahGateway();
        $sig = base64_encode(hash_hmac('sha256', self::BODY, '', true));
        $this->assertFalse($gw->verifyWebhook(self::BODY, ['MyFatoorah-Signature' => $sig], []));
        $this->assertFalse($gw->verifyWebhook(self::BODY, ['MyFatoorah-Signature' => $sig], ['webhook_secret' => '']));
    }

    public function testMyfatoorahAcceptsValidSignature(): void
    {
        $gw = new MyfatoorahGateway();
        $sig = base64_encode(hash_hmac('sha256', self::BODY, self::SECRET, true));
        $this->assertTrue($gw->verifyWebhook(self::BODY, ['MyFatoorah-Signature' => $sig], ['webhook_secret' => self::SECRET]));
    }

    public function testMyfatoorahRejectsBadSignature(): void
    {
        $gw = new MyfatoorahGateway();
        $this->assertFalse($gw->verifyWebhook(self::BODY, [], ['webhook_secret' => self::SECRET]));
        $this->assertFalse($gw->verifyWebhook(self::BODY, ['MyFatoorah-Signature' => ''], ['webhook_secret' => self::SECRET]));
        $this->assertFalse($gw->verifyWebhook(self::BODY, ['MyFatoorah-Signature' => 'deadbeef'], ['webhook_secret' => self::SECRET]));
        $valid = base64_encode(hash_hmac('sha256', self::BODY, self::SECRET, true));
        $this->assertFalse($gw->verifyWebhook(self::BODY . 'x', ['MyFatoorah-Signature' => $valid], ['webhook_secret' => self::SECRET]));
    }

    // Google Pay
    private function stripeSig(string $secret, string $body, ?int $ts = null): string
    {
        $ts = $ts ?? time();
        $sig = hash_hmac('sha256', $ts . '.' . $body, $secret);
        return 't=' . $ts . ',v1=' . $sig;
    }

    public function testGooglePayFailsClosedWithoutSecret(): void
    {
        $gw = new GooglePayGateway();
        $sig = $this->stripeSig('', self::BODY);
        $this->assertFalse($gw->verifyWebhook(self::BODY, ['Stripe-Signature' => $sig], []));
        $this->assertFalse($gw->verifyWebhook(self::BODY, ['Stripe-Signature' => $sig], ['webhook_secret' => '']));
    }

    public function testGooglePayAcceptsValidSignature(): void
    {
        $gw = new GooglePayGateway();
        $sig = $this->stripeSig(self::SECRET, self::BODY);
        $this->assertTrue($gw->verifyWebhook(self::BODY, ['Stripe-Signature' => $sig], ['webhook_secret' => self::SECRET]));
    }

    public function testGooglePayRejectsBadSignature(): void
    {
        $gw = new GooglePayGateway();
        $this->assertFalse($gw->verifyWebhook(self::BODY, [], ['webhook_secret' => self::SECRET]));
        $this->assertFalse($gw->verifyWebhook(self::BODY, ['Stripe-Signature' => ''], ['webhook_secret' => self::SECRET]));
        $this->assertFalse($gw->verifyWebhook(self::BODY, ['Stripe-Signature' => 't=1,v1=deadbeef'], ['webhook_secret' => self::SECRET]));
    }

    // Apple Pay (same Stripe scheme)
    public function testApplePayFailsClosedWithoutSecret(): void
    {
        $gw = new ApplePayGateway();
        $sig = $this->stripeSig('', self::BODY);
        $this->assertFalse($gw->verifyWebhook(self::BODY, ['Stripe-Signature' => $sig], []));
        $this->assertFalse($gw->verifyWebhook(self::BODY, ['Stripe-Signature' => $sig], ['webhook_secret' => '']));
    }

    public function testApplePayAcceptsValidSignature(): void
    {
        $gw = new ApplePayGateway();
        $sig = $this->stripeSig(self::SECRET, self::BODY);
        $this->assertTrue($gw->verifyWebhook(self::BODY, ['Stripe-Signature' => $sig], ['webhook_secret' => self::SECRET]));
    }

    public function testApplePayRejectsBadSignature(): void
    {
        $gw = new ApplePayGateway();
        $this->assertFalse($gw->verifyWebhook(self::BODY, [], ['webhook_secret' => self::SECRET]));
        $this->assertFalse($gw->verifyWebhook(self::BODY, ['Stripe-Signature' => ''], ['webhook_secret' => self::SECRET]));
        $this->assertFalse($gw->verifyWebhook(self::BODY, ['Stripe-Signature' => 't=1,v1=deadbeef'], ['webhook_secret' => self::SECRET]));
    }

    // Flutterwave
    public function testFlutterwaveFailsClosedWithoutSecret(): void
    {
        $gw = new FlutterwaveGateway();
        $this->assertFalse($gw->verifyWebhook(self::BODY, ['Verif-Hash' => self::SECRET], []));
        $this->assertFalse($gw->verifyWebhook(self::BODY, ['Verif-Hash' => self::SECRET], ['secret_hash' => '']));
    }

    public function testFlutterwaveAcceptsValidSignature(): void
    {
        $gw = new FlutterwaveGateway();
        $this->assertTrue($gw->verifyWebhook(self::BODY, ['Verif-Hash' => self::SECRET], ['secret_hash' => self::SECRET]));
    }

    public function testFlutterwaveRejectsBadSignature(): void
    {
        $gw = new FlutterwaveGateway();
        $this->assertFalse($gw->verifyWebhook(self::BODY, [], ['secret_hash' => self::SECRET]));
        $this->assertFalse($gw->verifyWebhook(self::BODY, ['Verif-Hash' => ''], ['secret_hash' => self::SECRET]));
        $this->assertFalse($gw->verifyWebhook(self::BODY, ['Verif-Hash' => 'wrong'], ['secret_hash' => self::SECRET]));
    }

    // BTCPay
    public function testBTCPayFailsClosedWithoutSecret(): void
    {
        $gw = new BTCPayGateway();
        $sig = 'sha256=' . hash_hmac('sha256', self::BODY, '');
        $this->assertFalse($gw->verifyWebhook(self::BODY, ['Btcpay-Sig' => $sig], []));
        $this->assertFalse($gw->verifyWebhook(self::BODY, ['Btcpay-Sig' => $sig], ['webhook_secret' => '']));
    }

    public function testBTCPayAcceptsValidSignature(): void
    {
        $gw = new BTCPayGateway();
        $sig = 'sha256=' . hash_hmac('sha256', self::BODY, self::SECRET);
        $this->assertTrue($gw->verifyWebhook(self::BODY, ['Btcpay-Sig' => $sig], ['webhook_secret' => self::SECRET]));
    }

    public function testBTCPayRejectsBadSignature(): void
    {
        $gw = new BTCPayGateway();
        $this->assertFalse($gw->verifyWebhook(self::BODY, [], ['webhook_secret' => self::SECRET]));
        $this->assertFalse($gw->verifyWebhook(self::BODY, ['Btcpay-Sig' => ''], ['webhook_secret' => self::SECRET]));
        $this->assertFalse($gw->verifyWebhook(self::BODY, ['Btcpay-Sig' => 'sha256=deadbeef'], ['webhook_secret' => self::SECRET]));
    }

    // Checkout.com
    public function testCheckoutComFailsClosedWithoutSecret(): void
    {
        $gw = new CheckoutComGateway();
        $sig = hash_hmac('sha256', self::BODY, '');
        $this->assertFalse($gw->verifyWebhook(self::BODY, ['Cko-Signature' => $sig], []));
        $this->assertFalse($gw->verifyWebhook(self::BODY, ['Cko-Signature' => $sig], ['webhook_secret' => '']));
    }

    public function testCheckoutComAcceptsValidSignature(): void
    {
        $gw = new CheckoutComGateway();
        $sig = hash_hmac('sha256', self::BODY, self::SECRET);
        $this->assertTrue($gw->verifyWebhook(self::BODY, ['Cko-Signature' => $sig], ['webhook_secret' => self::SECRET]));
    }

    public function testCheckoutComRejectsBadSignature(): void
    {
        $gw = new CheckoutComGateway();
        $this->assertFalse($gw->verifyWebhook(self::BODY, [], ['webhook_secret' => self::SECRET]));
        $this->assertFalse($gw->verifyWebhook(self::BODY, ['Cko-Signature' => ''], ['webhook_secret' => self::SECRET]));
        $this->assertFalse($gw->verifyWebhook(self::BODY, ['Cko-Signature' => 'deadbeef'], ['webhook_secret' => self::SECRET]));
    }

    // Coinbase
    public function testCoinbaseFailsClosedWithoutSecret(): void
    {
        $gw = new CoinbaseGateway();
        $sig = hash_hmac('sha256', self::BODY, '');
        $this->assertFalse($gw->verifyWebhook(self::BODY, ['X-Cc-Webhook-Signature' => $sig], []));
        $this->assertFalse($gw->verifyWebhook(self::BODY, ['X-Cc-Webhook-Signature' => $sig], ['shared_secret' => '']));
    }

    public function testCoinbaseAcceptsValidSignature(): void
    {
        $gw = new CoinbaseGateway();
        $sig = hash_hmac('sha256', self::BODY, self::SECRET);
        $this->assertTrue($gw->verifyWebhook(self::BODY, ['X-Cc-Webhook-Signature' => $sig], ['shared_secret' => self::SECRET]));
    }

    public function testCoinbaseRejectsBadSignature(): void
    {
        $gw = new CoinbaseGateway();
        $this->assertFalse($gw->verifyWebhook(self::BODY, [], ['shared_secret' => self::SECRET]));
        $this->assertFalse($gw->verifyWebhook(self::BODY, ['X-Cc-Webhook-Signature' => ''], ['shared_secret' => self::SECRET]));
        $this->assertFalse($gw->verifyWebhook(self::BODY, ['X-Cc-Webhook-Signature' => 'deadbeef'], ['shared_secret' => self::SECRET]));
    }

    // Tap Payments
    public function testTapPaymentsFailsClosedWithoutSecret(): void
    {
        $gw = new TapPaymentsGateway();
        $sig = hash_hmac('sha256', self::BODY, '');
        $this->assertFalse($gw->verifyWebhook(self::BODY, ['X-Tap-Sign' => $sig], []));
        $this->assertFalse($gw->verifyWebhook(self::BODY, ['X-Tap-Sign' => $sig], ['webhook_secret' => '']));
    }

    public function testTapPaymentsAcceptsValidSignature(): void
    {
        $gw = new TapPaymentsGateway();
        $sig = hash_hmac('sha256', self::BODY, self::SECRET);
        $this->assertTrue($gw->verifyWebhook(self::BODY, ['X-Tap-Sign' => $sig], ['webhook_secret' => self::SECRET]));
    }

    public function testTapPaymentsRejectsBadSignature(): void
    {
        $gw = new TapPaymentsGateway();
        $this->assertFalse($gw->verifyWebhook(self::BODY, [], ['webhook_secret' => self::SECRET]));
        $this->assertFalse($gw->verifyWebhook(self::BODY, ['X-Tap-Sign' => ''], ['webhook_secret' => self::SECRET]));
        $this->assertFalse($gw->verifyWebhook(self::BODY, ['X-Tap-Sign' => 'deadbeef'], ['webhook_secret' => self::SECRET]));
    }
}