<?php
declare(strict_types=1);

namespace OwnPay\Middleware;

use OwnPay\Container;
use OwnPay\Http\Request;
use OwnPay\Http\Response;

/**
 * Middleware responsible for configuring and starting PHP secure sessions.
 *
 * Implements OWASP-compliant session management: strict mode, httponly, secure, samesite cookies,
 * explicit idle timeouts, and periodic session ID regeneration to prevent session hijacking.
 */
final class SessionMiddleware
{
    /**
     * @var Container The dependency injection container.
     */
    private Container $container;

    /**
     * Constructs a new SessionMiddleware instance.
     *
     * @param Container $container The dependency injection container.
     */
    public function __construct(Container $container)
    {
        $this->container = $container;
    }

    /**
     * Shared session initialization helper.
     *
     * Ensures session is properly started with all security hardening, idle timeout,
     * and ID regeneration rules. Called by both handle() and MaintenanceMiddleware
     * to prevent logic duplication.
     *
     * @param Container $container The dependency injection container.
     * @param Request $request The request context.
     * @return void
     */
    public static function ensureStarted(Container $container, Request $request): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        $config = $container->get('config.app');
        $sessionLifetime = 7200;
        if (is_array($config) && isset($config['session']) && is_array($config['session'])) {
            if (isset($config['session']['lifetime']) && (is_int($config['session']['lifetime']) || is_string($config['session']['lifetime']) || is_numeric($config['session']['lifetime']))) {
                $sessionLifetime = (int) $config['session']['lifetime'];
            }
        }
        $secure = $request->isSecure();
        $sameSite = 'Lax';
        if (is_array($config) && isset($config['session']) && is_array($config['session'])) {
            $configuredSameSite = $config['session']['samesite'] ?? '';
            $sameSite = match ($configuredSameSite) {
                'Lax', 'lax' => 'Lax',
                'None', 'none' => 'None',
                'Strict', 'strict' => 'Strict',
                default => 'Lax',
            };
        }

        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.cookie_httponly', '1');

        session_set_cookie_params([
            'lifetime' => $sessionLifetime,
            'path'     => '/',
            'domain'   => '',
            'secure'   => $secure,
            'httponly'  => true,
            'samesite'  => $sameSite,
        ]);

        session_name('op_session');
        session_start();

        // Explicit idle timeout - prevents indefinite sessions when PHP gc_maxlifetime differs.
        if (isset($_SESSION['_last_activity']) && (is_int($_SESSION['_last_activity']) || is_numeric($_SESSION['_last_activity']))) {
            $lastActivity = (int) $_SESSION['_last_activity'];
            if (time() - $lastActivity > $sessionLifetime) {
                // Session expired - destroy and redirect to login
                $_SESSION = [];
                session_destroy();
                session_start();
                return;
            }
        }
        $_SESSION['_last_activity'] = time();

        // Regenerate stale session ID (every 15 minutes)
        if (!isset($_SESSION['_last_regen']) || (!is_int($_SESSION['_last_regen']) && !is_numeric($_SESSION['_last_regen']))) {
            $_SESSION['_last_regen'] = time();
        } else {
            $lastRegen = (int) $_SESSION['_last_regen'];
            if (time() - $lastRegen > 900) {
                session_regenerate_id(true);
                $_SESSION['_last_regen'] = time();
            }
        }
    }

    /**
     * Handles starting the PHP session for the incoming request execution pipeline.
     *
     * @param Request $request The incoming HTTP request.
     * @param callable(Request): Response $next Next handler in the pipeline.
     * @return Response The HTTP response.
     */
    public function handle(Request $request, callable $next): Response
    {
        self::ensureStarted($this->container, $request);

        return $next($request);
    }
}
