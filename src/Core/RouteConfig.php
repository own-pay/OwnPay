<?php

declare(strict_types=1);

namespace OwnPay\Core;

/**
 * Route configuration loader.
 *
 * Reads configurable route paths from the environment
 * (admin path, payment path, etc.) with sensible defaults.
 */
final class RouteConfig
{
    /**
     * Load route configuration from the environment.
     *
     * @return array{
     *     payment: string,
     *     invoice: string,
     *     paymentLink: string,
     *     admin: string,
     *     cron: string,
     *     homepageRedirect: string
     * }
     */
    public static function load(): array
    {
        return [
            'payment'          => self::env('geneal-application-settings-paymentPath', 'payment'),
            'invoice'          => self::env('geneal-application-settings-invoicePath', 'invoice'),
            'paymentLink'      => self::env('geneal-application-settings-paymentLinkPath', 'payment-link'),
            'admin'            => self::env('geneal-application-settings-adminPath', 'admin'),
            'cron'             => self::env('geneal-application-settings-cronPath', 'cron'),
            'homepageRedirect' => self::env('geneal-application-settings-homepageRedirect', ''),
        ];
    }

    /**
     * Get an environment value with a default fallback.
     */
    private static function env(string $key, string $default): string
    {
        try {
            $value = \OwnPay\Service\System\EnvironmentService::get($key);
            return ($value !== null && $value !== '') ? $value : $default;
        } catch (\Throwable) {
            return $default;
        }
    }
}
