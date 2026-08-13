<?php

declare(strict_types=1);

namespace OwnPay\Core;

/**
 * Route and URL parsing helper utilities.
 *
 * Implements functions for extracting domains, admin path structures, appending query parameters,
 * and resolving full/main domains.
 */
final class RouteHelper
{
    /**
     * Resolves the current site URL, main domain, or full domain depending on resolution type.
     *
     * Automatically handles secure protocols, Host headers, and strips port segments to avoid
     * resolution errors (e.g. during localhost testing on non-standard ports).
     *
     * @param string $type The resolution type ('FullDomain', 'MainDomain', or default 'Full').
     * @param \OwnPay\Http\Request|null $request The current active request instance to parse from.
     * @return string The resolved URL or domain string.
     */
    public static function siteUrl(string $type = "Full", ?\OwnPay\Http\Request $request = null): string
    {
        $isHttps = false;
        $host = 'localhost';
        $requestUri = '';
        if ($request !== null) {
            // HTTP-1: rely solely on Request::isSecure() for scheme
            // detection. It already performs the trusted-proxy check
            // (Request::scheme() only honors X-Forwarded-Proto when
            // REMOTE_ADDR is in TRUSTED_PROXIES). The previous direct
            // read of the X-Forwarded-Proto header allowed any end user
            // to spoof the scheme by sending the header themselves, which
            // could cause password-reset emails and Secure-cookie flags
            // to be computed against an attacker-chosen scheme.
            $isHttps = $request->isSecure();
            $hostVal = $request->header('Host') ?: 'localhost';
            $host = (string) $hostVal;
            $requestUri = $request->uri();
        } else {
            // No-Request fallback (CLI / pre-boot): use the raw $_SERVER
            // values. The X-Forwarded-Proto header is deliberately NOT
            // consulted here either, to keep behavior consistent with the
            // Request branch.
            $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'
                || ($_SERVER['SERVER_PORT'] ?? 0) == 443);
            $hostVal = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $host = is_string($hostVal) ? $hostVal : 'localhost';
            $requestUriVal = $_SERVER['REQUEST_URI'] ?? '';
            $requestUri = is_string($requestUriVal) ? $requestUriVal : '';
        }

        // HTTP-2: Validate the Host header against an allow-list of
        // permitted hosts. The Host header is fully client-controlled in
        // HTTP/1.1, so without validation any caller of siteUrl() that
        // places the result in an outbound email, redirect, or JSON
        // response is vulnerable to host-header injection — most
        // critically the password-reset flow, where a spoofed
        // Host: attacker.com causes the victim to receive a reset-email
        // link pointing at the attacker's host, leaking the reset token
        // when clicked.
        //
        // We check against (in order of preference):
        //   1. ALLOWED_HOSTS env var (comma-separated)
        //   2. APP_URL env var (parse its host)
        //   3. $_SERVER['SERVER_NAME'] (set by web server config, not
        //      the client)
        // If the Host header does not match any allowed host, we fall
        // back to the configured APP_URL or SERVER_NAME rather than
        // echoing the attacker-controlled value back into the response.
        // When no validation is configured, we return null and the
        // caller uses the raw Host (legacy behavior for backward
        // compatibility).
        $validatedHost = self::validateHost($host);
        if ($validatedHost !== null) {
            $host = $validatedHost;
        }

        $protocol = $isHttps ? 'https://' : 'http://';

        $hostWithoutPort = preg_replace('/:\d+$/', '', $host);
        $hostWithoutPortStr = is_string($hostWithoutPort) ? $hostWithoutPort : $host;
        $hostParts = explode('.', $hostWithoutPortStr);
        $numParts = count($hostParts);
        $mainDomain = ($numParts >= 2)
            ? $hostParts[$numParts - 2] . '.' . $hostParts[$numParts - 1]
            : $hostWithoutPortStr;

