<?php
declare(strict_types=1);

namespace OwnPay\Middleware;

use OwnPay\Container;
use OwnPay\Http\Request;
use OwnPay\Http\Response;

/**
 * Security headers middleware â€” adds all hardening headers.
 *
 * Per OWASP configuration & headers rules.
 */
final class SecurityHeadersMiddleware
{
    private Container $container;

    public function __construct(Container $container)
    {
        $this->container = $container;
    }

    public function handle(Request $request, callable $next): Response
    {
        $response = $next($request);

        $response->withHeader('X-Content-Type-Options', 'nosniff');
        $response->withHeader('X-Frame-Options', 'DENY');
        $response->withHeader('X-XSS-Protection', '0'); // Modern browsers: CSP replaces this
        $response->withHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->withHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=()');

        // HSTS â€” only on HTTPS
        if ($request->isSecure()) {
            $response->withHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        // CSP â€” strict policy, report-only in debug mode
        $debug = $this->container->get('config.app')['debug'] ?? false;
        $cspHeader = $debug ? 'Content-Security-Policy-Report-Only' : 'Content-Security-Policy';
        $csp = implode('; ', [
            "default-src 'self'",
            "script-src 'self'",
            "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com",
            "font-src 'self' https://fonts.gstatic.com",
            "img-src 'self' data: https:",
            "connect-src 'self'",
            "frame-ancestors 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "report-uri /csp-report",
        ]);
        $response->withHeader($cspHeader, $csp);

        return $response;
    }
}
