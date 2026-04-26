<?php

declare(strict_types=1);

namespace OwnPay\Plugin;

/**
 * Declares the permission capabilities a plugin may request in its manifest.
 *
 * The PluginSandbox enforces these at install time by static-analysing the
 * plugin source and rejecting any code that exercises an undeclared capability.
 */
enum Capability: string
{
    /** Read data via Repository / Database::fetchOne|fetchAll */
    case DB_READ = 'db_read';

    /** Write data via Repository / Database::execute|insert|update */
    case DB_WRITE = 'db_write';

    /** Read files from the storage/ or media/ directories */
    case FILE_READ = 'file_read';

    /** Write files to storage/ or media/ (e.g. upload handling) */
    case FILE_WRITE = 'file_write';

    /** Make outbound HTTP requests via HttpClient */
    case HTTP_OUTBOUND = 'http_outbound';

    /** Register recurring cron jobs via CronJobRunner */
    case CRON = 'cron';

    /** Register admin sidebar menu entries */
    case ADMIN_MENU = 'admin_menu';

    /** Store and read plugin settings via the env table */
    case SETTINGS = 'settings';

    /** Register action / filter hooks (implied by default) */
    case HOOKS = 'hooks';

    /** Inject or modify checkout-page rendering */
    case CHECKOUT_UI = 'checkout_ui';

    /**
     * Attempt to resolve a capability string to an enum case.
     * Returns null if the string is not a known capability.
     */
    public static function tryFromName(string $name): ?self
    {
        return self::tryFrom($name);
    }

    /**
     * Validate an array of capability strings.
     *
     * @param list<string> $capabilities
     * @return list<string> Invalid capability strings (empty if all valid)
     */
    public static function validateAll(array $capabilities): array
    {
        $invalid = [];
        foreach ($capabilities as $cap) {
            if (self::tryFrom($cap) === null) {
                $invalid[] = $cap;
            }
        }
        return $invalid;
    }
}
