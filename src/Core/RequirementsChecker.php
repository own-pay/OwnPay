<?php

declare(strict_types=1);

namespace OwnPay\Core;

/**
 * System requirements checker.
 *
 * Validates PHP version, extensions, and dependencies
 * before the application boots.
 */
final class RequirementsChecker
{
    /**
     * Check all system requirements.
     *
     * @return bool True if all requirements are met.
     */
    public static function check(): bool
    {
        $requirements = self::getRequirements();

        foreach ($requirements as $req) {
            if (!$req['check']) {
                return false;
            }
        }

        return true;
    }

    /**
     * Get the full requirements list with check results.
     *
     * @return array<int, array{name: string, required: string, current: string, check: bool}>
     */
    public static function getRequirements(): array
    {
        return [
            [
                'name'     => 'PHP Version',
                'required' => '8.3.x - 8.4.x',
                'current'  => PHP_VERSION,
                'check'    => version_compare(PHP_VERSION, '8.5.0', '<'),
            ],
            [
                'name'     => 'cURL',
                'required' => 'Enabled',
                'current'  => function_exists('curl_init') ? 'Enabled' : 'Disabled',
                'check'    => function_exists('curl_init'),
            ],
            [
                'name'     => 'PDO',
                'required' => 'Enabled',
                'current'  => extension_loaded('pdo') && class_exists('PDO') ? 'Enabled' : 'Disabled',
                'check'    => extension_loaded('pdo') && class_exists('PDO'),
            ],
            [
                'name'     => 'GD Library',
                'required' => 'Enabled',
                'current'  => extension_loaded('gd') ? 'Enabled' : 'Disabled',
                'check'    => extension_loaded('gd'),
            ],
            [
                'name'     => 'Fileinfo',
                'required' => 'Enabled',
                'current'  => function_exists('finfo_open') ? 'Enabled' : 'Disabled',
                'check'    => function_exists('finfo_open'),
            ],
            [
                'name'     => 'OpenSSL',
                'required' => 'Enabled',
                'current'  => extension_loaded('openssl') ? 'Enabled' : 'Disabled',
                'check'    => extension_loaded('openssl'),
            ],
            [
                'name'     => 'ZipArchive',
                'required' => 'Enabled',
                'current'  => (extension_loaded('zip') && class_exists('ZipArchive')) ? 'Enabled' : 'Disabled',
                'check'    => extension_loaded('zip') && class_exists('ZipArchive'),
            ],
            [
                'name'     => 'Mbstring',
                'required' => 'Enabled',
                'current'  => extension_loaded('mbstring') ? 'Enabled' : 'Disabled',
                'check'    => extension_loaded('mbstring'),
            ],
            [
                'name'     => 'Tokenizer',
                'required' => 'Enabled',
                'current'  => extension_loaded('tokenizer') ? 'Enabled' : 'Disabled',
                'check'    => extension_loaded('tokenizer'),
            ],
            [
                'name'     => 'JSON',
                'required' => 'Enabled',
                'current'  => extension_loaded('json') ? 'Enabled' : 'Disabled',
                'check'    => extension_loaded('json'),
            ],
            [
                'name'     => 'BCMath',
                'required' => 'Enabled',
                'current'  => extension_loaded('bcmath') ? 'Enabled' : 'Disabled',
                'check'    => extension_loaded('bcmath'),
            ],
            [
                'name'     => 'Composer Dependencies',
                'required' => 'Installed',
                'current'  => file_exists(dirname(__DIR__, 2) . '/vendor/autoload.php') ? 'Installed' : 'Missing',
                'check'    => file_exists(dirname(__DIR__, 2) . '/vendor/autoload.php'),
            ],
        ];
    }
}
