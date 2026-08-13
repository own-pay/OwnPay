<?php
declare(strict_types=1);

namespace OwnPay\Security;

/**
 * Class UrlValidator
 *
 * Provides functions to validate and sanitize URLs, mitigating Server-Side Request Forgery (SSRF),
 * open redirection, and protocol injection vulnerability vectors in compliance with OWASP guidelines.
 *
 * @package OwnPay\Security
 */
final class UrlValidator
{
    /**
     * @var array<int, string> Allowed URL schemes.
     */
    private const ALLOWED_SCHEMES = ['http', 'https'];

    /**
     * @var array<int, string> Allowed URL schemes for sanitize() — broader than
     *                         ALLOWED_SCHEMES because sanitize() is also used for
     *                         mailto/tel links and other user-supplied URL strings
     *                         that are not outbound HTTP targets.
     */
    private const SANITIZE_ALLOWED_SCHEME_PREFIXES = [
        'http://',
        'https://',
        'mailto:',
        'tel:',
        'ftp://',
    ];

    /**
     * @var string URL-safe character set per RFC 3986 unreserved + reserved
     *             (excluding delimiters that aren't legal in a URL string) plus
     *             percent-encoded triplet characters. Used by sanitize() to
     *             reject any URL containing characters outside this set.
     */
    private const SANITIZE_ALLOWED_CHARS_REGEX = '/^[a-zA-Z0-9\-._~:\/?#\[\]@!$&\'()*+,;=%]+$/';

    /**
     * @var array<int, string> Private and loopback IP CIDR ranges blocked to prevent SSRF.
     * @phpstan-ignore classConstant.unused
     */
    private const BLOCKED_RANGES = [
        '10.0.0.0/8',
        '172.16.0.0/12',
        '192.168.0.0/16',
        '127.0.0.0/8',
        '169.254.0.0/16',
        '0.0.0.0/8',
    ];

    /**
     * Validates if a URL is safe for outbound communication (mitigating SSRF and open redirect issues).
     *
     * @param string $url The target URL to validate.
     * @param string|null $reason Output parameter updated with the validation failure key if returning false.
     * @return bool True if the URL is deemed safe, otherwise false.
     */
    public static function isSafeOutbound(string $url, ?string &$reason = null): bool
    {
        $url = trim($url);
        if ($url === '') {
            $reason = 'missing';
            return false;
        }

        $parsed = parse_url($url);
        if ($parsed === false || !isset($parsed['scheme'])) {
            $reason = 'scheme';
            return false;
        }

        $scheme = strtolower($parsed['scheme']);
        if (!in_array($scheme, self::ALLOWED_SCHEMES, true)) {
            $reason = 'scheme';
            return false;
        }

        if (isset($parsed['user']) || isset($parsed['pass'])) {
            $reason = 'userinfo';
            return false;
        }

        if (!isset($parsed['host'])) {
            $reason = 'scheme';
            return false;
        }

        $host = strtolower($parsed['host']);
        
        if ($host === 'localhost' || $host === '0.0.0.0' || $host === '[::]' || $host === '[::1]') {
            $reason = $host;
            return false;
        }

        $ip = $host;
        if (str_starts_with($ip, '[') && str_ends_with($ip, ']')) {
            $ip = substr($ip, 1, -1);
        }

        if (filter_var($ip, FILTER_VALIDATE_IP) !== false) {
            if ($ip === '127.0.0.1' || str_starts_with($ip, '127.')) {
                $reason = $ip;
                return false;
            }
            if (self::isPrivateIp($ip)) {
                $reason = $ip;
                return false;
            }
            if ($ip === '169.254.169.254' || str_starts_with($ip, '169.254.')) {
                $reason = '169.254';
                return false;
            }
            if ($ip === '::1') {
                $reason = '::1';
                return false;
            }
        }

        return true;
    }

