<?php
declare(strict_types=1);

namespace OwnPay\Controller\Api;

use OwnPay\Container;
use OwnPay\Http\Request;
use OwnPay\Http\Response;
use OwnPay\Service\System\InputSanitizer;
use OwnPay\Repository\CustomerRepository;

final class CustomerController
{
    private Container $c;
    private CustomerRepository $customers;

    public function __construct(Container $c, CustomerRepository $customers)
    {
        $this->c = $c;
        $this->customers = $customers;
    }

    public function index(Request $req): Response
    {
        $mid  = (int) $req->getAttribute('merchant_id');
        $page = max(1, (int) $req->get('page', '1'));
        $limit = min(100, max(1, (int) $req->get('per_page', '25')));
        $offset = ($page - 1) * $limit;

        $repo = $this->customers->forTenant($mid);
        $result = $repo->paginateScoped($page, $limit);

        return Response::json([
            'success' => true,
            'data'    => $result['items'],
            'meta'    => ['page' => $page, 'total' => $result['total']],
        ]);
    }

    public function show(Request $req): Response
    {
        $id  = (int) $req->param('id');
        $mid = (int) $req->getAttribute('merchant_id');

        $c = $this->customers->forTenant($mid)->findScoped($id);
        if (!$c) return Response::json(['success' => false, 'error' => 'Not found'], 404);
        return Response::json(['success' => true, 'data' => $c]);
    }

    public function create(Request $req): Response
    {
        $mid  = (int) $req->getAttribute('merchant_id');
        $body = $req->json();
        if (empty($body['name'])) return Response::json(['success' => false, 'error' => 'name required'], 422);

        $id = $this->customers->forTenant($mid)->createScoped([
            'name'  => InputSanitizer::string($body['name']),
            'email' => $body['email'] ?? null,
            'phone' => $body['phone'] ?? null,
        ]);

        return Response::json(['success' => true, 'id' => $id], 201);
    }
}
