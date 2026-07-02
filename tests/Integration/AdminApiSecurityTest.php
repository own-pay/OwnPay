<?php

declare(strict_types=1);

namespace Tests\Integration;

use OwnPay\Container;
use OwnPay\Core\Database;
use OwnPay\Http\Request;
use OwnPay\Http\Response;
use OwnPay\Middleware\AdminBearerAuthMiddleware;
use OwnPay\Repository\AuditLogRepository;
use OwnPay\Service\Customer\ApiKeyService;
use OwnPay\Cron\SmsVerificationJob;
use OwnPay\Update\BackupService;

final class AdminApiSecurityTest extends IntegrationTestCase
{
    private Database $db;
    private Container $container;

    protected function setUp(): void
    {
        parent::setUp();

        if (!static::$dbAvailable) {
            $this->markTestSkipped('Database not available');
        }

        $this->db = Database::getInstance();
        $this->container = new Container();
        $bootstrap = require dirname(__DIR__, 2) . '/config/services.php';
        $bootstrap($this->container);
        $this->container->instance(Database::class, $this->db);

        require_once dirname(__DIR__, 2) . '/modules/gateways/stripe/StripeGateway.php';
        require_once dirname(__DIR__, 2) . '/modules/gateways/razorpay/RazorpayGateway.php';

        $this->db->execute("DELETE FROM op_api_keys WHERE merchant_id IN (99998, 99999)");
        $this->db->execute("DELETE FROM op_merchants WHERE id IN (99998, 99999)");
        $this->db->execute("DELETE FROM op_sms_parsed WHERE merchant_id IN (99998, 99999)");
        $this->db->execute("DELETE FROM op_transactions WHERE merchant_id IN (99998, 99999)");

        $this->db->execute(
            "INSERT INTO op_merchants (id, uuid, name, slug, email, status, settings)
             VALUES (99998, 'test-merchant-uuid-99998', 'Security Test Merchant A', 'sec-test-a', 'sec-a@test.com', 'active', '{}')"
        );
        $this->db->execute(
            "INSERT INTO op_merchants (id, uuid, name, slug, email, status, settings)
             VALUES (99999, 'test-merchant-uuid-99999', 'Security Test Merchant B', 'sec-test-b', 'sec-b@test.com', 'active', '{}')"
        );
    }

    protected function tearDown(): void
    {
        if (static::$dbAvailable) {
            $this->db->execute("DELETE FROM op_api_keys WHERE merchant_id IN (99998, 99999)");
            $this->db->execute("DELETE FROM op_merchants WHERE id IN (99998, 99999)");
            $this->db->execute("DELETE FROM op_sms_parsed WHERE merchant_id IN (99998, 99999)");
            $this->db->execute("DELETE FROM op_transactions WHERE merchant_id IN (99998, 99999)");
        }
        parent::tearDown();
    }

    public function testAdminBearerAuthEnforcesAdminScope(): void
    {
        $apiKeyService = $this->container->get(ApiKeyService::class);
        $this->assertInstanceOf(ApiKeyService::class, $apiKeyService);

        $standardKeyInfo = $apiKeyService->generate(99999, 'Standard Key', ['read', 'write']);
        $adminKeyInfo = $apiKeyService->generate(99999, 'Admin Key', ['read', 'write', 'admin']);

        $middleware = new AdminBearerAuthMiddleware($this->container);

        $reqStandard = new Request([], [], [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/api/admin/v1/sms-templates',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $standardKeyInfo['key']
        ]);

        $responseStandard = $middleware->handle($reqStandard, function(Request $r) {
            return Response::plain('Passed');
        });

        $this->assertSame(403, $responseStandard->getStatusCode());
        $bodyStandard = json_decode($responseStandard->getBody(), true);
        $this->assertFalse($bodyStandard['success']);
        $this->assertStringContainsString('Insufficient scope', $bodyStandard['message']);

        $reqAdmin = new Request([], [], [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/api/admin/v1/sms-templates',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $adminKeyInfo['key']
        ]);

        $responseAdmin = $middleware->handle($reqAdmin, function(Request $r) {
            return Response::plain('Passed');
        });

        $this->assertSame(200, $responseAdmin->getStatusCode());
        $this->assertSame('Passed', $responseAdmin->getBody());
    }

