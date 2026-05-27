<?php
declare(strict_types=1);

namespace Tests\Integration;

use OwnPay\Core\Database;
use OwnPay\Repository\GatewayConfigRepository;
use OwnPay\Repository\TransactionRepository;
use OwnPay\Repository\SmsParsedRepository;
use OwnPay\Cron\SmsVerificationJob;
use Tests\Integration\IntegrationTestCase;

/**
 * SovereignArchitectureTest
 *
 * Verification suite testing the transition from SaaS isolation to Sovereign Single-Owner model.
 * Verifies global configuration fallback/inheritance and dynamic cross-tenant SMS companion device matching.
 *
 * @group Integration
 */
final class SovereignArchitectureTest extends IntegrationTestCase
{
    private Database $db;
    private GatewayConfigRepository $gwConfigRepo;
    private TransactionRepository $txRepo;
    private SmsParsedRepository $smsRepo;
    private SmsVerificationJob $job;

    private array $testMerchantIds = [];
    private string $testGatewaySlug = 'stripe';
    private int $globalGatewayId = 1; // Built-in Stripe ID in standard seeds

    private int $merchantId1 = 99991;
    private int $merchantId2 = 99992;

    protected function setUp(): void
    {
        parent::setUp();

        if (!static::$dbAvailable) {
            $this->markTestSkipped('Database not available');
        }

        $this->db = Database::getInstance();

        // Instantiate PSR-11 Container and load bootstrap configs
        $c = new \OwnPay\Container();
        $bootstrap = require dirname(__DIR__, 2) . '/config/services.php';
        $bootstrap($c);

        // Inject active test database override
        $c->instance(Database::class, $this->db);

        $this->gwConfigRepo = $c->get(GatewayConfigRepository::class);
        $this->txRepo = $c->get(TransactionRepository::class);
        $this->smsRepo = $c->get(SmsParsedRepository::class);
        $this->job = $c->get(SmsVerificationJob::class);

        $this->cleanupData();
        $this->setupMerchants();
    }

    protected function tearDown(): void
    {
        $this->cleanupData();
        parent::tearDown();
    }

    private function cleanupData(): void
    {
        $this->db->execute("DELETE FROM op_sms_parsed WHERE merchant_id IN (99991, 99992)");
        $this->db->execute("DELETE FROM op_ledger_transactions WHERE merchant_id IN (99991, 99992)");
        $this->db->execute("DELETE FROM op_ledger_accounts WHERE merchant_id IN (99991, 99992)");
        $this->db->execute("DELETE FROM op_transactions WHERE merchant_id IN (99991, 99992)");
        $this->db->execute("DELETE FROM op_gateway_configs WHERE gateway_id = :gw_id", ['gw_id' => $this->globalGatewayId]);
        $this->db->execute("DELETE FROM op_paired_devices WHERE device_id IN ('global-shared-device-123', 'shared-device-uuid-999')");
        $this->db->execute("DELETE FROM op_device_pairing_tokens WHERE merchant_id IN (99991, 99992) OR merchant_id IS NULL");

        foreach ($this->testMerchantIds as $mid) {
            $this->db->execute("DELETE FROM op_merchants WHERE id = :id", ['id' => $mid]);
        }
        $this->testMerchantIds = [];
    }

    private function setupMerchants(): void
    {
        // Ensure Stripe Gateway exists in op_gateways
        $gw = $this->db->fetchOne("SELECT * FROM op_gateways WHERE slug = :slug LIMIT 1", ['slug' => $this->testGatewaySlug]);
        if ($gw === null) {
            $this->db->execute(
                "INSERT INTO op_gateways (id, slug, name, type, status)
                 VALUES (:id, :slug, 'Stripe Gateway', 'api', 'active')",
                ['id' => $this->globalGatewayId, 'slug' => $this->testGatewaySlug]
            );
        } else {
            $this->globalGatewayId = (int)$gw['id'];
        }

        // Ensure Brand 1 exists
        $m1 = $this->db->fetchOne("SELECT * FROM op_merchants WHERE id = :mid LIMIT 1", ['mid' => $this->merchantId1]);
        if ($m1 === null) {
            $this->db->execute(
                "INSERT INTO op_merchants (id, uuid, name, slug, email, status, settings)
                 VALUES (:mid, 'sovereign-merchant-uuid-1', 'Sovereign Brand 1', 'sov-1', 'sov1@test.com', 'active', '{}')",
                ['mid' => $this->merchantId1]
            );
        }
        $this->testMerchantIds[] = $this->merchantId1;

        // Ensure Brand 2 exists
        $m2 = $this->db->fetchOne("SELECT * FROM op_merchants WHERE id = :mid LIMIT 1", ['mid' => $this->merchantId2]);
        if ($m2 === null) {
            $this->db->execute(
                "INSERT INTO op_merchants (id, uuid, name, slug, email, status, settings)
                 VALUES (:mid, 'sovereign-merchant-uuid-2', 'Sovereign Brand 2', 'sov-2', 'sov2@test.com', 'active', '{}')",
                ['mid' => $this->merchantId2]
            );
        }
        $this->testMerchantIds[] = $this->merchantId2;
    }

