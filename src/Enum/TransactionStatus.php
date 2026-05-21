<?php
declare(strict_types=1);

namespace OwnPay\Enum;

/**
 * Enum TransactionStatus
 *
 * Defines the canonical state lifecycle of payment transactions within the system database (op_transactions).
 * These status states govern transaction eligibility transition rules, determine ledger post triggers
 * for double-entry bookkeeping (e.g. balancing accounts only upon entering terminal Completed state),
 * and restrict gateway interaction scopes during checkout execution.
 *
 * @package OwnPay\Enum
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
     * Retrieve status values where checkout payment submissions are actively permitted.
     *
     * @return array<int, \OwnPay\Enum\TransactionStatus> An array of active checkout statuses.
     */
    public static function checkoutActive(): array
    {
        return [self::Pending, self::Created];
    }

    /**
     * Generate an SQL IN clause fragment containing escaped active checkout status values.
     *
     * @return string SQL safe status value list.
     */
    public static function checkoutActiveIn(): string
    {
        return "'" . implode("','", array_map(fn(self $s) => $s->value, self::checkoutActive())) . "'";
    }

    /**
     * Retrieve statuses representing final terminal nodes in the transaction state machine.
     *
     * Terminal statuses prohibit any subsequent programmatic status updates.
     *
     * @return array<int, \OwnPay\Enum\TransactionStatus> An array of terminal transaction statuses.
     */
    public static function terminal(): array
    {
        return [self::Completed, self::Failed, self::Cancelled, self::Expired, self::Refunded];
    }

    /**
     * Determine if this transaction status represents a terminal state.
     *
     * @return bool True if terminal, false otherwise.
     */
    public function isTerminal(): bool
    {
        return in_array($this, self::terminal(), true);
    }

    /**
     * Retrieve a human-readable display label matching this status.
     *
     * @return string The display label.
     */
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

    /**
     * Retrieve the CSS badge color styling identifier associated with this status.
     *
     * @return string The color identifier name.
     */
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

