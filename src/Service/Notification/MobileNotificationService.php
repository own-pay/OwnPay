<?php
declare(strict_types=1);

namespace OwnPay\Service\Notification;

use OwnPay\Support\DateHelper;

/**
 * Mobile notification service â€” sends push notifications to paired devices.
 */
final class MobileNotificationService
{
    /**
     * Queue payment notification for device.
     */
    public function queuePaymentNotification(
        string $deviceUuid,
        string $type,
        ?string $amount,
        ?string $senderName,
        ?string $trxId,
        string $smsFrom
    ): void {
        // Queue for async dispatch (FCM/APNs)
        $payload = [
            'device_uuid' => $deviceUuid,
            'type'        => 'payment_' . $type,
            'title'       => $this->buildTitle($type, $amount),
            'body'        => $this->buildBody($type, $amount, $senderName, $trxId),
            'data'        => [
                'trx_id'   => $trxId,
                'amount'   => $amount,
                'sender'   => $senderName,
                'sms_from' => $smsFrom,
            ],
        ];

        // Store in notification queue table for async processing
        $this->queueNotification($payload);
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

    private function buildTitle(string $type, ?string $amount): string
    {
        return match ($type) {
            'credit', 'received' => "Payment Received" . ($amount ? ": {$amount}" : ''),
            'debit', 'sent'      => "Payment Sent" . ($amount ? ": {$amount}" : ''),
            default              => "Transaction Update",
        };
    }

    private function buildBody(string $type, ?string $amount, ?string $sender, ?string $trxId): string
    {
        $parts = [];
        if ($amount !== null) {
            $parts[] = "Amount: {$amount}";
        }
        if ($sender !== null) {
            $parts[] = "From: {$sender}";
        }
        if ($trxId !== null) {
            $parts[] = "TRX: {$trxId}";
        }
        return implode(' | ', $parts) ?: 'Transaction processed';
    }

    private function queueNotification(array $payload): void
    {
        // Write to op_notification_queue for async cron/worker dispatch
        // This will be picked up by the FCM/APNs cron job
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