    /**
     * Test global configuration fallback and brand-scoped overriding inheritance.
     */
    public function testGatewayConfigurationResolverInheritance(): void
    {
        // 1. Initially, no config exists. findCredentialsBySlug should return null.
        $creds = $this->gwConfigRepo->forTenant($this->merchantId1)->findCredentialsBySlug($this->testGatewaySlug);
        $this->assertNull($creds);

        // 2. Insert global config (merchant_id = NULL)
        $this->db->execute(
            "INSERT INTO op_gateway_configs (merchant_id, gateway_id, credentials_enc, settings, mode, status)
             VALUES (NULL, :gw, 'ENCRYPTED_GLOBAL_KEYS', '{}', 'sandbox', 'active')",
            ['gw' => $this->globalGatewayId]
        );

        // 3. Brand 1 and Brand 2 should now BOTH inherit global configurations
        $creds1 = $this->gwConfigRepo->forTenant($this->merchantId1)->findCredentialsBySlug($this->testGatewaySlug);
        $this->assertSame('ENCRYPTED_GLOBAL_KEYS', $creds1);

        $creds2 = $this->gwConfigRepo->forTenant($this->merchantId2)->findCredentialsBySlug($this->testGatewaySlug);
        $this->assertSame('ENCRYPTED_GLOBAL_KEYS', $creds2);

        // 4. Overrides: Insert brand-scoped override config for Brand 1
        $this->db->execute(
            "INSERT INTO op_gateway_configs (merchant_id, gateway_id, credentials_enc, settings, mode, status)
             VALUES (:mid, :gw, 'ENCRYPTED_BRAND1_KEYS', '{}', 'sandbox', 'active')",
            ['mid' => $this->merchantId1, 'gw' => $this->globalGatewayId]
        );

        // 5. Brand 1 should resolve its brand-scoped keys, while Brand 2 still inherits the global defaults
        $creds1Overridden = $this->gwConfigRepo->forTenant($this->merchantId1)->findCredentialsBySlug($this->testGatewaySlug);
        $this->assertSame('ENCRYPTED_BRAND1_KEYS', $creds1Overridden);

        $creds2Remaining = $this->gwConfigRepo->forTenant($this->merchantId2)->findCredentialsBySlug($this->testGatewaySlug);
        $this->assertSame('ENCRYPTED_GLOBAL_KEYS', $creds2Remaining);
    }

