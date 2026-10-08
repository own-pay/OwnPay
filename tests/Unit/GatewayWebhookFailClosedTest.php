<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/modules/gateways/biller-genie/BillerGenieGateway.php';
require_once dirname(__DIR__, 2) . '/modules/gateways/blik/BlikGateway.php';
require_once dirname(__DIR__, 2) . '/modules/gateways/bluesnap/BlueSnapGateway.php';
require_once dirname(__DIR__, 2) . '/modules/gateways/chase-paymentech/ChasePaymentechGateway.php';
require_once dirname(__DIR__, 2) . '/modules/gateways/cybersource/CybersourceGateway.php';
require_once dirname(__DIR__, 2) . '/modules/gateways/dlocal/DLocalGateway.php';
require_once dirname(__DIR__, 2) . '/modules/gateways/ebanx/EbanxGateway.php';
require_once dirname(__DIR__, 2) . '/modules/gateways/elavon/ElavonGateway.php';
require_once dirname(__DIR__, 2) . '/modules/gateways/fastspring/FastSpringGateway.php';
require_once dirname(__DIR__, 2) . '/modules/gateways/fattmerchant/FattmerchantGateway.php';
require_once dirname(__DIR__, 2) . '/modules/gateways/first-data/FirstDataGateway.php';
require_once dirname(__DIR__, 2) . '/modules/gateways/fiserv/FiservGateway.php';
require_once dirname(__DIR__, 2) . '/modules/gateways/giropay/GiropayGateway.php';
require_once dirname(__DIR__, 2) . '/modules/gateways/global-payments/GlobalPaymentsGateway.php';
require_once dirname(__DIR__, 2) . '/modules/gateways/heartland/HeartlandGateway.php';
require_once dirname(__DIR__, 2) . '/modules/gateways/helcim/HelcimGateway.php';
require_once dirname(__DIR__, 2) . '/modules/gateways/kushki/KushkiGateway.php';
require_once dirname(__DIR__, 2) . '/modules/gateways/moneris/MonerisGateway.php';
require_once dirname(__DIR__, 2) . '/modules/gateways/neteller/NetellerGateway.php';
require_once dirname(__DIR__, 2) . '/modules/gateways/nmi/NmiGateway.php';
require_once dirname(__DIR__, 2) . '/modules/gateways/payline-data/PaylineDataGateway.php';
require_once dirname(__DIR__, 2) . '/modules/gateways/payment-depot/PaymentDepotGateway.php';
require_once dirname(__DIR__, 2) . '/modules/gateways/payoneer/PayoneerGateway.php';
require_once dirname(__DIR__, 2) . '/modules/gateways/paytabs/PayTabsGateway.php';
require_once dirname(__DIR__, 2) . '/modules/gateways/paytrace/PaytraceGateway.php';
require_once dirname(__DIR__, 2) . '/modules/gateways/przelewy24/Przelewy24Gateway.php';
require_once dirname(__DIR__, 2) . '/modules/gateways/rapyd/RapydGateway.php';
require_once dirname(__DIR__, 2) . '/modules/gateways/shift4/Shift4Gateway.php';
require_once dirname(__DIR__, 2) . '/modules/gateways/skrill/SkrillGateway.php';
require_once dirname(__DIR__, 2) . '/modules/gateways/sofort/SofortGateway.php';
require_once dirname(__DIR__, 2) . '/modules/gateways/stax/StaxGateway.php';
require_once dirname(__DIR__, 2) . '/modules/gateways/trustcommerce/TrustCommerceGateway.php';
require_once dirname(__DIR__, 2) . '/modules/gateways/tsys/TsysGateway.php';
require_once dirname(__DIR__, 2) . '/modules/gateways/worldpay/WorldpayGateway.php';
require_once dirname(__DIR__, 2) . '/modules/gateways/braintree/BraintreeGateway.php';
require_once dirname(__DIR__, 2) . '/modules/gateways/btcpay/BTCPayGateway.php';
require_once dirname(__DIR__, 2) . '/modules/gateways/checkout-com/CheckoutComGateway.php';

