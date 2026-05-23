<?php
declare(strict_types=1);

namespace OwnPay\Controller\Api\Mobile;

use OwnPay\Container;
use OwnPay\Http\Request;
use OwnPay\Http\Response;
use OwnPay\Repository\MobileNotificationRepository;

/**
 * Class NotificationController
 *
 * Handles API actions related to mobile companion app push notifications.
 *
 * @package OwnPay\Controller\Api\Mobile
 */
final class NotificationController
{
    /**
     * @var MobileNotificationRepository The mobile notification repository.
     */
    private MobileNotificationRepository $notifRepo;

    /**
     * NotificationController constructor.
     *
     * @param Container                    $c         The DI container.
     * @param MobileNotificationRepository $notifRepo The mobile notification repository.
     *
     * @phpstan-ignore-next-line
     */
    public function __construct(Container $c, MobileNotificationRepository $notifRepo)
    {
        $this->notifRepo = $notifRepo;
    }

    /**
     * Lists notifications dispatched for the authenticated device.
     *
     * GET /api/mobile/v1/notifications
     *
     * @param Request $req The incoming HTTP request.
     * @return Response The HTTP response with notifications list.
     */
    public function index(Request $req): Response
    {
        $mid = (int) $req->getAttribute('merchant_id');
        // BUG-008 FIX: device_id is a UUID string — don't cast to int
        $did = (string) $req->getAttribute('device_id');
        $notifs = $this->notifRepo->listForDevice($mid, $did);
        return Response::json(['success' => true, 'data' => $notifs]);
    }

    /**
     * Acknowledges receipt of notification IDs.
     *
     * POST /api/mobile/v1/notifications/ack
     * Input Body: { ids: [1, 2, 3] }
     *
     * BUG-007 FIX: Scope ack by device_id to prevent IDOR.
     * Previously any device in a brand could silence another device's notifications.
     *
     * @param Request $req The incoming HTTP request.
     * @return Response The HTTP response indicating acknowledgment count.
     */
    public function ack(Request $req): Response
    {
        $body = $req->json();
        $ids = array_values(array_filter(array_map('intval', $body['ids'] ?? [])));
        if (empty($ids)) {
            return Response::json(['success' => false, 'error' => 'ids required'], 422);
        }

        $mid = (int) $req->getAttribute('merchant_id');
        $did = (string) $req->getAttribute('device_id');
        $count = $this->notifRepo->acknowledgeIds($ids, $mid, $did);
        return Response::json(['success' => true, 'acknowledged' => $count]);
    }
}
