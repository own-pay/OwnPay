<?php

declare(strict_types=1);

namespace Tests\Integration;

use OwnPay\Container;
use OwnPay\Core\Database;
use OwnPay\Controller\Admin\PaymentLinkController;
use OwnPay\Http\Request;
use OwnPay\Service\Brand\BrandContext;
use OwnPay\Service\Payment\PaymentLinkService;

/**
 * Regression coverage for the payment-link admin audit findings:
 * - B-A: global "All Brands" view could never edit/update any link (merchant_id=0 never matched).
 * - B-B: update() flashed "Updated" even when the row was never actually reached/changed.
 * - B-C: an explicit duplicate slug threw an uncaught PDOException (500) instead of a validation error.
 * - B-E: amount/min_amount/max_amount accepted non-numeric/negative input with no validation.
 * - B-F: status was written from raw input with no enum whitelist.
 */
final class PaymentLinkAdminFixesTest extends IntegrationTestCase
{
    private Database $db;
    private PaymentLinkService $links;
    private PaymentLinkController $controller;
    private BrandContext $brand;
    private int $merchantId = 1;
    private int $otherMerchantId = 2;

    protected function setUp(): void
    {
        parent::setUp();

        if (!static::$dbAvailable) {
            $this->markTestSkipped('Database not available');
        }

        $this->db = Database::getInstance();
        $container = new Container();
        $bootstrap = require dirname(__DIR__, 2) . '/config/services.php';
        $bootstrap($container);
        $container->instance(Database::class, $this->db);

        $links = $container->get(PaymentLinkService::class);
        $this->assertInstanceOf(PaymentLinkService::class, $links);
        $this->links = $links;

        $controller = $container->get(PaymentLinkController::class);
        $this->assertInstanceOf(PaymentLinkController::class, $controller);
        $this->controller = $controller;

        $brand = $container->get(BrandContext::class);
        $this->assertInstanceOf(BrandContext::class, $brand);
        $this->brand = $brand;

        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }

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
        $this->db->execute("DELETE FROM op_payment_links WHERE slug LIKE 'zzplink-%'");
        unset($_SESSION['active_brand_id'], $_SESSION['brand_view_mode']);
    }

    private function insertLink(int $merchantId, string $slug): int
    {
        return (int) $this->db->insert(
            "INSERT INTO op_payment_links (merchant_id, uuid, slug, title, currency, status, created_at)
             VALUES (:mid, :uuid, :slug, 'Test Link', 'BDT', 'active', NOW())",
            ['mid' => $merchantId, 'uuid' => bin2hex(random_bytes(16)), 'slug' => $slug]
        );
    }

    public function testGlobalViewCanEditAndUpdateAnyBrandsLink(): void
    {
        $id = $this->insertLink($this->otherMerchantId, 'zzplink-global-1');

        $_SESSION['active_brand_id'] = 0; // "All Brands" global view marker
        $_SESSION['brand_view_mode'] = 'global';
        $this->brand->resolveFromRequest(new Request([], [], ['REQUEST_METHOD' => 'GET']));

        $getReq = new Request([], [], ['REQUEST_METHOD' => 'GET']);
        $getReq->setRouteParams(['id' => (string) $id]);
        $getRes = $this->controller->edit($getReq);
        $this->assertSame(200, $getRes->getStatusCode(), 'superadmin in global view must be able to open the edit form for any brand\'s link');

        $postReq = new Request([], ['title' => 'Updated From Global View', 'currency' => 'BDT'], ['REQUEST_METHOD' => 'POST']);
        $postReq->setRouteParams(['id' => (string) $id]);
        $postRes = $this->controller->edit($postReq);
        $this->assertSame(302, $postRes->getStatusCode());
        $this->assertSame('/admin/payment-links', $postRes->getHeaders()['Location'] ?? null, 'a real update must redirect to the index, not silently no-op');

        $row = $this->db->fetchOne("SELECT title FROM op_payment_links WHERE id = :id", ['id' => $id]);
        $this->assertSame('Updated From Global View', $row['title'], 'the update must actually persist against the link\'s real owning merchant');
    }

    public function testGlobalViewEditingNonexistentLinkFailsCleanly(): void
    {
        $_SESSION['active_brand_id'] = 0;
        $_SESSION['brand_view_mode'] = 'global';
        $this->brand->resolveFromRequest(new Request([], [], ['REQUEST_METHOD' => 'GET']));

        $getReq = new Request([], [], ['REQUEST_METHOD' => 'GET']);
        $getReq->setRouteParams(['id' => '999999999']);
        $res = $this->controller->edit($getReq);

        $this->assertSame(302, $res->getStatusCode());
        $this->assertSame('/admin/payment-links', $res->getHeaders()['Location'] ?? null);
    }

    public function testExplicitDuplicateSlugIsRejectedAsValidationErrorNotServerError(): void
    {
        $this->insertLink($this->merchantId, 'zzplink-dup');

        $this->expectException(\InvalidArgumentException::class);
        $this->links->create($this->merchantId, ['title' => 'Second Link', 'slug' => 'zzplink-dup']);
    }

    public function testNegativeAmountIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->links->create($this->merchantId, ['title' => 'Bad Amount Link', 'amount' => '-50.00']);
    }

    public function testNonNumericAmountIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->links->create($this->merchantId, ['title' => 'Bad Amount Link', 'amount' => 'not-a-number']);
    }

    public function testMinMaxAmountsPersistThroughCreateAndUpdate(): void
    {
        $link = $this->links->create($this->merchantId, [
            'title' => 'Range Link',
            'slug' => 'zzplink-range',
            'min_amount' => '10.00',
            'max_amount' => '500.00',
        ]);
        $this->assertSame('10.00', $link['min_amount']);
        $this->assertSame('500.00', $link['max_amount']);

        $updated = $this->links->update($this->merchantId, (int) $link['id'], [
            'title' => 'Range Link',
            'min_amount' => '20.00',
            'max_amount' => '999.00',
        ]);
        $this->assertSame('20.00', $updated['min_amount']);
        $this->assertSame('999.00', $updated['max_amount']);
    }

    public function testInvalidStatusValueIsRejected(): void
    {
        $id = $this->insertLink($this->merchantId, 'zzplink-status');

        $this->expectException(\InvalidArgumentException::class);
        $this->links->update($this->merchantId, $id, ['title' => 'x', 'status' => 'not-a-real-status']);
    }

    public function testValidStatusValuesAreAccepted(): void
    {
        $id = $this->insertLink($this->merchantId, 'zzplink-status-ok');

        $updated = $this->links->update($this->merchantId, $id, ['title' => 'x', 'status' => 'inactive']);
        $this->assertSame('inactive', $updated['status']);
    }

    public function testEditControllerSurfacesValidationErrorWithoutCrashing(): void
    {
        $id = $this->insertLink($this->merchantId, 'zzplink-ctrl-validate');

        $_SESSION['active_brand_id'] = $this->merchantId;
        $_SESSION['brand_view_mode'] = 'single';
        $this->brand->resolveFromRequest(new Request([], [], ['REQUEST_METHOD' => 'GET']));

        $postReq = new Request([], ['title' => 'x', 'amount' => 'garbage'], ['REQUEST_METHOD' => 'POST']);
        $postReq->setRouteParams(['id' => (string) $id]);
        $res = $this->controller->edit($postReq);

        $this->assertSame(302, $res->getStatusCode());
        $this->assertSame("/admin/payment-links/{$id}", $res->getHeaders()['Location'] ?? null);

        $row = $this->db->fetchOne("SELECT title FROM op_payment_links WHERE id = :id", ['id' => $id]);
        $this->assertSame('Test Link', $row['title'], 'an invalid submission must not partially apply');
    }
}
