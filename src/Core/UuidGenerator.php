<?php

declare(strict_types=1);

namespace OwnPay\Core;

use Ramsey\Uuid\Uuid;

/**
 * Generates time-ordered UUIDs (v7) for public-facing IDs.
 *
 * UUID v7 is preferred over v4 because:
 * - Naturally sortable by creation time
 * - Better index locality in B-tree (reduces page splits)
 * - Maintains uniqueness guarantees
 */
final class UuidGenerator
{
    /**
     * Generate a new UUID v7 string (36 chars with hyphens).
     */
    public static function generate(): string
    {
        return Uuid::uuid7()->toString();
    }

    /**
     * Validate whether a string is a valid UUID format.
     */
    public static function isValid(string $uuid): bool
    {
        return Uuid::isValid($uuid);
    }
}
