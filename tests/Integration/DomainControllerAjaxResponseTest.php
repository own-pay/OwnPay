<?php

declare(strict_types=1);

namespace Tests\Integration;

use OwnPay\Container;
use OwnPay\Controller\Admin\DomainController;
use OwnPay\Core\Database;
use OwnPay\Http\Request;
use OwnPay\Service\Brand\BrandContext;

final class DomainControllerAjaxResponseTest extends IntegrationTestCase
{
    private Database $db;
    private DomainController $controller;
    private BrandContext $brand;
    private int $merchantId = 99991;
    private int $domainId = 0;

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

        $controller = $c->get(DomainController::class);
        $this->assertInstanceOf(DomainController::class, $controller);
        $this->controller = $controller;

        $brand = $c->get(BrandContext::class);
        $this->assertInstanceOf(BrandContext::class, $brand);
        $this->brand = $brand;

        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }

        $this->cleanup();
        $this->seedMerchant();
        $this->domainId = $this->seedDomain();
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
        $this->db->execute("DELETE FROM op_domains WHERE merchant_id = :mid", ['mid' => $this->merchantId]);
        $this->db->execute("DELETE FROM op_merchants WHERE id = :mid", ['mid' => $this->merchantId]);
        unset($_SESSION['active_brand_id'], $_SESSION['brand_view_mode']);
    }

    private function seedMerchant(): void
    {
        $this->db->execute(
            "INSERT INTO op_merchants (id, uuid, name, slug, email, status, is_platform, settings)
             VALUES (:mid, :uuid, :name, :slug, :email, 'active', 0, '{}')",
            [
                'mid'   => $this->merchantId,
                'uuid'  => 'zz-domain-ajax-test-' . $this->merchantId,
                'name'  => 'Domain AJAX Test Merchant',
                'slug'  => 'zz-domain-ajax-test-' . $this->merchantId,
                'email' => 'zz-domain-ajax-test-' . $this->merchantId . '@test.com',
            ]
        );
    }

    private function seedDomain(): int
    {
        return (int) $this->db->insert(
            "INSERT INTO op_domains (merchant_id, domain, type, verification_token, dns_verified, ssl_status, status, created_at)
             VALUES (:mid, :domain, 'checkout', :token, 0, 'none', 'pending', NOW(6))",
            [
                'mid'    => $this->merchantId,
                'domain' => 'zz-ajax-test-' . $this->merchantId . '.example.com',
                'token'  => 'op-verify-zztest' . $this->merchantId,
            ]
        );
    }

    private function activateBrand(): void
    {
        $_SESSION['active_brand_id'] = $this->merchantId;
        $this->brand->resolveFromRequest(new Request([], [], ['REQUEST_METHOD' => 'GET']));
    }

    public function testDeleteReturnsJsonWhenAjax(): void
    {
        $this->activateBrand();

        $req = new Request([], [], [
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => '/admin/domains/' . $this->domainId . '/delete',
            'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest',
        ]);
        $req->setRouteParams(['id' => (string) $this->domainId]);

        $response = $this->controller->delete($req);
        $body = json_decode($response->getBody(), true);

        $this->assertIsArray($body);
        $this->assertTrue($body['success']);

        $row = $this->db->fetchOne("SELECT id FROM op_domains WHERE id = :id", ['id' => $this->domainId]);
        $this->assertNull($row, 'Domain row should actually be deleted, not just report success');
    }

    public function testDeleteReturnsRedirectWhenNotAjax(): void
    {
        $this->activateBrand();

        $req = new Request([], [], [
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => '/admin/domains/' . $this->domainId . '/delete',
        ]);
        $req->setRouteParams(['id' => (string) $this->domainId]);

        $response = $this->controller->delete($req);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/admin/domains', $response->getHeaders()['Location']);
    }

    public function testUpdateReturnsJsonWithFreshDomainStateWhenAjax(): void
    {
        $this->activateBrand();

        $req = new Request([], [
            'type' => 'api', 'redirect_url' => 'https://example.com/x',
            'status' => 'pending', 'dns_verified' => '0', 'is_primary' => '0',
        ], [
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => '/admin/domains/' . $this->domainId . '/update',
            'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest',
        ]);
        $req->setRouteParams(['id' => (string) $this->domainId]);

        $response = $this->controller->update($req);
        $body = json_decode($response->getBody(), true);

        $this->assertIsArray($body);
        $this->assertTrue($body['success']);
        $this->assertSame('api', $body['domain']['type']);
        $this->assertSame('https://example.com/x', $body['domain']['redirect_url']);
        $this->assertSame('Pending DNS', $body['domain']['status_pill']['label']);
    }
}
