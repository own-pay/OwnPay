<?php
declare(strict_types=1);

namespace OwnPay\Plugin;

/**
 * Plugin capability enum — declares what a plugin can do.
 *
 * Used by PluginLoader for capability-based routing and sandbox permissions.
 */
enum Capability: string
{
    case GATEWAY       = 'gateway';        // Payment gateway
    case THEME         = 'theme';          // Frontend theme
    case ADDON         = 'addon';          // General addon
    case COMMUNICATION = 'communication';  // SMS/Email/Telegram provider
    case ANALYTICS     = 'analytics';      // Analytics/reporting
    case WEBHOOK       = 'webhook';        // Custom webhook handler
    case NOTIFICATION  = 'notification';   // Push/notification provider
    case EXPORT        = 'export';         // Data export format
    case AUTHENTICATION = 'authentication'; // Auth provider (SSO, OAuth)
    case STORAGE       = 'storage';        // File storage provider
    case CRON          = 'cron';           // Scheduled task
    case DASHBOARD     = 'dashboard';      // Dashboard widget

    /**
     * Required permissions for this capability.
     * @return string[]
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