    /**
     * Validates if a URL is safe to perform client redirects (mitigating open redirection).
     *
     * SECURITY: $allowedDomain is now MANDATORY. The previous signature allowed
     * a null $allowedDomain, in which case any URL with an http/https scheme and
     * a host was accepted — an open-redirect sink for any caller that didn't
     * pass an explicit allowlist. The fix is to require the caller to explicitly
     * provide the app's canonical host (e.g. from config.app.url, SERVER_NAME,
     * or RouteHelper::siteUrl('MainDomain')), so every redirect target is
     * constrained to the application's own domain or a configured subdomain.
     *
     * @param string $url The target URL to validate.
     * @param string $allowedDomain Required domain string to restrict the host.
     *                              Subdomains are accepted (e.g. passing
     *                              'example.com' allows 'foo.example.com').
     * @return bool True if the URL is valid for redirection to the allowed
     *              domain, otherwise false.
     */
    public static function isValidRedirect(string $url, string $allowedDomain): bool
    {
        // An empty allowed domain means the caller has no configured canonical
        // host — fail closed rather than risk an open-redirect sink.
        if ($allowedDomain === '') {
            return false;
        }

        // Enforce absolute URL parsing criteria.
        $parsed = parse_url($url);
        if ($parsed === false || !isset($parsed['scheme'], $parsed['host'])) {
            return false;
        }

        // Validate scheme matches allowed redirect schemes.
        if (!in_array(strtolower($parsed['scheme']), self::ALLOWED_SCHEMES, true)) {
            return false;
        }

        // Apply domain restriction: host must equal the allowed domain or be a
        // subdomain of it. str_ends_with('foo.example.com', '.example.com')
        // correctly matches subdomains while preventing suffix attacks
        // (e.g. 'evilexample.com' is NOT a suffix-match of '.example.com').
        $host = strtolower($parsed['host']);
        $domain = strtolower($allowedDomain);
        if ($host !== $domain && !str_ends_with($host, '.' . $domain)) {
            return false;
        }

        return true;
    }

    /**
     * Validates if a URL is safe for webhook dispatch (preventing Server-Side Request Forgery).
     *
     * Enforces HTTPS, checks for direct private IPs, resolves hostnames to verify that underlying
     * IPs do not match internal CIDR ranges, and blocks local loopback hostnames.
     *
     * @param string $url The target webhook URL.
     * @return bool True if the webhook URL is safe, otherwise false.
     */
    public static function isValidWebhookUrl(string $url): bool
    {
        $parsed = parse_url($url);
        if ($parsed === false || !isset($parsed['scheme'], $parsed['host'])) {
            return false;
        }

        // Enforce HTTPS exclusively for webhook outbound triggers.
        if (strtolower($parsed['scheme']) !== 'https') {
            return false;
        }

        // Validate that the host is not a direct private IP address.
        $host = $parsed['host'];
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            if (self::isPrivateIp($host)) {
                return false;
            }
        }

