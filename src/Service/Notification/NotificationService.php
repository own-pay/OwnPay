<?php
declare(strict_types=1);

namespace OwnPay\Service\Notification;

use OwnPay\Event\EventManager;

/**
 * Orchestrates multi-channel notifications across the enterprise platform.
 *
 * Dispatches alerts, push notifications, emails, and SMS messages based on
 * configuration. Enables external plugins to integrate custom communication channels
 * via Hook/Filter hooks such as `communication.channels`.
 */
final class NotificationService
{
    /**
     * The event manager for dispatching hooks and filter pipelines.
     */
    private EventManager $events;

    /**
     * The system alert service for administrative notifications.
     */
    private AlertService $alerts;

    /**
     * The mobile push notification delivery service.
     */
    private MobileNotificationService $mobile;

    /**
     * Initializes the notification service with required dependencies.
     *
     * @param EventManager $events The event manager for hooks and filter pipelines.
     * @param AlertService $alerts The system alert service for admin-facing logs.
     * @param MobileNotificationService $mobile The mobile push notification dispatcher.
     */
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
     * Dispatches notification payloads across designated delivery channels.
     *
     * Each targeted channel is resolved dynamically. Unknown or third-party channel handlers
     * configured by active plugins are registered and executed downstream via filter hooks.
     *
     * @param int $merchantId The unique identifier of the brand/merchant context.
     * @param string $event The name/identifier of the trigger event (e.g., 'payment.completed').
     * @param array<string, mixed> $data The payload containing notification context and message content.
     * @param array<int, string> $channels List of target channels (e.g., ['admin', 'push', 'email']).
     * @return void
     */
    public function notify(int $merchantId, string $event, array $data, array $channels = ['admin']): void
    {
        foreach ($channels as $channel) {
            match ($channel) {
                'admin' => $this->notifyAdmin($merchantId, $event, $data),
                'push'  => $this->notifyPush($merchantId, $event, $data),
                'email' => $this->notifyEmail($merchantId, $event, $data),
                'sms'   => $this->notifySms($merchantId, $event, $data),
                default => null,
            };
        }
    }

    /**
     * Creates an in-app administrative alert within the merchant database.
     *
     * Logs the transaction or system event details to the admin dashboard.
     *
     * @param int $merchantId The unique identifier of the brand/merchant context.
     * @param string $event The name of the trigger event.
     * @param array<string, mixed> $data Context parameters containing 'title', 'message', and 'severity'.
     * @return void
     */
    private function notifyAdmin(int $merchantId, string $event, array $data): void
    {
        $titleVal = $data['title'] ?? $event;
        $title = is_scalar($titleVal) ? (string) $titleVal : $event;
        $messageVal = $data['message'] ?? null;
        $message = is_scalar($messageVal) ? (string) $messageVal : json_encode($data);
        if ($message === false) {
            $message = '';
        }
        $severityVal = $data['severity'] ?? 'info';
        $severity = is_scalar($severityVal) ? (string) $severityVal : 'info';
        $this->alerts->create($merchantId, $event, $title, $message, $severity);
    }

    /**
     * Triggers a push notification to paired mobile companion devices.
     *
     * Resolves the target device UUID from the payload and forwards the push message.
     *
     * @param int $merchantId The unique identifier of the brand/merchant context.
     * @param string $event The name of the trigger event.
     * @param array<string, mixed> $data Context parameters containing 'device_uuid', 'title', and 'message'.
     * @return void
     */
    private function notifyPush(int $merchantId, string $event, array $data): void
    {
        $deviceUuid = $data['device_uuid'] ?? null;
        if (is_string($deviceUuid)) {
            $titleVal = $data['title'] ?? $event;
            $title = is_scalar($titleVal) ? (string) $titleVal : $event;
            $bodyVal = $data['message'] ?? '';
            $body = is_scalar($bodyVal) ? (string) $bodyVal : '';
            $this->mobile->send($deviceUuid, $title, $body, $data);
        }
    }

    /**
     * Executes email dispatch via the system communication hook.
     *
     * Delegates delivery downstream to the email provider registered on the system.
     *
     * @param int $merchantId The unique identifier of the brand/merchant context.
     * @param string $event The name of the trigger event.
     * @param array<string, mixed> $data Context parameters containing 'email', 'subject', 'title', and 'message'.
     * @return void
     */
    private function notifyEmail(int $merchantId, string $event, array $data): void
    {
        $this->events->doAction('communication.mail.send', [
            'merchant_id' => $merchantId,
            'event'       => $event,
            'to'          => $data['email'] ?? null,
            'subject'     => $data['subject'] ?? $data['title'] ?? $event,
            'body'        => $data['message'] ?? '',
        ]);
    }

    /**
     * Executes SMS dispatch via the system communication hook.
     *
     * Delegates transmission to the mobile carrier or SMS gateway gateway adapters.
     *
     * @param int $merchantId The unique identifier of the brand/merchant context.
     * @param string $event The name of the trigger event.
     * @param array<string, mixed> $data Context parameters containing 'phone' and 'message'.
     * @return void
     */
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
