<?php

declare(strict_types=1);

namespace OwnPay\Core;

class RequestHelper
{
    public static function getAuthorizationHeader(): ?string
    {
        if (function_exists('getallheaders')) {
            $headers = getallheaders();
            if (isset($headers['X-API-Key'])) {
                return trim($headers['X-API-Key']);
            }
        }

        foreach ($_SERVER as $key => $value) {
            if (stripos($key, 'HTTP_X_API_KEY') !== false) {
                return trim($value);
            }
        }

        return null;
    }

    public static function getUserDeviceInfo(): array
    {
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
        $ipAddress = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';

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
