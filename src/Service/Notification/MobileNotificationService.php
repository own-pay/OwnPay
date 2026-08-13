<?php
declare(strict_types=1);

namespace OwnPay\Service\Notification;

/**
 * Service managing mobile push notifications for paired devices.
 *
 * Handles formatting, queuing, polling, and cleaning up notifications
 * intended for companion application devices.
 */
final class MobileNotificationService
{
    /**
     * @var object|null Repository interface or database handler resolving notifications storage.
     */
    private ?object $repo;

    /**
     * Constructs a new MobileNotificationService instance.
     *
     * @param object|null $repo Optional custom notification repository instance.
     */
    public function __construct(?object $repo = null)
    {
        $this->repo = $repo;
    }

    /**
     * Queues a payment-related notification for a paired companion device.
     *
     * Maps generic event tags to appropriate local notification types, formats bodies
     * containing amounts and details, and stores the resulting event record.
     *
     * @param string $deviceUuid Cryptographic identifier of target device.
     * @param string $type Transaction type category ('credit', 'received', 'debit', 'sent', etc).
     * @param string|float|int|null $amount Value/amount involved in the transaction.
     * @param string|null $senderName Name or label identifying the transaction party.
     * @param string|null $trxId Unique transaction reference identifier.
     * @param string|null $smsFrom Gateway identifier/address initiating the transaction notification.
     * @return int Created notification entry ID or status code indicator.
     */
    public function queuePaymentNotification(
        string $deviceUuid,
        string $type,
        $amount = null,
        $senderName = null,
        $trxId = null,
        $smsFrom = null
    ): int {
        $mappedType = match ($type) {
            'credit', 'received' => 'payment_received',
            'debit', 'sent'      => 'payment_sent',
            default              => 'payment_detected',
        };

        $title = match ($mappedType) {
            'payment_received' => 'Payment Received',
            'payment_sent'     => 'Payment Sent',
            default            => 'Transaction Detected',
        };

        if ($amount === null && $senderName === null && $trxId === null) {
            $body = 'New transaction detected.';
        } else {
            if ($mappedType === 'payment_received') {
                $body = '';
                if ($amount !== null) {
                    $body .= 'Tk ' . (is_numeric($amount) ? number_format((float)$amount, 2) : '0.00') . ' ';
                }
                $body .= 'received';
                if ($senderName !== null) {
                    $body .= ' from ' . $senderName;
                }
                if ($trxId !== null) {
                    $body .= ' | TRX: ' . $trxId;
                }
                $body = trim($body);
            } elseif ($mappedType === 'payment_sent') {
                $body = '';
                if ($amount !== null) {
                    $body .= 'Tk ' . (is_numeric($amount) ? number_format((float)$amount, 2) : '0.00') . ' ';
                }
                $body .= 'sent';
                if ($senderName !== null) {
                    $body .= ' to ' . $senderName;
                }
                if ($trxId !== null) {
                    $body .= ' | TRX: ' . $trxId;
                }
                $body = trim($body);
            } else {
                $body = '';
                if ($amount !== null) {
                    $body .= 'Tk ' . (is_numeric($amount) ? number_format((float)$amount, 2) : '0.00') . ' ';
                }
                $body .= 'transaction detected';
                $body = trim($body);
            }
        }

        $payload = [
            'amount' => $amount,
            'sender' => $senderName,
            'trx_id' => $trxId,
            'sms_from' => $smsFrom,
        ];

        if ($this->repo !== null) {
            if (method_exists($this->repo, 'queue')) {
                return (int) $this->repo->queue($deviceUuid, $mappedType, $title, $body, $payload);
            } elseif (method_exists($this->repo, 'create')) {
                return (int) $this->repo->create($deviceUuid, $mappedType, $title, $body, $payload);
            }
        }

        // API-4: Removed the global /tmp/op_notifications.json fallback.
        // The previous fallback wrote every merchant's notification payloads
        // (containing transaction amounts, sender names, TRX IDs — PII and
        // financial data) into a single shared temp file with no tenant
        // separation and no consumption mechanism, growing unbounded. Any
        // process running as the web user could read every merchant's
        // notification data. We now fail loudly: if no repository is
        // configured, the service cannot queue notifications and the caller
        // must handle the failure rather than silently losing data into an
        // insecure shared file.
        throw new \RuntimeException(
            'MobileNotificationService cannot queue notifications: no repository configured. '
            . 'Wire OwnPay\Repository\MobileNotificationRepository into the container.'
        );
    }

