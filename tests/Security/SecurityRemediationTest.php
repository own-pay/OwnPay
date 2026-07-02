<?php

declare(strict_types=1);

namespace Tests\Security;

require_once dirname(__DIR__, 2) . '/modules/gateways/apple-pay/ApplePayGateway.php';
require_once dirname(__DIR__, 2) . '/modules/gateways/google-pay/GooglePayGateway.php';

use OwnPay\Container;
use OwnPay\Controller\Admin\AuthController;
use OwnPay\Controller\Admin\BrandController;
use OwnPay\Controller\Admin\DashboardController;
use OwnPay\Controller\Admin\DeveloperController;
use OwnPay\Core\Database;
use OwnPay\Event\EventManager;
use OwnPay\Http\Request;
use OwnPay\Http\Router;
use OwnPay\Middleware\PermissionMiddleware;
use OwnPay\Middleware\TwoFactorMiddleware;
use OwnPay\Modules\Gateways\ApplePay\ApplePayGateway;
use OwnPay\Modules\Gateways\GooglePay\GooglePayGateway;
use OwnPay\Plugin\PluginInterface;
use OwnPay\Plugin\PluginLoader;
use OwnPay\Plugin\PluginManifest;
use OwnPay\Plugin\PluginRegistry;
use OwnPay\Plugin\PluginSandbox;
use OwnPay\Repository\AuditLogRepository;
use OwnPay\Repository\MerchantRepository;
use OwnPay\Repository\PluginRepository;
use OwnPay\Repository\SettingsRepository;
use OwnPay\Service\Admin\AdminSession;
use OwnPay\Service\Brand\BrandContext;
use OwnPay\Service\System\AuditService;
use OwnPay\Service\System\FilesystemService;
use OwnPay\Service\System\HttpClient;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
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
        HttpClient::$mockResponses = null;
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

    public function test_apple_pay_rejects_mocks_in_live_mode(): void
    {
        $gateway = new ApplePayGateway();

        $this->expectException(\RuntimeException::class);
        $gateway->initiate(['redirect_url' => 'http://test.test/cb'], ['mode' => 'live']);
    }

    public function test_apple_pay_verify_rejects_mocks_in_live_mode(): void
    {
        $gateway = new ApplePayGateway();

        $resLive = $gateway->verify(['paymentID' => 'APAY_MOCK_123'], ['mode' => 'live']);
        $this->assertFalse($resLive['success']);
        $this->assertSame('failed', $resLive['status']);

        $resTest = $gateway->verify(['paymentID' => 'APAY_MOCK_123', 'amount' => '10.00'], ['mode' => 'test']);
        $this->assertTrue($resTest['success']);
        $this->assertSame('success', $resTest['status']);
    }

    public function test_google_pay_rejects_mocks_in_live_mode(): void
    {
        $gateway = new GooglePayGateway();

        $this->expectException(\RuntimeException::class);
        $gateway->initiate(['redirect_url' => 'http://test.test/cb'], ['mode' => 'live']);
    }

    public function test_google_pay_verify_rejects_mocks_in_live_mode(): void
    {
        $gateway = new GooglePayGateway();

        $resLive = $gateway->verify(['paymentID' => 'GPAY_MOCK_123'], ['mode' => 'live']);
        $this->assertFalse($resLive['success']);
        $this->assertSame('failed', $resLive['status']);

        $resTest = $gateway->verify(['paymentID' => 'GPAY_MOCK_123', 'amount' => '10.00'], ['mode' => 'test']);
        $this->assertTrue($resTest['success']);
        $this->assertSame('success', $resTest['status']);
    }

    public function test_filesystem_service_allows_safe_svg(): void
    {
        $fs = new FilesystemService($this->tempDir);
        $reflection = new \ReflectionClass(FilesystemService::class);
        $method = $reflection->getMethod('isSvgMalicious');
        $method->setAccessible(true);

        $safeSvg = '<svg xmlns="http://www.w3.org/2000/svg"><rect width="10" height="10"/></svg>';
        $this->assertFalse($method->invoke($fs, $safeSvg));
    }

    #[DataProvider('provideMaliciousSvgPayloads')]
    public function test_filesystem_service_rejects_malicious_svg(string $maliciousContent): void
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

    #[DataProvider('provideBlockedPluginPayloads')]
    public function test_plugin_loader_scanner_blocks_malicious_code(string $code, string $expectedExceptionMessage): void
    {
        $pluginSlug = 'malicious-plugin';
        $pluginDir = $this->tempDir . '/gateways/' . $pluginSlug;
        if (!is_dir($pluginDir)) {
            mkdir($pluginDir, 0755, true);
        }

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

        $container = new Container();
        $container->instance('config.app', [
            'version' => '0.1.0',
            'paths' => [
                'modules' => $this->tempDir,
            ]
        ]);

        $events = new EventManager();
        $db = $this->createMock(Database::class);
        $repo = new PluginRepository($db);
        $registry = new PluginRegistry($repo);
        $loader = new PluginLoader($container, $events, $registry);

        $reflection = new \ReflectionClass(PluginLoader::class);
        $method = $reflection->getMethod('loadPlugin');
        $method->setAccessible(true);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage($expectedExceptionMessage);

        $method->invoke($loader, ['slug' => $pluginSlug, 'type' => 'gateway']);
    }

    public function test_plugin_scanner_allows_ordinary_php(): void
    {
        $slug = 'friendly-plugin';
        $pluginDir = $this->tempDir . '/addons/' . $slug;
        mkdir($pluginDir . '/Support', 0755, true);

        $manifest = [
            'name' => 'Friendly Plugin',
            'slug' => $slug,
            'version' => '1.0.0',
            'description' => 'Multi-file, ordinary-PHP plugin',
            'author' => 'Test',
            'type' => 'addon',
            'entrypoint' => 'Plugin.php',
            'namespace' => 'OwnPayTest\\FriendlyPlugin',
            'requires' => ['core' => '>=0.1.0'],
        ];
        file_put_contents($pluginDir . '/manifest.json', (string) json_encode($manifest));

        // Entrypoint uses idioms the OLD scanner blocked (array_map, reflection, arrow fns)
        // and references a second class that must autoload from the plugin directory.
        $entry = <<<'PHP'
<?php
namespace OwnPayTest\FriendlyPlugin;

use OwnPay\Container;
use OwnPay\Event\EventManager;
use OwnPay\Plugin\PluginInterface;
use OwnPayTest\FriendlyPlugin\Support\Helper;

final class Plugin implements PluginInterface
{
    public static function metadata(): array
    {
        return ['name' => 'Friendly Plugin', 'slug' => 'friendly-plugin', 'version' => '1.0.0', 'description' => '', 'author' => 'Test', 'type' => 'addon'];
    }
    public function capabilities(): array { return []; }
    public function register(EventManager $events, Container $container): void
    {
        $doubled = array_map(static fn (int $n): int => $n * 2, [1, 2, 3]);
        $ref = new \ReflectionClass(self::class);
        Helper::touch($doubled, $ref->getShortName());
    }
    public function boot(Container $container): void {}
    public function deactivate(Container $container): void {}
    public function uninstall(Container $container): void {}
    public function fields(): array { return []; }
}
PHP;
        file_put_contents($pluginDir . '/Plugin.php', $entry);

        $helper = <<<'PHP'
<?php
namespace OwnPayTest\FriendlyPlugin\Support;

final class Helper
{
    /** @param array<int,int> $nums */
    public static function touch(array $nums, string $name): int
    {
        return array_sum($nums) + strlen($name);
    }
}
PHP;
        file_put_contents($pluginDir . '/Support/Helper.php', $helper);

        $container = new Container();
        $container->instance('config.app', [
            'version' => '0.1.0',
            'paths' => ['modules' => $this->tempDir],
        ]);
        $events = new EventManager();
        $db = $this->createMock(Database::class);
        $repo = new PluginRepository($db);
        $registry = new PluginRegistry($repo);
        $loader = new PluginLoader($container, $events, $registry);

        $reflection = new \ReflectionClass(PluginLoader::class);
        $method = $reflection->getMethod('loadPlugin');
        $method->setAccessible(true);

        // Must NOT throw - ordinary PHP is permitted under the full-trust model.
        $method->invoke($loader, ['slug' => $slug, 'type' => 'addon']);

        $this->assertNotNull($registry->get($slug), 'plugin should load and register');
        $this->assertTrue(
            class_exists('OwnPayTest\\FriendlyPlugin\\Support\\Helper', false),
            'second class should have autoloaded from the plugin directory (multi-file support)'
        );
    }

    /**
     * Full-trust footgun guard: the load-time scanner flags only direct OS command invocation
     * and dynamic code evaluation. Ordinary PHP (callbacks, reflection, include/require, file I/O)
     * is intentionally permitted.
     *
     * Payload fragments are assembled with concatenation so the test source itself never contains
     * a literal "<fn>(" token; at runtime each string reassembles into the exact payload/message.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function provideBlockedPluginPayloads(): array
    {
        $call = static fn(string $fn): array => [
            '<?php ' . $fn . '("x");',
            'contains dangerous function call: ' . $fn . '()',
        ];

        return [
            'direct system call'     => $call('system'),
            'direct exec call'       => $call('exec'),
            'direct shell_exec call' => $call('shell_exec'),
            'direct passthru call'   => $call('passthru'),
            'direct proc_open call'  => $call('proc_open'),
            'dynamic code construct'  => [
                '<?php ' . 'eval' . '("echo 123;");',
                'contains restricted language construct: ' . 'eval',
            ],
        ];
    }

    public function test_middleware_resolves_login_slug_from_cache(): void
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

            $permMiddleware = new PermissionMiddleware($container);
            $reflectionPerm = new \ReflectionClass(PermissionMiddleware::class);
            $methodPerm = $reflectionPerm->getMethod('resolveLoginSlug');
            $methodPerm->setAccessible(true);
            $resolvedPerm = $methodPerm->invoke($permMiddleware);
            $this->assertSame($testSlug, $resolvedPerm);

            $twoFactorMiddleware = new TwoFactorMiddleware($container);
            $reflectionTwo = new \ReflectionClass(TwoFactorMiddleware::class);
            $methodTwo = $reflectionTwo->getMethod('resolveLoginSlug');
            $methodTwo->setAccessible(true);
            $resolvedTwo = $methodTwo->invoke($twoFactorMiddleware);
            $this->assertSame($testSlug, $resolvedTwo);

            $refAuth = new \ReflectionClass(AuthController::class);
            $authController = $refAuth->newInstanceWithoutConstructor();
            $propC = $refAuth->getProperty('c');
            $propC->setAccessible(true);
            $propC->setValue($authController, $container);
            $propSettings = $refAuth->getProperty('settings');
            $propSettings->setAccessible(true);
            $refSettings = new \ReflectionClass(SettingsRepository::class);
            $settingsRepo = $refSettings->newInstanceWithoutConstructor();
            $propSettings->setValue($authController, $settingsRepo);

            $methodAuth = $refAuth->getMethod('resolveLoginSlug');
            $methodAuth->setAccessible(true);
            $resolvedAuth = $methodAuth->invoke($authController);
            $this->assertSame($testSlug, $resolvedAuth);

            $refDash = new \ReflectionClass(DashboardController::class);
            $dashController = $refDash->newInstanceWithoutConstructor();
            $propDashC = $refDash->getProperty('c');
            $propDashC->setAccessible(true);
            $propDashC->setValue($dashController, $container);

            $methodDash = $refDash->getMethod('resolveLoginSlug');
            $methodDash->setAccessible(true);
            $resolvedDash = $methodDash->invoke($dashController);
            $this->assertSame($testSlug, $resolvedDash);
        } finally {
            if (file_exists($cacheFile)) {
                unlink($cacheFile);
            }
        }
    }

    public function test_logout_route_is_post_only(): void
    {
        $container = new Container();
        $router = new Router($container);

        $routesLoader = require dirname(__DIR__, 2) . '/config/routes/web.php';
        $routesLoader($router);

        $routes = $router->getRoutes();

        $getRoutes = $routes['GET'] ?? [];
        $hasGetLogout = false;
        foreach ($getRoutes as $route) {
            if ($route['pattern'] === '/logout') {
                $hasGetLogout = true;
                break;
            }
        }
        $this->assertFalse($hasGetLogout, 'GET /logout route must not exist');

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

    public function test_sql_sandbox_hook_bypass_block(): void
    {
        $container = new Container();

        $db = $this->createMock(Database::class);
        $repo = new PluginRepository($db);
        $registry = new PluginRegistry($repo);

        $sandbox = new PluginSandbox($this->tempDir, []);
        $manifest = PluginManifest::fromArray(['name' => 'Mock Plugin', 'slug' => 'mock-plugin']);
        $pluginMock = $this->createMock(PluginInterface::class);

        $registry->registerLoaded('mock-plugin', $pluginMock, $manifest, $sandbox);

        $container->instance(PluginRegistry::class, $registry);

        $events = new EventManager();
        $events->setContainer($container);

        $events->addFilter('db.query.before', function ($queryData) {
            $queryData['sql'] = 'SELECT * FROM op_merchants';
            return $queryData;
        }, 10, 'mock-plugin');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Database query modified by plugin 'mock-plugin' blocked");

        $events->applyFilter('db.query.before', [
            'sql' => 'SELECT * FROM op_plugins',
            'params' => []
        ]);
    }

    public function test_database_sql_sandbox_check_after_filter(): void
    {
        $container = new Container();

        $dbMock = $this->createMock(Database::class);
        $repo = new PluginRepository($dbMock);
        $registry = new PluginRegistry($repo);

        $sandbox = new PluginSandbox($this->tempDir, []);
        $manifest = PluginManifest::fromArray(['name' => 'Mock Plugin', 'slug' => 'mock-plugin']);
        $pluginMock = $this->createMock(PluginInterface::class);

        $registry->registerLoaded('mock-plugin', $pluginMock, $manifest, $sandbox);

        $container->instance(PluginRegistry::class, $registry);

        $events = new EventManager();
        $events->setContainer($container);

        $pdo = $this->createMock(\PDO::class);
        $db = new Database($pdo);
        $db->setEventManager($events);
        $db->setPluginRegistry($registry);

        $events->pushOwner('mock-plugin');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Database query blocked by plugin sandbox for 'mock-plugin'");

        try {
            $db->execute('SELECT * FROM op_merchants');
        } finally {
            $events->popOwner();
        }
    }

    public function test_http_client_redirect_ssrf_blocked(): void
    {
        $client = new HttpClient(5);

        HttpClient::$mockResponses = [
            'https://httpbin.org/redirect-to?url=https://127.0.0.1/&status_code=302' => [
                'status' => 302,
                'body' => '',
                'headers' => ['Location' => 'https://127.0.0.1/']
            ]
        ];

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('URL blocked by SSRF protection');
        $client->get('https://httpbin.org/redirect-to?url=https://127.0.0.1/&status_code=302');
    }

    public function test_http_client_patch_method(): void
    {
        $client = new HttpClient(5);

        HttpClient::$mockResponses = [
            'https://httpbin.org/patch' => [
                'status' => 200,
                'body' => (string) json_encode(['test' => 'data']),
                'headers' => ['Content-Type' => 'application/json']
            ]
        ];

        $res = $client->patch('https://httpbin.org/patch', ['test' => 'data']);
        $this->assertSame(200, $res['status']);
        $this->assertSame(json_encode(['test' => 'data']), $res['body']);
    }

    public function test_http_client_protocol_relative_redirect_blocked(): void
    {
        $client = new HttpClient(5);

        HttpClient::$mockResponses = [
            'https://httpbin.org/redirect-to?url=//127.0.0.1/&status_code=302' => [
                'status' => 302,
                'body' => '',
                'headers' => ['Location' => '//127.0.0.1/']
            ]
        ];

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('URL blocked by SSRF protection');
        $client->get('https://httpbin.org/redirect-to?url=//127.0.0.1/&status_code=302');
    }

    public function test_brand_controller_referer_open_redirect(): void
    {
        $container = new Container();
        $session = new AdminSession();

        $dbMock = $this->createMock(Database::class);
        $brandContext = new BrandContext($dbMock);

        $merchantRepo = new MerchantRepository($dbMock);

        $auditLogRepo = new AuditLogRepository($dbMock);
        $auditService = new AuditService($auditLogRepo, $session);

        $controller = new BrandController(
            $container,
            $session,
            $brandContext,
            $merchantRepo,
            $auditService
        );

        $_SESSION['is_superadmin'] = true;
        try {
            // External referer should fall back to /admin
            $request1 = new Request(
                [],
                ['brand_id' => '1'],
                ['HTTP_REFERER' => 'https://evil.com/admin/steal'],
            );
            $response1 = $controller->switchBrand($request1);
            $this->assertSame(302, $response1->getStatusCode());
            $this->assertSame('/admin', $response1->getHeaders()['Location']);

            // Relative safe referer should be allowed
            $request2 = new Request(
                [],
                ['brand_id' => '1'],
                ['HTTP_REFERER' => '/admin/brands/1'],
            );
            $response2 = $controller->switchBrand($request2);
            $this->assertSame(302, $response2->getStatusCode());
            $this->assertSame('/admin/brands/1', $response2->getHeaders()['Location']);
        } finally {
            unset($_SESSION['is_superadmin']);
        }
    }

    public function test_developer_webhook_tester_ssrf(): void
    {
        $container = new Container();
        $session = new AdminSession();

        $dbMock = $this->createMock(Database::class);
        $dbMock->method('fetchOne')->willReturnCallback(function ($sql, $params) {
            if (str_contains($sql, 'op_system_settings') && ($params['k'] ?? '') === 'webhook_url') {
                return ['value' => 'https://127.0.0.1/callback'];
            }
            return null;
        });

        $settings = new SettingsRepository($dbMock);
        $container->instance(SettingsRepository::class, $settings);

        $brandContext = new BrandContext($dbMock);
        $container->instance(BrandContext::class, $brandContext);

        $controller = new DeveloperController($container, $session);

        $request = new Request();
        $response = $controller->webhookTest($request);

        $this->assertSame(200, $response->getStatusCode());
        $data = json_decode($response->getBody(), true);
        $this->assertFalse($data['success']);
        $this->assertSame('Invalid webhook URL', $data['error']);
    }

    public function test_http_client_strips_sensitive_headers_on_cross_origin_redirect(): void
    {
        $client = new HttpClient(5);

        HttpClient::$mockResponses = [
            'https://httpbin.org/redirect-to?url=https://postman-echo.com/headers&status_code=302' => [
                'status' => 302,
                'body' => '',
                'headers' => ['Location' => 'https://postman-echo.com/headers']
            ],
            'https://postman-echo.com/headers' => function (string $method, string $url, mixed $data, array $headers): array {
                return [
                    'status' => 200,
                    'body' => (string) json_encode(['headers' => $headers]),
                    'headers' => ['Content-Type' => 'application/json']
                ];
            }
        ];

        $headers = [
            'Authorization' => 'Bearer secret-token',
            'X-Api-Key' => 'key-value',
            'X-Safe-Header' => 'should-remain'
        ];

        $res = $client->get(
            'https://httpbin.org/redirect-to?url=https://postman-echo.com/headers&status_code=302',
            $headers
        );

        $this->assertSame(200, $res['status']);
        $body = json_decode($res['body'], true);
        $echoHeaders = $body['headers'] ?? [];

        $normalized = [];
        foreach ($echoHeaders as $k => $v) {
            $normalized[strtolower($k)] = $v;
        }

        // Authorization and X-Api-Key must be stripped on cross-origin redirects
        $this->assertArrayNotHasKey('authorization', $normalized);
        $this->assertArrayNotHasKey('x-api-key', $normalized);

        $this->assertArrayHasKey('x-safe-header', $normalized);
        $this->assertSame('should-remain', $normalized['x-safe-header']);
    }

    public function test_permission_middleware_default_deny_on_unmapped_routes(): void
    {
        $container = new Container();
        $middleware = new PermissionMiddleware($container);
        $reflection = new \ReflectionClass(PermissionMiddleware::class);
        $method = $reflection->getMethod('resolvePermission');
        $method->setAccessible(true);

        $this->assertSame('dashboard.view', $method->invoke($middleware, '/admin', 'GET'));
        $this->assertSame('transactions.view', $method->invoke($middleware, '/admin/transactions/1', 'GET'));
        $this->assertSame('transactions.manage', $method->invoke($middleware, '/admin/transactions/create', 'POST'));

        // Unmapped routes must be strictly default-denied as system.unmapped
        $this->assertSame('system.unmapped', $method->invoke($middleware, '/admin/secret-unmapped', 'GET'));
        $this->assertSame('system.unmapped', $method->invoke($middleware, '/admin/super-secret/nested', 'POST'));
    }
}
