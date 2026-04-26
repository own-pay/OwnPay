<?php

declare(strict_types=1);

namespace OwnPay\Http\Controller;

use OwnPay\Http\JsonResponse;
use OwnPay\Middleware\JwtAuthMiddleware;
use OwnPay\Service\MobileNotificationService;

/**
 * MobileNotificationController — Polling endpoint for mobile notifications.
 *
 * Endpoints:
 *   GET  /v1/notifications/poll     — Poll for new notifications (cursor-based)
 *   POST /v1/notifications/read     — Mark notification IDs as read
 */
final class MobileNotificationController
{
    private MobileNotificationService $notifService;

    public function __construct()
    {
        $this->notifService = new MobileNotificationService();
    }

    /**
     * GET /v1/notifications/poll?since=<iso_timestamp>
     *
     * Returns all notifications newer than the `since` cursor.
     * If `since` is omitted, returns all unread notifications.
     *
     * Response:
     * {
     *   "notifications": [...],
     *   "unread_count": 5,
     *   "poll_interval_seconds": 10
     * }
     */
    public function poll(array $params): void
    {
        $device = (new JwtAuthMiddleware())->guard();

        $since = isset($_GET['since']) ? trim($_GET['since']) : null;

        // Normalize `since` to MySQL datetime if provided
        if ($since !== null && $since !== '') {
            try {
                $dt = new \DateTimeImmutable($since);
                $since = $dt->format('Y-m-d H:i:s');
            } catch (\Throwable) {
                JsonResponse::error('INVALID_SINCE', 'The "since" parameter must be a valid ISO 8601 timestamp.', 400);
                return;
            }
        } else {
            $since = null;
        }

        $result = $this->notifService->poll($device['device_uuid'], $since);
        JsonResponse::success($result);
    }

    /**
     * POST /v1/notifications/read
     *
     * Mark notification IDs as read.
     *
     * Request body: { "ids": [1, 2, 3] }
     * Response:     { "marked_read": 3 }
     */
    public function markRead(array $params): void
    {
        $device = (new JwtAuthMiddleware())->guard();

        $body = JsonResponse::parseRequestBody();
        if ($body === null) {
            JsonResponse::error('INVALID_JSON', 'Request body must be valid JSON.', 400);
            return;
        }

        $ids = $body['ids'] ?? [];
        if (!is_array($ids) || empty($ids)) {
            JsonResponse::error('MISSING_IDS', 'The "ids" array is required and must not be empty.', 400);
            return;
        }

        $count = $this->notifService->markRead($device['device_uuid'], $ids);
        JsonResponse::success(['marked_read' => $count]);
    }
}