use OwnPay\Modules\Gateways\BillerGenie\BillerGenieGateway;
use OwnPay\Modules\Gateways\Blik\BlikGateway;
use OwnPay\Modules\Gateways\BlueSnap\BlueSnapGateway;
use OwnPay\Modules\Gateways\Braintree\BraintreeGateway;
use OwnPay\Modules\Gateways\BTCPay\BTCPayGateway;
use OwnPay\Modules\Gateways\ChasePaymentech\ChasePaymentechGateway;
use OwnPay\Modules\Gateways\CheckoutCom\CheckoutComGateway;
use OwnPay\Modules\Gateways\Cybersource\CybersourceGateway;
use OwnPay\Modules\Gateways\DLocal\DLocalGateway;
use OwnPay\Modules\Gateways\Ebanx\EbanxGateway;
use OwnPay\Modules\Gateways\Elavon\ElavonGateway;
use OwnPay\Modules\Gateways\FastSpring\FastSpringGateway;
use OwnPay\Modules\Gateways\Fattmerchant\FattmerchantGateway;
use OwnPay\Modules\Gateways\FirstData\FirstDataGateway;
use OwnPay\Modules\Gateways\Fiserv\FiservGateway;
use OwnPay\Modules\Gateways\Giropay\GiropayGateway;
use OwnPay\Modules\Gateways\GlobalPayments\GlobalPaymentsGateway;
use OwnPay\Modules\Gateways\Heartland\HeartlandGateway;
use OwnPay\Modules\Gateways\Helcim\HelcimGateway;
use OwnPay\Modules\Gateways\Kushki\KushkiGateway;
use OwnPay\Modules\Gateways\Moneris\MonerisGateway;
use OwnPay\Modules\Gateways\Neteller\NetellerGateway;
use OwnPay\Modules\Gateways\Nmi\NmiGateway;
use OwnPay\Modules\Gateways\PaylineData\PaylineDataGateway;
use OwnPay\Modules\Gateways\PaymentDepot\PaymentDepotGateway;
use OwnPay\Modules\Gateways\Payoneer\PayoneerGateway;
use OwnPay\Modules\Gateways\PayTabs\PayTabsGateway;
use OwnPay\Modules\Gateways\Paytrace\PaytraceGateway;
use OwnPay\Modules\Gateways\Przelewy24\Przelewy24Gateway;
use OwnPay\Modules\Gateways\Rapyd\RapydGateway;
use OwnPay\Modules\Gateways\Shift4\Shift4Gateway;
use OwnPay\Modules\Gateways\Skrill\SkrillGateway;
use OwnPay\Modules\Gateways\Sofort\SofortGateway;
use OwnPay\Modules\Gateways\Stax\StaxGateway;
use OwnPay\Modules\Gateways\TrustCommerce\TrustCommerceGateway;
use OwnPay\Modules\Gateways\Tsys\TsysGateway;
use OwnPay\Modules\Gateways\Worldpay\WorldpayGateway;

/**
 * Pins the fail-closed contract for every bundled adapter that has no real
 * signature scheme.
 *
 * `POST /webhook/{gateway}` is unauthenticated and its only gate is the
 * adapter's `verifyWebhook()`, so an adapter that accepts a caller's own
 * signature header lets anyone mark a pending transaction paid (GHSA-49jc-pgw7-64hg).
 * Each stub therefore rejects both an empty request and one carrying the provider's
 * signature header, and each adapter is listed here by name so that reintroducing
 * an accept branch fails the suite.
 */
final class GatewayWebhookFailClosedTest extends TestCase
{
    /**
     * A signature value a caller can invent - no secret is ever needed to produce it.
     */
    private const FORGED_SIGNATURE = 'deadbeefdeadbeefdeadbeefdeadbeefdeadbeefdeadbeefdeadbeefdeadbeef';

