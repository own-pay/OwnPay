<?php
declare(strict_types=1);

namespace OwnPay\Middleware;

use OwnPay\Container;
use OwnPay\Http\Request;
use OwnPay\Http\Response;

/**
 * IP allowlist middleware — restricts access to configured IPs.
 *
 * Per OWASP: defense-in-depth for admin/API routes.
 * Supports IPv4, IPv6, CIDR notation.
 */
final class IpAllowlistMiddleware
{
    /** @phpstan-ignore property.onlyWritten */
    private Container $container;

    public function __construct(Container $container)
    {
        $this->container = $container;
    }

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
     * Check if IP matches allowed entry (exact or CIDR).
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
     * @return string[]
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
