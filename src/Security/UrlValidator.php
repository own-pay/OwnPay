<?php

declare(strict_types=1);

namespace OwnPay\Security;

/**
 * UrlValidator — SSRF defense for outbound HTTP requests.
 *
 * Reject URLs that:
 *   - Use a non-http(s) scheme (file://, gopher://, dict://, ftp://, ldap://, jar://)
 *   - Resolve to a private / loopback / link-local / metadata IP range
 *   - Are malformed
 *
 * F5 + F7 from docs/security_audit/full_codebase_audit.md
 *
 * Usage:
 *   if (!UrlValidator::isSafeOutbound($url, $reason)) {
 *       Logger::security()->warning('outbound_blocked', ['url' => $url, 'reason' => $reason]);
 *       return;
 *   }
 *   HttpClient::post($url, $body);
 */
final class UrlValidator
{
    /** Schemes the platform is permitted to dial */
    private const ALLOWED_SCHEMES = ['http', 'https'];

    /**
     * IPv4 CIDR ranges that are forbidden as outbound targets.
     * Includes loopback, private, link-local, multicast, broadcast,
     * and well-known cloud metadata endpoints.
     */
    private const BLOCKED_IPV4_CIDRS = [
        '0.0.0.0/8',         // "this" network
        '10.0.0.0/8',        // RFC1918 private
        '100.64.0.0/10',     // CGNAT
        '127.0.0.0/8',       // loopback
        '169.254.0.0/16',    // link-local + cloud metadata (AWS, Azure, GCP)
        '172.16.0.0/12',     // RFC1918 private
        '192.0.0.0/24',      // IETF protocol assignments
        '192.168.0.0/16',    // RFC1918 private
        '198.18.0.0/15',     // benchmarking
        '224.0.0.0/4',       // multicast
        '240.0.0.0/4',       // reserved (incl. broadcast 255.255.255.255)
    ];

    /** IPv6 prefixes that are forbidden as outbound targets. */
    private const BLOCKED_IPV6_PREFIXES = [
        '::1',              // loopback
        '::',               // unspecified
        'fe80:',            // link-local
        'fc00:',            // unique-local
        'fd00:',            // unique-local
        'ff00:',            // multicast
        '64:ff9b:',         // NAT64
    ];

    /**
     * Check whether $url is safe to dial as an outbound HTTP request.
     *
     * @param string      $url
     * @param string|null $reason  Out-param: human-readable rejection reason on failure
     * @return bool                True if the URL passes all SSRF checks
     */
    public static function isSafeOutbound(string $url, ?string &$reason = null): bool
    {
        $reason = null;

        // 1. Parse
        $parts = parse_url($url);
        if ($parts === false || !is_array($parts)) {
            $reason = 'malformed URL';
            return false;
        }
        $scheme = strtolower($parts['scheme'] ?? '');
        $host   = $parts['host'] ?? '';
        if ($scheme === '' || $host === '') {
            $reason = 'missing scheme or host';
            return false;
        }

        // 2. Scheme allowlist
        if (!in_array($scheme, self::ALLOWED_SCHEMES, true)) {
            $reason = "scheme '{$scheme}' is not permitted (allowed: " . implode(', ', self::ALLOWED_SCHEMES) . ')';
            return false;
        }

        // 3. Reject userinfo (foo:bar@host) — defeats some host-parser bugs
        if (isset($parts['user']) || isset($parts['pass'])) {
            $reason = 'userinfo (user:pass@) is not permitted';
            return false;
        }

        // 4. Resolve host to IP(s) and check every result
        $ips = self::resolveHost($host);
        if ($ips === []) {
            $reason = "DNS resolution failed for '{$host}'";
            return false;
        }
        foreach ($ips as $ip) {
            if (self::isBlockedIp($ip)) {
                $reason = "host '{$host}' resolves to blocked IP '{$ip}'";
                return false;
            }
        }

        return true;
    }

    /**
     * Resolve a hostname to a list of IPv4 / IPv6 addresses.
     * If the host is already an IP literal, return it directly.
     *
     * @return array<int,string>
     */
    private static function resolveHost(string $host): array
    {
        // IP literal — strip IPv6 brackets if present
        $bare = trim($host, '[]');
        if (filter_var($bare, FILTER_VALIDATE_IP)) {
            return [$bare];
        }
        // DNS — gethostbynamel for IPv4
        $ipv4 = @gethostbynamel($host);
        $ipv6 = [];
        // dns_get_record may fail / return empty silently — that's fine; we have IPv4
        $aaaa = @dns_get_record($host, DNS_AAAA);
        if (is_array($aaaa)) {
            foreach ($aaaa as $rec) {
                if (isset($rec['ipv6'])) {
                    $ipv6[] = $rec['ipv6'];
                }
            }
        }
        return array_values(array_unique(array_merge($ipv4 ?: [], $ipv6)));
    }

    /**
     * Check if an IP address falls in any blocked range.
     */
    private static function isBlockedIp(string $ip): bool
    {
        // IPv4
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            foreach (self::BLOCKED_IPV4_CIDRS as $cidr) {
                if (self::ipv4InCidr($ip, $cidr)) {
                    return true;
                }
            }
            return false;
        }
        // IPv6
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $lower = strtolower($ip);
            foreach (self::BLOCKED_IPV6_PREFIXES as $prefix) {
                if ($lower === rtrim($prefix, ':') || str_starts_with($lower, $prefix)) {
                    return true;
                }
            }
            return false;
        }
        // Unknown — block by default (defense in depth)
        return true;
    }

    private static function ipv4InCidr(string $ip, string $cidr): bool
    {
        [$subnet, $maskLen] = explode('/', $cidr) + [null, null];
        $ipLong     = ip2long($ip);
        $subnetLong = ip2long($subnet);
        if ($ipLong === false || $subnetLong === false || !is_numeric($maskLen)) {
            return false;
        }
        $mask = -1 << (32 - (int) $maskLen);
        return ($ipLong & $mask) === ($subnetLong & $mask);
    }
}
