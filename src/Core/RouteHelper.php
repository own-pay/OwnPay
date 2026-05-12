<?php

declare(strict_types=1);

namespace OwnPay\Core;

final class RouteHelper
{
    public static function siteUrl(string $type = "Full"): string
    {
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'
            || ($_SERVER['SERVER_PORT'] ?? 0) == 443) ? "https://" : "http://";

        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $requestUri = $_SERVER['REQUEST_URI'] ?? '';

        $hostParts = explode('.', $host);
        $numParts = count($hostParts);
        $mainDomain = ($numParts >= 2)
            ? $hostParts[$numParts - 2] . '.' . $hostParts[$numParts - 1]
            : $host;

        return match (strtolower($type)) {
            'fulldomain' => $protocol . $host,
            'maindomain' => $mainDomain,
            default      => $protocol . $host . $requestUri,
        };
    }

    public static function getAdminPath(string $url): string
    {
        $url = explode('?', $url)[0];
        $pos = strpos($url, 'admin/');
        if ($pos === false) {
            return '';
        }
        return trim(substr($url, $pos + strlen('admin/')), '/');
    }

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

        $host = preg_replace('/^www\./i', '', $host);

        if (!preg_match('/^(?!-)(?:[a-z0-9-]{1,63}\.)+[a-z]{2,}$/i', $host)) {
            return false;
        }

        return strtolower($host);
    }

    public static function addQueryParams(string $url, array $params = []): string
    {
        $parsedUrl = parse_url($url);

        $existingParams = [];
        if (!empty($parsedUrl['query'])) {
            parse_str($parsedUrl['query'], $existingParams);
        }

        $finalParams = array_merge($existingParams, $params);
        $queryString = http_build_query($finalParams);

        $baseUrl =
            ($parsedUrl['scheme'] ?? '') . ($parsedUrl['scheme'] ? '://' : '') .
            ($parsedUrl['host'] ?? '') .
            ($parsedUrl['path'] ?? '');

        return $baseUrl . '?' . $queryString;
    }
}
