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
        $midVal = $req->getAttribute('merchant_id');
        $mid = (is_int($midVal) || is_string($midVal)) ? (int) $midVal : 0;
        // device_id is a UUID string - don't cast to int
        $didVal = $req->getAttribute('device_id');
        $did = is_string($didVal) ? $didVal : '';
 
        $today   = $this->txnRepo->getTodayStats($mid);
        $recent  = $this->txnRepo->getRecentTransactions($mid, 5);
        $unread  = $this->notifRepo->countUnread($mid, $did);
 
        $appConfig = $this->c->get('config.app');
        $version = (is_array($appConfig) && isset($appConfig['version']) && is_string($appConfig['version'])) ? $appConfig['version'] : '0.1.0';

        $headers = [
            'X-API-Version' => $version,
        ];

        $data = [
            'today'                 => $today,
            'recent_transactions'   => $recent,
            'unread_notifications'  => $unread,
            'server_time'           => DateHelper::iso(),
        ];

        return Response::apiSuccess($data, null, 200, $headers);
    }
}
