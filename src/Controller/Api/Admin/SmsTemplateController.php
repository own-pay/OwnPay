<?php
declare(strict_types=1);

namespace OwnPay\Controller\Api\Admin;

use OwnPay\Http\Request;
use OwnPay\Http\Response;
use OwnPay\Repository\SmsTemplateRepository;

/**
 * Class SmsTemplateController
 *
 * Handles API actions related to SMS template configurations.
 *
 * @package OwnPay\Controller\Api\Admin
 */
final class SmsTemplateController
{
    /**
     * @var SmsTemplateRepository The SMS template repository.
     */
    private SmsTemplateRepository $tplRepo;

    /**
     * SmsTemplateController constructor.
     *
     * @param SmsTemplateRepository $tplRepo The SMS template repository.
     */
    public function __construct(SmsTemplateRepository $tplRepo)
    {
        $this->tplRepo = $tplRepo;
    }

    /**
     * Lists all SMS templates for a merchant.
     *
     * @param Request $req The incoming HTTP request.
     * @return Response The HTTP response with templates data.
     */
    public function index(Request $req): Response
    {
        $mid = (int) $req->getAttribute('merchant_id');
        $templates = $this->tplRepo->listForAdmin($mid, 'priority ASC, created_at DESC');
        return Response::json(['success' => true, 'data' => $templates]);
    }

    /**
     * Updates an SMS template.
     *
     * @param Request $req The incoming HTTP request.
     * @return Response The HTTP response indicating success.
     */
    public function update(Request $req): Response
    {
        $id  = (int) $req->param('id');
        $mid = (int) $req->getAttribute('merchant_id');
        $body = $req->json();

        $data = [];
        $allowed = ['gateway_slug', 'sender_pattern', 'amount_regex', 'trx_id_regex', 'sender_regex', 'priority', 'status'];
        foreach ($allowed as $col) {
            if (array_key_exists($col, $body)) {
                if ($col === 'priority') {
                    $data[$col] = (int) $body[$col];
                } else {
                    $data[$col] = $body[$col];
                }
            }
        }

        $this->tplRepo->updateTemplate($id, $mid, $data);
        return Response::json(['success' => true]);
    }
}
