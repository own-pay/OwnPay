<?php
declare(strict_types=1);

namespace OwnPay\Controller\Api\Mobile;

use OwnPay\Container;
use OwnPay\Http\Request;
use OwnPay\Http\Response;
use OwnPay\Repository\TransactionRepository;
use OwnPay\Repository\MobileNotificationRepository;
use OwnPay\Support\DateHelper;

final class DashboardController
{
    private Container $c;
    private TransactionRepository $txnRepo;
    private MobileNotificationRepository $notifRepo;

    public function __construct(Container $c, TransactionRepository $txnRepo, MobileNotificationRepository $notifRepo)
    {
        $this->c         = $c;
        $this->txnRepo   = $txnRepo;
        $this->notifRepo = $notifRepo;
    }

    public function index(Request $req): Response
    {
        $mid = (int) $req->getAttribute('merchant_id');
        $did = (int) $req->getAttribute('device_id');

        $today   = $this->txnRepo->getTodayStats($mid);
        $recent  = $this->txnRepo->getRecentTransactions($mid, 5);
        $unread  = $this->notifRepo->countUnread($mid, (string) $did);

        /** @phpstan-ignore-next-line */
        return Response::json([
            'success'               => true,
            'today'                 => $today,
            'recent_transactions'   => $recent,
            'unread_notifications'  => $unread,
            'server_time'           => DateHelper::iso(),
        ], 200, ['X-API-Version' => $this->c->get('config.app')['version'] ?? '0.1.0']);
    }
}
