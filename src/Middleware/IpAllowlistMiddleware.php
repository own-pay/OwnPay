<?php
declare(strict_types=1);

namespace OwnPay\Middleware;

use OwnPay\Container;
use OwnPay\Http\Request;
use OwnPay\Http\Response;

/**
 * Middleware responsible for restricting request access based on client IP addresses.
 *
 * Implements CIDR-based and exact IP matching for IPv4/IPv6 addresses configured
 * in the application's environment.
 */
final class IpAllowlistMiddleware
{
    /**
     * @var Container The dependency injection container instance.
     */
    private Container $container; // @phpstan-ignore-line


    /**
     * Constructs a new IpAllowlistMiddleware instance.
     *
     * @param Container $container The dependency injection container.
     */
    public function __construct(Container $container)
    {
        $this->container = $container;
    }

    /**
     * Handles IP authorization check for incoming HTTP requests.
     *
     * @param Request $request The incoming HTTP request.
     * @param callable(Request): Response $next Next handler in the pipeline.
     * @return Response The HTTP response.
     */
    public function handle(Request $request, callable $next): Response
    {
        $allowlist = $this->getAllowlist();

        // Empty allowlist = feature disabled
        if (empty($allowlist)) {
            return $next($request);
        }

        $clientIp = $request->ip();

        foreach ($allowlist as $allowed) {
            if ($this->matches($clientIp, trim($allowed))) {
                return $next($request);
            }
        }

        if ($request->expectsJson()) {
            return Response::json([
                'success' => false,
                'message' => 'Access denied: IP not allowed',
            ], 403);
        }

        return Response::html('<h1>403 Forbidden</h1><p>Your IP address is not authorized.</p>', 403);
    }

    /**
     * Checks if a client IP address matches an allowed IP pattern (exact or CIDR).
     *
     * @param string $ip The client IP address.
     * @param string $allowed The allowed IP pattern/subnet.
     * @return bool True if the IP matches; false otherwise.
     */
    private function matches(string $ip, string $allowed): bool
    {
        // Exact match
        if ($ip === $allowed) {
            return true;
        }

        // CIDR match
        if (str_contains($allowed, '/')) {
            return $this->cidrMatch($ip, $allowed);
        }

        return false;
    }

    /**
     * Evaluates a client IP against a subnet defined in Classless Inter-Domain Routing (CIDR) notation.
     *
     * Supports both IPv4 and IPv6 families.
     *
     * @param string $ip The client IP address.
     * @param string $cidr The CIDR subnet string (e.g. 192.168.1.0/24).
     * @return bool True if the IP resides within the subnet range; false otherwise.
     */
    private function cidrMatch(string $ip, string $cidr): bool
    {
        [$subnet, $bits] = explode('/', $cidr, 2);
        $bits = (int) $bits;

        $ipBin = inet_pton($ip);
        $subnetBin = inet_pton($subnet);

        if ($ipBin === false || $subnetBin === false) {
            return false;
        }

        // Must be same family (IPv4/IPv6)
        if (strlen($ipBin) !== strlen($subnetBin)) {
            return false;
        }

        // Build mask
        $fullBytes = intdiv($bits, 8);
        $remainBits = $bits % 8;
        $mask = str_repeat("\xff", $fullBytes);
        if ($remainBits > 0) {
            $mask .= chr(0xff << (8 - $remainBits) & 0xff);
        }
        $mask = str_pad($mask, strlen($ipBin), "\x00");

        return ($ipBin & $mask) === ($subnetBin & $mask);
    }

    /**
     * Resolves the configured list of allowed IP addresses from system environment variables.
     *
     * @return string[] List of allowed IP subnets or exact addresses.
     */
    private function getAllowlist(): array
    {
        $raw = getenv('IP_ALLOWLIST') ?: '';
        if ($raw === '') {
            return [];
        }
        return array_filter(array_map('trim', explode(',', $raw)));
    }
}
