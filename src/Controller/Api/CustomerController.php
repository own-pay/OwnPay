<?php
declare(strict_types=1);

namespace OwnPay\Controller\Api;

use OwnPay\Container;
use OwnPay\Http\Request;
use OwnPay\Http\Response;
use OwnPay\Service\Customer\CustomerPiiService;
use OwnPay\Service\System\InputSanitizer;

/**
 * Customer API — CRUD with PII encryption.
 * OWASP: All PII encrypted at rest, lookup by hash only.
 */
final class CustomerController
{
    /** @phpstan-ignore property.onlyWritten */
    private Container $c;
    private CustomerPiiService $pii;

    public function __construct(Container $c, CustomerPiiService $pii)
    {
        $this->c = $c;
        $this->pii = $pii;
    }

    public function index(Request $req): Response
    {
        $mid  = (int) $req->getAttribute('merchant_id');
        $page = max(1, (int) $req->query('page', '1'));
        $perPage = min(100, max(1, (int) $req->query('per_page', '25')));

        $result = $this->pii->list($mid, $page, $perPage);

        return Response::json([
            'success' => true,
            'data'    => array_map(fn(array $c) => [
                'id'           => $c['id'],
                'uuid'         => $c['uuid'],
                'name'         => $c['name'] ?? null,
                'email_masked' => $c['email_masked'] ?? null,
                'phone_masked' => $c['phone_masked'] ?? null,
                'created_at'   => $c['created_at'],
            ], $result['items']),
            'meta'    => ['page' => $page, 'per_page' => $perPage, 'total' => $result['total']],
        ]);
    }

    /**
     * GET /api/v1/customers/{identifier}
     * Auto-detect: email (contains @) or phone number.
     */
    public function show(Request $req): Response
    {
        $identifier = trim($req->param('identifier'));
        $mid = (int) $req->getAttribute('merchant_id');

        if ($identifier === '') {
            return Response::json(['success' => false, 'error' => 'Identifier required'], 422);
        }

        $customer = $this->pii->findByContact($mid, $identifier);
        if (!$customer) {
            return Response::json(['success' => false, 'error' => 'Customer not found'], 404);
        }

        return Response::json(['success' => true, 'data' => [
            'id'         => $customer['id'],
            'uuid'       => $customer['uuid'],
            'name'       => $customer['name'] ?? null,
            'email'      => $customer['email'] ?? null,
            'phone'      => $customer['phone'] ?? null,
            'created_at' => $customer['created_at'],
        ]]);
    }

    /**
     * POST /api/v1/customers
     * Body: { name, email?, phone? }
     */
    public function create(Request $req): Response
    {
        $mid  = (int) $req->getAttribute('merchant_id');
        $body = $req->json();

        if (empty($body['name'])) {
            return Response::json(['success' => false, 'error' => 'name required'], 422);
        }

        try {
            // Check duplicate before insert (email uniqueness per brand)
            if (!empty($body['email'])) {
                $existing = $this->pii->findByEmail($mid, $body['email']);
                if ($existing) {
                    return Response::json([
                        'success' => false,
                        'error'   => 'Customer with this email already exists',
                        'data'    => ['id' => $existing['id'], 'uuid' => $existing['uuid']],
                    ], 409);
                }
            }

            $customer = $this->pii->create($mid, [
                'name'  => InputSanitizer::string($body['name']),
                'email' => $body['email'] ?? null,
                'phone' => $body['phone'] ?? null,
            ]);

            return Response::json([
                'success' => true,
                'data'    => [
                    'id'   => $customer['id'],
                    'uuid' => $customer['uuid'],
                ],
            ], 201);
        } catch (\PDOException $e) {
            // MySQL duplicate key = SQLSTATE 23000
            if (str_contains($e->getMessage(), '23000') || str_contains($e->getMessage(), 'Duplicate entry')) {
                return Response::json(['success' => false, 'error' => 'Customer with this email already exists'], 409);
            }
            return Response::json(['success' => false, 'error' => 'Customer creation failed'], 500);
        } catch (\Throwable $e) {
            return Response::json(['success' => false, 'error' => 'Customer creation failed'], 500);
        }
    }
}
