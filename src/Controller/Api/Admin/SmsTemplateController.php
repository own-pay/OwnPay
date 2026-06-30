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
        $midVal = $req->getAttribute('merchant_id');
        $mid = (is_int($midVal) || is_string($midVal)) ? (int) $midVal : 0;
        $templates = $this->tplRepo->listForAdmin($mid, 'priority ASC, created_at DESC');
        return Response::apiSuccess($templates);
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
        $midVal = $req->getAttribute('merchant_id');
        $mid = (is_int($midVal) || is_string($midVal)) ? (int) $midVal : 0;
        $body = $req->json();
        $bodyArr = is_array($body) ? $body : [];

        $data = [];
        $allowed = ['gateway_slug', 'sender_pattern', 'amount_regex', 'trx_id_regex', 'sender_regex', 'priority', 'status'];
        foreach ($allowed as $col) {
            if (array_key_exists($col, $bodyArr)) {
                if ($col === 'priority') {
                    $priorityVal = $bodyArr[$col];
                    $data[$col] = (is_int($priorityVal) || is_string($priorityVal)) ? (int) $priorityVal : 0;
                } else {
                    $data[$col] = $bodyArr[$col];
                }
            }
        }

        $this->tplRepo->updateTemplate($id, $mid, $data);
        return Response::apiSuccess(['message' => 'Template updated successfully']);
    }
}