        // Resolve DNS records to verify and inspect host IP addresses (both IPv4 and IPv6).
        $resolvedIps = [];
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);
        if (is_array($records)) {
            foreach ($records as $record) {
                if (isset($record['type'])) {
                    if ($record['type'] === 'A' && isset($record['ip'])) {
                        $resolvedIps[] = $record['ip'];
                    } elseif ($record['type'] === 'AAAA' && isset($record['ipv6'])) {
                        $resolvedIps[] = $record['ipv6'];
                    }
                }
            }
        }

        // Fallback to gethostbynamel for IPv4
        if (empty($resolvedIps)) {
            $ipv4s = gethostbynamel($host);
            if (is_array($ipv4s)) {
                $resolvedIps = array_merge($resolvedIps, $ipv4s);
            }
        }

        if (empty($resolvedIps)) {
            return false;
        }

        foreach ($resolvedIps as $ip) {
            if (self::isPrivateIp($ip)) {
                return false;
            }
        }

        // Validate that the host does not resolve to local loopback patterns.
        $blocked = ['localhost', '0.0.0.0', '[::]', '[::1]'];
        if (in_array(strtolower($host), $blocked, true)) {
            return false;
        }

        return true;
    }

    /**
     * Resolves a webhook host to a single public IP address that is safe to connect to,
     * for pinning into the cURL handle (CURLOPT_RESOLVE).
     *
     * This closes the DNS-rebinding (time-of-check/time-of-use) window: isValidWebhookUrl()
     * validates at check time, but a normal cURL call re-resolves the host at send time and a
     * malicious resolver can return a public IP for the check and a private IP for the request.
     * By resolving once here, re-verifying every returned record is public, and pinning the exact
     * IP for the connection, the address cURL dials is guaranteed to be the one we validated.
     *
     * @param string $url The target webhook URL (must already satisfy isValidWebhookUrl).
     * @param string|null $reason Output parameter set to the failure key when null is returned.
     * @return string|null A public IP address to pin, or null if the URL is unsafe/unresolvable.
     */
    public static function resolveSafeWebhookIp(string $url, ?string &$reason = null): ?string
    {
        if (!self::isValidWebhookUrl($url)) {
            $reason = 'ssrf';
            return null;
        }

        $parsed = parse_url($url);
        $host = is_array($parsed) && isset($parsed['host']) ? (string) $parsed['host'] : '';
        if ($host === '') {
            $reason = 'host';
            return null;
        }

        // Literal IP host already passed the public-range check in isValidWebhookUrl().
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return $host;
        }

        $ips = [];
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);
        if (is_array($records)) {
            foreach ($records as $record) {
                if (($record['type'] ?? '') === 'A' && isset($record['ip']) && is_string($record['ip'])) {
                    $ips[] = $record['ip'];
                } elseif (($record['type'] ?? '') === 'AAAA' && isset($record['ipv6']) && is_string($record['ipv6'])) {
                    $ips[] = $record['ipv6'];
                }
            }
        }
        if ($ips === []) {
            $ipv4s = gethostbynamel($host);
            if (is_array($ipv4s)) {
                $ips = $ipv4s;
            }
        }
        if ($ips === []) {
            $reason = 'unresolvable';
            return null;
        }

        // Re-verify every resolved address is public, then pin the first one.
        foreach ($ips as $ip) {
            if (self::isPrivateIp($ip)) {
                $reason = 'private';
                return null;
            }
        }

        return $ips[0];
    }

    /**
     * Cleanses a URL string by blocking unsafe URI schemes and rejecting any URL
     * containing characters outside the URL-safe set.
     *
     * PHP 8.1 deprecated FILTER_SANITIZE_URL and PHP 8.2 removed it; on PHP 8.2+
     * filter_var($url, FILTER_SANITIZE_URL) returns false, so the previous
     * implementation silently dropped every URL parameter. We replace that
     * filter with an explicit allowlist approach:
     *
     *   1. Trim the URL.
     *   2. Reject dangerous schemes (javascript:, data:, vbscript:, file:).
     *   3. Require an allowed scheme prefix (http://, https://, mailto:,
     *      tel:, ftp://).
     *   4. Reject if the URL contains any character outside the URL-safe
     *      character set [a-zA-Z0-9-._~:/?#\[\]@!$&'()*+,;=%].
     *   5. Otherwise return the URL as-is.
     *
     * @param string $url The raw URL string.
     * @return string The sanitized URL string, or empty if dangerous or invalid.
     */
    public static function sanitize(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }

        // Terminate validation if the URL initiates with a dangerous scheme pattern.
        $dangerous = ['javascript:', 'data:', 'vbscript:', 'file:'];
        foreach ($dangerous as $scheme) {
            if (stripos($url, $scheme) === 0) {
                return '';
            }
        }

        // Require an allowed scheme prefix. Anything else (e.g. relative URLs,
        // protocol-relative URLs, unknown schemes) is rejected.
        $schemeOk = false;
        foreach (self::SANITIZE_ALLOWED_SCHEME_PREFIXES as $prefix) {
            if (stripos($url, $prefix) === 0) {
                $schemeOk = true;
                break;
            }
        }
        if (!$schemeOk) {
            return '';
        }

        // Reject any character outside the URL-safe set. This catches control
        // characters, whitespace, quotes, backticks, and anything else that
        // could be used to break out of a URL context.
        if (preg_match(self::SANITIZE_ALLOWED_CHARS_REGEX, $url) !== 1) {
            return '';
        }

        return $url;
    }

    /**
     * Checks if an IP falls within private or reserved address blocks.
     *
     * @param string $ip The target IP address to check.
     * @return bool True if the IP is private or reserved, otherwise false.
     */
    private static function isPrivateIp(string $ip): bool
    {
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) === false;
    }
}
