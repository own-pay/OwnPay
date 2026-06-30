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
     * @param MobileNotificationRepository $notifRepo The mobile notification repository.
     */
    public function __construct(MobileNotificationRepository $notifRepo)
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
        $midVal = $req->getAttribute('merchant_id');
        $mid = (is_int($midVal) || is_string($midVal)) ? (int) $midVal : 0;
        // device_id is a UUID string - don't cast to int
        $didVal = $req->getAttribute('device_id');
        $did = is_string($didVal) ? $didVal : '';
        $notifs = $this->notifRepo->listForDevice($mid, $did);
        return Response::apiSuccess($notifs);
    }

    /**
     * Acknowledges receipt of notification IDs.
     *
     * POST /api/mobile/v1/notifications/acknowledgements
     * Input Body: { ids: [1, 2, 3] }
     *
     * Scope ack by device_id to prevent IDOR.
     * Previously any device in a brand could silence another device's notifications.
     *
     * @param Request $req The incoming HTTP request.
     * @return Response The HTTP response indicating acknowledgment count.
     */
    public function ack(Request $req): Response
    {
        $body = $req->json();
        $bodyArr = is_array($body) ? $body : [];
        $idsRaw = $bodyArr['ids'] ?? [];
        $idsRaw = is_array($idsRaw) ? $idsRaw : [];

        $ids = [];
        foreach ($idsRaw as $idVal) {
            if (is_int($idVal) || is_string($idVal) || is_numeric($idVal)) {
                $ids[] = (int) $idVal;
            }
        }

        if (empty($ids)) {
            return Response::apiError('IDS_REQUIRED', 'ids required', 'ids', 422);
        }

        $midVal = $req->getAttribute('merchant_id');
        $mid = (is_int($midVal) || is_string($midVal)) ? (int) $midVal : 0;
        $didVal = $req->getAttribute('device_id');
        $did = is_string($didVal) ? $didVal : '';
        
        $count = $this->notifRepo->acknowledgeIds($ids, $mid, $did);
        return Response::apiSuccess(['acknowledged' => $count]);
    }
}
