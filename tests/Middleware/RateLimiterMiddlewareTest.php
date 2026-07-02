<?php

declare(strict_types=1);

namespace Tests\Middleware;

use OwnPay\Container;
use OwnPay\Core\Database;
use OwnPay\Http\Request;
use OwnPay\Http\Response;
use OwnPay\Middleware\RateLimiterMiddleware;
use OwnPay\Repository\SettingsRepository;
use PHPUnit\Framework\TestCase;

final class RateLimiterMiddlewareTest extends TestCase
{
    private Container $container;

    protected function setUp(): void
    {
        parent::setUp();
        $this->container = new Container();
    }

    public function testIpWhitelistingBypassesRateLimit(): void
    {
        $dbMockForSettings = $this->createMock(Database::class);
        $dbMockForSettings->method('fetchOne')->willReturnCallback(function (string $sql, array $params) {
            if (isset($params['k']) && $params['k'] === 'rate_limit_whitelist_ips') {
                return ['value' => '192.168.1.5, 10.0.0.0/24, 2001:db8::/32'];
            }
            return null;
        });

        $settings = new SettingsRepository($dbMockForSettings);
        $this->container->instance(SettingsRepository::class, $settings);
        $this->container->instance('config.app', []);

        $middleware = new RateLimiterMiddleware($this->container);

        $req1 = new Request([], [], ['REMOTE_ADDR' => '192.168.1.5']);
        $response1 = $middleware->handle($req1, function (Request $r) {
            return Response::json(['status' => 'passed']);
        });
        $this->assertSame(200, $response1->getStatusCode());

        $req2 = new Request([], [], ['REMOTE_ADDR' => '10.0.0.50']);
        $response2 = $middleware->handle($req2, function (Request $r) {
            return Response::json(['status' => 'passed']);
        });
        $this->assertSame(200, $response2->getStatusCode());

        $req3 = new Request([], [], ['REMOTE_ADDR' => '2001:db8:abcd::1']);
        $response3 = $middleware->handle($req3, function (Request $r) {
            return Response::json(['status' => 'passed']);
        });
        $this->assertSame(200, $response3->getStatusCode());
    }

    public function testDynamicRulesMatching(): void
    {
        $dbMockForSettings = $this->createMock(Database::class);
        $rulesJson = json_encode([
            [
                'path' => '/api/v1/payments/*',
                'method' => 'POST',
                'limit' => 2,
                'window' => 60
            ]
        ]);
        $dbMockForSettings->method('fetchOne')->willReturnCallback(function (string $sql, array $params) use ($rulesJson) {
            if (isset($params['k']) && $params['k'] === 'rate_limit_whitelist_ips') {
                return ['value' => ''];
            }
            if (isset($params['k']) && $params['k'] === 'rate_limit_rules') {
                return ['value' => $rulesJson];
            }
            return null;
        });

        $settings = new SettingsRepository($dbMockForSettings);
        $this->container->instance(SettingsRepository::class, $settings);
        $this->container->instance('config.app', []);

        $dbMock = $this->createMock(Database::class);
        $dbMock->method('fetchColumn')->willReturnOnConsecutiveCalls(1, 2, 3);

        $this->container->instance(Database::class, $dbMock);

        $middleware = new RateLimiterMiddleware($this->container);

        $req = new Request([], [], [
            'REMOTE_ADDR' => '1.2.3.4',
            'REQUEST_URI' => '/api/v1/payments/intent_xyz',
            'REQUEST_METHOD' => 'POST',
            'HTTP_ACCEPT' => 'application/json'
        ]);

        $response1 = $middleware->handle($req, function (Request $r) {
            return Response::json(['status' => 'passed']);
        });
        $this->assertSame(200, $response1->getStatusCode());
        $this->assertSame('2', $response1->getHeaders()['X-RateLimit-Limit'] ?? null);

        $response2 = $middleware->handle($req, function (Request $r) {
            return Response::json(['status' => 'passed']);
        });
        $this->assertSame(200, $response2->getStatusCode());

        $response3 = $middleware->handle($req, function (Request $r) {
            return Response::json(['status' => 'passed']);
        });
        $this->assertSame(429, $response3->getStatusCode());
    }

    public function testHtmlViewFallbackWhenExpectsJsonIsFalse(): void
    {
        $dbMockForSettings = $this->createMock(Database::class);
        $dbMockForSettings->method('fetchOne')->willReturnCallback(function (string $sql, array $params) {
            if (isset($params['k']) && $params['k'] === 'rate_limit_whitelist_ips') {
                return ['value' => ''];
            }
            if (isset($params['k']) && $params['k'] === 'rate_limit_rules') {
                return ['value' => '[]'];
            }
            return null;
        });

        $settings = new SettingsRepository($dbMockForSettings);
        $this->container->instance(SettingsRepository::class, $settings);
        $this->container->instance('config.app', []);

        $dbMock = $this->createMock(Database::class);
        $dbMock->method('fetchColumn')->willReturn(1000);
        $this->container->instance(Database::class, $dbMock);

        $middleware = new RateLimiterMiddleware($this->container);

        $req = new Request([], [], [
            'REMOTE_ADDR' => '1.2.3.4',
            'REQUEST_URI' => '/some/web/page',
            'REQUEST_METHOD' => 'GET',
            'HTTP_ACCEPT' => 'text/html'
        ]);

        $response = $middleware->handle($req, function (Request $r) {
            return Response::json(['status' => 'passed']);
        });

        $this->assertSame(429, $response->getStatusCode());
        $this->assertStringContainsString('text/html', $response->getHeaders()['Content-Type'] ?? '');
        $this->assertStringContainsString('Too Many Requests', $response->getBody());
    }
}
