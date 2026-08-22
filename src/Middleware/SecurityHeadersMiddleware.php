<?php
declare(strict_types=1);

namespace OwnPay\Middleware;

use OwnPay\Container;
use OwnPay\Http\Request;
use OwnPay\Http\Response;

/**
 * Middleware responsible for appending security hardening HTTP headers to all outgoing responses.
 *
 * Implements strict Content Security Policy (CSP) rules, generates per-request CSP nonces,
 * dynamically resolves CSP source exclusions declared by payment gateways, and applies standard OWASP recommendations.
 */
final class SecurityHeadersMiddleware
{
    /**
     * @var Container The dependency injection container.
     */
    private Container $container;

    /**
     * Constructs a new SecurityHeadersMiddleware instance.
     *
     * @param Container $container The dependency injection container.
     */
    public function __construct(Container $container)
    {
        $this->container = $container;
    }

    /**
     * Injects HTTP security headers and generates CSP rules for the response.
     *
     * @param Request $request The incoming HTTP request.
     * @param callable(Request): Response $next Next handler in the pipeline.
     * @return Response The HTTP response.
     */
    public function handle(Request $request, callable $next): Response
    {
        // Generate per-request CSP nonce for inline styles to avoid 'unsafe-inline'.
        $nonce = base64_encode(random_bytes(16));
        $request->setAttribute('csp_nonce', $nonce);

        // Store nonce in the CspNonce singleton (registered before container freeze).
        if ($this->container->has(\OwnPay\Security\CspNonce::class)) {
            $this->container->get(\OwnPay\Security\CspNonce::class)->set($nonce);
        }

        $response = $next($request);

        $response->withHeader('X-Content-Type-Options', 'nosniff');
        $response->withHeader('X-Frame-Options', 'DENY');
        $response->withHeader('Referrer-Policy', 'strict-origin-when-cross-origin');

        // Keep payment=() - OwnPay uses gateway redirects, not Payment Request API.
        $response->withHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=()');

        // Enforce modern Report-To header (SSRF/CSP reporting compliance).
        //
        // SECURITY: The report endpoint URL must be resolved from a server-side
        // configuration value (APP_URL / config.app.url) and never from the
        // client-supplied HTTP Host header. Browsers POST CSP violation reports
        // to whatever URL we advertise here, so a spoofed Host could otherwise
        // direct victims' reports to an attacker-controlled domain.
        $reportUrl = $this->resolveCspReportUrl();
        if ($reportUrl !== null) {
            $reportTo = json_encode([
                'group' => 'csp-endpoint',
                'max_age' => 10886400,
                'endpoints' => [
                    ['url' => $reportUrl]
                ],
                'include_subdomains' => true
            ], JSON_UNESCAPED_SLASHES);
            if (is_string($reportTo)) {
                $response->withHeader('Report-To', $reportTo);
            }
        }

        // HSTS - only on HTTPS
        if ($request->isSecure()) {
            $response->withHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        // CSP - strict policy, report-only in debug mode
        $configApp = $this->container->get('config.app');
        $debug = false;
        if (is_array($configApp)) {
            $debug = (bool) ($configApp['debug'] ?? false);
        }
        $cspHeader = $debug ? 'Content-Security-Policy-Report-Only' : 'Content-Security-Policy';
        
        $path = $request->path();
        $isCheckout = str_starts_with($path, '/checkout') || str_starts_with($path, '/invoice') || str_starts_with($path, '/pay');

        if ($isCheckout) {
            $gatewayCsp = $this->collectGatewayCspSources();

            // Resolve the explicit list of frame-ancestors the checkout/invoice/pay
            // page may be embedded by. Per TMPL-5: previously `frame-ancestors
            // 'self' https:` allowed ANY HTTPS origin to iframe the checkout
            // page (modern browsers honour CSP frame-ancestors over the global
            // X-Frame-Options: DENY header), enabling clickjacking overlays.
            // Now we enumerate the active brand's DNS-verified+active custom
            // domains from `op_domains` and emit them as the explicit allow
            // list. Falls back to `'self'` only when no verified domain is
            // registered or the container/DB is unavailable — merchants who
            // want embedded checkout must explicitly register their embedding
            // domain in the Domains admin and complete DNS verification.
            $frameAncestors = $this->resolveCheckoutFrameAncestors();

            $scriptSrc  = array_unique(array_merge(["'self'", "'nonce-{$nonce}'"], $gatewayCsp['script_src']));
            $styleSrc   = array_unique(array_merge(["'self'", "'unsafe-inline'", 'https://fonts.googleapis.com'], $gatewayCsp['style_src']));
            $frameSrc   = array_unique(array_merge(["'self'"], $gatewayCsp['frame_src']));
            $connectSrc = array_unique(array_merge(["'self'"], $gatewayCsp['connect_src']));

            $csp = implode('; ', [
                "default-src 'self'",
                'script-src ' . implode(' ', $scriptSrc),
                'style-src ' . implode(' ', $styleSrc),
                "font-src 'self' data: https://fonts.gstatic.com https:",
                "img-src 'self' data: https:",
                'connect-src ' . implode(' ', $connectSrc),
                'frame-src ' . implode(' ', $frameSrc),
                "frame-ancestors {$frameAncestors}",
                "base-uri 'self'",
                "form-action 'self' https:",
                "report-uri /csp-report",
                "report-to csp-endpoint",
            ]);

            if (strlen($csp) > 7500) {
                $this->logHeaderOverflow(strlen($csp));
                $csp = implode('; ', [
                    "default-src 'self'",
                    "script-src 'self' 'nonce-{$nonce}'",
                    "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com",
                    "font-src 'self' data: https://fonts.gstatic.com https:",
                    "img-src 'self' data: https:",
                    "connect-src 'self'",
                    "frame-src 'self'",
                    "frame-ancestors {$frameAncestors}",
                    "base-uri 'self'",
                    "form-action 'self' https:",
                    "report-uri /csp-report",
                    "report-to csp-endpoint",
                ]);
            }
        } else {
            $csp = implode('; ', [
                "default-src 'self'",
                "script-src 'self' 'nonce-{$nonce}'",
                "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com",
                "font-src 'self' https://fonts.gstatic.com",
                "img-src 'self' data: https:",
                "connect-src 'self'",
                "frame-ancestors 'none'",
                "base-uri 'self'",
                "form-action 'self'",
                "report-uri /csp-report",
                "report-to csp-endpoint",
            ]);
        }
        $response->withHeader($cspHeader, $csp);

        if ($request->getAttribute('custom_domain') !== null) {
            $existingHeaders = $response->getHeaders();
            $varyVal = $existingHeaders['Vary'] ?? '';
            $varyParts = array_values(array_filter(array_map('trim', explode(',', $varyVal))));
            if (!in_array('Host', $varyParts, true)) {
                $varyParts[] = 'Host';
            }
            $response->withHeader('Vary', implode(', ', $varyParts));
        }

        return $response;
    }

    /**
     * Collect CSP source domains from the manifests of ACTIVE gateways only.
     *
     * Reads the "csp" field from modules/gateways/{slug}/manifest.json for each
     * gateway with an active configuration (brand-scoped via BrandContext when the
     * controller has resolved one, otherwise any active config). Scanning every
     * installed manifest is forbidden here: with 100+ bundled gateways the merged
     * CSP exceeded mod_fcgid's 8KB header limit and hard-killed the FastCGI worker.
     * Also fires the 'checkout.csp.sources' filter hook so plugins can add
     * CSP domains dynamically at runtime (e.g., for conditional loading).
     *
     * @return array{script_src: string[], style_src: string[], frame_src: string[], connect_src: string[]} Directives mapping.
     */
    private function collectGatewayCspSources(): array
    {
        $sources = [
            'script_src'  => [],
            'style_src'   => [],
            'frame_src'   => [],
            'connect_src' => [],
        ];

        // 1. Read CSP declarations from the manifests of active gateways
        try {
            $configApp = $this->container->get('config.app');
            $modulesPath = '';
            if (is_array($configApp) && isset($configApp['paths']) && is_array($configApp['paths'])) {
                $modulesPath = is_string($configApp['paths']['modules'] ?? null) ? $configApp['paths']['modules'] : '';
            }
            $gatewaysDir = $modulesPath . '/gateways';

            if ($modulesPath !== '' && is_dir($gatewaysDir)) {
                foreach ($this->activeGatewaySlugs() as $slug) {
                    $manifestPath = $gatewaysDir . '/' . $slug . '/manifest.json';
                    if (!is_file($manifestPath)) {
                        continue;
                    }
                    $content = @file_get_contents($manifestPath);
                    if ($content === false) {
                        continue;
                    }
                    $manifest = json_decode($content, true);
                    if (!is_array($manifest) || empty($manifest['csp'])) {
                        continue;
                    }
                    $csp = $manifest['csp'];
                    if (is_array($csp)) {
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
            // Graceful degradation - CSP will just be more restrictive
        }

        // 2. Apply filter hook so plugins can add CSP domains dynamically at runtime.
        if ($this->container->has(\OwnPay\Event\EventManager::class)) {
            try {
                $events = $this->container->get(\OwnPay\Event\EventManager::class);
                if ($events instanceof \OwnPay\Event\EventManager) {
                    $filtered = $events->applyFilter('checkout.csp.sources', $sources);
                    if (is_array($filtered)) {
                        foreach (['script_src', 'style_src', 'frame_src', 'connect_src'] as $directive) {
                            if (isset($filtered[$directive]) && is_array($filtered[$directive])) {
                                $validated = [];
                                foreach ($filtered[$directive] as $origin) {
                                    if (is_string($origin)) {
                                        $validated[] = $origin;
                                    }
                                }
                                $sources[$directive] = $validated;
                            }
                        }
                    }
                }
            } catch (\Throwable) {
                // Filter application failed - continue with manifest-only sources
            }
        }

        return $sources;
    }

    /**
     * Resolve the slugs of gateways with an active configuration.
     *
     * Runs in the response phase, after the controller has resolved the brand via
     * BrandContext::setActiveBrandId(); the slug list is therefore brand-accurate
     * on checkout pages. When no brand context exists (e.g. early redirects), it
     * falls back to every active config across brands - a small superset that is
     * still bounded by what the owner actually enabled, never the full catalog.
     *
     * @return string[] Sanitized gateway slugs safe for filesystem path use.
     */
    private function activeGatewaySlugs(): array
    {
        $slugs = [];
        try {
            $repo = $this->container->get(\OwnPay\Repository\GatewayConfigRepository::class);
            if (!$repo instanceof \OwnPay\Repository\GatewayConfigRepository) {
                return [];
            }

            $brandId = null;
            if ($this->container->has(\OwnPay\Service\Brand\BrandContext::class)) {
                $ctx = $this->container->get(\OwnPay\Service\Brand\BrandContext::class);
                if ($ctx instanceof \OwnPay\Service\Brand\BrandContext) {
                    $brandId = $ctx->getActiveBrandId();
                }
            }

            $rows = ($brandId !== null && $brandId > 0)
                ? $repo->forTenant($brandId)->listActive()
                : $repo->listActive();

            foreach ($rows as $row) {
                $slug = isset($row['slug']) && is_scalar($row['slug']) ? (string) $row['slug'] : '';
                // Constrain charset before using the slug in a filesystem path.
                if ($slug !== '' && preg_match('/^[a-z0-9_\-]+$/i', $slug) === 1) {
                    $slugs[] = $slug;
                }
            }
        } catch (\Throwable) {
            // DB unavailable - serve the strict baseline CSP without gateway sources
        }
        return array_values(array_unique($slugs));
    }

    /**
     * Log a CSP header overflow so operators learn gateway sources were dropped.
     *
     * @param int $length Byte length of the oversized CSP header value.
     */
    private function logHeaderOverflow(int $length): void
    {
        try {
            if ($this->container->has(\OwnPay\Service\System\Logger::class)) {
                $logger = $this->container->get(\OwnPay\Service\System\Logger::class);
                if ($logger instanceof \OwnPay\Service\System\Logger) {
                    $logger->warning(
                        "CSP header reached {$length} bytes (mod_fcgid limit is 8192) - gateway CSP sources dropped for this response. Reduce active gateway count or manifest csp entries."
                    );
                    return;
                }
            }
        } catch (\Throwable) {
            // Fall through to error_log
        }
        error_log("[OwnPay] CSP header overflow: {$length} bytes - gateway sources dropped.");
    }

    /**
     * Validates a Content Security Policy origin string to prevent script/header injection.
     *
     * Ensures origins strictly follow secure protocols and exclude whitespace or malicious characters.
     *
     * @param string $origin The origin string (e.g. 'https://*.example.com').
     * @return bool True if valid; false otherwise.
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

    /**
     * Resolves the explicit CSP frame-ancestors source list for checkout/invoice/pay pages.
     *
     * Per TMPL-5: the previous `frame-ancestors 'self' https:` policy allowed ANY
     * HTTPS origin to iframe the checkout page. Modern browsers honour CSP
     * frame-ancestors over the global `X-Frame-Options: DENY` header, so the
     * global DENY was overridden on checkout paths — an attacker site at
     * `https://evil.com` could iframe the OwnPay checkout, overlay it with a
     * transparent UI, and trick the customer into clicking "Pay" on what they
     * thought was the attacker's UI but was actually the OwnPay checkout
     * (classic clickjacking).
     *
     * Resolution order (every source is server-controlled — never the client):
     *   1. `'self'` always (the OwnPay origin itself, including white-labeled
     *      custom domains that resolve to this OwnPay install).
     *   2. Every DNS-verified + active custom domain registered for the active
     *      brand in `op_domains`, emitted as `https://<host>`. Only verified+
     *      active rows are eligible — a `pending` or `inactive` domain must
     *      never be a frame-ancestor.
     *
     * Strict hostname validation (letters, digits, hyphen, dot only, ASCII) is
     * applied to every domain before emission as defence-in-depth on top of the
     * admin controller's own storage-time validation: a tampered `domain` value
     * containing whitespace, semicolons, quotes, or a scheme/port must never
     * reach the CSP header (which would allow CSP-directive injection).
     *
     * Falls back to `'self'` only when BrandContext / DomainRepository is
     * unavailable, the active brand is unresolved, or the brand has no
     * verified+active custom domains. Merchants who want embedded checkout
     * must explicitly register their embedding domain in the Domains admin
     * and complete DNS verification.
     *
     * @return string Space-separated CSP frame-ancestors source list, e.g.
     *                `"'self' https://shop.example.com https://pay.other.com"`.
     */
    private function resolveCheckoutFrameAncestors(): string
    {
        $sources = ["'self'"];

        try {
            if (!$this->container->has(\OwnPay\Service\Brand\BrandContext::class)) {
                return implode(' ', $sources);
            }
            $ctx = $this->container->get(\OwnPay\Service\Brand\BrandContext::class);
            if (!$ctx instanceof \OwnPay\Service\Brand\BrandContext) {
                return implode(' ', $sources);
            }
            $brandId = $ctx->getActiveBrandId();
            if ($brandId === null || $brandId <= 0) {
                return implode(' ', $sources);
            }

            $repo = $this->container->get(\OwnPay\Repository\DomainRepository::class);
            if (!$repo instanceof \OwnPay\Repository\DomainRepository) {
                return implode(' ', $sources);
            }

            // Pull every domain registered for the active brand (tenant-scoped).
            // Filter to dns_verified=1 + status=active only. The list is
            // typically 1-3 entries per merchant; cap at 20 as a sanity bound
            // against pathological cases that could blow up the CSP header.
            $rows = $repo->forTenant($brandId)->listAllScoped();
            $emitted = 0;
            foreach ($rows as $row) {
                if ($emitted >= 20) {
                    break;
                }
                $domain = isset($row['domain']) && is_scalar($row['domain']) ? trim((string) $row['domain']) : '';
                if ($domain === '') {
                    continue;
                }
                $dnsVerified = isset($row['dns_verified']) ? (bool) $row['dns_verified'] : false;
                $status = isset($row['status']) && is_scalar($row['status']) ? (string) $row['status'] : '';
                if (!$dnsVerified || $status !== 'active') {
                    continue;
                }
                // Defence-in-depth: strict ASCII hostname charset. Rejects any
                // tampered `domain` value containing scheme/port/path/whitespace/
                // quote/semicolon — prevents CSP-directive injection from a
                // corrupted row.
                if (preg_match('/^[a-z0-9.\-]+$/i', $domain) !== 1) {
                    continue;
                }
                $sources[] = 'https://' . strtolower($domain);
                $emitted++;
            }
        } catch (\Throwable) {
            // Container / DB unavailable — fail closed to 'self' only.
        }

        return implode(' ', array_values(array_unique($sources)));
    }

    /**
     * Resolves the CSP Report-To endpoint URL from server-side configuration only.
     *
     * Resolution order (every source is server-controlled — never the HTTP Host header):
     *   1. config.app.url (set from the APP_URL env var in config/app.php)
     *   2. $_SERVER['SERVER_NAME'] (set by the web server, not the client)
     *
     * Returns null when no trusted server-side origin can be determined; in that case
     * the Report-To header is simply omitted rather than being constructed from an
     * untrusted source.
     *
     * @return string|null Fully-qualified report endpoint URL, or null if unavailable.
     */
    private function resolveCspReportUrl(): ?string
    {
        $base = '';

        try {
            $configApp = $this->container->get('config.app');
            if (is_array($configApp) && isset($configApp['url']) && is_string($configApp['url'])) {
                $base = trim($configApp['url']);
            }
        } catch (\Throwable) {
            // Container / config unavailable — fall through to SERVER_NAME below.
        }

        if ($base === '') {
            $serverName = $_SERVER['SERVER_NAME'] ?? '';
            $serverName = is_string($serverName) ? trim($serverName) : '';
            if ($serverName !== '') {
                // SERVER_NAME in standard web SAPIs is the configured virtual-host
                // value, not the client's HTTP Host header. Build a URL from it.
                $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                    || (($_SERVER['SERVER_PORT'] ?? 0) == 443);
                $base = ($isHttps ? 'https://' : 'http://') . $serverName;
            }
        }

        if ($base === '') {
            return null;
        }

        // Strip any trailing slash before appending the report path.
        return rtrim($base, '/') . '/csp-report-api';
    }
}
