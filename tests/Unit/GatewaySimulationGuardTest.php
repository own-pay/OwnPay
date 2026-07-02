<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/modules/gateways/cybersource/CybersourceGateway.php';

use OwnPay\Modules\Gateways\Cybersource\CybersourceGateway;

final class GatewaySimulationGuardTest extends TestCase
{
    /** @var string|null */
    private $prevEnv;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prevEnv = $_ENV['APP_ENV'] ?? null;
    }

    protected function tearDown(): void
    {
        if ($this->prevEnv === null) {
            unset($_ENV['APP_ENV']);
        } else {
            $_ENV['APP_ENV'] = $this->prevEnv;
        }
        parent::tearDown();
    }

    public function testRefundSimulationDisabledInProduction(): void
    {
        $gw = new CybersourceGateway();

        $_ENV['APP_ENV'] = 'production';
        $prod = $gw->refund('gw_trx_1', '10.00', ['mode' => 'sandbox']);
        $this->assertFalse($prod['success']);
        $this->assertArrayHasKey('error', $prod);
    }

    public function testRefundSimulationActiveInTesting(): void
    {
        $gw = new CybersourceGateway();

        $_ENV['APP_ENV'] = 'testing';
        $test = $gw->refund('gw_trx_1', '10.00', ['mode' => 'sandbox']);
        $this->assertTrue($test['success']);
    }

    public function testCallbackSimulationDisabledInProduction(): void
    {
        $gw = new CybersourceGateway();

        $_ENV['APP_ENV'] = 'production';
        $res = $gw->verify(
            ['gateway_trx_id' => 'x', 'amount' => '10.00', 'reference' => 'OP-1'],
            ['mode' => 'sandbox']
        );
        $this->assertFalse($res['success'] ?? true);
    }
}
