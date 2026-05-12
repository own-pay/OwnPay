<?php
declare(strict_types=1);

namespace OwnPay\Controller\Api\Admin;

use OwnPay\Http\Request;
use OwnPay\Http\Response;
use OwnPay\Repository\SmsTemplateRepository;

final class SmsTemplateController
{
    private SmsTemplateRepository $tplRepo;

    public function __construct(SmsTemplateRepository $tplRepo)
    {
        $this->tplRepo = $tplRepo;
    }

    public function index(Request $req): Response
    {
        $mid = (int) $req->getAttribute('merchant_id');
        $templates = $this->tplRepo->listForAdmin($mid, 'event ASC');
        return Response::json(['success' => true, 'data' => $templates]);
    }

    public function update(Request $req): Response
    {
        $id  = (int) $req->param('id');
        $mid = (int) $req->getAttribute('merchant_id');
        $body = $req->json();

        $this->tplRepo->updateTemplate($id, $mid, $body['body'] ?? '', (bool) ($body['enabled'] ?? true));
        return Response::json(['success' => true]);
    }
}
