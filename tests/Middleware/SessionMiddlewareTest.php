<?php
declare(strict_types=1);

namespace Tests\Middleware;

use OwnPay\Container;
use OwnPay\Http\Request;
use OwnPay\Middleware\SessionMiddleware;
use PHPUnit\Framework\TestCase;

final class SessionMiddlewareTest extends TestCase
{
    private Container $container;
    private array $sessionBackup = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->container = new Container();
        $this->sessionBackup = $_SESSION ?? [];
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = $this->sessionBackup;
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        parent::tearDown();
    }

    public function testEnsureStartedAppliesSameSiteFromConfig(): void
    {
        // Close any active session to allow setting cookie parameters
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        $this->container->instance('config.app', [
            'session' => [
                'lifetime' => 3600,
                'samesite' => 'Strict'
            ]
        ]);

        $request = new Request([], [], ['HTTPS' => 'on']);

        // Suppress "headers already sent" warning in CLI
        @SessionMiddleware::ensureStarted($this->container, $request);

        $params = session_get_cookie_params();
        $this->assertSame(3600, $params['lifetime']);
        $this->assertSame('Strict', $params['samesite']);
        $this->assertTrue($params['secure']);
    }

    public function testEnsureStartedFallsBackToLaxSameSite(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        $this->container->instance('config.app', [
            'session' => [
                'lifetime' => 1800,
                'samesite' => 'invalid-option'
            ]
        ]);

        $request = new Request([], [], ['HTTPS' => 'off']);

        @SessionMiddleware::ensureStarted($this->container, $request);

        $params = session_get_cookie_params();
        $this->assertSame(1800, $params['lifetime']);
        $this->assertSame('Lax', $params['samesite']);
        $this->assertFalse($params['secure']);
    }
}
