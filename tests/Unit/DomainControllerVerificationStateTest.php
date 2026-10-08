<?php

declare(strict_types=1);

namespace Tests\Unit;

use OwnPay\Container;
use OwnPay\Controller\Admin\DomainController;
use OwnPay\Core\Database;
use OwnPay\Event\EventManager;
use OwnPay\Http\Request;
use OwnPay\Repository\DomainRepository;
use OwnPay\Service\Admin\AdminSession;
use OwnPay\Service\Brand\BrandContext;
use OwnPay\Service\Domain\DomainService;
use PHPUnit\Framework\TestCase;

/**
 * `status` / `dns_verified` gate DomainMiddleware::resolve() - the row that
 * carries `dns_verified = 1 AND status = 'active'` is served as the brand's live
 * checkout host. Only DomainService::verify() may set them, after proving DNS
 * ownership, so DomainController::update() must never mass-assign them from the
 * request body.
 *
 * @see https://github.com/own-pay/OwnPay/issues/616
 */
final class DomainControllerVerificationStateTest extends TestCase
{
    private Database $db;

    protected function setUp(): void
    {
        parent::setUp();
        $_SESSION = [];

        $this->db = $this->createMock(Database::class);
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        parent::tearDown();
    }

    private function controller(): DomainController
    {
        $container = new Container();

        $brandContext = new BrandContext($this->db);
        $brandContext->setActiveBrandId(5);
        $container->instance(BrandContext::class, $brandContext);
        $container->instance(Database::class, $this->db);
        $container->instance(DomainRepository::class, new DomainRepository($this->db));

        return new DomainController(
            $container,
            new AdminSession(),
            new DomainService(
                new DomainRepository($this->db),
                new \OwnPay\Service\Domain\DnsVerifier(),
                new EventManager()
            )
        );
    }

    /**
     * @param array<string, mixed> $post
     * @param array<int, array{sql: string, params: array<string, mixed>}> $captured
     */
    private function updateWithPost(array $post, array &$captured, int $storedDnsVerified, string $storedStatus): Request
    {
        $this->db->method('fetchOne')->willReturn([
            'id'                 => 77,
            'merchant_id'        => 5,
            'domain'             => 'shop.example.com',
            'type'               => 'checkout',
            'redirect_url'       => null,
            'status'             => $storedStatus,
            'dns_verified'       => $storedDnsVerified,
            'ssl_status'         => 'none',
            'is_primary'         => 1,
            'verification_token' => 'op-verify-abc',
        ]);

        $this->db->method('update')->willReturnCallback(
            function (string $sql, array $params) use (&$captured): int {
                $captured[] = ['sql' => $sql, 'params' => $params];
                return 1;
            }
        );

        $req = new Request([], $post, [
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI'    => '/admin/domains/77/update',
            'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest',
        ]);
        $req->setRouteParams(['id' => '77']);

        return $req;
    }

    public function testPostedDnsVerifiedAndStatusAreIgnored(): void
    {
        $captured = [];
        $req = $this->updateWithPost([
            'type'         => 'api',
            'redirect_url' => '',
            // Attacker-controlled escalation attempt.
            'dns_verified' => '1',
            'status'       => 'active',
            'is_primary'   => '1',
        ], $captured, 0, 'pending');

        $response = $this->controller()->update($req);
        $this->assertSame(200, $response->getStatusCode());

        $this->assertCount(1, $captured, 'Exactly one UPDATE should be issued.');
        $written = $captured[0]['params'];

        $this->assertArrayNotHasKey('dns_verified', $written, 'dns_verified must never be mass-assigned.');
        $this->assertArrayNotHasKey('status', $written, 'status must never be mass-assigned.');
        $this->assertStringNotContainsString('dns_verified', $captured[0]['sql']);
        $this->assertStringNotContainsString('status', $captured[0]['sql']);

        // The legitimate fields still round-trip.
        $this->assertSame('api', $written['type']);
    }

    public function testSavePreservesVerifiedActiveState(): void
    {
        $captured = [];
        $req = $this->updateWithPost([
            'type'         => 'checkout',
            'redirect_url' => '',
            'is_primary'   => '1',
        ], $captured, 1, 'active');

        $this->controller()->update($req);

        $this->assertCount(1, $captured);
        $written = $captured[0]['params'];
        $this->assertArrayNotHasKey('dns_verified', $written);
        $this->assertArrayNotHasKey('status', $written);
    }

    public function testAjaxResponseReflectsStoredVerificationStateNotThePostedOne(): void
    {
        $captured = [];
        $req = $this->updateWithPost([
            'type'         => 'checkout',
            'redirect_url' => '',
            'dns_verified' => '1',
            'status'       => 'active',
            'is_primary'   => '1',
        ], $captured, 0, 'pending');

        $response = $this->controller()->update($req);
        $body = json_decode($response->getBody(), true);

        $this->assertIsArray($body);
        $this->assertTrue($body['success']);
        $this->assertSame('Pending DNS', $body['domain']['status_pill']['label']);
    }
}