    /**
     * A notification body shaped like a completing callback.
     */
    private const BODY = '{"reference":"OP-1","status":"paid","amount":"100.00"}';

    /**
     * Every fail-closed adapter, keyed by gateway slug, with the header a forged
     * caller would send. Adapters that read no header at all are given a generic
     * pair so their acceptance of any provider header stays pinned too.
     *
     * @return array<string, array{class-string, array<string, string>}>
     */
    public static function failClosedAdapters(): array
    {
        return [
            'biller-genie' => [BillerGenieGateway::class, ['X-Biller-Signature' => self::FORGED_SIGNATURE]],
            'blik' => [BlikGateway::class, ['X-BLIK-Signature' => self::FORGED_SIGNATURE]],
            'bluesnap' => [BlueSnapGateway::class, ['Authorization' => self::FORGED_SIGNATURE]],
            'chase-paymentech' => [ChasePaymentechGateway::class, ['X-Chase-Signature' => self::FORGED_SIGNATURE]],
            'cybersource' => [CybersourceGateway::class, ['X-Signature' => self::FORGED_SIGNATURE]],
            'dlocal' => [DLocalGateway::class, ['X-DLocal-Signature' => self::FORGED_SIGNATURE]],
            'ebanx' => [EbanxGateway::class, ['X-Ebanx-Signature' => self::FORGED_SIGNATURE]],
            'elavon' => [ElavonGateway::class, ['X-Elavon-Signature' => self::FORGED_SIGNATURE]],
            'fastspring' => [FastSpringGateway::class, ['X-FS-Signature' => self::FORGED_SIGNATURE]],
            'fattmerchant' => [FattmerchantGateway::class, ['X-Fatt-Signature' => self::FORGED_SIGNATURE]],
            'first-data' => [FirstDataGateway::class, ['X-Payeezy-Signature' => self::FORGED_SIGNATURE]],
            'fiserv' => [FiservGateway::class, ['X-Fiserv-Signature' => self::FORGED_SIGNATURE]],
            'giropay' => [GiropayGateway::class, ['X-Giropay-Signature' => self::FORGED_SIGNATURE]],
            'global-payments' => [GlobalPaymentsGateway::class, ['X-GP-Signature' => self::FORGED_SIGNATURE]],
            'heartland' => [HeartlandGateway::class, ['X-Heartland-Signature' => self::FORGED_SIGNATURE]],
            'helcim' => [HelcimGateway::class, ['X-Helcim-Signature' => self::FORGED_SIGNATURE]],
            'kushki' => [KushkiGateway::class, ['X-Kushki-Signature' => self::FORGED_SIGNATURE]],
            'moneris' => [MonerisGateway::class, ['X-Moneris-Signature' => self::FORGED_SIGNATURE]],
            'neteller' => [NetellerGateway::class, ['Authorization' => self::FORGED_SIGNATURE]],
            'nmi' => [NmiGateway::class, ['X-NMI-Signature' => self::FORGED_SIGNATURE]],
            'payline-data' => [PaylineDataGateway::class, ['X-Payline-Signature' => self::FORGED_SIGNATURE]],
            'payment-depot' => [PaymentDepotGateway::class, ['X-Depot-Signature' => self::FORGED_SIGNATURE]],
            'payoneer' => [PayoneerGateway::class, ['X-Payoneer-Signature' => self::FORGED_SIGNATURE]],
            'paytabs' => [PayTabsGateway::class, ['signature' => self::FORGED_SIGNATURE]],
            'paytrace' => [PaytraceGateway::class, ['X-Paytrace-Signature' => self::FORGED_SIGNATURE]],
            'przelewy24' => [Przelewy24Gateway::class, ['X-P24-Signature' => self::FORGED_SIGNATURE]],
            'rapyd' => [RapydGateway::class, ['signature' => self::FORGED_SIGNATURE]],
            'shift4' => [Shift4Gateway::class, ['Shift4-Signature' => self::FORGED_SIGNATURE]],
            'skrill' => [SkrillGateway::class, ['X-Skrill-Signature' => self::FORGED_SIGNATURE]],
            'sofort' => [SofortGateway::class, ['X-Sofort-Signature' => self::FORGED_SIGNATURE]],
            'stax' => [StaxGateway::class, ['X-Stax-Signature' => self::FORGED_SIGNATURE]],
            'trustcommerce' => [TrustCommerceGateway::class, ['X-TC-Signature' => self::FORGED_SIGNATURE]],
            'tsys' => [TsysGateway::class, ['X-TSYS-Signature' => self::FORGED_SIGNATURE]],
            'worldpay' => [WorldpayGateway::class, ['X-Worldpay-Signature' => self::FORGED_SIGNATURE]],

            // Real signature schemes that used to fail open when no secret was
            // configured, or that accepted unconditionally.
            'braintree' => [BraintreeGateway::class, ['bt-signature' => self::FORGED_SIGNATURE]],
            'btcpay' => [BTCPayGateway::class, ['Btcpay-Sig' => self::FORGED_SIGNATURE]],
            'checkout-com' => [CheckoutComGateway::class, ['Cko-Signature' => self::FORGED_SIGNATURE]],
        ];
    }

