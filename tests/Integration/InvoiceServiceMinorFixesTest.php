<?php

declare(strict_types=1);

namespace Tests\Integration;

use OwnPay\Container;
use OwnPay\Core\Database;
use OwnPay\Controller\Admin\InvoiceController;
use OwnPay\Http\Request;
use OwnPay\Service\Brand\BrandContext;
use OwnPay\Service\Payment\InvoiceService;

/**
 * Regression coverage for the invoice audit findings:
 * - C2: generatePdf()'s template references {{merchant_name}} and {{amount}}, neither of which
 *   is a real op_invoices column - both must be populated or the literal placeholder text leaks
 *   into the rendered document.
 * - C3: create() allowed a zero-line-item invoice (update() already blocked it) - now consistent.
 * - C4: manually setting status to 'paid' never stamped paid_at (unlike the real payment-
 *   completion path via PaymentCompletionListener) - now stamped once, preserved thereafter.
 */
final class InvoiceServiceMinorFixesTest extends IntegrationTestCase
{
    private Database $db;
    private InvoiceService $invoices;
    private InvoiceController $controller;
    private BrandContext $brand;
    private int $merchantId = 1;

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

        $invoices = $container->get(InvoiceService::class);
        $this->assertInstanceOf(InvoiceService::class, $invoices);
        $this->invoices = $invoices;

        $controller = $container->get(InvoiceController::class);
        $this->assertInstanceOf(InvoiceController::class, $controller);
        $this->controller = $controller;

        $brand = $container->get(BrandContext::class);
        $this->assertInstanceOf(BrandContext::class, $brand);
        $this->brand = $brand;

        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }
        $_SESSION['active_brand_id'] = $this->merchantId;
        $this->brand->resolveFromRequest(new Request([], [], ['REQUEST_METHOD' => 'GET']));

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
        $ids = $this->db->fetchAll("SELECT id FROM op_invoices WHERE invoice_number LIKE 'ZZINV-%'");
        $this->db->execute("DELETE FROM op_invoices WHERE invoice_number LIKE 'ZZINV-%'");
        unset($_SESSION['active_brand_id']);
        foreach ($ids as $row) {
            $path = dirname(__DIR__, 2) . '/storage/pdf/invoice_' . (int) $row['id'] . '.html';
            if (file_exists($path)) {
                @unlink($path);
            }
        }
    }

    private function createInvoiceWithItems(string $number, string $status = 'draft'): array
    {
        $invoice = $this->invoices->create($this->merchantId, [
            'invoice_number' => $number,
            'items' => [['description' => 'Widget', 'quantity' => 2, 'unit_price' => '25.00']],
        ]);
        if ($status !== 'draft') {
            $invoice = $this->invoices->update($this->merchantId, (int) $invoice['id'], [
                'status' => $status,
                'items' => [['description' => 'Widget', 'quantity' => 2, 'unit_price' => '25.00']],
            ]);
        }
        return $invoice;
    }

    public function testCreateRejectsZeroLineItemsJustLikeUpdate(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->invoices->create($this->merchantId, ['invoice_number' => 'ZZINV-EMPTY', 'items' => []]);
    }

    public function testGeneratePdfPopulatesMerchantNameAndAmountNotLiteralPlaceholders(): void
    {
        $merchantRow = $this->db->fetchOne("SELECT name FROM op_merchants WHERE id = :id", ['id' => $this->merchantId]);
        $this->assertNotNull($merchantRow, 'test merchant must exist for this assertion to be meaningful');
        $merchantName = (string) $merchantRow['name'];

        $invoice = $this->createInvoiceWithItems('ZZINV-PDF-1');

        $html = $this->invoices->generatePdf($this->merchantId, (int) $invoice['id']);

        $this->assertStringNotContainsString('{{merchant_name}}', $html);
        $this->assertStringNotContainsString('{{amount}}', $html);
        $this->assertStringContainsString($merchantName, $html);
        $this->assertStringContainsString('50.00', $html); // 2 x 25.00
    }

    public function testManuallySettingStatusToPaidStampsPaidAtOnce(): void
    {
        $invoice = $this->createInvoiceWithItems('ZZINV-PAID-1');
        $this->assertNull($invoice['paid_at']);

        $paid = $this->invoices->update($this->merchantId, (int) $invoice['id'], [
            'status' => 'paid',
            'items' => [['description' => 'Widget', 'quantity' => 2, 'unit_price' => '25.00']],
        ]);
        $this->assertNotNull($paid['paid_at'], 'manually marking an invoice paid must stamp paid_at');
        $firstPaidAt = $paid['paid_at'];

        usleep(1100000); // ensure a re-save would produce a detectably different timestamp if overwritten

        $resaved = $this->invoices->update($this->merchantId, (int) $invoice['id'], [
            'status' => 'paid',
            'notes' => 'unrelated edit',
            'items' => [['description' => 'Widget', 'quantity' => 2, 'unit_price' => '25.00']],
        ]);
        $this->assertSame($firstPaidAt, $resaved['paid_at'], 're-saving an already-paid invoice must not overwrite the original paid_at');
    }

    public function testCreateControllerSurfacesEmptyItemsValidationErrorWithoutCrashing(): void
    {
        $req = new Request([], ['invoice_number' => 'ZZINV-CTRL-1'], ['REQUEST_METHOD' => 'POST']);
        $res = $this->controller->create($req);

        $this->assertSame(302, $res->getStatusCode());
        $this->assertSame('/admin/invoices/create', $res->getHeaders()['Location'] ?? null);

        $row = $this->db->fetchOne("SELECT id FROM op_invoices WHERE invoice_number = 'ZZINV-CTRL-1'");
        $this->assertNull($row, 'no invoice should be created when validation fails');
    }
}
