<?php
declare(strict_types=1);

namespace OwnPay\Controller\Api\Mobile;

use OwnPay\Container;
use OwnPay\Http\Request;
use OwnPay\Http\Response;
use OwnPay\Repository\TransactionRepository;
use OwnPay\Repository\MobileNotificationRepository;
use OwnPay\Support\DateHelper;

/**
 * Class DashboardController
 *
 * Handles API actions related to the mobile dashboard metrics and summary.
 *
 * @package OwnPay\Controller\Api\Mobile
 */
final class DashboardController
{
    /**
     * @var Container The dependency injection container.
     */
    private Container $c;

    /**
     * @var TransactionRepository The transaction repository.
     */
    private TransactionRepository $txnRepo;

    /**
     * @var MobileNotificationRepository The mobile notification repository.
     */
    private MobileNotificationRepository $notifRepo;

    /**
     * DashboardController constructor.
     *
     * @param Container                    $c         The DI container.
     * @param TransactionRepository        $txnRepo   The transaction repository.
     * @param MobileNotificationRepository $notifRepo The mobile notification repository.
     */
    public function __construct(Container $c, TransactionRepository $txnRepo, MobileNotificationRepository $notifRepo)
    {
        $this->c         = $c;
        $this->txnRepo   = $txnRepo;
        $this->notifRepo = $notifRepo;
    }

    /**
     * Retrieves dashboard data (stats, transactions, unread notifications) for the mobile app.
     *
     * GET /api/mobile/v1/dashboard
     *
     * @param Request $req The incoming HTTP request.
     * @return Response The HTTP response with dashboard dashboard stats.
     */
    public function index(Request $req): Response
    {
        $mid = (int) $req->getAttribute('merchant_id');
        // BUG-008 FIX: device_id is a UUID string — don't cast to int
        $did = (string) $req->getAttribute('device_id');

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
