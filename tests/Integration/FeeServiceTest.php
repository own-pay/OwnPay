<?php

declare(strict_types=1);

namespace Tests\Integration;

use OwnPay\Core\Database;
use OwnPay\Service\Payment\FeeService;
use OwnPay\Repository\SettingsRepository;
use OwnPay\Repository\FeeRuleRepository;
use OwnPay\Event\EventManager;

class FeeServiceTest extends IntegrationTestCase
{
    private FeeService $feeService;
    private Database $db;

    protected function setUp(): void
    {
        parent::setUp();

        $this->db = Database::getInstance();
        if (static::$dbAvailable) {
            $this->db->execute("DELETE FROM op_fee_rules");
        }

        $events = new EventManager();
        $settings = new SettingsRepository($this->db);
        $feeRuleRepo = new FeeRuleRepository($this->db);
        $this->feeService = new FeeService($events, $settings, $feeRuleRepo);
    }

    protected function tearDown(): void
    {
        if (static::$dbAvailable) {
            $this->db->execute("DELETE FROM op_fee_rules");
        }
        parent::tearDown();
    }

    public function testFallbackToSettings(): void
    {
        $fee = $this->feeService->calculate('1000.00', 'BDT', 'stripe', 1);
        $this->assertSame('25.00', $fee);
    }

    public function testGlobalPercentageRule(): void
    {
        $this->db->execute(
            "INSERT INTO op_fee_rules (merchant_id, gateway_slug, type, value, currency, status)
             VALUES (NULL, NULL, 'percentage', 3.5000, 'BDT', 'active')"
        );

        $fee = $this->feeService->calculate('1000.00', 'BDT', 'stripe', 1);
        $this->assertSame('35.00', $fee);
    }

    public function testFlatRuleWithMinCap(): void
    {
        $this->db->execute(
            "INSERT INTO op_fee_rules (merchant_id, gateway_slug, type, value, min_fee, max_fee, currency, status)
             VALUES (1, 'stripe', 'flat', 10.0000, 15.00, 50.00, 'BDT', 'active')"
        );

        $fee = $this->feeService->calculate('1000.00', 'BDT', 'stripe', 1);
        $this->assertSame('15.00', $fee);
    }

    public function testPercentageRuleWithMaxCap(): void
    {
        $this->db->execute(
            "INSERT INTO op_fee_rules (merchant_id, gateway_slug, type, value, min_fee, max_fee, currency, status)
             VALUES (1, 'stripe', 'percentage', 5.0000, 5.00, 30.00, 'BDT', 'active')"
        );

        $fee = $this->feeService->calculate('1000.00', 'BDT', 'stripe', 1);
        $this->assertSame('30.00', $fee);
    }

    public function testSpecificityPriorityLookup(): void
    {
        $this->db->execute("INSERT INTO op_fee_rules (merchant_id, gateway_slug, type, value, currency, status) VALUES (NULL, NULL, 'percentage', 1.0000, 'BDT', 'active')");
        $this->db->execute("INSERT INTO op_fee_rules (merchant_id, gateway_slug, type, value, currency, status) VALUES (NULL, 'stripe', 'percentage', 2.0000, 'BDT', 'active')");
        $this->db->execute("INSERT INTO op_fee_rules (merchant_id, gateway_slug, type, value, currency, status) VALUES (1, NULL, 'percentage', 3.0000, 'BDT', 'active')");
        $this->db->execute("INSERT INTO op_fee_rules (merchant_id, gateway_slug, type, value, currency, status) VALUES (1, 'stripe', 'percentage', 4.0000, 'BDT', 'active')");

        $feeA = $this->feeService->calculate('1000.00', 'BDT', 'paypal', 2);
        $this->assertSame('10.00', $feeA);

        $feeB = $this->feeService->calculate('1000.00', 'BDT', 'stripe', 2);
        $this->assertSame('20.00', $feeB);

        $feeC = $this->feeService->calculate('1000.00', 'BDT', 'paypal', 1);
        $this->assertSame('30.00', $feeC);

        $feeD = $this->feeService->calculate('1000.00', 'BDT', 'stripe', 1);
        $this->assertSame('40.00', $feeD);
    }

    public function testTieredRule(): void
    {
        $tiersJson = json_encode([
            ['limit' => 500, 'type' => 'flat', 'value' => 5.00],
            ['limit' => 1000, 'type' => 'flat', 'value' => 10.00],
            ['limit' => null, 'type' => 'percentage', 'value' => 2.00]
        ]);

        $this->db->execute(
            "INSERT INTO op_fee_rules (merchant_id, gateway_slug, type, value, currency, tiers, status)
             VALUES (1, 'stripe', 'tiered', 0.0000, 'BDT', :tiers, 'active')",
            ['tiers' => $tiersJson]
        );

        $fee1 = $this->feeService->calculate('400.00', 'BDT', 'stripe', 1);
        $this->assertSame('5.00', $fee1);

        $fee2 = $this->feeService->calculate('800.00', 'BDT', 'stripe', 1);
        $this->assertSame('10.00', $fee2);

        $fee3 = $this->feeService->calculate('2000.00', 'BDT', 'stripe', 1);
        $this->assertSame('40.00', $fee3);
    }
}
