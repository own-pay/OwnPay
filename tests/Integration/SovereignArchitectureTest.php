<?php

declare(strict_types=1);

namespace Tests\Integration;

use OwnPay\Container;
use OwnPay\Core\Database;
use OwnPay\Repository\GatewayConfigRepository;
use OwnPay\Repository\TransactionRepository;
use OwnPay\Repository\SmsParsedRepository;
use OwnPay\Cron\SmsVerificationJob;

final class SovereignArchitectureTest extends IntegrationTestCase
{
    private Database $db;
    private GatewayConfigRepository $gwConfigRepo;
    private TransactionRepository $txRepo;
    private SmsParsedRepository $smsRepo;
    private SmsVerificationJob $job;

    private array $testMerchantIds = [];
    private string $testGatewaySlug = 'stripe';
    private int $globalGatewayId = 1;

    private int $merchantId1 = 99991;
    private int $merchantId2 = 99992;

    protected function setUp(): void
    {
        parent::setUp();

        if (!static::$dbAvailable) {
            $this->markTestSkipped('Database not available');
        }

        $this->db = Database::getInstance();

        $c = new Container();
        $bootstrap = require dirname(__DIR__, 2) . '/config/services.php';
        $bootstrap($c);
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
        if (static::$dbAvailable) {
            $this->cleanupData();
        }
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

        $m1 = $this->db->fetchOne("SELECT * FROM op_merchants WHERE id = :mid LIMIT 1", ['mid' => $this->merchantId1]);
        if ($m1 === null) {
            $this->db->execute(
                "INSERT INTO op_merchants (id, uuid, name, slug, email, status, settings)
                 VALUES (:mid, 'sovereign-merchant-uuid-1', 'Sovereign Brand 1', 'sov-1', 'sov1@test.com', 'active', '{}')",
                ['mid' => $this->merchantId1]
            );
        }
        $this->testMerchantIds[] = $this->merchantId1;

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

    public function testGatewayConfigurationResolverInheritance(): void
    {
        $creds = $this->gwConfigRepo->forTenant($this->merchantId1)->findCredentialsBySlug($this->testGatewaySlug);
        $this->assertNull($creds);

        $this->db->execute(
            "INSERT INTO op_gateway_configs (merchant_id, gateway_id, credentials_enc, settings, mode, status)
             VALUES (NULL, :gw, 'ENCRYPTED_GLOBAL_KEYS', '{}', 'sandbox', 'active')",
            ['gw' => $this->globalGatewayId]
        );

        $creds1 = $this->gwConfigRepo->forTenant($this->merchantId1)->findCredentialsBySlug($this->testGatewaySlug);
        $this->assertSame('ENCRYPTED_GLOBAL_KEYS', $creds1);

        $creds2 = $this->gwConfigRepo->forTenant($this->merchantId2)->findCredentialsBySlug($this->testGatewaySlug);
        $this->assertSame('ENCRYPTED_GLOBAL_KEYS', $creds2);

        $this->db->execute(
            "INSERT INTO op_gateway_configs (merchant_id, gateway_id, credentials_enc, settings, mode, status)
             VALUES (:mid, :gw, 'ENCRYPTED_BRAND1_KEYS', '{}', 'sandbox', 'active')",
            ['mid' => $this->merchantId1, 'gw' => $this->globalGatewayId]
        );

        $creds1Overridden = $this->gwConfigRepo->forTenant($this->merchantId1)->findCredentialsBySlug($this->testGatewaySlug);
        $this->assertSame('ENCRYPTED_BRAND1_KEYS', $creds1Overridden);

        $creds2Remaining = $this->gwConfigRepo->forTenant($this->merchantId2)->findCredentialsBySlug($this->testGatewaySlug);
        $this->assertSame('ENCRYPTED_GLOBAL_KEYS', $creds2Remaining);
    }

    public function testGlobalSharedDeviceSmsRoutingAndContextAlignment(): void
    {
        $trxId = 'TXN_SOV_1001';
        $this->db->execute(
            "INSERT INTO op_transactions (merchant_id, uuid, trx_id, amount, fee, net_amount, currency, gateway_slug, method, status)
             VALUES (:mid, 'txn-uuid-sov-1001', :trx, 2500.00, 0.00, 2500.00, 'BDT', 'bKash', 'sms', 'pending')",
            ['mid' => $this->merchantId2, 'trx' => $trxId]
        );

        $this->db->execute(
            "INSERT INTO op_sms_parsed (merchant_id, device_id, sender, body, amount, trx_id, gateway_slug, parser_type, match_status, received_at)
             VALUES (:mid, 'shared-device-uuid-999', 'bKash', 'You received money...', 2500.00, :trx, 'bKash', 'regex', 'pending', NOW())",
            ['mid' => $this->merchantId1, 'trx' => $trxId]
        );

        $smsPre = $this->db->fetchOne("SELECT * FROM op_sms_parsed WHERE trx_id = :trx", ['trx' => $trxId]);
        $this->assertSame($this->merchantId1, (int)$smsPre['merchant_id']);
        $this->assertSame('pending', $smsPre['match_status']);

        $res = $this->job->run();
        $this->assertSame(0, $res['matched']);
        $this->assertSame(1, $res['failed']);

        $txPost = $this->db->fetchOne("SELECT * FROM op_transactions WHERE trx_id = :trx", ['trx' => $trxId]);
        $this->assertSame('pending', $txPost['status']);

        $smsPost = $this->db->fetchOne("SELECT * FROM op_sms_parsed WHERE trx_id = :trx", ['trx' => $trxId]);
        $this->assertSame($this->merchantId1, (int)$smsPost['merchant_id']);
        $this->assertSame('pending', $smsPost['match_status']);
        $this->assertNull($smsPost['transaction_id']);

        $ledgerTrans = $this->db->fetchOne(
            "SELECT * FROM op_ledger_transactions WHERE merchant_id = :mid",
            ['mid' => $this->merchantId2]
        );
        $this->assertNull($ledgerTrans);
    }

    public function testGlobalDeviceRegistrationAndFallbackLookup(): void
    {
        $deviceId = 'global-shared-device-123';
        $this->db->execute(
            "INSERT INTO op_paired_devices (merchant_id, device_id, device_name, jwt_fingerprint, status, paired_at)
             VALUES (NULL, :did, 'System Global Phone', 'GLOBAL_FP_HASH', 'active', NOW())",
            ['did' => $deviceId]
        );

        $deviceGlobal = $this->gwConfigRepo->getDatabase()->fetchOne(
            "SELECT * FROM op_paired_devices WHERE device_id = :did",
            ['did' => $deviceId]
        );
        $this->assertNotNull($deviceGlobal);
        $this->assertNull($deviceGlobal['merchant_id']);

        $deviceBrand1 = $this->gwConfigRepo->getDatabase()->fetchOne(
            "SELECT * FROM op_paired_devices WHERE device_id = :did",
            ['did' => $deviceId]
        );
        $this->assertNotNull($deviceBrand1);

        $c = new Container();
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
