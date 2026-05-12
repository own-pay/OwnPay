<?php
declare(strict_types=1);

namespace OwnPay\Controller\Api\Admin;

use OwnPay\Http\Request;
use OwnPay\Http\Response;
use OwnPay\Repository\CommLogRepository;

final class SmsQueueController
{
    private CommLogRepository $commRepo;

    public function __construct(CommLogRepository $commRepo)
    {
        $this->commRepo = $commRepo;
    }

    public function index(Request $req): Response
    {
        $mid = (int) $req->getAttribute('merchant_id');
        $queue = $this->commRepo->listSmsQueue($mid, 100);
        return Response::json(['success' => true, 'data' => $queue]);
    }

    public function retry(Request $req): Response
    {
        $id  = (int) $req->param('id');
        $mid = (int) $req->getAttribute('merchant_id');
        $this->commRepo->retrySms($id, $mid);
        return Response::json(['success' => true, 'message' => 'Queued for retry']);
    }
}
