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
            $isHttps = $request->header('X-Forwarded-Proto') === 'https' || $request->isSecure();
            $hostVal = $request->header('Host') ?: 'localhost';
            $host = (string) $hostVal;
            $requestUri = $request->uri();
        } else {
            $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'
                || ($_SERVER['SERVER_PORT'] ?? 0) == 443);
            $hostVal = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $host = is_string($hostVal) ? $hostVal : 'localhost';
            $requestUriVal = $_SERVER['REQUEST_URI'] ?? '';
            $requestUri = is_string($requestUriVal) ? $requestUriVal : '';
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
}
