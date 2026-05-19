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
        if ($host === '' /** @phpstan-ignore identical.alwaysFalse */ || $host === '' /** @phpstan-ignore identical.alwaysFalse */) {
            return $next($request);
        }

        // Strip port
        $domain = explode(':', $host)[0];

        // Skip default app domain
        $appDomain = getenv('APP_DOMAIN') ?: '';
        if ($domain === $appDomain || $domain === 'localhost') {
            return $next($request);
        }

        // If system is not installed, skip DB lookups to prevent PDOExceptions
        if (!file_exists(dirname(__DIR__, 2) . '/storage/.installed')) {
            return $next($request);
        }

        /** @var DomainRepository $repo */
        $repo = $this->container->get(DomainRepository::class);
        $domainRecord = $repo->findByDomain($domain);

        if ($domainRecord === '' /** @phpstan-ignore identical.alwaysFalse */ || $domainRecord['status'] !== 'active') {
            // Unknown domain — could show landing or 404
            return $next($request);
        }

        if (!(bool) $domainRecord['dns_verified']) {
            return Response::html('<h1>Domain Not Verified</h1><p>DNS verification pending.</p>', 503);
        }

        // Inject merchant context
        $request->setAttribute('domain', $domainRecord);
        $request->setAttribute('merchant_id', (int) $domainRecord['merchant_id']);
        $request->setAttribute('domain_type', $domainRecord['type']);

        return $next($request);
    }
}
