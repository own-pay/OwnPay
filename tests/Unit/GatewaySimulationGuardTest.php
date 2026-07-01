<?php
declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/modules/gateways/cybersource/CybersourceGateway.php';

use OwnPay\Modules\Gateways\Cybersource\CybersourceGateway;

/**
 * Verifies that gateway sandbox "simulation" paths (which fake success without
 * contacting the provider) are disabled in production. A gateway accidentally
 * left in sandbox mode must never complete a transaction or fake a refund on a
 * live deployment.
 */
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
        $this->assertFalse($prod['success'], 'Simulated refund must fail closed in production');
        $this->assertArrayHasKey('error', $prod);
    }

    public function testRefundSimulationActiveInTesting(): void
    {
        $gw = new CybersourceGateway();

        // Non-production keeps the simulation for local/offline testing.
        $_ENV['APP_ENV'] = 'testing';
        $test = $gw->refund('gw_trx_1', '10.00', ['mode' => 'sandbox']);
        $this->assertTrue($test['success'], 'Refund simulation should remain available outside production');
    }

    public function testCallbackSimulationDisabledInProduction(): void
    {
        $gw = new CybersourceGateway();

        // verify() with an unreachable API + sandbox mode simulates-accept only
        // outside production. In production the simulated accept must not fire.
        $_ENV['APP_ENV'] = 'production';
        $res = $gw->verify(
            ['gateway_trx_id' => 'x', 'amount' => '10.00', 'reference' => 'OP-1'],
            ['mode' => 'sandbox']
        );
        $this->assertFalse($res['success'] ?? true, 'Simulated callback accept must not fire in production');
    }
}