    /**
     * Polls active mobile device notifications.
     *
     * @param string $deviceUuid Unique identifier of the polling device.
     * @param int $merchantId Merchant/brand scope for unread-count queries
     *                        (API-3: previously hardcoded to 1, leaking
     *                        merchant 1's notification counts to every brand).
     * @param string|null $since ISO timestamp cursor representing the last poll boundary.
     * @return array{notifications: array<int, array<string, mixed>>, unread_count: int, poll_interval_seconds: int} Poll results packet.
     */
    public function poll(string $deviceUuid, int $merchantId, ?string $since = null): array
    {
        $notifications = [];
        $unreadCount = 0;

        if ($this->repo !== null) {
            /** @phpstan-ignore-next-line */
            $notifications = $this->repo->pollSince($deviceUuid, $since);
            foreach ($notifications as &$notif) {
                if (isset($notif['payload']) && is_string($notif['payload'])) {
                    $notif['payload'] = json_decode($notif['payload'], true) ?: [];
                }
            }
            if (method_exists($this->repo, 'countUnread')) {
                $ref = new \ReflectionMethod($this->repo, 'countUnread');
                if ($ref->getNumberOfParameters() === 1) {
                    $unreadCount = $this->repo->countUnread($deviceUuid);
                } else {
                    // API-3: pass the actual merchant context — previously
                    // hardcoded to 1, which leaked merchant 1's notification
                    // counts to every brand on the installation.
                    $unreadCount = $this->repo->countUnread($merchantId, $deviceUuid);
                }
            }
        }

        return [
            'notifications'         => $notifications,
            'unread_count'          => $unreadCount,
            'poll_interval_seconds' => 10,
        ];
    }

    /**
     * Marks a list of device notification records as read.
     *
     * @param string $deviceUuid Unique identifier of the targeting device.
     * @param int[]|string[] $ids List of notification identifiers to flag.
     * @return int Count of marked notification records.
     */
    public function markRead(string $deviceUuid, array $ids): int
    {
        if ($this->repo !== null && method_exists($this->repo, 'markRead')) {
            return $this->repo->markRead($deviceUuid, $ids);
        }
        return 0;
    }

    /**
     * Purges historic read notifications beyond the specified day age.
     *
     * @param int $olderThanDays Age threshold in days.
     * @return int Count of deleted notification database records.
     */
    public function cleanup(int $olderThanDays): int
    {
        if ($this->repo !== null && method_exists($this->repo, 'purgeOldRead')) {
            return $this->repo->purgeOldRead($olderThanDays);
        }
        return 0;
    }

    /**
     * Queues and sends a general notifications message packet.
     *
     * @param string $deviceUuid Cryptographic identifier of target device.
     * @param string $title Title header text.
     * @param string $body Body text description.
     * @param array<string, mixed> $data Associated parameters payload.
     * @return void
     * @throws \RuntimeException when no repository is configured (API-4).
     */
    public function send(string $deviceUuid, string $title, string $body, array $data = []): void
    {
        if ($this->repo !== null) {
            if (method_exists($this->repo, 'queue')) {
                $this->repo->queue($deviceUuid, 'general', $title, $body, $data);
                return;
            } elseif (method_exists($this->repo, 'create')) {
                $this->repo->create($deviceUuid, 'general', $title, $body, $data);
                return;
            }
        }

        // API-4: see queuePaymentNotification() for the rationale. The temp-file
        // fallback was a cross-tenant data leak and has been removed.
        throw new \RuntimeException(
            'MobileNotificationService cannot send notifications: no repository configured. '
            . 'Wire OwnPay\Repository\MobileNotificationRepository into the container.'
        );
    }
}
