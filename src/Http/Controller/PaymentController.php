<?php

declare(strict_types=1);

namespace OwnPay\Http\Controller;

use OwnPay\Http\JsonResponse;
use OwnPay\Middleware\BearerAuthMiddleware;
use OwnPay\Service\PaymentService;
use OwnPay\Service\IdempotencyService;

/**
 * POST /v1/payments        — Create a payment intent
 * GET  /v1/payments/{id}   — Get a payment intent by UUID
 */
final class PaymentController
{
    private PaymentService $payments;
    private IdempotencyService $idempotency;

    public function __construct()
    {
        $this->payments = new PaymentService();
        $this->idempotency = new IdempotencyService();
    }

    /**
     * POST /v1/payments
     */
    public function create(array $params): void
    {
        $merchant = (new BearerAuthMiddleware())->guard('create_payment');

        $body = JsonResponse::parseRequestBody();
        if ($body === null) {
            JsonResponse::error('INVALID_JSON', 'Request body must be valid JSON.', 400);
            return;
        }

        // Required fields
        $amount = $body['amount'] ?? null;
        $currency = $body['currency'] ?? 'BDT';

        if ($amount === null || !is_numeric($amount)) {
            JsonResponse::error('INVALID_AMOUNT', 'The "amount" field is required and must be numeric.', 400);
            return;
        }

        $customerInfo = [
            'name' => $body['customer_name'] ?? $body['full_name'] ?? '',
            'email' => $body['customer_email'] ?? $body['email_address'] ?? '',
            'phone' => $body['customer_phone'] ?? $body['mobile_number'] ?? '',
        ];

        $metadata = $body['metadata'] ?? [];

        // Idempotency check
        $idempotencyKey = $_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? null;
        $idempotencyResult = null;

        if ($idempotencyKey !== null) {
            $requestHash = hash('sha256', json_encode($body));
            $idempotencyResult = $this->idempotency->acquire('payment', $idempotencyKey, $requestHash);

            if ($idempotencyResult['isReplay']) {
                http_response_code(200);
                header('Content-Type: application/json; charset=utf-8');
                header('X-Idempotency-Replayed: true');
                echo $idempotencyResult['cachedResponse'];
                return;
            }

            if ($idempotencyResult['isConflict']) {
                JsonResponse::error(
                    'IDEMPOTENCY_CONFLICT',
                    'A request with this Idempotency-Key is currently being processed.',
                    409
                );
                return;
            }
        }

        // Create intent via service
        $intent = $this->payments->createIntent(
            $merchant['merchant_id'],
            number_format((float) $amount, 4, '.', ''),
            strtoupper($currency),
            $customerInfo,
            $metadata,
            $idempotencyKey
        );

        // Format response
        $response = [
            'id' => $intent['public_id'],
            'amount' => $intent['amount'],
            'currency' => $intent['currency'],
            'status' => $intent['status'],
            'created_at' => $intent['created_at'],
        ];

        // Store in idempotency cache
        if ($idempotencyResult !== null) {
            $responseJson = json_encode(['success' => true, 'data' => $response], JSON_UNESCAPED_SLASHES);
            $this->idempotency->complete($idempotencyResult['keyId'], $responseJson, 201);
        }

        JsonResponse::created($response);
    }

    /**
     * GET /v1/payments/{id}
     */
    public function show(array $params): void
    {
        (new BearerAuthMiddleware())->guard('view_payment');

        $publicId = $params['id'] ?? '';

        $intent = (new \OwnPay\Repository\PaymentIntentRepository())->findByPublicId($publicId);

        if ($intent === null) {
            JsonResponse::error('NOT_FOUND', 'Payment intent not found.', 404);
            return;
        }

        // Strip internal fields
        JsonResponse::success([
            'id' => $intent['public_id'],
            'amount' => $intent['amount'],
            'currency' => $intent['currency'],
            'status' => $intent['status'],
            'idempotency_key' => $intent['idempotency_key'],
            'created_at' => $intent['created_at'],
            'updated_at' => $intent['updated_at'],
        ]);
    }
}