    public function testGatewayWebhooksFailClosedWhenSecretIsEmpty(): void
    {
        $stripe = new \OwnPay\Modules\Gateways\Stripe\StripeGateway();
        $razorpay = new \OwnPay\Modules\Gateways\Razorpay\RazorpayGateway();

        $rawBody = '{"event": "payment_intent.succeeded"}';
        $headers = ['stripe-signature' => 't=123,v1=signature'];

        $resStripe = $stripe->verifyWebhook($rawBody, $headers, [
            'secret_key' => 'sk_test_123',
            'publishable_key' => 'pk_test_123',
            'webhook_secret' => '',
            'mode' => 'sandbox'
        ]);
        $this->assertFalse($resStripe);

        $resRazorpay = $razorpay->verifyWebhook($rawBody, ['x-razorpay-signature' => 'sig'], [
            'key_id' => 'key_123',
            'key_secret' => 'secret_123',
            'webhook_secret' => ''
        ]);
        $this->assertFalse($resRazorpay);
    }

    public function testSmsVerificationJobDoesNotCrossMerchantBoundaries(): void
    {
        $trxId = 'TXN_SEC_TEST_999';

        $this->db->execute(
            "INSERT INTO op_transactions (merchant_id, uuid, trx_id, amount, fee, net_amount, currency, gateway_slug, method, status)
             VALUES (99998, 'txn-uuid-sec-998', :trx, 1000.00, 0.00, 1000.00, 'BDT', 'bKash', 'sms', 'pending')",
            ['trx' => $trxId]
        );

        $this->db->execute(
            "INSERT INTO op_sms_parsed (merchant_id, device_id, sender, body, amount, trx_id, gateway_slug, parser_type, match_status, received_at)
             VALUES (99999, 'device-sec-999', 'bKash', 'You received money...', 1000.00, :trx, 'bKash', 'regex', 'pending', NOW())",
            ['trx' => $trxId]
        );

        $job = $this->container->get(SmsVerificationJob::class);
        $res = $job->run();

        $this->assertSame(0, $res['matched']);

        $tx = $this->db->fetchOne("SELECT * FROM op_transactions WHERE trx_id = :trx", ['trx' => $trxId]);
        $this->assertSame('pending', $tx['status']);

        $sms = $this->db->fetchOne("SELECT * FROM op_sms_parsed WHERE trx_id = :trx", ['trx' => $trxId]);
        $this->assertSame(99999, (int)$sms['merchant_id']);
        $this->assertSame('pending', $sms['match_status']);
    }

    public function testAuditLogRepositoryThrowsExceptionOnInsecureSecret(): void
    {
        $auditRepo = $this->container->get(AuditLogRepository::class);
        $this->assertInstanceOf(AuditLogRepository::class, $auditRepo);

        $oldSecret = getenv('AUDIT_HMAC_SECRET');
        putenv('AUDIT_HMAC_SECRET=too-short');
        \OwnPay\Service\System\EnvironmentService::clearCache();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Insecure or missing AUDIT_HMAC_SECRET');

        try {
            $auditRepo->calculateSignature(1, 1, 'test', 'User', 1, null, null, '127.0.0.1', 'UA');
        } finally {
            if ($oldSecret !== false) {
                putenv('AUDIT_HMAC_SECRET=' . $oldSecret);
            } else {
                putenv('AUDIT_HMAC_SECRET');
            }
            \OwnPay\Service\System\EnvironmentService::clearCache();
        }
    }

    public function testBackupServiceZipRestoreValidation(): void
    {
        $backupService = $this->container->get(BackupService::class);
        $this->assertInstanceOf(BackupService::class, $backupService);

        $tempDir = sys_get_temp_dir() . '/ownpay_sec_test_' . uniqid();
        mkdir($tempDir);
        file_put_contents($tempDir . '/manifest.json', json_encode(['version' => '0.1.0']));

        $zip = new \ZipArchive();
        $zipFile = $tempDir . '/code.zip';
        if ($zip->open($zipFile, \ZipArchive::CREATE) === true) {
            $zip->addFromString('../traversal.php', '<?php echo "unsafe"; ?>');
            $zip->close();
        }

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Backup archive contains unsafe paths');

        try {
            $backupService->restore($tempDir);
        } finally {
            @unlink($zipFile);
            @unlink($tempDir . '/manifest.json');
            @rmdir($tempDir);
        }
    }
}
