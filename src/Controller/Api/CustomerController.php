<?php
declare(strict_types=1);

namespace OwnPay\Controller\Api;

use OwnPay\Container;
use OwnPay\Http\Request;
use OwnPay\Http\Response;
use OwnPay\Service\Customer\CustomerPiiService;
use OwnPay\Service\System\InputSanitizer;

/**
 * Controller for managing customer records via REST API endpoints.
 * Ensures OWASP and GDPR compliance by encrypting PII at rest and resolving queries by hash lookup.
 */
final class CustomerController
{
    /**
     * The dependency injection container.
     *
     * @phpstan-ignore property.onlyWritten
     */
    private Container $c;

    /**
     * The customer PII manager service.
     */
    private CustomerPiiService $pii;

    /**
     * CustomerController constructor.
     *
     * @param Container $c The dependency injection container.
     * @param CustomerPiiService $pii The customer PII manager service.
     */
    public function __construct(Container $c, CustomerPiiService $pii)
    {
        $this->c = $c;
        $this->pii = $pii;
    }

    /**
     * List all customer records for the active brand.
     *
     * @param Request $req The incoming HTTP request.
     * @return Response The JSON response with customer records.
     * @throws \Exception If lookup fails.
     */
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
     * Show details for a specific customer.
     *
     * Auto-detects identifier type (email contains '@', otherwise defaults to phone).
     *
     * @param Request $req The incoming HTTP request.
     * @return Response The JSON response with decrypted customer details or error.
     * @throws \Exception If query fails.
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
     * Create a new customer record.
     *
     * Body format: { name, email?, phone? }
     *
     * @param Request $req The incoming HTTP request.
     * @return Response The JSON response with created customer ID / UUID or duplicate error.
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