    /**
     * A stub adapter has no way to tell its own provider's notification from a
     * forged one, so it must reject the request whether or not the caller supplies
     * the header the old accept branch keyed on.
     *
     * @param class-string $adapterClass Gateway adapter class under test.
     * @param array<string, string> $forgedHeaders Signature headers a caller controls.
     * @return void
     */
    #[DataProvider('failClosedAdapters')]
    public function testStubAdapterRejectsUnauthenticatedNotification(string $adapterClass, array $forgedHeaders): void
    {
        /** @var \OwnPay\Gateway\GatewayAdapterInterface $adapter */
        $adapter = new $adapterClass();

        $this->assertFalse(
            $adapter->verifyWebhook(self::BODY, [], []),
            $adapterClass . ' must reject a notification carrying no signature header'
        );
        $this->assertFalse(
            $adapter->verifyWebhook(self::BODY, $forgedHeaders, []),
            $adapterClass . ' must reject a notification signed with a caller-chosen header value'
        );
    }

    /**
     * checkout-com does implement HMAC verification, so closing its "no secret
     * configured" branch must not have closed the real one.
     *
     * @return void
     */
    public function testCheckoutComStillVerifiesConfiguredSecret(): void
    {
        $adapter = new CheckoutComGateway();
        $credentials = ['webhook_secret' => 'cko_webhook_secret'];

        $valid = hash_hmac('sha256', self::BODY, 'cko_webhook_secret');

        $this->assertTrue($adapter->verifyWebhook(self::BODY, ['Cko-Signature' => $valid], $credentials));
        $this->assertTrue($adapter->verifyWebhook(self::BODY, ['cko-signature' => strtoupper($valid)], $credentials));
        $this->assertFalse($adapter->verifyWebhook(self::BODY, ['Cko-Signature' => self::FORGED_SIGNATURE], $credentials));
    }

    /**
     * btcpay does implement HMAC verification, so closing its "no secret configured"
     * branch must not have closed the real one.
     *
     * @return void
     */
    public function testBtcpayStillVerifiesConfiguredSecret(): void
    {
        $adapter = new BTCPayGateway();
        $credentials = ['webhook_secret' => 'btcpay_webhook_secret'];

        $valid = 'sha256=' . hash_hmac('sha256', self::BODY, 'btcpay_webhook_secret');

        $this->assertTrue($adapter->verifyWebhook(self::BODY, ['Btcpay-Sig' => $valid], $credentials));
        $this->assertFalse($adapter->verifyWebhook(self::BODY, ['Btcpay-Sig' => self::FORGED_SIGNATURE], $credentials));
    }
}
