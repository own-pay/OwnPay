<?php
declare(strict_types=1);

namespace OwnPay\Service\Auth;

use OwnPay\Support\DateHelper;

/**
 * OwnPay Status Guard.
 *
 * Implements centralized state evaluations for merchants, users, gateway setups,
 * and API keys, asserting active lifetimes and throwing guard exceptions on violations.
 *
 * @package OwnPay\Service\Auth
 */
final class StatusGuard
{
    /**
     * @var string[] List of system statuses that authorize active operations.
     */
    private const ACTIVE_STATUSES = ['active'];

    /**
     * Evaluates if the provided merchant/brand record is in an active state.
     *
     * @param array<string, mixed> $merchant The merchant profile entity array.
     * @return bool True if active; false otherwise.
     */
    public static function isMerchantActive(array $merchant): bool
    {
        return in_array($merchant['status'] ?? '', self::ACTIVE_STATUSES, true);
    }

    /**
     * Evaluates if a user account is active.
     *
     * @param array<string, mixed> $user The system user entity array.
     * @return bool True if active; false otherwise.
     */
    public static function isUserActive(array $user): bool
    {
        return in_array($user['status'] ?? '', self::ACTIVE_STATUSES, true);
    }

    /**
     * Evaluates if a configured gateway is active.
     *
     * @param array<string, mixed> $gatewayConfig The gateway configuration entity array.
     * @return bool True if active; false otherwise.
     */
    public static function isGatewayUsable(array $gatewayConfig): bool
    {
        return ($gatewayConfig['status'] ?? '') === 'active';
    }

    /**
     * Evaluates if an API key is valid (active and not expired).
     *
     * @param array{status?: string, expires_at?: string|null} $apiKey The api key entity array.
     * @return bool True if valid; false otherwise.
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
     * Evaluates if a paired device is in a valid state for authentication.
     *
     * Uses an allowlist (status must be exactly 'active') rather than a denylist
     * so that any new or unexpected status (pending, suspended, lost, inactive,
     * revoked, etc.) fails closed. This prevents tokens bound to a device in any
     * non-active state from being accepted by JwtAuthMiddleware.
     *
     * @param string $status The paired device status string from the database.
     * @return bool True only when the status is exactly 'active'.
     */
    public static function isDeviceStatusValid(string $status): bool
    {
        return $status === 'active';
    }

    /**
     * Asserts that an entity state is active, throwing an exception upon failure.
     *
     * @param array{status?: string} $entity The entity array to check.
     * @param string $label Debug identifier label for inclusion in the exception.
     * @return void
     * @throws \RuntimeException If the entity's status does not match an active status.
     */
    public static function requireActive(array $entity, string $label = 'Entity'): void
    {
        $status = $entity['status'] ?? 'inactive';
        if (!in_array($status, self::ACTIVE_STATUSES, true)) {
            throw new \RuntimeException("{$label} is not active (status: {$status})");
        }
    }
}
