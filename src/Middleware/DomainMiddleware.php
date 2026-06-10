<?php
declare(strict_types=1);

namespace OwnPay\Middleware;

use OwnPay\Container;
use OwnPay\Http\Request;
use OwnPay\Http\Response;
use OwnPay\Repository\DomainRepository;

/**
 * Resolves the incoming custom domain to the corresponding merchant brand context.
 *
 * This middleware intercepts requests to perform custom domain mapping for the white-label pipeline.
 * It matches the incoming Host header against configured domains in the `op_domains` database table.
 * Successfully resolved hosts propagate the merchant brand context down the routing pipeline by
 * injecting attributes such as `merchant_id` and domain configuration into the request context.
 * Additionally, it enforces security containment by blocking access to admin routing endpoints
 * when accessed via a white-labeled custom domain.
 */
final class DomainMiddleware
{
    /**
     * @var \OwnPay\Container The dependency injection container.
     */
    private Container $container;

    /**
     * Initializes the domain resolution middleware with the DI container.
     *
     * @param \OwnPay\Container $container The dependency injection container.
     */
    public function __construct(Container $container)
    {
        $this->container = $container;
    }

    /**
     * Intercepts and processes the HTTP request to perform brand-scoped domain routing.
     *
     * This handler extracts the host header, normalizes the hostname by removing ports
     * (handling IPv4 and IPv6 structures), resolves the master domain configuration,
     * and performs brand context mapping. Active custom domains are verified against
     * the database to inject `merchant_id` context into request attributes. Security rules
     * are enforced to reject unverified domains or admin path access on custom hostnames.
     *
     * @param \OwnPay\Http\Request $request The incoming HTTP request instance.
     * @param callable(Request): Response $next The next middleware or handler in the pipeline.
     * @return \OwnPay\Http\Response The HTTP response output.
     */
    public function handle(Request $request, callable $next): Response
    {
        $host = $request->header('Host');
        if ($host === '' /** @phpstan-ignore identical.alwaysFalse */) {
            return $next($request);
        }

        // Normalize the host string by removing any specified port.
        // For IPv6 addresses wrapped in square brackets (e.g., [::1]:8080), isolate the bracket contents.
        if (str_starts_with($host, '[')) {
            $closeBracket = strpos($host, ']');
            $domain = $closeBracket !== false ? substr($host, 1, $closeBracket - 1) : $host;
        } else {
            // For IPv4 hostnames or domains, locate the last colon delimiter to strip out the port number.
            $colonPos = strrpos($host, ':');
            $domain = $colonPos !== false ? substr($host, 0, $colonPos) : $host;
        }

        // Compare normalized hostname against the resolved system-wide master domain and localhost.
        // Standard admin panel routes are directly processed without mapping tenant scopes.
        $masterDomain = $this->resolveMasterDomain();
        $isLocalhostLoopback = ($domain === 'localhost' && in_array($request->ip(), ['127.0.0.1', '::1', 'localhost'], true));
        if ($domain === $masterDomain || $isLocalhostLoopback) {
            return $next($request);
        }

        // Ensure database operations are skipped if the installation sequence has not completed.
        // This avoids throwing PDOExceptions during system initialization or initial installation.
        if (!file_exists(dirname(__DIR__, 2) . '/storage/.installed')) {
            return $next($request);
        }

        /** @var DomainRepository $repo */
        $repo = $this->container->get(DomainRepository::class);
        $domainRecord = $repo->findByDomain($domain);

        // Enforce access control: reject requests targeting unrecognized or inactive domain records.
        // This mitigates unscoped domain spoofing and potential routing leakage.
        if ($domainRecord === null || $domainRecord['status'] !== 'active') {
            return Response::html('<h1>404 Not Found</h1>', 404);
        }

        // Enforce domain verification check: require validated DNS settings for active custom domains.
        if (!(bool) $domainRecord['dns_verified']) {
            return Response::html('<h1>Domain Not Verified</h1><p>DNS verification pending.</p>', 503);
        }

        // Restrict administrative actions: deny route pathways pointing to `/admin` or `/admin/*`
        // when requested via a merchant's custom domain, throwing a hard 404 response to maintain
        // strict isolation of the primary admin panel.
        $path = $request->path();
        if (str_starts_with($path, '/admin/') || $path === '/admin') {
            return Response::html('', 404);
        }

        if (!isset($domainRecord['merchant_id']) || !is_scalar($domainRecord['merchant_id'])) {
            return Response::html('<h1>404 Not Found</h1>', 404);
        }
        $merchantId = (int) $domainRecord['merchant_id'];

        // Enforce active merchant status check
        $merchantRepo = $this->container->get(\OwnPay\Repository\MerchantRepository::class);
        if (!$merchantRepo instanceof \OwnPay\Repository\MerchantRepository) {
            throw new \RuntimeException("MerchantRepository not found in container");
        }
        $merchant = $merchantRepo->find($merchantId);
        if ($merchant === null || ($merchant['status'] ?? 'active') !== 'active') {
            return Response::html('<h1>404 Not Found</h1>', 404);
        }

        // Set request attributes to propagate resolved brand parameters down the application pipeline.
        $request->setAttribute('domain', $domainRecord);
        $request->setAttribute('merchant_id', $merchantId);
        $request->setAttribute('domain_type', $domainRecord['type']);
        $request->setAttribute('custom_domain', $domain);

        return $next($request);
    }

    /**
     * Resolves the primary master domain hostname from environment configurations.
     *
     * Checks variables in order: explicit `APP_DOMAIN` definition, followed by parsing
     * the hostname directly from the `APP_URL` parameter. Falls back to an empty string if unresolved.
     *
     * @return string The resolved master domain hostname.
     */
    private function resolveMasterDomain(): string
    {
        // Step 1: Look for explicit APP_DOMAIN environment override configuration.
        $appDomainVal = $_ENV['APP_DOMAIN'] ?? $_SERVER['APP_DOMAIN'] ?? getenv('APP_DOMAIN') ?: '';
        $appDomain = is_string($appDomainVal) ? $appDomainVal : '';
        if ($appDomain !== '') {
            return $appDomain;
        }

        // Step 2: Extract host section from the APP_URL environment variable.
        $appUrlVal = $_ENV['APP_URL'] ?? $_SERVER['APP_URL'] ?? getenv('APP_URL') ?: '';
        $appUrl = is_string($appUrlVal) ? $appUrlVal : '';
        if ($appUrl !== '') {
            $parsed = parse_url($appUrl, PHP_URL_HOST);
            if (is_string($parsed)) {
                return $parsed;
            }
        }

        return '';
    }
}
