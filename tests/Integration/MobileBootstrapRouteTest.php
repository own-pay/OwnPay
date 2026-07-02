<?php

declare(strict_types=1);

namespace Tests\Integration;

use OwnPay\Container;
use OwnPay\Http\Request;
use OwnPay\Http\Router;
use OwnPay\Middleware\CorsMiddleware;
use OwnPay\Middleware\JwtAuthMiddleware;
use OwnPay\Middleware\RateLimiterMiddleware;
use PHPUnit\Framework\TestCase;

/**
 * Loads REAL config files and replicates Kernel::runMiddleware() stack resolution.
 * Asserts what middleware actually executes for each route - if anyone re-points pairing
 * back under `mobile`, deletes the `mobile-bootstrap` group, or injects JWT auth into it,
 * this fails loudly. Intentionally container/DB-free.
 */
final class MobileBootstrapRouteTest extends TestCase
{
    /** Routes a token-less device MUST reach to bootstrap itself. */
    private const BOOTSTRAP_ROUTES = [
        ['POST', '/api/mobile/v1/devices'],
        ['POST', '/api/mobile/v1/devices/token-refreshes'],
    ];

    /** Authenticated mobile surface that MUST keep the JWT gate. */
    private const AUTHENTICATED_ROUTES = [
        ['POST', '/api/mobile/v1/sms'],
        ['GET', '/api/mobile/v1/dashboard'],
        ['GET', '/api/mobile/v1/notifications'],
        ['POST', '/api/mobile/v1/devices/heartbeats'],
        ['GET', '/api/mobile/v1/devices/statuses'],
        ['GET', '/api/mobile/v1/config/filter-rules'],
    ];

    public function testPairingRouteResolvesWithoutJwtMiddleware(): void
    {
        $stack = $this->resolvedStackFor('POST', '/api/mobile/v1/devices');

        self::assertNotContains(
            JwtAuthMiddleware::class,
            $stack,
            'POST /api/mobile/v1/devices (pairing) must NOT run JwtAuthMiddleware, '
            . 'or a token-less new device is 401d before it can pair.'
        );
    }

    public function testTokenRefreshRouteResolvesWithoutJwtMiddleware(): void
    {
        $stack = $this->resolvedStackFor('POST', '/api/mobile/v1/devices/token-refreshes');

        self::assertNotContains(
            JwtAuthMiddleware::class,
            $stack,
            'token-refresh must NOT run JwtAuthMiddleware - it authenticates via '
            . 'the refresh-JWT in the body, and a device with an EXPIRED access token must still refresh.'
        );
    }

    /**
     * JWT-free does not mean protection-free. The bootstrap routes still expose an
     * unauthenticated surface (OTP guessing, refresh-token spraying), so CORS and the
     * rate limiter must remain in front of them.
     */
    public function testBootstrapRoutesKeepCorsAndRateLimiting(): void
    {
        foreach (self::BOOTSTRAP_ROUTES as [$method, $path]) {
            $stack = $this->resolvedStackFor($method, $path);
            self::assertContains(
                RateLimiterMiddleware::class,
                $stack,
                "Bootstrap route {$method} {$path} must stay rate-limited (brute-force defense)."
            );
            self::assertContains(
                CorsMiddleware::class,
                $stack,
                "Bootstrap route {$method} {$path} must keep CORS handling."
            );
        }
    }

    public function testAuthenticatedMobileRoutesStillRequireJwt(): void
    {
        foreach (self::AUTHENTICATED_ROUTES as [$method, $path]) {
            $stack = $this->resolvedStackFor($method, $path);
            self::assertContains(
                JwtAuthMiddleware::class,
                $stack,
                "Authenticated route {$method} {$path} must keep JwtAuthMiddleware"
                . 'must not have opened the protected mobile surface.'
            );
        }
    }

    /**
     * Guards the group definition itself: the `mobile-bootstrap` stack as declared in
     * config must never list JWT auth, independent of which routes point at it.
     */
    public function testMobileBootstrapGroupDefinitionExcludesJwt(): void
    {
        $config = $this->middlewareConfig();

        self::assertArrayHasKey(
            'mobile-bootstrap',
            $config,
            'The mobile-bootstrap middleware group must exist; pairing/refresh routes depend on it.'
        );
        self::assertNotContains(JwtAuthMiddleware::class, $config['mobile-bootstrap']);
        self::assertContains(JwtAuthMiddleware::class, $config['mobile'] ?? []);
    }

    /**
     * @return list<string>
     */
    private function resolvedStackFor(string $method, string $path): array
    {
        $router = new Router(new Container());
        $registerRoutes = require $this->configPath('routes/api.php');
        self::assertIsCallable($registerRoutes, 'config/routes/api.php must return a callable.');
        $registerRoutes($router);

        $request = new Request([], [], [
            'REQUEST_METHOD' => $method,
            'REQUEST_URI'    => $path,
        ]);

        $match = $router->match($request);
        self::assertNotNull($match, "Route {$method} {$path} is not registered.");

        $config = $this->middlewareConfig();
        $stack = array_merge(
            $config['global'] ?? [],
            $config[$match['middleware']] ?? []
        );

        return array_values(array_unique($stack));
    }

    /**
     * @return array<string, list<string>>
     */
    private function middlewareConfig(): array
    {
        /** @var array<string, list<string>> $config */
        $config = require $this->configPath('middleware.php');
        self::assertIsArray($config, 'config/middleware.php must return an array.');

        return $config;
    }

    private function configPath(string $relative): string
    {
        return dirname(__DIR__, 2) . '/config/' . $relative;
    }
}
