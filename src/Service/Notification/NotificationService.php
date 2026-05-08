<?php
declare(strict_types=1);

namespace OwnPay\Service\Notification;

use OwnPay\Event\EventManager;

/**
 * Notification service â€” orchestrates multi-channel notifications.
 *
 * Channels: push (mobile), email, sms, admin alert.
 * Plugins add channels via communication.channels filter.
 */
final class NotificationService
{
    private EventManager $events;
    private AlertService $alerts;
    private MobileNotificationService $mobile;

    public function __construct(
        EventManager $events,
        AlertService $alerts,
        MobileNotificationService $mobile
    ) {
        $this->events = $events;
        $this->alerts = $alerts;
        $this->mobile = $mobile;
    }

    /**
     * Send notification via specified channels.
     *
     * @param string[] $channels e.g. ['admin', 'push', 'email']
     */
    public function notify(int $merchantId, string $event, array $data, array $channels = ['admin']): void
    {
        foreach ($channels as $channel) {
            match ($channel) {
                'admin' => $this->notifyAdmin($merchantId, $event, $data),
                'push'  => $this->notifyPush($merchantId, $event, $data),
                'email' => $this->notifyEmail($merchantId, $event, $data),
                'sms'   => $this->notifySms($merchantId, $event, $data),
                default => null, // Plugin channels handled via event
            };
        }
    }

    private function notifyAdmin(int $merchantId, string $event, array $data): void
    {
        $title = $data['title'] ?? $event;
        $message = $data['message'] ?? json_encode($data);
        $severity = $data['severity'] ?? 'info';
        $this->alerts->create($merchantId, $event, $title, $message, $severity);
    }

    private function notifyPush(int $merchantId, string $event, array $data): void
    {
        $deviceUuid = $data['device_uuid'] ?? null;
        if ($deviceUuid !== null) {
            $this->mobile->send($deviceUuid, $data['title'] ?? $event, $data['message'] ?? '', $data);
        }
    }

    private function notifyEmail(int $merchantId, string $event, array $data): void
    {
        // Dispatch via communication service hook
        $this->events->doAction('communication.mail.send', [
            'merchant_id' => $merchantId,
            'event'       => $event,
            'to'          => $data['email'] ?? null,
            'subject'     => $data['subject'] ?? $data['title'] ?? $event,
            'body'        => $data['message'] ?? '',
        ]);
    }

    private function notifySms(int $merchantId, string $event, array $data): void
    {
        $this->events->doAction('communication.sms.send', [
            'merchant_id' => $merchantId,
            'event'       => $event,
            'to'          => $data['phone'] ?? null,
            'message'     => $data['message'] ?? '',
        ]);
    }
}
