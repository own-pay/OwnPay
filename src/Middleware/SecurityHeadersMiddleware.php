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
            // CSP-PLUGIN FIX: Build checkout CSP dynamically from gateway plugin manifests
            // instead of hardcoding domains. Third-party gateway plugins declare their CSP
            // needs via the "csp" field in manifest.json, e.g.:
            //   "csp": {
            //     "script_src": ["https://*.mypayment.com"],
            //     "style_src":  ["https://*.mypayment.com"],
            //     "frame_src":  ["https://*.mypayment.com"],
            //     "connect_src": ["https://api.mypayment.com"]
            //   }
            $gatewayCsp = $this->collectGatewayCspSources();

            $scriptSrc  = array_unique(array_merge(["'self'", "'nonce-{$nonce}'"], $gatewayCsp['script_src']));
            $styleSrc   = array_unique(array_merge(["'self'", "'nonce-{$nonce}'", 'https://fonts.googleapis.com'], $gatewayCsp['style_src']));
            $frameSrc   = array_unique(array_merge(["'self'"], $gatewayCsp['frame_src']));
            $connectSrc = array_unique(array_merge(["'self'", 'https:'], $gatewayCsp['connect_src']));

            $csp = implode('; ', [
                "default-src 'self' https:",
                'script-src ' . implode(' ', $scriptSrc),
                'style-src ' . implode(' ', $styleSrc),
                "font-src 'self' data: https://fonts.gstatic.com https:",
                "img-src 'self' data: https:",
                'connect-src ' . implode(' ', $connectSrc),
                'frame-src ' . implode(' ', $frameSrc),
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

    /**
     * Collect CSP source domains from all gateway plugin manifests.
     *
     * Reads the "csp" field from each gateway manifest.json under modules/gateways/.
     * Also fires the 'checkout.csp.sources' filter hook so plugins can add
     * CSP domains dynamically at runtime (e.g., for conditional loading).
     *
     * @return array{script_src: string[], style_src: string[], frame_src: string[], connect_src: string[]}
     */
    private function collectGatewayCspSources(): array
    {
        $sources = [
            'script_src'  => [],
            'style_src'   => [],
            'frame_src'   => [],
            'connect_src' => [],
        ];

        // 1. Read CSP declarations from gateway manifests
        try {
            $modulesPath = $this->container->get('config.app')['paths']['modules'] ?? '';
            $gatewaysDir = $modulesPath . '/gateways';

            if ($modulesPath !== '' && is_dir($gatewaysDir)) {
                $manifests = glob($gatewaysDir . '/*/manifest.json');
                if ($manifests !== false) {
                    foreach ($manifests as $manifestPath) {
                        $content = @file_get_contents($manifestPath);
                        if ($content === false) {
                            continue;
                        }
                        $manifest = json_decode($content, true);
                        if (!is_array($manifest) || empty($manifest['csp'])) {
                            continue;
                        }
                        $csp = $manifest['csp'];
                        foreach (['script_src', 'style_src', 'frame_src', 'connect_src'] as $directive) {
                            if (!empty($csp[$directive]) && is_array($csp[$directive])) {
                                // Sanitize: only allow https:// origins
                                foreach ($csp[$directive] as $origin) {
                                    if (is_string($origin) && $this->isValidCspOrigin($origin)) {
                                        $sources[$directive][] = $origin;
                                    }
                                }
                            }
                        }
                    }
                }
            }
        } catch (\Throwable) {
            // Graceful degradation — CSP will just be more restrictive
        }

        // 2. Apply filter hook so plugins can add CSP domains dynamically at runtime.
        // Gateway plugins can hook this in their boot() method:
        //   $events->addFilter('checkout.csp.sources', function (array $sources): array {
        //       $sources['script_src'][] = 'https://*.mypayment.com';
        //       $sources['frame_src'][]  = 'https://*.mypayment.com';
        //       return $sources;
        //   });
        if ($this->container->has(\OwnPay\Event\EventManager::class)) {
            try {
                $events = $this->container->get(\OwnPay\Event\EventManager::class);
                $sources = $events->applyFilter('checkout.csp.sources', $sources);
            } catch (\Throwable) {
                // Filter application failed — continue with manifest-only sources
            }
        }

        return $sources;
    }

    /**
     * Validate a CSP origin string — only allow safe patterns.
     *
     * Accepts: 'https://*.example.com', 'https://api.example.com'
     * Rejects: 'data:', 'unsafe-inline', '*', javascript:, etc.
     */
    private function isValidCspOrigin(string $origin): bool
    {
        // Must start with https:// (enforce secure origins only)
        if (!str_starts_with($origin, 'https://')) {
            return false;
        }

        // Must not contain spaces, semicolons, or single quotes (CSP injection prevention)
        if (preg_match('/[\s;\'"\\\\]/', $origin)) {
            return false;
        }

        return true;
    }
}
