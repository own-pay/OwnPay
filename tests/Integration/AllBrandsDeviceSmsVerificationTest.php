<?php
declare(strict_types=1);

namespace Tests\Integration;

use OwnPay\Core\Database;
use OwnPay\Cron\SmsVerificationJob;
use OwnPay\Service\Brand\BrandContext;
use Tests\Integration\IntegrationTestCase;

/**
 * AllBrandsDeviceSmsVerificationTest
 *
 * Verifies the multi-brand ("all-brands") companion-device SMS verification rule:
 *   - A device paired from the All-Brands view (op_paired_devices.merchant_id = platform id)
 *     has its SMS matched GLOBALLY across every brand, then rebound + completed under the
 *     brand that actually owns the matched transaction.
 *   - A device paired to a specific brand still NEVER matches another brand's transaction.
 *   - Cross-brand amount matching is money-safe: an amount that is ambiguous across brands
 *     is refused (never auto-completed).
 *
 * @group Integration
 */
final class AllBrandsDeviceSmsVerificationTest extends IntegrationTestCase
{
    private Database $db;
    private SmsVerificationJob $job;
    private int $platformId = 0;

    private int $brandA = 99993;
    private int $brandB = 99994;
    private string $deviceAllBrands = 'allbrands-device-test-uuid';
    private string $deviceBrandScoped = 'brandscoped-device-test-uuid';

    protected function setUp(): void
    {
        parent::setUp();

        if (!static::$dbAvailable) {
            $this->markTestSkipped('Database not available');
        }

        $this->db = Database::getInstance();

        $c = new \OwnPay\Container();
        $bootstrap = require dirname(__DIR__, 2) . '/config/services.php';
        $bootstrap($c);
        $c->instance(Database::class, $this->db);

        $this->job = $c->get(SmsVerificationJob::class);

        $brandContext = $c->get(BrandContext::class);
        $this->platformId = $brandContext->getPlatformId();

        $this->cleanupData();
        $this->setupBrands();
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
        $this->db->execute(
            "DELETE FROM op_sms_parsed WHERE device_id IN (:d1, :d2)",
            ['d1' => $this->deviceAllBrands, 'd2' => $this->deviceBrandScoped]
        );
        // Entries cascade from ledger_transactions / ledger_accounts (ON DELETE CASCADE).
        $this->db->execute("DELETE FROM op_ledger_transactions WHERE merchant_id IN (99993, 99994)");
        $this->db->execute("DELETE FROM op_ledger_accounts WHERE merchant_id IN (99993, 99994)");
        $this->db->execute("DELETE FROM op_transactions WHERE merchant_id IN (99993, 99994)");
        $this->db->execute("DELETE FROM op_merchants WHERE id IN (99993, 99994)");
    }

    private function setupBrands(): void
    {
        foreach ([[$this->brandA, 'allbrands-test-1'], [$this->brandB, 'allbrands-test-2']] as [$mid, $slug]) {
            $exists = $this->db->fetchOne("SELECT id FROM op_merchants WHERE id = :mid LIMIT 1", ['mid' => $mid]);
            if ($exists === null) {
                $this->db->execute(
                    "INSERT INTO op_merchants (id, uuid, name, slug, email, status, is_platform, settings)
                     VALUES (:mid, :uuid, :name, :slug, :email, 'active', 0, '{}')",
                    [
                        'mid'   => $mid,
                        'uuid'  => 'ab-merchant-uuid-' . $mid,
                        'name'  => 'All-Brands Test ' . $mid,
                        'slug'  => $slug,
                        'email' => $slug . '@test.com',
                    ]
                );
            }
        }
    }

    private function seedPendingTransaction(int $merchantId, string $uuid, string $amount, string $gateway, ?string $providerTrxId): int
    {
        $this->db->execute(
            "INSERT INTO op_transactions (merchant_id, uuid, trx_id, provider_trx_id, amount, fee, net_amount, currency, gateway_slug, method, status, created_at)
             VALUES (:mid, :uuid, :trx, :ptrx, :amt, 0.00, :amt2, 'BDT', :gw, 'sms', 'pending', NOW(6))",
            [
                'mid'  => $merchantId,
                'uuid' => $uuid,
                'trx'  => $uuid,
                'ptrx' => $providerTrxId,
                'amt'  => $amount,
                'amt2' => $amount,
                'gw'   => $gateway,
            ]
        );
        return (int) $this->db->fetchColumn("SELECT id FROM op_transactions WHERE uuid = :uuid", ['uuid' => $uuid]);
    }

    private function seedParsedSms(int $merchantId, string $deviceId, ?string $trxId, string $amount, string $gateway): int
    {
        $this->db->execute(
            "INSERT INTO op_sms_parsed (merchant_id, device_id, sender, body, amount, trx_id, gateway_slug, parser_type, match_status, received_at)
             VALUES (:mid, :did, :sender, 'Payment received notification', :amt, :trx, :gw, 'regex', 'pending', NOW(6))",
            [
                'mid'    => $merchantId,
                'did'    => $deviceId,
                'sender' => $gateway,
                'amt'    => $amount,
                'trx'    => $trxId,
                'gw'     => $gateway,
            ]
        );
        return (int) $this->db->fetchColumn(
            "SELECT id FROM op_sms_parsed WHERE device_id = :did ORDER BY id DESC LIMIT 1",
            ['did' => $deviceId]
        );
    }

