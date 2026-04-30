<?php
declare(strict_types=1);

namespace OwnPay\Controller\Api;

use OwnPay\Container;
use OwnPay\Http\Request;
use OwnPay\Http\Response;
use OwnPay\Service\Payment\PaymentService;
use OwnPay\Event\EventManager;
use OwnPay\Service\System\InputSanitizer;

/**
 * Payment API — initiate and query payments.
 * OWASP: Input validation, no PII in error responses.
 * PCI: Never logs/stores card data. Tokenized via gateway.
 */
final class PaymentController
{
    private Container $c;
    private PaymentService $payments;
    private EventManager $events;

    public function __construct(Container $c, PaymentService $payments, EventManager $events)
    {
        $this->c = $c;
        $this->payments = $payments;
        $this->events = $events;
    }

    /**
     * POST /api/v1/payments/initiate
     * Body: { gateway, amount, currency, customer_email?, customer_name?, customer_phone?, metadata?, callback_url }
     */
    public function initiate(Request $req): Response
    {
        $mid = (int) $req->getAttribute('merchant_id');
        $body = $req->jsonBody();

        // OWASP: Validate required fields
        $errors = [];
        if (empty($body['gateway'])) $errors[] = 'gateway is required';
        if (empty($body['amount']) || !is_numeric($body['amount']) || (float) $body['amount'] <= 0) $errors[] = 'amount must be a positive number';
        if (empty($body['currency'])) $errors[] = 'currency is required';
        if (!empty($errors)) {
            return Response::json(['success' => false, 'errors' => $errors], 422);
        }

        $data = [
            'merchant_id'    => $mid,
            'gateway'        => InputSanitizer::slug($body['gateway']),
            'amount'         => InputSanitizer::decimal($body['amount']),
            'currency'       => strtoupper(InputSanitizer::string($body['currency'])),
            'customer_email' => $body['customer_email'] ?? null,
            'customer_name'  => $body['customer_name'] ?? null,
            'customer_phone' => $body['customer_phone'] ?? null,
            'callback_url'   => $body['callback_url'] ?? null,
            'metadata'       => $body['metadata'] ?? [],
            'ip_address'     => $req->ip(),
        ];

        $this->events->doAction('api.payment.before', $data);

        try {
            $result = $this->payments->initiate($data);
            $this->events->doAction('api.payment.initiated', $result);
            return Response::json([
                'success'      => true,
                'payment_id'   => $result['id'],
                'trx_id'       => $result['trx_id'],
                'checkout_url' => $result['checkout_url'] ?? null,
                'status'       => $result['status'],
            ], 201);
        } catch (\InvalidArgumentException $e) {
            return Response::json(['success' => false, 'error' => $e->getMessage()], 400);
        } catch (\Throwable $e) {
            // OWASP: Don't leak internal errors
            $this->c->get(\OwnPay\Core\Logger::class)->error('Payment initiation failed', ['error' => $e->getMessage(), 'merchant' => $mid]);
            return Response::json(['success' => false, 'error' => 'Payment processing failed'], 500);
        }
    }

    /**
     * GET /api/v1/payments/{id}
     */
    public function show(Request $req, int $id): Response
    {
        $mid = (int) $req->getAttribute('merchant_id');
        $payment = $this->payments->findForMerchant($mid, $id);

        if ($payment === null) {
            return Response::json(['success' => false, 'error' => 'Payment not found'], 404);
        }

        // OWASP: Only expose safe fields
        return Response::json([
            'success' => true,
            'payment' => [
                'id'          => $payment['id'],
                'trx_id'      => $payment['trx_id'],
                'amount'      => $payment['amount'],
                'currency'    => $payment['currency'],
                'status'      => $payment['status'],
                'gateway'     => $payment['gateway'],
                'customer'    => [
                    'name'  => $payment['customer_name'] ?? null,
                    'email' => $payment['customer_email'] ?? null,
                ],
                'created_at'  => $payment['created_at'],
                'completed_at' => $payment['completed_at'] ?? null,
            ],
        ]);
    }
}
