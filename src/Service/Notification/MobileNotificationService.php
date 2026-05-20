<?php
declare(strict_types=1);

namespace OwnPay\Service\Notification;

use OwnPay\Support\DateHelper;

/**
 * Mobile notification service — sends push notifications to paired devices.
 */
final class MobileNotificationService
{
    private $repo;

    public function __construct($repo = null)
    {
        $this->repo = $repo;
    }

    /**
     * Queue payment notification for device.
     */
    public function queuePaymentNotification(
        string $deviceUuid,
        string $type,
        $amount = null,
        $senderName = null,
        $trxId = null,
        $smsFrom = null
    ): int {
        // Map type
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

        // Format body
        if ($amount === null && $senderName === null && $trxId === null) {
            $body = 'New transaction detected.';
        } else {
            if ($mappedType === 'payment_received') {
                $body = '';
                if ($amount !== null) {
                    $body .= 'Tk ' . number_format((float)$amount, 2) . ' ';
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
                    $body .= 'Tk ' . number_format((float)$amount, 2) . ' ';
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
                    $body .= 'Tk ' . number_format((float)$amount, 2) . ' ';
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
            if (method_exists($this->repo, 'create')) {
                return (int) $this->repo->create($deviceUuid, $mappedType, $title, $body, $payload);
            } elseif (method_exists($this->repo, 'queue')) {
                return (int) $this->repo->queue($deviceUuid, $mappedType, $title, $body, $payload);
            }
        }

        // Store in notification queue table/file for async processing
        $this->queueNotification([
            'device_uuid' => $deviceUuid,
            'type'        => 'payment_' . $type,
            'title'       => $title,
            'body'        => $body,
            'data'        => [
                'trx_id'   => $trxId,
                'amount'   => $amount,
                'sender'   => $senderName,
                'sms_from' => $smsFrom,
            ],
        ]);
        return 1;
    }

    /**
     * Poll for notifications.
     */
    public function poll(string $deviceUuid, ?string $since = null): array
    {
        $notifications = [];
        $unreadCount = 0;

        if ($this->repo !== null) {
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
                    $unreadCount = $this->repo->countUnread(1, $deviceUuid);
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
     * Mark notifications as read.
     */
    public function markRead(string $deviceUuid, array $ids): int
    {
        if ($this->repo !== null && method_exists($this->repo, 'markRead')) {
            return $this->repo->markRead($deviceUuid, $ids);
        }
        return 0;
    }

    /**
     * Cleanup old notifications.
     */
    public function cleanup(int $olderThanDays): int
    {
        if ($this->repo !== null && method_exists($this->repo, 'purgeOldRead')) {
            return $this->repo->purgeOldRead($olderThanDays);
        }
        return 0;
    }

    /**
     * Queue general notification.
     */
    public function send(string $deviceUuid, string $title, string $body, array $data = []): void
    {
        $this->queueNotification([
            'device_uuid' => $deviceUuid,
            'type'        => 'general',
            'title'       => $title,
            'body'        => $body,
            'data'        => $data,
        ]);
    }

    private function queueNotification(array $payload): void
    {
        $file = sys_get_temp_dir() . '/op_notifications.json';
        $queue = [];
        if (file_exists($file)) {
            $queue = json_decode(file_get_contents($file) ?: '[]', true) ?: [];
        }
        $payload['queued_at'] = DateHelper::now();
        $queue[] = $payload;
        file_put_contents($file, json_encode($queue));
    }
}