    /**
     * An all-brands device's SMS matched by transaction id completes the transaction
     * under the brand that owns it and rebinds the parsed SMS to that brand.
     */
    public function testAllBrandsDeviceMatchesByTrxIdAcrossBrand(): void
    {
        $trxRef = 'TXN_AB_TRX_1001';
        $txId = $this->seedPendingTransaction($this->brandA, 'txn-uuid-ab-trx-1001', '18247.550000', 'bKash', $trxRef);
        $smsId = $this->seedParsedSms($this->platformId, $this->deviceAllBrands, $trxRef, '18247.550000', 'bKash');

        $this->job->run();

        $tx = $this->db->fetchOne("SELECT * FROM op_transactions WHERE id = :id", ['id' => $txId]);
        $this->assertSame('completed', $tx['status'], 'cross-brand transaction should be completed');

        $sms = $this->db->fetchOne("SELECT * FROM op_sms_parsed WHERE id = :id", ['id' => $smsId]);
        $this->assertSame($this->brandA, (int) $sms['merchant_id'], 'SMS should be rebound to the resolved brand');
        $this->assertSame($txId, (int) $sms['transaction_id'], 'SMS should link to the matched transaction');
        $this->assertSame('matched', $sms['match_status']);

        $ledger = $this->db->fetchOne("SELECT * FROM op_ledger_transactions WHERE merchant_id = :mid", ['mid' => $this->brandA]);
        $this->assertNotNull($ledger, 'ledger should be posted under the resolved brand');
    }

    /**
     * An all-brands device's SMS with no trx id matches by amount + gateway when exactly
     * one brand has a matching pending transaction in the time window.
     */
    public function testAllBrandsDeviceMatchesSingleAmountAcrossBrand(): void
    {
        $txId = $this->seedPendingTransaction($this->brandB, 'txn-uuid-ab-amt-2001', '27913.400000', 'Nagad', null);
        $smsId = $this->seedParsedSms($this->platformId, $this->deviceAllBrands, null, '27913.400000', 'Nagad');

        $this->job->run();

        $tx = $this->db->fetchOne("SELECT * FROM op_transactions WHERE id = :id", ['id' => $txId]);
        $this->assertSame('completed', $tx['status']);

        $sms = $this->db->fetchOne("SELECT * FROM op_sms_parsed WHERE id = :id", ['id' => $smsId]);
        $this->assertSame($this->brandB, (int) $sms['merchant_id']);
        $this->assertSame($txId, (int) $sms['transaction_id']);
    }

    /**
     * Money-safety: when the same amount + gateway is pending in MORE THAN ONE brand,
     * the all-brands amount fallback must refuse to match (never guess which brand is paid).
     */
    public function testAllBrandsDeviceDoesNotMatchAmbiguousAmountAcrossBrands(): void
    {
        $txA = $this->seedPendingTransaction($this->brandA, 'txn-uuid-ab-ambig-a', '33331.110000', 'bKash', null);
        $txB = $this->seedPendingTransaction($this->brandB, 'txn-uuid-ab-ambig-b', '33331.110000', 'bKash', null);
        $smsId = $this->seedParsedSms($this->platformId, $this->deviceAllBrands, null, '33331.110000', 'bKash');

        $this->job->run();

        $this->assertSame('pending', $this->db->fetchColumn("SELECT status FROM op_transactions WHERE id = :id", ['id' => $txA]));
        $this->assertSame('pending', $this->db->fetchColumn("SELECT status FROM op_transactions WHERE id = :id", ['id' => $txB]));

        $sms = $this->db->fetchOne("SELECT * FROM op_sms_parsed WHERE id = :id", ['id' => $smsId]);
        $this->assertSame('pending', $sms['match_status'], 'ambiguous cross-brand amount must not auto-match');
        $this->assertSame($this->platformId, (int) $sms['merchant_id'], 'ambiguous SMS must stay platform-scoped');
        $this->assertNull($sms['transaction_id']);
    }

    /**
     * A brand-scoped device must still NEVER match another brand's transaction,
     * even when the amount + gateway line up. (Guards the platform-only gate.)
     */
    public function testBrandScopedDeviceStillDoesNotMatchAcrossBrands(): void
    {
        $txId = $this->seedPendingTransaction($this->brandB, 'txn-uuid-bs-3001', '41255.770000', 'Nagad', null);
        $smsId = $this->seedParsedSms($this->brandA, $this->deviceBrandScoped, null, '41255.770000', 'Nagad');

        $this->job->run();

        $this->assertSame('pending', $this->db->fetchColumn("SELECT status FROM op_transactions WHERE id = :id", ['id' => $txId]));

        $sms = $this->db->fetchOne("SELECT * FROM op_sms_parsed WHERE id = :id", ['id' => $smsId]);
        $this->assertSame('pending', $sms['match_status']);
        $this->assertSame($this->brandA, (int) $sms['merchant_id']);
        $this->assertNull($sms['transaction_id']);
    }
}
