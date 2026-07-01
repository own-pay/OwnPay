<?php

declare(strict_types=1);

namespace Tests\Integration;

use OwnPay\Container;
use OwnPay\Core\Database;
use OwnPay\Controller\Admin\GatewayController;
use OwnPay\Http\Request;

/**
 * Phase 2c governance + per-brand account configuration, driven through the real GatewayController.
 *
 * Asserts the model-A rules: only All Brands creates gateway TYPES (templates); a brand cannot create
 * types (direct-URL closed) but configures its OWN account for a platform template; that account then
 * wins at checkout. Uses 'zztest-' slugs so it never touches real seeded gateways.
 */
final class GatewayGovernanceTest extends IntegrationTestCase
{
    private Database $db;
    private Container $container;
    private GatewayController $controller;
    private int $brandId = 1;
    private int $platformId = 0;

    protected function setUp(): void
    {
        parent::setUp();
        if (!static::$dbAvailable) {
            $this->markTestSkipped('Database not available');
        }

        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }

        $this->db = Database::getInstance();
        $this->container = new Container();
        $bootstrap = require dirname(__DIR__, 2) . '/config/services.php';
        $bootstrap($this->container);
        $this->container->instance(Database::class, $this->db);

        $row = $this->db->fetchOne("SELECT id FROM op_merchants WHERE is_platform = 1 ORDER BY id ASC LIMIT 1");
        $this->platformId = ($row !== null && is_scalar($row['id'] ?? null)) ? (int) $row['id'] : 0;
        if ($this->platformId === 0 || $this->platformId === $this->brandId) {
            $this->markTestSkipped('Platform-owner row unavailable or collides with brand id.');
        }

        $controller = $this->container->get(GatewayController::class);
        $this->assertInstanceOf(GatewayController::class, $controller);
        $this->controller = $controller;

