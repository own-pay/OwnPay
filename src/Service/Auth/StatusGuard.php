<?php

declare(strict_types=1);

namespace OwnPay\Service\Auth;

/**
 * StatusGuard — Lightweight state machine guard for transaction status transitions.
 *
 * Mirrors the TRANSITIONS map from PaymentService but provides a simpler,
 * static API for use from the legacy adapter.php code.
 *
 * The legacy system uses these statuses:
 *   initiated, pending, processing, completed, failed, canceled, refunded
 *
 * Usage:
 *   \OwnPay\Service\Auth\StatusGuard::assertTransition('initiated', 'completed');
 *   // throws InvalidArgumentException if the transition is invalid
 */
final class StatusGuard
{
    /**
     * Allowed state transitions: from => [to, ...]
     *
     * Designed to prevent nonsensical transitions like:
     *   failed → completed, refunded → initiated, completed → initiated
     */
    private const TRANSITIONS = [
        'initiated' => ['pending', 'processing', 'completed', 'failed', 'canceled'],
        'pending' => ['processing', 'completed', 'failed', 'canceled', 'refunded'],
        'processing' => ['completed', 'failed', 'canceled'],
        'completed' => ['refunded', 'partially_refunded'],
        'failed' => ['initiated'],  // retry allowed
        'canceled' => [],              // terminal state
        'refunded' => [],              // terminal state
        'partially_refunded' => ['refunded'],    // can complete refund
    ];

    /**
     * Check if a status transition is allowed.
     */
    public static function canTransition(string $from, string $to): bool
    {
        $from = strtolower(trim($from));
        $to = strtolower(trim($to));

        if (!isset(self::TRANSITIONS[$from])) {
            return false; // unknown source status
        }

        return in_array($to, self::TRANSITIONS[$from], true);
    }

    /**
     * Assert that a status transition is allowed — throws if not.
     *
     * @throws \InvalidArgumentException
     */
    public static function assertTransition(string $from, string $to): void
    {
        if (!self::canTransition($from, $to)) {
            throw new \InvalidArgumentException(
                "Invalid transaction status transition: '{$from}' → '{$to}'. " .
                "Allowed transitions from '{$from}': [" .
                implode(', ', self::TRANSITIONS[$from] ?? []) . ']'
            );
        }
    }

    /**
     * Get all allowed target statuses from a given status.
     *
     * @return string[]
     */
    public static function allowedFrom(string $status): array
    {
        return self::TRANSITIONS[strtolower(trim($status))] ?? [];
    }
}