    /**
     * Test centralized global shared device pool transaction routing.
     * Verifies that an SMS received on a device registered under Brand 1 is dynamically
     * matched to a pending transaction belonging to Brand 2, automatically aligning their contexts.
     */
    public function testGlobalSharedDeviceSmsRoutingAndContextAlignment(): void
    {
        // 1. Seed a pending transaction in Brand 2
        $trxId = 'TXN_SOV_1001';
        $this->db->execute(
            "INSERT INTO op_transactions (merchant_id, uuid, trx_id, amount, fee, net_amount, currency, gateway_slug, method, status)
             VALUES (:mid, 'txn-uuid-sov-1001', :trx, 2500.00, 0.00, 2500.00, 'BDT', 'bKash', 'sms', 'pending')",
            ['mid' => $this->merchantId2, 'trx' => $trxId]
        );

        // 2. Insert a parsed SMS record registered under Brand 1 (representing a single physical phone capturing SMS)
        $this->db->execute(
            "INSERT INTO op_sms_parsed (merchant_id, device_id, sender, body, amount, trx_id, gateway_slug, parser_type, match_status, received_at)
             VALUES (:mid, 'shared-device-uuid-999', 'bKash', 'You received money...', 2500.00, :trx, 'bKash', 'regex', 'pending', NOW())",
            ['mid' => $this->merchantId1, 'trx' => $trxId]
        );

        // Verify pre-run conditions
        $smsPre = $this->db->fetchOne("SELECT * FROM op_sms_parsed WHERE trx_id = :trx", ['trx' => $trxId]);
        $this->assertSame($this->merchantId1, (int)$smsPre['merchant_id']);
        $this->assertSame('pending', $smsPre['match_status']);

        // 3. Execute the SMS verification job (which matches dynamically across the global pool)
        $res = $this->job->run();
        $this->assertSame(1, $res['matched']);
        $this->assertSame(0, $res['failed']);

        // 4. Verify post-run assertions:
        // A. Transaction for Brand 2 is now successfully completed
        $txPost = $this->db->fetchOne("SELECT * FROM op_transactions WHERE trx_id = :trx", ['trx' => $trxId]);
        $this->assertSame('completed', $txPost['status']);

        // B. Parsed SMS merchant_id is automatically aligned to Brand 2 context
        $smsPost = $this->db->fetchOne("SELECT * FROM op_sms_parsed WHERE trx_id = :trx", ['trx' => $trxId]);
        $this->assertSame($this->merchantId2, (int)$smsPost['merchant_id']);
        $this->assertSame('matched', $smsPost['match_status']);
        $this->assertSame($txPost['id'], $smsPost['transaction_id']);

        // C. Double-entry ledger accounts and entries were posted under Brand 2
        $ledgerTrans = $this->db->fetchOne(
            "SELECT * FROM op_ledger_transactions WHERE merchant_id = :mid AND reference_id = :tx_id",
            ['mid' => $this->merchantId2, 'tx_id' => $txPost['id']]
        );
        $this->assertNotNull($ledgerTrans);
    }

    /**
     * Test globally paired companion device registration and verification.
     * Verifies that a device registered with merchant_id = NULL (global shared device)
     * is correctly found when queried under a brand context (fallback lookup).
     */
    public function testGlobalDeviceRegistrationAndFallbackLookup(): void
    {
        // 1. Insert a global device configuration (merchant_id = NULL)
        $deviceId = 'global-shared-device-123';
        $this->db->execute(
            "INSERT INTO op_paired_devices (merchant_id, device_id, device_name, jwt_fingerprint, status, paired_at)
             VALUES (NULL, :did, 'System Global Phone', 'GLOBAL_FP_HASH', 'active', NOW())",
            ['did' => $deviceId]
        );

        // 2. Querying globally (without tenant context) should find it
        $deviceGlobal = $this->gwConfigRepo->getDatabase()->fetchOne(
            "SELECT * FROM op_paired_devices WHERE device_id = :did",
            ['did' => $deviceId]
        );
        $this->assertNotNull($deviceGlobal);
        $this->assertNull($deviceGlobal['merchant_id']);

        // 3. Querying through the PairedDeviceRepository under Brand 1 context should find it via fallback
        $deviceBrand1 = $this->gwConfigRepo->getDatabase()->fetchOne(
            "SELECT * FROM op_paired_devices WHERE device_id = :did",
            ['did' => $deviceId]
        );
        $this->assertNotNull($deviceBrand1);

        // Check finding it via repository method scoped to Brand 1 (requires fallback lookup)
        // Instantiate the repository cleanly
        $c = new \OwnPay\Container();
        $bootstrap = require dirname(__DIR__, 2) . '/config/services.php';
        $bootstrap($c);
        $c->instance(Database::class, $this->db);
        $deviceRepo = $c->get(\OwnPay\Repository\PairedDeviceRepository::class);

        $resolvedDevice = $deviceRepo->forTenant($this->merchantId1)->findByDeviceId($deviceId);
        $this->assertNotNull($resolvedDevice);
        $this->assertSame($deviceId, $resolvedDevice['device_id']);
        $this->assertNull($resolvedDevice['merchant_id']);
    }
}
