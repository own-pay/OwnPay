<?php

declare(strict_types=1);

namespace Tests\Unit;

use OwnPay\Container;
use OwnPay\Controller\Admin\BrandController;
use OwnPay\Core\Database;
use OwnPay\Http\Request;
use OwnPay\Repository\AuditLogRepository;
use OwnPay\Repository\MerchantRepository;
use OwnPay\Service\Admin\AdminSession;
use OwnPay\Service\Brand\BrandContext;
use OwnPay\Service\Payment\PaymentLinkService;
use OwnPay\Service\System\AuditService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Regression tests for Brand settings language persistence
 * (issues: empty op_languages table + hardcoded/overwritten language fields).
 */
final class BrandControllerLanguageSettingsTest extends TestCase
{
    /** @var array<int, array<string, mixed>> */
    private array $capturedInserts = [];

    /** @var array<int, array<string, mixed>> */
    private array $capturedExecutes = [];

    /** @var Database&MockObject */
    private $db;

    private Container $container;

    protected function setUp(): void
    {
        parent::setUp();

        $_SESSION['is_superadmin'] = true;

        $this->db = $this->createMock(Database::class);
        $this->db->method('insert')->willReturnCallback(function (string $sql, array $params): string {
            $this->capturedInserts[] = $params;
            return '1';
        });
        $this->db->method('execute')->willReturnCallback(function (string $sql, array $params): \PDOStatement {
            $this->capturedExecutes[] = $params;
            return new class extends \PDOStatement {
            };
        });

        $this->container = new Container();
        $this->container->instance(PaymentLinkService::class, new PaymentLinkService($this->db));
    }

    protected function tearDown(): void
    {
        unset($_SESSION['is_superadmin']);
        parent::tearDown();
    }

    /**
     * @return array{0: BrandController, 1: AdminSession}
     */
    private function makeController(): array
    {
        $session = new AdminSession();
        $merchants = new MerchantRepository($this->db);
        $audit = new AuditService(new AuditLogRepository($this->db), $session);
        $controller = new BrandController($this->container, $session, new BrandContext($this->db), $merchants, $audit);
        return [$controller, $session];
    }

    private function capturedMerchantInsert(): ?array
    {
        foreach ($this->capturedInserts as $params) {
            if (array_key_exists('settings', $params)) {
                return $params;
            }
        }
        return null;
    }

    public function testStorePersistsSubmittedLanguage(): void
    {
        [$controller] = $this->makeController();

        $res = $controller->store(new Request(
            [],
            ['name' => 'Acme', 'email' => 'acme@example.com', 'language' => 'bn'],
            ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/admin/brands/store']
        ));
        $this->assertSame(302, $res->getStatusCode());

        $insert = $this->capturedMerchantInsert();
        $this->assertNotNull($insert, 'Expected a merchant INSERT to be issued');
        $settings = json_decode((string) $insert['settings'], true);
        $this->assertSame('bn', $settings['language'] ?? null);
    }

    public function testStoreDefaultsToEnglishWhenLanguageMissing(): void
    {
        [$controller] = $this->makeController();

        $controller->store(new Request(
            [],
            ['name' => 'Acme', 'email' => 'acme@example.com'],
            ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/admin/brands/store']
        ));

        $insert = $this->capturedMerchantInsert();
        $this->assertNotNull($insert);
        $settings = json_decode((string) $insert['settings'], true);
        $this->assertSame('en', $settings['language'] ?? null);
    }

    public function testUpdateMergesSubmittedLanguageIntoExistingSettings(): void
    {
        $this->db->method('fetchOne')->willReturn([
            'id' => 5,
            'name' => 'Acme',
            'slug' => 'acme',
            'email' => 'acme@example.com',
            'phone' => '',
            'timezone' => 'Asia/Dhaka',
            'default_currency' => 'BDT',
            'status' => 'active',
            'logo_path' => null,
            'settings' => json_encode(['primary_color' => '#FF0000', 'language' => 'en']),
        ]);

        [$controller] = $this->makeController();

        $request = new Request(
            [],
            ['name' => 'Acme', 'email' => 'acme@example.com', 'language' => 'ar'],
            ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/admin/brands/5/update']
        );
        $request->setRouteParams(['id' => '5']);

        $controller->update($request);

        $this->assertCount(1, $this->capturedExecutes, 'Expected exactly one brand UPDATE');
        $settings = json_decode((string) $this->capturedExecutes[0]['settings'], true);
        $this->assertIsArray($settings);
        $this->assertSame('ar', $settings['language'] ?? null);
        $this->assertSame('#FF0000', $settings['primary_color'] ?? null, 'Unrelated settings must be preserved');
    }

    public function testUpdateKeepsExistingLanguageWhenNotSubmitted(): void
    {
        $this->db->method('fetchOne')->willReturn([
            'id' => 5,
            'name' => 'Acme',
            'slug' => 'acme',
            'email' => 'acme@example.com',
            'phone' => '',
            'timezone' => 'Asia/Dhaka',
            'default_currency' => 'BDT',
            'status' => 'active',
            'logo_path' => null,
            'settings' => json_encode(['primary_color' => '#FF0000', 'language' => 'en']),
        ]);

        [$controller] = $this->makeController();

        $request = new Request(
            [],
            ['name' => 'Acme', 'email' => 'acme@example.com'],
            ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/admin/brands/5/update']
        );
        $request->setRouteParams(['id' => '5']);

        $controller->update($request);

        $this->assertCount(1, $this->capturedExecutes);
        $settings = json_decode((string) $this->capturedExecutes[0]['settings'], true);
        $this->assertSame('en', $settings['language'] ?? null);
    }

    public function testUpdateSurvivesMalformedExistingSettings(): void
    {
        $this->db->method('fetchOne')->willReturn([
            'id' => 5,
            'name' => 'Acme',
            'slug' => 'acme',
            'email' => 'acme@example.com',
            'phone' => '',
            'timezone' => 'Asia/Dhaka',
            'default_currency' => 'BDT',
            'status' => 'active',
            'logo_path' => null,
            'settings' => 'not-json{{{',
        ]);

        [$controller] = $this->makeController();

        $request = new Request(
            [],
            ['name' => 'Acme', 'email' => 'acme@example.com', 'language' => 'bn'],
            ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/admin/brands/5/update']
        );
        $request->setRouteParams(['id' => '5']);

        $controller->update($request);

        $this->assertCount(1, $this->capturedExecutes);
        $settings = json_decode((string) $this->capturedExecutes[0]['settings'], true);
        $this->assertIsArray($settings);
        $this->assertSame('bn', $settings['language'] ?? null);
    }
}