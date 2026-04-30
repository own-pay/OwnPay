<?php
declare(strict_types=1);

namespace OwnPay\Service\System;

/**
 * Environment service — runtime environment detection and checks.
 */
final class EnvironmentService
{
    /**
     * Get current environment mode.
     */
    public static function mode(): string
    {
        return getenv('APP_ENV') ?: 'production';
    }

    public static function isProduction(): bool
    {
        return self::mode() === 'production';
    }

    public static function isDevelopment(): bool
    {
        return in_array(self::mode(), ['development', 'dev', 'local'], true);
    }

    public static function isStaging(): bool
    {
        return self::mode() === 'staging';
    }

    /**
     * Check if debug mode is enabled.
     */
    public static function debugEnabled(): bool
    {
        return (getenv('APP_DEBUG') ?: 'false') === 'true';
    }

    /**
     * Get app version.
     */
    public static function version(): string
    {
        return getenv('APP_VERSION') ?: '0.1.0';
    }

    /**
     * Check PHP requirements.
     * @return string[] Errors (empty = OK)
     */
    public static function checkRequirements(): array
    {
        $errors = [];

        if (version_compare(PHP_VERSION, '8.1.0', '<')) {
            $errors[] = 'PHP 8.1+ required (current: ' . PHP_VERSION . ')';
        }

        $requiredExtensions = ['pdo', 'pdo_mysql', 'openssl', 'mbstring', 'json', 'curl', 'bcmath'];
        foreach ($requiredExtensions as $ext) {
            if (!extension_loaded($ext)) {
                $errors[] = "PHP extension required: {$ext}";
            }
        }

        $optionalExtensions = ['redis', 'imagick', 'zip'];
        foreach ($optionalExtensions as $ext) {
            if (!extension_loaded($ext)) {
                // Log warning but don't fail
            }
        }

        return $errors;
    }

    /**
     * Get server info.
     */
    public static function serverInfo(): array
    {
        return [
            'php_version'  => PHP_VERSION,
            'os'           => PHP_OS,
            'sapi'         => PHP_SAPI,
            'memory_limit' => ini_get('memory_limit'),
            'max_upload'   => ini_get('upload_max_filesize'),
            'timezone'     => date_default_timezone_get(),
            'extensions'   => get_loaded_extensions(),
        ];
    }
}