        return match (strtolower($type)) {
            'fulldomain' => $protocol . $host,
            'maindomain' => $mainDomain,
            default      => $protocol . $host . $requestUri,
        };
    }

    /**
     * Extracts the relative path within the admin panel area from a given URL string.
     *
     * Useful for checking navigation states and parsing routing sub-paths.
     *
     * @param string $url The complete URL or path string.
     * @return string The relative admin path segment.
     */
    public static function getAdminPath(string $url): string
    {
        $url = explode('?', $url)[0];
        $pos = strpos($url, 'admin/');
        if ($pos === false) {
            return '';
        }
        return trim(substr($url, $pos + strlen('admin/')), '/');
    }

    /**
     * Validates and standardizes a domain name representation from user input or configuration settings.
     *
     * Strips leading protocol schemes and 'www.' prefixes, then validates standard hostname format constraints.
     *
     * @param string $input The raw input string containing a domain or URL.
     * @return string|false The standardized lowercase domain string, or false if invalid.
     */
    public static function getDomainValue(string $input): string|false
    {
        $input = trim($input);

        if ($input === '') {
            return false;
        }

        if (!preg_match('#^https?://#i', $input)) {
            $input = 'http://' . $input;
        }

        $host = parse_url($input, PHP_URL_HOST);
        if (!$host) {
            return false;
        }

        $host = (string) preg_replace('/^www\./i', '', $host);

        if (!preg_match('/^(?!-)(?:[a-z0-9-]{1,63}\.)+[a-z]{2,}$/i', $host)) {
            return false;
        }

        return strtolower($host);
    }

    /**
     * Appends query parameters to an existing URL string while maintaining existing parameter structures.
     *
     * @param string $url The base URL string.
     * @param array<string, mixed> $params The associative array of query parameters to merge.
     * @return string The formatted URL with combined query parameters.
     */
    public static function addQueryParams(string $url, array $params = []): string
    {
        $parsedUrl = parse_url($url);
        if ($parsedUrl === false) {
            return $url;
        }

        $existingParams = [];
        if (!empty($parsedUrl['query'])) {
            parse_str($parsedUrl['query'], $existingParams);
        }

        $finalParams = array_merge($existingParams, $params);
        $queryString = http_build_query($finalParams);

        $baseUrl =
            (!empty($parsedUrl['scheme']) ? $parsedUrl['scheme'] . '://' : '') .
            ($parsedUrl['host'] ?? '') .
            (!empty($parsedUrl['port']) ? ':' . $parsedUrl['port'] : '') .
            ($parsedUrl['path'] ?? '');

        return $baseUrl . '?' . $queryString;
    }

    /**
     * Validate the supplied Host header against the configured allow-list.
     *
     * Returns the validated host (which may be the supplied value, or a
     * fallback when the supplied value is not allowed) or null when no
     * validation is configured (legacy behavior — caller uses the raw Host).
     *
     * @param string $host The Host header value to validate.
     * @return string|null The validated host, or null when no allow-list is configured.
     */
    private static function validateHost(string $host): ?string
    {
        // Strip port for comparison.
        $hostWithoutPort = preg_replace('/:\d+$/', '', $host);
        $hostForComparison = is_string($hostWithoutPort) ? strtolower($hostWithoutPort) : strtolower($host);

        // 1. ALLOWED_HOSTS env var (comma-separated).
        $allowedEnv = $_ENV['ALLOWED_HOSTS'] ?? $_SERVER['ALLOWED_HOSTS'] ?? getenv('ALLOWED_HOSTS') ?: '';
        if (is_string($allowedEnv) && $allowedEnv !== '') {
            $allowed = array_map(
                static fn (string $h) => strtolower(trim($h)),
                explode(',', $allowedEnv)
            );
            $allowed = array_filter($allowed, static fn (string $h) => $h !== '');
            if (!empty($allowed)) {
                if (in_array($hostForComparison, $allowed, true)) {
                    return $host;
                }
                // Host not in allow-list — fall back to the first allowed host.
                return $allowed[0];
            }
        }

        // 2. APP_URL env var.
        $appUrl = $_ENV['APP_URL'] ?? $_SERVER['APP_URL'] ?? getenv('APP_URL') ?: '';
        if (is_string($appUrl) && $appUrl !== '') {
            $parsed = parse_url($appUrl);
            $appHost = is_array($parsed) && isset($parsed['host']) ? strtolower((string) $parsed['host']) : '';
            if ($appHost !== '') {
                if ($hostForComparison === $appHost) {
                    return $host;
                }
                // Preserve port from APP_URL if present.
                $port = is_array($parsed) && isset($parsed['port']) ? ':' . (int) $parsed['port'] : '';
                return $appHost . $port;
            }
        }

        // 3. $_SERVER['SERVER_NAME'] (web-server-config-set, not client-controlled).
        $serverName = $_SERVER['SERVER_NAME'] ?? '';
        if (is_string($serverName) && $serverName !== '') {
            $serverNameLower = strtolower($serverName);
            if ($hostForComparison === $serverNameLower) {
                return $host;
            }
            return $serverName;
        }

        // No validation configured — return null to signal "use the raw Host".
        // This preserves backward compatibility for installations that have
        // not yet set ALLOWED_HOSTS or APP_URL. Operators should set one of
        // these to enable host-header validation.
        return null;
    }
}
