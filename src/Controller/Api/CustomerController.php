<?php
declare(strict_types=1);

namespace OwnPay\Controller\Api;

use OwnPay\Container;
use OwnPay\Http\Request;
use OwnPay\Http\Response;
use OwnPay\Service\System\InputSanitizer;

/**
 * Customer API.
 */
final class CustomerController
{
    private Container $c;
    public function __construct(Container $c) { $this->c = $c; }

    public function index(Request $req): Response
    {
        $mid = (int) $req->getAttribute('merchant_id');
        $db = $this->c->get(\OwnPay\Core\Database::class);
        $page = max(1, (int) $req->get('page', '1'));
        $limit = min(100, max(1, (int) $req->get('per_page', '25')));
        $offset = ($page - 1) * $limit;

        $total = (int) ($db->fetchOne("SELECT COUNT(*) as cnt FROM op_customers WHERE merchant_id = :mid", ['mid' => $mid])['cnt'] ?? 0);
        $customers = $db->fetchAll("SELECT id, name, email, phone, created_at FROM op_customers WHERE merchant_id = :mid ORDER BY created_at DESC LIMIT {$limit} OFFSET {$offset}", ['mid' => $mid]);

        return Response::json(['success' => true, 'data' => $customers, 'meta' => ['page' => $page, 'total' => $total]]);
    }

    public function show(Request $req, int $id): Response
    {
        $mid = (int) $req->getAttribute('merchant_id');
        $db = $this->c->get(\OwnPay\Core\Database::class);
        $c = $db->fetchOne("SELECT id, name, email, phone, created_at FROM op_customers WHERE id = :id AND merchant_id = :mid", ['id' => $id, 'mid' => $mid]);
        if (!$c) return Response::json(['success' => false, 'error' => 'Not found'], 404);
        return Response::json(['success' => true, 'data' => $c]);
    }

    public function create(Request $req): Response
    {
        $mid = (int) $req->getAttribute('merchant_id');
        $body = $req->jsonBody();
        if (empty($body['name'])) return Response::json(['success' => false, 'error' => 'name required'], 422);

        $db = $this->c->get(\OwnPay\Core\Database::class);
        $id = $db->insert("INSERT INTO op_customers (merchant_id, name, email, phone, created_at) VALUES (:mid, :name, :email, :phone, NOW())", [
            'mid' => $mid, 'name' => InputSanitizer::string($body['name']),
            'email' => $body['email'] ?? null, 'phone' => $body['phone'] ?? null,
        ]);
        return Response::json(['success' => true, 'id' => $id], 201);
    }
}
