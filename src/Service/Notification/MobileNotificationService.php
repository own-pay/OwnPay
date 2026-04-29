<?php

declare(strict_types=1);

namespace OwnPay\Service\Notification;

use OwnPay\Repository\MobileNotificationRepository;

/**
 * MobileNotificationService — Business logic for the mobile notification queue.
 *
 * Responsibilities:
 *   - Queue notifications when SMS is parsed successfully
 *   - Provide polling interface for companion app
 *   - Auto-cleanup of stale read notifications
 *
 * Follows the same mixed-constructor pattern for testability.
 */
final class MobileNotificationService
{
    private mixed $notifRepo;

    /** Default poll interval hint sent to mobile app (seconds). */
    private const DEFAULT_POLL_INTERVAL = 10;

    public function __construct(mixed $notifRepo = null)
    {
        $this->notifRepo = $notifRepo ?? new MobileNotificationRepository();
    }

    /**
     * Queue a payment notification for a device.
     *
     * Called by SmsParserService after successful parse.
     *
     * @param string      $deviceUuid Device to notify
     * @param string      $type       Notification type (payment_received, payment_sent, etc.)
     * @param float|null  $amount     Transaction amount
     * @param string|null $sender     Sender/receiver phone
     * @param string|null $trxId      Transaction ID
     * @param string|null $provider   MFS provider (bKash, Nagad, etc.)
     * @return int Notification ID
     */
    public function queuePaymentNotification(
        string $deviceUuid,
        string $type,
        ?float $amount = null,
        ?string $sender = null,
        ?string $trxId = null,
        ?string $provider = null,
    ): int {
        $title = match ($type) {
            'credit'  => 'Payment Received',
            'debit'   => 'Payment Sent',
            default   => 'Transaction Detected',
        };

        // Build body text
        $parts = [];
        if ($amount !== null) {
            $parts[] = 'Tk ' . number_format($amount, 2);
        }
        if ($sender !== null) {
            $parts[] = ($type === 'credit' ? 'from ' : 'to ') . $sender;
        }
        if ($trxId !== null) {
            $parts[] = "(TrxID: {$trxId})";
        }

        $body = !empty($parts) ? implode(' ', $parts) : 'New transaction detected.';

        $payload = array_filter([
            'amount'   => $amount,
            'trx_id'   => $trxId,
            'sender'   => $sender,
            'provider' => $provider,
            'type'     => $type,
        ], fn ($v) => $v !== null);

        return $this->notifRepo->create(
            $deviceUuid,
            'payment_' . ($type === 'credit' ? 'received' : ($type === 'debit' ? 'sent' : 'detected')),
            $title,
            $body,
            $payload
        );
    }

    /**
     * Poll notifications for a device.
     *
     * @param string      $deviceUuid
     * @param string|null $since  ISO timestamp cursor
     * @return array{notifications: array, unread_count: int, poll_interval_seconds: int}
     */
    public function poll(string $deviceUuid, ?string $since = null): array
    {
        $notifications = $this->notifRepo->pollSince($deviceUuid, $since);

        // Decode JSON payload for each notification
        foreach ($notifications as &$n) {
            if (isset($n['payload']) && is_string($n['payload'])) {
                $n['payload'] = json_decode($n['payload'], true) ?? [];
            }
        }
        unset($n);

        return [
            'notifications'        => $notifications,
            'unread_count'         => $this->notifRepo->countUnread($deviceUuid),
            'poll_interval_seconds' => self::DEFAULT_POLL_INTERVAL,
        ];
    }

    /**
     * Mark notifications as read.
     *
     * @return int Number of notifications marked read
     */
    public function markRead(string $deviceUuid, array $ids): int
    {
        return $this->notifRepo->markRead($deviceUuid, $ids);
    }

    /**
     * Auto-cleanup: delete read notifications older than $days.
     *
     * @return int Number of rows deleted
     */
    public function cleanup(int $days = 7): int
    {
        return $this->notifRepo->purgeOldRead($days);
    }
}
