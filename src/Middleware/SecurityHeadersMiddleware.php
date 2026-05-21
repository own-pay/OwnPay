<?php
declare(strict_types=1);

namespace OwnPay\Middleware;

use OwnPay\Container;
use OwnPay\Http\Request;
use OwnPay\Http\Response;

/**
 * Security headers middleware — adds all hardening headers.
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
        // M-12 FIX: Generate per-request CSP nonce for inline styles.
        // This eliminates 'unsafe-inline' from style-src.
        $nonce = base64_encode(random_bytes(16));
        $request->setAttribute('csp_nonce', $nonce);

        // BUG-12 FIX: Store nonce in Container so Twig global can read it.
        // Without this, templates have no way to add nonce="" to inline <style> tags,
        // causing CSP to block all inline styles in production mode.
        $this->container->instance('csp_nonce', $nonce);

        $response = $next($request);

        $response->withHeader('X-Content-Type-Options', 'nosniff');
        $response->withHeader('X-Frame-Options', 'DENY');
        $response->withHeader('X-XSS-Protection', '0'); // Modern browsers: CSP replaces this
        $response->withHeader('Referrer-Policy', 'strict-origin-when-cross-origin');

        // M-13: Keep payment=() — OwnPay uses gateway redirects, not Payment Request API.
        $response->withHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=()');

        // HSTS — only on HTTPS
        if ($request->isSecure()) {
            $response->withHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        // CSP — strict policy, report-only in debug mode
        $debug = $this->container->get('config.app')['debug'] ?? false;
        $cspHeader = $debug ? 'Content-Security-Policy-Report-Only' : 'Content-Security-Policy';
        
        $path = $request->path();
        $isCheckout = str_starts_with($path, '/checkout') || str_starts_with($path, '/invoice') || str_starts_with($path, '/pay');

        if ($isCheckout) {
            $csp = implode('; ', [
                "default-src 'self' https:",
                "script-src 'self' 'nonce-{$nonce}' 'unsafe-inline' 'unsafe-eval' https://*.stripe.com https://*.sslcommerz.com https://*.bkash.com https://*.paypal.com https://*.paypalobjects.com",
                "style-src 'self' 'nonce-{$nonce}' 'unsafe-inline' https://fonts.googleapis.com https://*.stripe.com https://*.sslcommerz.com https://*.bkash.com https://*.paypal.com",
                "font-src 'self' data: https://fonts.gstatic.com https:",
                "img-src 'self' data: https:",
                "connect-src 'self' https:",
                "frame-src 'self' https://*.stripe.com https://*.paypal.com https://*.bkash.com https://*.sslcommerz.com",
                "frame-ancestors 'self' https:",
                "base-uri 'self'",
                "form-action 'self' https:",
                "report-uri /csp-report",
            ]);
        } else {
            $csp = implode('; ', [
                "default-src 'self'",
                "script-src 'self' 'nonce-{$nonce}'",
                "style-src 'self' 'nonce-{$nonce}' https://fonts.googleapis.com",
                "font-src 'self' https://fonts.gstatic.com",
                "img-src 'self' data: https:",
                "connect-src 'self'",
                "frame-ancestors 'none'",
                "base-uri 'self'",
                "form-action 'self'",
                "report-uri /csp-report",
            ]);
        }
        $response->withHeader($cspHeader, $csp);

        return $response;
    }
}
