<?php
declare(strict_types=1);

namespace OwnPay\Controller\Api\Admin;

use OwnPay\Http\Request;
use OwnPay\Http\Response;
use OwnPay\Repository\CommLogRepository;

/**
 * Class SmsQueueController
 *
 * Handles API actions related to the SMS sending queue.
 *
 * @package OwnPay\Controller\Api\Admin
 */
final class SmsQueueController
{
    /**
     * @var CommLogRepository The communication log repository.
     */
    private CommLogRepository $commRepo;

    /**
     * SmsQueueController constructor.
     *
     * @param CommLogRepository $commRepo The communication log repository.
     */
    public function __construct(CommLogRepository $commRepo)
    {
        $this->commRepo = $commRepo;
    }

    /**
     * Lists items in the SMS queue.
     *
     * @param Request $req The incoming HTTP request.
     * @return Response The HTTP response with queue data.
     */
    public function index(Request $req): Response
    {
        $midVal = $req->getAttribute('merchant_id');
        $mid = (is_int($midVal) || is_string($midVal)) ? (int) $midVal : 0;
        $queue = $this->commRepo->listSmsQueue($mid, 100);
        return Response::apiSuccess($queue);
    }

    /**
     * Retries sending a failed SMS from the queue.
     *
     * @param Request $req The incoming HTTP request.
     * @return Response The HTTP response indicating retry status.
     */
    public function retry(Request $req): Response
    {
        $id  = (int) $req->param('id');
        $midVal = $req->getAttribute('merchant_id');
        $mid = (is_int($midVal) || is_string($midVal)) ? (int) $midVal : 0;
        
        try {
            $this->commRepo->retrySms($id, $mid);
            return Response::apiSuccess(['message' => 'Queued for retry']);
        } catch (\Throwable $e) {
            return Response::apiError('SMS_RETRY_FAILED', $e->getMessage(), 'id', 400);
        }
    }
}
