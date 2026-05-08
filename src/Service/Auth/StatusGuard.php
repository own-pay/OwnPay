<?php
declare(strict_types=1);

namespace OwnPay\Service\Auth;

use OwnPay\Support\DateHelper;

/**
 * Status guard â€” checks entity status for access control.
 */
final class StatusGuard
{
    /** Active statuses that allow operations */
    private const ACTIVE_STATUSES = ['active'];

    /**
     * Check if merchant is active.
     */
    public static function isMerchantActive(array $merchant): bool
    {
        return in_array($merchant['status'] ?? '', self::ACTIVE_STATUSES, true);
    }

    /**
     * Check if user account is active.
     */
    public static function isUserActive(array $user): bool
    {
        return in_array($user['status'] ?? '', self::ACTIVE_STATUSES, true);
    }

    /**
     * Check if gateway config is live/test mode and active.
     */
    public static function isGatewayUsable(array $gatewayConfig): bool
    {
        return ($gatewayConfig['status'] ?? '') === 'active';
    }

    /**
     * Check if API key is valid (active + not expired).
     */
    public static function isApiKeyValid(array $apiKey): bool
    {
        if (($apiKey['status'] ?? '') !== 'active') {
            return false;
        }
        if (!empty($apiKey['expires_at']) && DateHelper::isPast($apiKey['expires_at'])) {
            return false;
        }
        return true;
    }

    /**
     * Guard check â€” throws on inactive.
     * @throws \RuntimeException
     */
    public static function requireActive(array $entity, string $label = 'Entity'): void
    {
        if (!in_array($entity['status'] ?? '', self::ACTIVE_STATUSES, true)) {
            throw new \RuntimeException("{$label} is not active (status: {$entity['status']})");
        }
    }
}
