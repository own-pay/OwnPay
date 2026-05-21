<?php
declare(strict_types=1);

namespace OwnPay\Middleware;

use OwnPay\Container;
use OwnPay\Http\Request;
use OwnPay\Http\Response;
use OwnPay\Repository\DomainRepository;

/**
 * Domain middleware — resolves custom domain to merchant context.
 *
 * Maps incoming Host header to op_domains table.
 * Injects merchant_id + domain config into request attributes.
 *
 * White-label pipeline: Every non-master-domain host is resolved as a brand's
 * custom domain. Admin routes are blocked on custom domains (404).
 */
final class DomainMiddleware
{
    private Container $container;

    public function __construct(Container $container)
    {
        $this->container = $container;
    }

    public function handle(Request $request, callable $next): Response
    {
        $host = $request->header('Host');
        if ($host === '' /** @phpstan-ignore identical.alwaysFalse */) {
            return $next($request);
        }

        // Strip port
        $domain = explode(':', $host)[0];

        // Resolve master domain: APP_DOMAIN env, or parse host from APP_URL
        $masterDomain = $this->resolveMasterDomain();
        if ($domain === $masterDomain || $domain === 'localhost') {
            return $next($request);
        }

        // If system is not installed, skip DB lookups to prevent PDOExceptions
        if (!file_exists(dirname(__DIR__, 2) . '/storage/.installed')) {
            return $next($request);
        }

        /** @var DomainRepository $repo */
        $repo = $this->container->get(DomainRepository::class);
        $domainRecord = $repo->findByDomain($domain);

        if ($domainRecord === null || $domainRecord['status'] !== 'active') {
            // BUG-006 FIX: Block unknown/inactive domains — return 404.
            // Previously this passed through, allowing unscoped access.
            return Response::html('<h1>404 Not Found</h1>', 404);
        }

        if (!(bool) $domainRecord['dns_verified']) {
            return Response::html('<h1>Domain Not Verified</h1><p>DNS verification pending.</p>', 503);
        }

        // WHITE-LABEL SECURITY: Block admin routes on custom domains.
        // Admin panel must only be accessible on the master domain.
        $path = $request->path();
        if (str_starts_with($path, '/admin/') || $path === '/admin') {
            return Response::html('', 404);
        }

        // Inject merchant context
        $request->setAttribute('domain', $domainRecord);
        $request->setAttribute('merchant_id', (int) $domainRecord['merchant_id']);
        $request->setAttribute('domain_type', $domainRecord['type']);
        $request->setAttribute('custom_domain', $domain);

        return $next($request);
    }

    /**
     * Resolve the master/admin domain hostname.
     * Priority: APP_DOMAIN env > parse host from APP_URL > empty string
     */
    private function resolveMasterDomain(): string
    {
        // 1. Explicit APP_DOMAIN
        $appDomain = $_ENV['APP_DOMAIN'] ?? $_SERVER['APP_DOMAIN'] ?? getenv('APP_DOMAIN') ?: '';
        if ($appDomain !== '') {
            return $appDomain;
        }

        // 2. Parse from APP_URL
        $appUrl = $_ENV['APP_URL'] ?? $_SERVER['APP_URL'] ?? getenv('APP_URL') ?: '';
        if ($appUrl !== '') {
            $parsed = parse_url($appUrl, PHP_URL_HOST);
            if ($parsed !== null && $parsed !== false) {
                return $parsed;
            }
        }

        return '';
    }
}
