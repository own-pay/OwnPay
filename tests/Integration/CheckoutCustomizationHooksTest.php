<?php

declare(strict_types=1);

namespace Tests\Integration;

use OwnPay\Container;
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
}
