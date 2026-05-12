<?php
declare(strict_types=1);

namespace OwnPay\Middleware;

use OwnPay\Container;
use OwnPay\Http\Request;
use OwnPay\Http\Response;

/**
 * Session middleware â€” configures and starts PHP session.
 *
 * Per OWASP session management:
 *  - Strict mode, httponly, secure, samesite
 *  - Regenerate ID after login (done in Authenticator)
 *  - Cookie params from config
 */
final class SessionMiddleware
{
    private Container $container;

    public function __construct(Container $container)
    {
        $this->container = $container;
    }

    public function handle(Request $request, callable $next): Response
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return $next($request);
        }

        $config = $this->container->get('config.app');
        $secure = $request->isSecure();

        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.cookie_httponly', '1');

        session_set_cookie_params([
            'lifetime' => (int) ($config['session']['lifetime'] ?? 7200),
            'path'     => '/',
            'domain'   => '',
            'secure'   => $secure,
            'httponly'  => true,
            'samesite'  => 'Lax',
        ]);

        session_name('op_session');
        session_start();

        // Regenerate stale session ID (every 15 minutes)
        if (!isset($_SESSION['_last_regen'])) {
            $_SESSION['_last_regen'] = time();
        } elseif (time() - $_SESSION['_last_regen'] > 900) {
            session_regenerate_id(true);
            $_SESSION['_last_regen'] = time();
        }

        return $next($request);
    }
}
