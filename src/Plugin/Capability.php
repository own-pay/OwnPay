<?php
declare(strict_types=1);

namespace OwnPay\Plugin;

/**
 * Declares the architectural capabilities of plugins within OwnPay.
 *
 * In the OwnPay single-owner, multi-brand platform, plugins are categorized by
 * capabilities to facilitate capability-based routing, RBAC isolation, and sandbox constraints.
 *
 * @category Plugin
 * @package  OwnPay\Plugin
 */
enum Capability: string
{
    /** Gateway payment processing capability. */
    case GATEWAY       = 'gateway';

    /** Frontend layout and theme customization capability. */
    case THEME         = 'theme';

    /** General application extension capability. */
    case ADDON         = 'addon';

    /** SMS, Email, or Telegram communication provider capability. */
    case COMMUNICATION = 'communication';

    /** Business intelligence, reporting, and analytics capability. */
    case ANALYTICS     = 'analytics';

    /** Custom webhook handler capability. */
    case WEBHOOK       = 'webhook';

    /** Push and system notification provider capability. */
    case NOTIFICATION  = 'notification';

    /** Data export and format handler capability. */
    case EXPORT        = 'export';

    /** Single sign-on (SSO) and OAuth authentication provider capability. */
    case AUTHENTICATION = 'authentication';

    /** External file storage provider capability. */
    case STORAGE       = 'storage';

    /** Scheduled tasks and background cron execution capability. */
    case CRON          = 'cron';

    /** Custom administration dashboard widget capability. */
    case DASHBOARD     = 'dashboard';

    /** Database read access capability. */
    case DB_READ        = 'db_read';

    /** Database write access capability. */
    case DB_WRITE       = 'db_write';

    /** File system read access capability. */
    case FILE_READ      = 'file_read';

    /** File system write access capability. */
    case FILE_WRITE     = 'file_write';

    /** Outbound HTTP request capability. */
    case HTTP_OUTBOUND  = 'http_outbound';

    /** Event hooks registration capability. */
    case HOOKS          = 'hooks';

    /** Checkout UI modification and injection capability. */
    case CHECKOUT_UI    = 'checkout_ui';

    /**
     * Resolves the list of permission keys required for the capability.
     *
     * In the double-entry billing and merchant-scoped gateway runtime,
     * permissions restrict plugin execution within specific security domains.
     *
     * @return array<int, string> List of required permission identifiers.
     */
    public function requiredPermissions(): array
    {
        return match ($this) {
            self::GATEWAY       => ['gateway.process', 'gateway.config'],
            self::THEME         => ['theme.render'],
            self::COMMUNICATION => ['comm.send'],
            self::ANALYTICS     => ['analytics.read'],
            self::STORAGE       => ['storage.read', 'storage.write'],
            default             => [],
        };
    }
}
