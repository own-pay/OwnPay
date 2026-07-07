<?php

declare(strict_types=1);

namespace Tests\Integration;

use OwnPay\Container;
use OwnPay\Core\Database;
use OwnPay\Service\Payment\GatewayApiService;

/**
 * Regression for the plugin-hook webhook path completion guard (UnifiedWebhookController): 44
 * gateway modules register a `webhook.incoming.{slug}` hook and complete a referenced transaction
 * directly (TransactionService::complete()), bypassing the core handleCallback() pipeline's
 * gateway-match check entirely. GatewayApiService::isTransactionEligibleForWebhookCompletion()
 * closes this at the one shared dispatch point in UnifiedWebhookController, reusing the exact
 * isCompletionEligible() rule the core path already enforces, without touching any gateway module.
 */
final class GatewayApiServiceWebhookEligibilityTest extends IntegrationTestCase
{
    private Database $db;
    private GatewayApiService $service;
    private int $merchantId = 1;

    protected function setUp(): void
    {
        $_ENV['ENCRYPTION_KEY'] = 'def000003f9ea8d8ce30e46b9a896d84a362243d414a938c0fcfbeea0f46c6ea9b54c86e24687d89dbdf685c4b4a6b26ec588e7d7a188f6a4a6b2eec58ef7d7a';
        parent::setUp();

        if (!static::$dbAvailable) {
            $this->markTestSkipped('Database not available');
        }

        $this->db = Database::getInstance();
        $container = new Container();
        $bootstrap = require dirname(__DIR__, 2) . '/config/services.php';
        $bootstrap($container);
        $container->instance(Database::class, $this->db);

        $svc = $container->get(GatewayApiService::class);
        $this->assertInstanceOf(GatewayApiService::class, $svc);
        $this->service = $svc;

        $this->cleanup();
    }

    protected function tearDown(): void
    {
        if (static::$dbAvailable) {
            $this->cleanup();
        }
        parent::tearDown();
    }

    private function cleanup(): void
    {
        $this->db->execute("DELETE FROM op_transactions WHERE trx_id LIKE 'zzwhelig-%'");
    }

    private function insertTransaction(string $trxId, string $uuid, string $status, string $gatewaySlug): void
    {
        $this->db->execute(
            "INSERT INTO op_transactions (merchant_id, uuid, trx_id, gateway_slug, amount, net_amount, currency, method, status)
             VALUES (:mid, :uuid, :trx, :gw, '100.00', '100.00', 'BDT', 'api', :status)",
            ['mid' => $this->merchantId, 'uuid' => $uuid, 'trx' => $trxId, 'gw' => $gatewaySlug, 'status' => $status]
        );
    }

    public function testStaleWebhookFromAbandonedGatewayIsRejected(): void
    {
        // Customer started with nagad-api, abandoned it, went back and picked bkash-api - the
        // transaction's CURRENT gateway_slug is bkash-api. A late webhook claiming to be from
        // nagad-api for this same trx_id must not be allowed to complete it.
        $this->insertTransaction('zzwhelig-1', '11111111-2222-4333-8444-zzwhelig001', 'processing', 'bkash-api');

        $eligible = $this->service->isTransactionEligibleForWebhookCompletion('zzwhelig-1', $this->merchantId, 'nagad-api');
        $this->assertFalse($eligible);
    }

    public function testWebhookFromCurrentGatewayIsAllowed(): void
    {
        $this->insertTransaction('zzwhelig-2', '11111111-2222-4333-8444-zzwhelig002', 'processing', 'bkash-api');

        $eligible = $this->service->isTransactionEligibleForWebhookCompletion('zzwhelig-2', $this->merchantId, 'bkash-api');
        $this->assertTrue($eligible);
    }

    public function testPendingTransactionAllowedRegardlessOfGateway(): void
    {
        $this->insertTransaction('zzwhelig-3', '11111111-2222-4333-8444-zzwhelig003', 'pending', 'bkash-api');

        $eligible = $this->service->isTransactionEligibleForWebhookCompletion('zzwhelig-3', $this->merchantId, 'nagad-api');
        $this->assertTrue($eligible);
    }

    public function testAlreadyCompletedTransactionRejectsAnyFurtherWebhook(): void
    {
        $this->insertTransaction('zzwhelig-4', '11111111-2222-4333-8444-zzwhelig004', 'completed', 'bkash-api');

        $eligible = $this->service->isTransactionEligibleForWebhookCompletion('zzwhelig-4', $this->merchantId, 'bkash-api');
        $this->assertFalse($eligible, 'a terminal transaction must never be re-completed, even by its own original gateway');
    }

    public function testUnresolvableReferenceFailsOpenSoUnrelatedPluginsAreNotBlocked(): void
    {
        // No transaction matches this reference at all - the guard must not block a plugin that
        // resolves its own transaction differently (e.g. by gateway_trx_id after further lookup).
        $eligible = $this->service->isTransactionEligibleForWebhookCompletion('zzwhelig-does-not-exist', $this->merchantId, 'bkash-api');
        $this->assertTrue($eligible);
    }

    public function testEmptyReferenceFailsOpen(): void
    {
        $eligible = $this->service->isTransactionEligibleForWebhookCompletion('', $this->merchantId, 'bkash-api');
        $this->assertTrue($eligible);
    }

    public function testMatchesByGatewayTrxIdWhenTrxIdDiffers(): void
    {
        $this->db->execute(
            "INSERT INTO op_transactions (merchant_id, uuid, trx_id, gateway_trx_id, gateway_slug, amount, net_amount, currency, method, status)
             VALUES (:mid, :uuid, 'zzwhelig-5', 'gw-ref-999', 'nagad-api', '100.00', '100.00', 'BDT', 'api', 'processing')",
            ['mid' => $this->merchantId, 'uuid' => '11111111-2222-4333-8444-zzwhelig005']
        );

        $eligible = $this->service->isTransactionEligibleForWebhookCompletion('gw-ref-999', $this->merchantId, 'bkash-api');
        $this->assertFalse($eligible, 'lookup by gateway_trx_id must also enforce the gateway-match rule');
    }
}