        $this->cleanup();
    }

    protected function tearDown(): void
    {
        if (static::$dbAvailable) {
            $this->cleanup();
        }
        $_SESSION = [];
        parent::tearDown();
    }

    private function cleanup(): void
    {
        $this->db->execute("DELETE FROM op_manual_gateways WHERE slug LIKE 'zztest-%'");
    }

    private function setGlobalView(): void
    {
        $_SESSION['active_brand_id'] = 0;
        $_SESSION['brand_view_mode'] = 'global';
    }

    private function setBrandView(): void
    {
        $_SESSION['active_brand_id'] = $this->brandId;
        $_SESSION['brand_view_mode'] = 'single';
    }

    /**
     * @param array<string, mixed> $post
     * @param array<string, string> $routeParams
     */
    private function postRequest(array $post, array $routeParams = []): Request
    {
        $req = new Request([], $post, ['REQUEST_METHOD' => 'POST'], [], []);
        $req->setRouteParams($routeParams);
        return $req;
    }

    private function insertTemplate(string $slug, string $account): void
    {
        $this->db->execute(
            "INSERT INTO op_manual_gateways (merchant_id, slug, name, instructions, sms_verification, currency, status, sort_order)
             VALUES (:mid, :slug, :name, :instr, 0, 'BDT', 'active', 0)",
            ['mid' => $this->platformId, 'slug' => $slug, 'name' => $slug, 'instr' => json_encode($account)]
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchGateway(int $merchantId, string $slug): ?array
    {
        return $this->db->fetchOne(
            "SELECT * FROM op_manual_gateways WHERE merchant_id = :m AND slug = :s LIMIT 1",
            ['m' => $merchantId, 's' => $slug]
        );
    }

    public function testBrandViewCannotCreateGatewayType(): void
    {
        $this->setBrandView();

        $this->controller->createManual($this->postRequest([
            'name'         => 'ZZ Brand Hack',
            'slug'         => 'zztest-gov-brand',
            'instructions' => 'Send to BRAND',
        ]));

        // The All-Brands-only guard must redirect before any insert (closes the brand direct-URL).
        $this->assertNull($this->fetchGateway($this->brandId, 'zztest-gov-brand'), 'Brand must not create a type');
        $this->assertNull($this->fetchGateway($this->platformId, 'zztest-gov-brand'), 'Nothing created at all');
    }

    public function testGlobalViewCreatesPlatformTemplate(): void
    {
        $this->setGlobalView();

        $this->controller->createManual($this->postRequest([
            'name'         => 'ZZ Platform Template',
            'slug'         => 'zztest-gov-tmpl',
            'instructions' => 'Send to PLATFORM-DEFAULT',
        ]));

        $row = $this->fetchGateway($this->platformId, 'zztest-gov-tmpl');
        $this->assertNotNull($row, 'All Brands creates the platform template');
        $this->assertStringContainsString('PLATFORM-DEFAULT', (string) ($row['instructions'] ?? ''));
    }

    public function testBrandConfiguresOwnAccountForTemplate(): void
    {
        $this->insertTemplate('zztest-cfg', 'PLATFORM-DEFAULT-acct');
        $this->setBrandView();

        $this->controller->configureAccount($this->postRequest(
            ['instructions' => 'Send money to BRAND-OWN-01711111111'],
            ['slug' => 'zztest-cfg']
        ));

        $brandRow = $this->fetchGateway($this->brandId, 'zztest-cfg');
        $this->assertNotNull($brandRow, 'Brand account row created for the template slug');
        $this->assertStringContainsString('BRAND-OWN-01711111111', (string) ($brandRow['instructions'] ?? ''));

        // Money outcome: the brand's account now WINS at that brand's checkout.
        $repo = new \OwnPay\Repository\ManualGatewayRepository($this->db);
        $effective = $repo->listActiveForCheckout($this->brandId, $this->platformId);
        $found = null;
        foreach ($effective as $gw) {
            if (($gw['slug'] ?? null) === 'zztest-cfg') {
                $found = $gw;
            }
        }
        $this->assertNotNull($found);
        $this->assertSame($this->brandId, (int) $found['merchant_id'], 'Funds route to the brand account');
        $this->assertStringContainsString('BRAND-OWN-01711111111', (string) $found['instructions']);
    }

    public function testBrandUpdatesExistingAccountInsteadOfDuplicating(): void
    {
        $this->insertTemplate('zztest-cfg', 'PLATFORM-DEFAULT-acct');
        $this->setBrandView();

        $req1 = $this->postRequest(['instructions' => 'Send to FIRST'], ['slug' => 'zztest-cfg']);
        $this->controller->configureAccount($req1);
        $req2 = $this->postRequest(['instructions' => 'Send to SECOND-UPDATED'], ['slug' => 'zztest-cfg']);
        $this->controller->configureAccount($req2);

        $rows = $this->db->fetchAll(
            "SELECT * FROM op_manual_gateways WHERE merchant_id = :m AND slug = 'zztest-cfg'",
            ['m' => $this->brandId]
        );
        $this->assertCount(1, $rows, 'Re-configuring updates the single brand row, never duplicates');
        $this->assertStringContainsString('SECOND-UPDATED', (string) ($rows[0]['instructions'] ?? ''));
    }

    public function testConfigureAccountBlockedInGlobalView(): void
    {
        $this->insertTemplate('zztest-cfg', 'PLATFORM-DEFAULT-acct');
        $this->setGlobalView();

        $this->controller->configureAccount($this->postRequest(
            ['instructions' => 'Send to PLATFORM-AS-BRAND'],
            ['slug' => 'zztest-cfg']
        ));

        // Configuring a brand account is a brand-scoped action; All Brands edits the template directly.
        $this->assertNull(
            $this->fetchGateway($this->platformId, 'zztest-cfg-shouldnotexist'),
            'no stray rows'
        );
        $platformRows = $this->db->fetchAll(
            "SELECT * FROM op_manual_gateways WHERE merchant_id = :m AND slug = 'zztest-cfg'",
            ['m' => $this->platformId]
        );
        $this->assertCount(1, $platformRows, 'Template untouched');
        $this->assertStringContainsString('PLATFORM-DEFAULT-acct', (string) ($platformRows[0]['instructions'] ?? ''));
    }
}
