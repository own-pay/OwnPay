<?php
declare(strict_types=1);

namespace OwnPay\Enum;

/**
 * TransactionStatus — canonical status values for op_transactions.
 *
 * Replaces 21+ magic strings scattered across services/controllers.
 * Use ->value for DB storage, ::from() for deserialization.
 */
enum TransactionStatus: string
{
    case Pending = 'pending';
    case Created = 'created';
    case AwaitingVerification = 'awaiting_verification';
    case PendingReview = 'pending_review';
    case Processing = 'processing';
    case CallbackProcessing = 'callback_processing';
    case Completed = 'completed';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
    case Expired = 'expired';
    case Refunded = 'refunded';

    /**
     * Statuses that allow checkout payment submission.
     * @return self[]
     */
    public static function checkoutActive(): array
    {
        return [self::Pending, self::Created];
    }

    /**
     * SQL IN clause fragment for active checkout statuses.
     */
    public static function checkoutActiveIn(): string
    {
        return "'" . implode("','", array_map(fn(self $s) => $s->value, self::checkoutActive())) . "'";
    }

    /**
     * Terminal statuses (no further transitions).
     * @return self[]
     */
    public static function terminal(): array
    {
        return [self::Completed, self::Failed, self::Cancelled, self::Expired, self::Refunded];
    }

    public function isTerminal(): bool
    {
        return in_array($this, self::terminal(), true);
    }

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Created => 'Created',
            self::AwaitingVerification => 'Awaiting Verification',
            self::PendingReview => 'Pending Review',
            self::Processing => 'Processing',
            self::CallbackProcessing => 'Callback Processing',
            self::Completed => 'Completed',
            self::Failed => 'Failed',
            self::Cancelled => 'Cancelled',
            self::Expired => 'Expired',
            self::Refunded => 'Refunded',
        };
    }

    public function badgeColor(): string
    {
        return match ($this) {
            self::Completed => 'green',
            self::Pending, self::Created, self::Processing, self::CallbackProcessing => 'yellow',
            self::AwaitingVerification, self::PendingReview => 'blue',
            self::Failed => 'red',
            self::Cancelled, self::Expired => 'gray',
            self::Refunded => 'orange',
        };
    }
}
