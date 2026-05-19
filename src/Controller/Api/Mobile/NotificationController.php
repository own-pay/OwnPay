<?php
declare(strict_types=1);

namespace OwnPay\Controller\Api\Mobile;

use OwnPay\Container;
use OwnPay\Http\Request;
use OwnPay\Http\Response;
use OwnPay\Repository\MobileNotificationRepository;

final class NotificationController
{
    private MobileNotificationRepository $notifRepo;

    /** @phpstan-ignore-next-line */
    public function __construct(Container $c, MobileNotificationRepository $notifRepo)
    {
        $this->notifRepo = $notifRepo;
    }

    public function index(Request $req): Response
    {
        $mid = (int) $req->getAttribute('merchant_id');
        $did = (int) $req->getAttribute('device_id');
        $notifs = $this->notifRepo->listForDevice($mid, (string) $did);
        return Response::json(['success' => true, 'data' => $notifs]);
    }

    public function ack(Request $req): Response
    {
        $body = $req->json();
        $ids = array_filter(array_map('intval', $body['ids'] ?? []));
        if (empty($ids)) return Response::json(['success' => false, 'error' => 'ids required'], 422);

        $mid = (int) $req->getAttribute('merchant_id');
        $count = $this->notifRepo->acknowledgeIds($ids, $mid);
        return Response::json(['success' => true, 'acknowledged' => $count]);
    }
}
