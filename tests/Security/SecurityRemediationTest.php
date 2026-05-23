<?php

declare(strict_types=1);

namespace Tests\Security;

// Manually load the gateways since they are not in the main composer PSR-4 autoload mapping
require_once dirname(__DIR__, 2) . '/modules/gateways/apple-pay/ApplePayGateway.php';
require_once dirname(__DIR__, 2) . '/modules/gateways/google-pay/GooglePayGateway.php';

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use OwnPay\Modules\Gateways\ApplePay\ApplePayGateway;
use OwnPay\Modules\Gateways\GooglePay\GooglePayGateway;
use OwnPay\Service\System\FilesystemService;
use OwnPay\Plugin\PluginLoader;
use OwnPay\Plugin\PluginRegistry;
use OwnPay\Event\EventManager;
use OwnPay\Container;
use OwnPay\Http\Router;
use OwnPay\Middleware\PermissionMiddleware;
use OwnPay\Middleware\TwoFactorMiddleware;

/**
 * Integration and unit tests validating all 5 security remediations from audit_report.md.
 */
final class SecurityRemediationTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/ownpay_test_remediations_' . bin2hex(random_bytes(4));
        if (!is_dir($this->tempDir)) {
            mkdir($this->tempDir, 0755, true);
        }
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tempDir)) {
            $this->removeDirectory($this->tempDir);
        }
        parent::tearDown();
    }

    private function removeDirectory(string $dir): void
    {
        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 1. Apple Pay / Google Pay live mode mock rejection
    // ─────────────────────────────────────────────────────────────────────────

    public function testApplePayRejectsMocksInLiveMode(): void
    {
        $gateway = new ApplePayGateway();

        // Live mode initiate should throw RuntimeException
        $this->expectException(\RuntimeException::class);
        $gateway->initiate(['redirect_url' => 'http://test.test/cb'], ['mode' => 'live']);
    }

    public function testApplePayVerifyRejectsMocksInLiveMode(): void
    {
        $gateway = new ApplePayGateway();

        // Live mode verify should fail
        $resLive = $gateway->verify(['paymentID' => 'APAY_MOCK_123'], ['mode' => 'live']);
        $this->assertFalse($resLive['success']);
        $this->assertSame('failed', $resLive['status']);

        // Test mode verify should succeed
        $resTest = $gateway->verify(['paymentID' => 'APAY_MOCK_123', 'amount' => '10.00'], ['mode' => 'test']);
        $this->assertTrue($resTest['success']);
        $this->assertSame('success', $resTest['status']);
    }

    public function testGooglePayRejectsMocksInLiveMode(): void
    {
        $gateway = new GooglePayGateway();

        // Live mode initiate should throw RuntimeException
        $this->expectException(\RuntimeException::class);
        $gateway->initiate(['redirect_url' => 'http://test.test/cb'], ['mode' => 'live']);
    }

    public function testGooglePayVerifyRejectsMocksInLiveMode(): void
    {
        $gateway = new GooglePayGateway();

        // Live mode verify should fail
        $resLive = $gateway->verify(['paymentID' => 'GPAY_MOCK_123'], ['mode' => 'live']);
        $this->assertFalse($resLive['success']);
        $this->assertSame('failed', $resLive['status']);

        // Test mode verify should succeed
        $resTest = $gateway->verify(['paymentID' => 'GPAY_MOCK_123', 'amount' => '10.00'], ['mode' => 'test']);
        $this->assertTrue($resTest['success']);
        $this->assertSame('success', $resTest['status']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 2. SVG Upload Sanitization
    // ─────────────────────────────────────────────────────────────────────────

    public function testFilesystemServiceAllowsSafeSvg(): void
    {
        $fs = new FilesystemService($this->tempDir);
        $reflection = new \ReflectionClass(FilesystemService::class);
        $method = $reflection->getMethod('isSvgMalicious');
        $method->setAccessible(true);

        $safeSvg = '<svg xmlns="http://www.w3.org/2000/svg"><rect width="10" height="10"/></svg>';
        $this->assertFalse($method->invoke($fs, $safeSvg));
    }

    #[DataProvider('provideMaliciousSvgPayloads')]
    public function testFilesystemServiceRejectsMaliciousSvg(string $maliciousContent): void
    {
        $fs = new FilesystemService($this->tempDir);
        $reflection = new \ReflectionClass(FilesystemService::class);
        $method = $reflection->getMethod('isSvgMalicious');
        $method->setAccessible(true);

        $this->assertTrue($method->invoke($fs, $maliciousContent), "SVG should be flagged as malicious: " . $maliciousContent);
    }

    public static function provideMaliciousSvgPayloads(): array
    {
        return [
            'script tag' => ['<svg><script>alert(1)</script></svg>'],
            'script tag uppercase' => ['<svg><SCRIPT>alert(1)</SCRIPT></svg>'],
            'onload attribute' => ['<svg onload="alert(1)"></svg>'],
            'onload attribute with whitespace' => ['<svg onload   =   "alert(1)"></svg>'],
            'onclick attribute' => ['<svg onclick="alert(1)"></svg>'],
            'javascript URI' => ['<svg href="javascript:alert(1)"></svg>'],
            'XML entity definition' => ['<?xml version="1.0"?><!DOCTYPE svg [<!ENTITY xxe SYSTEM "file:///etc/passwd">]><svg>&xxe;</svg>'],
            'DOCTYPE declaration' => ['<!DOCTYPE svg PUBLIC "-//W3C//DTD SVG 1.1//EN" "http://www.w3.org/Graphics/SVG/1.1/DTD/svg11.dtd"><svg></svg>'],
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 3. Plugin Scanner Banned Expressions & Dynamic Code Blocks
    // ─────────────────────────────────────────────────────────────────────────

    #[DataProvider('provideBlockedPluginPayloads')]
    public function testPluginLoaderScannerBlocksMaliciousCode(string $code, string $expectedExceptionMessage): void
    {
        $pluginSlug = 'malicious-plugin';
        $pluginDir = $this->tempDir . '/gateways/' . $pluginSlug;
        if (!is_dir($pluginDir)) {
            mkdir($pluginDir, 0755, true);
        }

        // Write manifest
        $manifest = [
            'name' => 'Malicious Plugin',
            'slug' => $pluginSlug,
            'version' => '1.0.0',
            'description' => 'Test',
            'author' => 'Test',
            'type' => 'gateway',
            'category' => 'express',
            'entrypoint' => 'entrypoint.php',
            'requires' => [
                'core' => '^0.1.0'
            ]
        ];
        file_put_contents($pluginDir . '/manifest.json', json_encode($manifest));
        file_put_contents($pluginDir . '/entrypoint.php', $code);

        // Setup container with our test path
        $container = new Container();
        $container->instance('config.app', [
            'version' => '0.1.0',
            'paths' => [
                'modules' => $this->tempDir,
            ]
        ]);

        $events = new EventManager();
        $db = $this->createMock(\OwnPay\Core\Database::class);
        $repo = new \OwnPay\Repository\PluginRepository($db);
        $registry = new PluginRegistry($repo);
        $loader = new PluginLoader($container, $events, $registry);

        // Call the private loadPlugin method
        $reflection = new \ReflectionClass(PluginLoader::class);
        $method = $reflection->getMethod('loadPlugin');
        $method->setAccessible(true);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage($expectedExceptionMessage);

        $method->invoke($loader, ['slug' => $pluginSlug, 'type' => 'gateway']);
    }

    public static function provideBlockedPluginPayloads(): array
    {
        return [
            'direct system call' => [
                '<?php system("id");',
                'contains dangerous function call: system()'
            ],
            'variable function call' => [
                '<?php $f = "sys" . "tem"; $f("id");',
                'contains dynamic/variable function call: $f()'
            ],
            'variable function call with whitespace' => [
                '<?php $f = "sys" . "tem"; $f   ("id");',
                'contains dynamic/variable function call: $f()'
            ],
            'wrapped variable function call' => [
                '<?php $f = "sys" . "tem"; ($f)("id");',
                'contains dynamic/variable function call.'
            ],
            'wrapped variable function call with whitespace' => [
                '<?php $f = "sys" . "tem"; (  $f  )("id");',
                'contains dynamic/variable function call.'
            ],
            'dynamic class instantiation' => [
                '<?php $c = "ReflectionClass"; $obj = new $c("OwnPay\Container");',
                'contains dynamic class instantiation: new $c'
            ],
            'callback wrapper call_user_func' => [
                '<?php call_user_func("system", "id");',
                'contains dangerous function call: call_user_func()'
            ],
            'callback wrapper array_map' => [
                '<?php array_map("system", ["id"]);',
                'contains dangerous function call: array_map()'
            ],
            'ReflectionClass usage' => [
                '<?php $ref = new \ReflectionClass("OwnPay\Container");',
                'contains restricted reference: \ReflectionClass'
            ],
            'PDO usage' => [
                '<?php $db = new \PDO("sqlite::memory:");',
                'contains restricted reference: \PDO'
            ],
            'mysqli usage' => [
                '<?php $db = new \mysqli();',
                'contains restricted reference: \mysqli'
            ],
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 4. Login Slug Caching
    // ─────────────────────────────────────────────────────────────────────────

    public function testMiddlewareResolvesLoginSlugFromCache(): void
    {
        $cacheDir = dirname(__DIR__, 2) . '/storage/cache';
        if (!is_dir($cacheDir)) {
            mkdir($cacheDir, 0755, true);
        }
        $cacheFile = $cacheDir . '/login_slug.cache';
        
        $testSlug = 'secure-portal-' . bin2hex(random_bytes(4));
        file_put_contents($cacheFile, $testSlug);

        try {
            $container = new Container();
            
            // Test PermissionMiddleware
            $permMiddleware = new PermissionMiddleware($container);
            $reflectionPerm = new \ReflectionClass(PermissionMiddleware::class);
            $methodPerm = $reflectionPerm->getMethod('resolveLoginSlug');
            $methodPerm->setAccessible(true);
            $resolvedPerm = $methodPerm->invoke($permMiddleware);
            $this->assertSame($testSlug, $resolvedPerm);

            // Test TwoFactorMiddleware
            $twoFactorMiddleware = new TwoFactorMiddleware($container);
            $reflectionTwo = new \ReflectionClass(TwoFactorMiddleware::class);
            $methodTwo = $reflectionTwo->getMethod('resolveLoginSlug');
            $methodTwo->setAccessible(true);
            $resolvedTwo = $methodTwo->invoke($twoFactorMiddleware);
            $this->assertSame($testSlug, $resolvedTwo);
        } finally {
            if (file_exists($cacheFile)) {
                unlink($cacheFile);
            }
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 5. GET logout route removal
    // ─────────────────────────────────────────────────────────────────────────

    public function testLogoutRouteIsPostOnly(): void
    {
        $container = new Container();
        $router = new Router($container);

        $routesLoader = require dirname(__DIR__, 2) . '/config/routes/web.php';
        $routesLoader($router);

        $routes = $router->getRoutes();

        // Ensure GET /logout does not exist
        $getRoutes = $routes['GET'] ?? [];
        $hasGetLogout = false;
        foreach ($getRoutes as $route) {
            if ($route['pattern'] === '/logout') {
                $hasGetLogout = true;
                break;
            }
        }
        $this->assertFalse($hasGetLogout, 'GET /logout route must not exist');

        // Ensure POST /logout exists
        $postRoutes = $routes['POST'] ?? [];
        $hasPostLogout = false;
        foreach ($postRoutes as $route) {
            if ($route['pattern'] === '/logout') {
                $hasPostLogout = true;
                break;
            }
        }
        $this->assertTrue($hasPostLogout, 'POST /logout route must exist');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 6. SQL Sandbox Hook Bypass & Filter Check
    // ─────────────────────────────────────────────────────────────────────────

    public function testSqlSandboxHookBypassBlock(): void
    {
        $container = new Container();
        
        $db = $this->createMock(\OwnPay\Core\Database::class);
        $repo = new \OwnPay\Repository\PluginRepository($db);
        $registry = new PluginRegistry($repo);
        
        // Register sandbox for mock-plugin
        $sandbox = new \OwnPay\Plugin\PluginSandbox($this->tempDir, []);
        $manifest = \OwnPay\Plugin\PluginManifest::fromArray(['name' => 'Mock Plugin', 'slug' => 'mock-plugin']);
        $pluginMock = $this->createMock(\OwnPay\Plugin\PluginInterface::class);
        
        $registry->registerLoaded('mock-plugin', $pluginMock, $manifest, $sandbox);
        
        $container->instance(PluginRegistry::class, $registry);
        
        $events = new EventManager();
        $events->setContainer($container);
        
        // Add filter hook under mock-plugin owner
        $events->addFilter('db.query.before', function ($queryData) {
            $queryData['sql'] = 'SELECT * FROM op_merchants'; // restricted table
            return $queryData;
        }, 10, 'mock-plugin');
        
        // We expect a RuntimeException from EventManager because the modified SQL is unsafe
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Database query modified by plugin 'mock-plugin' blocked");
        
        $events->applyFilter('db.query.before', [
            'sql' => 'SELECT * FROM op_plugins',
            'params' => []
        ]);
    }

    public function testDatabaseSqlSandboxCheckAfterFilter(): void
    {
        $container = new Container();
        
        $dbMock = $this->createMock(\OwnPay\Core\Database::class);
        $repo = new \OwnPay\Repository\PluginRepository($dbMock);
        $registry = new PluginRegistry($repo);
        
        // Register sandbox for mock-plugin
        $sandbox = new \OwnPay\Plugin\PluginSandbox($this->tempDir, []);
        $manifest = \OwnPay\Plugin\PluginManifest::fromArray(['name' => 'Mock Plugin', 'slug' => 'mock-plugin']);
        $pluginMock = $this->createMock(\OwnPay\Plugin\PluginInterface::class);
        
        $registry->registerLoaded('mock-plugin', $pluginMock, $manifest, $sandbox);
        
        $container->instance(PluginRegistry::class, $registry);
        
        $events = new EventManager();
        $events->setContainer($container);
        
        // Set up connection details for a mock PDO
        $pdo = $this->createMock(\PDO::class);
        $db = new \OwnPay\Core\Database($pdo);
        $db->setEventManager($events);
        $db->setPluginRegistry($registry);
        
        // Put a plugin on the owner stack
        $events->pushOwner('mock-plugin');
        
        // Execute the query directly. Since the active owner is 'mock-plugin' and the SQL is unsafe,
        // it must throw RuntimeException.
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Database query blocked by plugin sandbox for 'mock-plugin'");
        
        try {
            $db->execute('SELECT * FROM op_merchants');
        } finally {
            $events->popOwner();
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 7. HttpClient Redirect SSRF Block
    // ─────────────────────────────────────────────────────────────────────────

    public function testHttpClientRedirectSsrfBlocked(): void
    {
        $client = new \OwnPay\Service\System\HttpClient(5);
        
        // Use httpbin.org to redirect to a local IP
        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('URL blocked by SSRF protection');
            $client->get('https://httpbin.org/redirect-to?url=https://127.0.0.1/&status_code=302');
        } catch (\RuntimeException $e) {
            if (str_contains($e->getMessage(), 'HTTP request failed')) {
                $this->markTestSkipped('Network unavailable — cannot reach httpbin.org');
            }
            throw $e;
        }
    }

    public function testHttpClientPatchMethod(): void
    {
        $client = new \OwnPay\Service\System\HttpClient(5);
        
        // Assert that the public patch method exists and executes (or fails with a network issue)
        try {
            $res = $client->patch('https://httpbin.org/patch', ['test' => 'data']);
            $this->assertSame(200, $res['status']);
        } catch (\RuntimeException $e) {
            if (str_contains($e->getMessage(), 'HTTP request failed')) {
                $this->markTestSkipped('Network unavailable — cannot reach httpbin.org');
            }
            throw $e;
        }
    }

    public function testHttpClientProtocolRelativeRedirectBlocked(): void
    {
        $client = new \OwnPay\Service\System\HttpClient(5);
        
        // Redirecting protocol-relative to a loopback address: //127.0.0.1/ should be blocked
        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('URL blocked by SSRF protection');
            $client->get('https://httpbin.org/redirect-to?url=//127.0.0.1/&status_code=302');
        } catch (\RuntimeException $e) {
            if (str_contains($e->getMessage(), 'HTTP request failed')) {
                $this->markTestSkipped('Network unavailable — cannot reach httpbin.org');
            }
            throw $e;
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 8. BrandController Referer Open Redirect Validation
    // ─────────────────────────────────────────────────────────────────────────

    public function testBrandControllerRefererOpenRedirect(): void
    {
        $container = new Container();
        $session = new \OwnPay\Service\Admin\AdminSession();
        
        $dbMock = $this->createMock(\OwnPay\Core\Database::class);
        $brandContext = new \OwnPay\Service\Brand\BrandContext($dbMock);
        
        $merchantRepo = new \OwnPay\Repository\MerchantRepository($dbMock);
        
        $auditLogRepo = new \OwnPay\Repository\AuditLogRepository($dbMock);
        $auditService = new \OwnPay\Service\System\AuditService($auditLogRepo, $session);
        
        $controller = new \OwnPay\Controller\Admin\BrandController(
            $container,
            $session,
            $brandContext,
            $merchantRepo,
            $auditService
        );

        $_SESSION['is_superadmin'] = true;
        try {
            // External referer should fall back to /admin
            $request1 = new \OwnPay\Http\Request(
                [], // query
                ['brand_id' => '1'], // post
                ['HTTP_REFERER' => 'https://evil.com/admin/steal'], // server
            );
            $response1 = $controller->switchBrand($request1);
            $this->assertSame(302, $response1->getStatusCode());
            $this->assertSame('/admin', $response1->getHeaders()['Location']);

            // Relative safe referer should be allowed
            $request2 = new \OwnPay\Http\Request(
                [], // query
                ['brand_id' => '1'], // post
                ['HTTP_REFERER' => '/admin/brands/1'], // server
            );
            $response2 = $controller->switchBrand($request2);
            $this->assertSame(302, $response2->getStatusCode());
            $this->assertSame('/admin/brands/1', $response2->getHeaders()['Location']);
        } finally {
            unset($_SESSION['is_superadmin']);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 9. DeveloperController Webhook Tester SSRF Validation
    // ─────────────────────────────────────────────────────────────────────────

    public function testDeveloperWebhookTesterSsrf(): void
    {
        $container = new Container();
        $session = new \OwnPay\Service\Admin\AdminSession();
        
        $dbMock = $this->createMock(\OwnPay\Core\Database::class);
        $dbMock->method('fetchOne')->willReturnCallback(function($sql, $params) {
            if (str_contains($sql, 'op_system_settings') && ($params['k'] ?? '') === 'webhook_url') {
                return ['value' => 'https://127.0.0.1/callback'];
            }
            return null;
        });
        
        $settings = new \OwnPay\Repository\SettingsRepository($dbMock);
        $container->instance(\OwnPay\Repository\SettingsRepository::class, $settings);
        
        $brandContext = new \OwnPay\Service\Brand\BrandContext($dbMock);
        $container->instance(\OwnPay\Service\Brand\BrandContext::class, $brandContext);
        
        $controller = new \OwnPay\Controller\Admin\DeveloperController($container, $session);
        
        $request = new \OwnPay\Http\Request();
        $response = $controller->webhookTest($request);
        
        $this->assertSame(200, $response->getStatusCode());
        $data = json_decode($response->getBody(), true);
        $this->assertFalse($data['success']);
        $this->assertSame('Invalid webhook URL', $data['error']);
    }
}
