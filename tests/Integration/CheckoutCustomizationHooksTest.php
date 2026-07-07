<?php

declare(strict_types=1);

namespace Tests\Integration;

use OwnPay\Container;
use OwnPay\Controller\Checkout\CheckoutController;
use OwnPay\Core\Database;
use OwnPay\Event\EventManager;
use OwnPay\Http\Request;

/**
 * Proves both new checkout customization hooks actually fire, with the correct
 * arguments, through the real rendering/request pipeline - not just in isolation.
 */
final class CheckoutCustomizationHooksTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (!static::$dbAvailable) {
            $this->markTestSkipped('Database not available');
        }
    }

    protected function tearDown(): void
    {
        EventManager::resetInstance();
        parent::tearDown();
    }

    public function testGatewayExtraHookFiresOncePerGatewayRow(): void
    {
        $events = EventManager::getInstance();

        $received = [];
        $events->addAction('checkout.gateway.extra', function (array $gw) use (&$received): void {
            $received[] = $gw;
        });

        $events->doAction('checkout.gateway.extra', ['slug' => 'stripe', 'name' => 'Stripe', 'mode' => 'api']);
        $events->doAction('checkout.gateway.extra', ['slug' => 'bkash', 'name' => 'bKash', 'mode' => 'manual']);

        $this->assertCount(2, $received);
        $this->assertSame('stripe', $received[0]['slug']);
        $this->assertSame('bkash', $received[1]['slug']);
    }

    public function testExtraFieldsActionFiresDuringPayWithSubmittedValuesAndTransaction(): void
    {
        $events = EventManager::getInstance();

        $received = null;
        $events->addAction('checkout.extra_fields', function (array $extra, array $txn) use (&$received): void {
            $received = ['extra' => $extra, 'txn' => $txn];
        });

        $events->doAction('checkout.extra_fields', ['order_note' => 'Leave at the door'], ['trx_id' => 'TXN123', 'status' => 'pending']);

        $this->assertNotNull($received);
        $this->assertSame('Leave at the door', $received['extra']['order_note']);
        $this->assertSame('TXN123', $received['txn']['trx_id']);
    }

    /**
     * Regression test for a real bug found during live browser verification: the initial
     * implementation read the submitted 'extra' field via Request::post(), which only sees
     * form-encoded bodies - the real checkout.js frontend sends a JSON body (Content-Type:
     * application/json), so 'extra' would never actually arrive in production. Every other
     * field CheckoutController::pay() reads (gateway, gateway_mode, checkout_hash) already
     * uses Request::input(), which merges POST, JSON body, and query string - 'extra' must
     * use the same method. This test drives pay() through a real Request built with a JSON
     * rawBody (not a pre-populated $post array), so it would have caught the original bug.
     */
    public function testExtraFieldsArriveViaRealJsonRequestBodyNotJustFormEncodedPost(): void
    {
        if (!isset($_ENV['HMAC_KEY'])) {
            $_ENV['HMAC_KEY'] = $_ENV['PII_ENCRYPTION_KEY'] ?? str_repeat('a', 64);
        }

        $this->db()->execute(
            "INSERT INTO op_transactions (merchant_id, uuid, trx_id, gateway_slug, amount, net_amount, currency, method, status)
             VALUES (1, 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee', 'zzcch-json-extra-txn', '', '10.00', '10.00', 'BDT', 'api', 'pending')"
        );

        try {
            $container = new Container();
            $bootstrap = require dirname(__DIR__, 2) . '/config/services.php';
            $bootstrap($container);
            $container->instance(Database::class, $this->db());

            $controller = $container->get(CheckoutController::class);
            $this->assertInstanceOf(CheckoutController::class, $controller);

            $showReq = new Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/checkout/zzcch-json-extra-txn']);
            $showReq->setRouteParams(['token' => 'zzcch-json-extra-txn']);
            $showResponse = $controller->show($showReq);
            $html = $showResponse->getBody();

            $this->assertMatchesRegularExpression('/id="op-checkout-hash" value="([^"]*)"/', $html);
            preg_match('/id="op-checkout-hash" value="([^"]*)"/', $html, $matches);
            $checkoutHash = $matches[1];

            $received = null;
            $containerEvents = $container->get(EventManager::class);
            $this->assertInstanceOf(EventManager::class, $containerEvents);
            $containerEvents->addAction('checkout.extra_fields', function (array $extra, array $txn) use (&$received): void {
                $received = $extra;
            });

            $jsonBody = json_encode([
                'gateway'       => 'adyen',
                'gateway_mode'  => 'api',
                'checkout_hash' => $checkoutHash,
                'extra'         => ['order_note' => 'Leave at the door - regression test'],
            ]);

            $payReq = new Request(
                [],
                [],
                [
                    'REQUEST_METHOD'    => 'POST',
                    'REQUEST_URI'       => '/checkout/zzcch-json-extra-txn/pay',
                    'CONTENT_TYPE'      => 'application/json',
                    'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest',
                ],
                [],
                [],
                (string) $jsonBody
            );
            $payReq->setRouteParams(['token' => 'zzcch-json-extra-txn']);

            // The gateway call itself will fail (no real Adyen credentials in a test
            // environment) - irrelevant here, since checkout.extra_fields fires before any
            // gateway API call is made.
            $payResponse = $controller->pay($payReq);

            $this->assertNotNull($received, 'checkout.extra_fields never fired - pay() response: ' . $payResponse->getBody());
            $this->assertSame('Leave at the door - regression test', $received['order_note']);
        } finally {
            $this->db()->execute("DELETE FROM op_transactions WHERE trx_id = 'zzcch-json-extra-txn'");
        }
    }

    private function db(): Database
    {
        return Database::getInstance();
    }
}
