<?php
declare(strict_types=1);

namespace OwnPay\Core;

/**
 * Class RequestHelper
 *
 * Provides static helper methods for extracting API headers and device information from HTTP requests.
 *
 * @package OwnPay\Core
 */
final class RequestHelper
{
    /**
     * Retrieves the custom X-API-Key authorization header from the request headers or server variables.
     *
     * @return string|null The API Key string, or null if not present.
     */
    public static function getAuthorizationHeader(): ?string
    {
        if (function_exists('getallheaders')) {
            $headers = getallheaders();
            if (isset($headers['X-API-Key'])) {
                return trim($headers['X-API-Key']);
            }
        }

        foreach ($_SERVER as $key => $value) {
            if (is_string($key) && stripos($key, 'HTTP_X_API_KEY') !== false) {
                return is_scalar($value) ? trim((string) $value) : '';
            }
        }

        return null;
    }

    /**
     * Parses the request metadata to extract IP, Device Type, OS, and Browser.
     *
     * @param \OwnPay\Http\Request|null $request The HTTP request instance.
     * @return array{ip_address: string, device: string, os: string, browser: string} The parsed client information.
     */
    public static function getUserDeviceInfo(?\OwnPay\Http\Request $request = null): array
    {
        $uaVal = $request?->header('User-Agent') ?? $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
        $userAgent = is_scalar($uaVal) ? (string) $uaVal : 'Unknown';
        $ipVal = $request?->ip() ?? $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
        $ipAddress = is_scalar($ipVal) ? (string) $ipVal : 'Unknown';

        $deviceType = match (true) {
            (bool) preg_match('/mobile/i', $userAgent) => 'Mobile',
            (bool) preg_match('/tablet/i', $userAgent) => 'Tablet',
            default                                    => 'Desktop',
        };

        $os = match (true) {
            (bool) preg_match('/Windows/i', $userAgent)    => 'Windows',
            (bool) preg_match('/Mac/i', $userAgent)        => 'Mac OS',
            (bool) preg_match('/Linux/i', $userAgent)      => 'Linux',
            (bool) preg_match('/Android/i', $userAgent)    => 'Android',
            (bool) preg_match('/iPhone|iPad/i', $userAgent) => 'iOS',
            default                                        => 'Unknown OS',
        };

        $browser = match (true) {
            (bool) preg_match('/MSIE|Trident/i', $userAgent) => 'Internet Explorer',
            (bool) preg_match('/Firefox/i', $userAgent)      => 'Firefox',
            (bool) preg_match('/Chrome/i', $userAgent)       => 'Chrome',
            (bool) preg_match('/Safari/i', $userAgent)       => 'Safari',
            (bool) preg_match('/Opera|OPR/i', $userAgent)    => 'Opera',
            (bool) preg_match('/Edge/i', $userAgent)         => 'Edge',
            default                                          => 'Unknown Browser',
        };

        return [
            'ip_address' => $ipAddress,
            'device'     => $deviceType,
            'os'         => $os,
            'browser'    => $browser,
        ];
    }
}
