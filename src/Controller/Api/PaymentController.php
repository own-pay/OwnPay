<?php
declare(strict_types=1);

namespace OwnPay\Controller\Api;

use OwnPay\Container;
use OwnPay\Http\Request;
use OwnPay\Http\Response;
use OwnPay\Service\Payment\GatewayApiService;
use OwnPay\Repository\TransactionRepository;
use OwnPay\Event\EventManager;
use OwnPay\Service\System\InputSanitizer;

/**
 * Payment API Controller
 *
 * Exposes endpoints to initiate and query payment states. Integrates input validation
 * controls, filters customer PII vectors, and enforces transport security bounds.
 */
final class PaymentController
{
    /**
     * @var Container The service container instance.
     */
    private Container $c;

    /**
     * @var TransactionRepository Repository handling transaction entities.
     */
    private TransactionRepository $transactions;

    /**
     * @var \OwnPay\Service\Payment\PaymentService Service layer managing payment intents.
     */
    private \OwnPay\Service\Payment\PaymentService $paymentService;

    /**
     * Constructor.
     *
     * @param Container $c The service container instance.
     * @param TransactionRepository $transactions Repository handling transaction entities.
     * @param \OwnPay\Service\Payment\PaymentService $paymentService Service layer managing payment intents.
     */
    public function __construct(
        Container $c,
        TransactionRepository $transactions,
        \OwnPay\Service\Payment\PaymentService $paymentService
    ) {
        $this->c = $c;
        $this->transactions = $transactions;
        $this->paymentService = $paymentService;
    }

    /**
     * Initiate a new payment session and create a payment intent.
     *
     * POST /api/v1/payments/initiate
     *
     * @param Request $req The incoming HTTP request.
     * @return Response The JSON response detailing initiation status or validation failures.
     */
    public function initiate(Request $req): Response
    {
        $mid = (int) $req->getAttribute('merchant_id');
        $body = $req->json();

        // Validate required request parameters for input validation standards.
        $errors = [];
        if (empty($body['amount']) || !is_numeric($body['amount']) || bccomp((string) $body['amount'], '0', 2) <= 0) {
            $errors[] = 'amount must be a positive number';
        }
        if (empty($body['currency'])) {
            $errors[] = 'currency is required';
        }

        // Validate currency formatting and verify existence against registered codes.
        if (!empty($body['currency'])) {
            $currencyCode = strtoupper(InputSanitizer::string($body['currency']));
            if (strlen($currencyCode) !== 3 || !preg_match('/^[A-Z]{3}$/', $currencyCode)) {
                $errors[] = 'currency must be a valid 3-letter ISO code';
            } else {
                $currSvc = $this->c->get(\OwnPay\Service\Payment\CurrencyService::class);
                if (!in_array($currencyCode, $currSvc->supported(), true)) {
                    $errors[] = "currency '{$currencyCode}' is not supported";
                }
            }
        }

        // Verify callback schemes enforce safe transport standards.
        foreach (['callback_url', 'redirect_url', 'cancel_url'] as $urlField) {
            if (!empty($body[$urlField])) {
                $urlVal = filter_var($body[$urlField], FILTER_VALIDATE_URL);
                if ($urlVal === false) {
                    $errors[] = "{$urlField} must be a valid URL";
                } else {
                    $scheme = parse_url($urlVal, PHP_URL_SCHEME);
                    if (!in_array($scheme, ['http', 'https'], true)) {
                        $errors[] = "{$urlField} must use http or https scheme";
                    }
                }
            }
        }

        if (!empty($errors)) {
            return Response::json(['success' => false, 'errors' => $errors], 422);
        }

        // Sanitize customer PII inputs to prevent injection vectors.
        $customerEmail = !empty($body['customer_email']) ? InputSanitizer::email($body['customer_email']) : null;
        $customerName = !empty($body['customer_name']) ? InputSanitizer::string($body['customer_name']) : null;
        $customerPhone = !empty($body['customer_phone']) ? preg_replace('/[^\d+\-\s()]/', '', (string) $body['customer_phone']) : null;

        // Truncate variable values to enforce database column sizing constraints.
        if ($customerName !== null) $customerName = mb_substr($customerName, 0, 150);
        if ($customerPhone !== null) $customerPhone = mb_substr($customerPhone, 0, 30);

        // Resolve existing customer reference or provision a new customer entity.
        $customerId = null;
        if ($customerEmail !== null && $customerEmail !== '') {
            try {
                $piiSvc = $this->c->get(\OwnPay\Service\Customer\CustomerPiiService::class);
                $existing = $piiSvc->findByEmail($mid, $customerEmail);
                if ($existing) {
                    $customerId = (int) $existing['id'];
                } else {
                    $created = $piiSvc->create($mid, [
                        'name'  => $customerName ?? 'API Customer',
                        'email' => $customerEmail,
                        'phone' => $customerPhone,
                    ]);
                    $customerId = (int) ($created['id'] ?? 0) ?: null;
                }
            } catch (\Throwable $e) {
                // Proceed with intent creation without a linked customer reference if mapping fails.
            }
        }

        $redirectVal = !empty($body['redirect_url']) ? filter_var($body['redirect_url'], FILTER_VALIDATE_URL) : (!empty($body['callback_url']) ? filter_var($body['callback_url'], FILTER_VALIDATE_URL) : null);
        $cancelVal   = !empty($body['cancel_url']) ? filter_var($body['cancel_url'], FILTER_VALIDATE_URL) : (!empty($body['callback_url']) ? filter_var($body['callback_url'], FILTER_VALIDATE_URL) : null);
        $webhookVal  = !empty($body['callback_url']) ? filter_var($body['callback_url'], FILTER_VALIDATE_URL) : null;

        $intentData = [
            'amount'   => InputSanitizer::decimal($body['amount']),
            'currency' => $currencyCode ?? strtoupper(InputSanitizer::string($body['currency'])),
            'metadata' => [
                'reference'       => $body['reference'] ?? null,
                'customer_email'  => $customerEmail,
                'customer_name'   => $customerName,
                'customer_phone'  => $customerPhone,
                'gateway'         => $body['gateway'] ?? null,
                'custom_metadata' => $body['metadata'] ?? [],
            ],
        ];

        if ($customerId !== null) {
            $intentData['customer_id'] = $customerId;
        }
        if (!empty($body['reference'])) {
            $intentData['description'] = InputSanitizer::string($body['reference']);
        }
        if (is_string($redirectVal)) {
            $intentData['redirect_url'] = $redirectVal;
        }
        if (is_string($cancelVal)) {
            $intentData['cancel_url'] = $cancelVal;
        }
        if (is_string($webhookVal)) {
            $intentData['webhook_url'] = $webhookVal;
        }

        try {
            $intent = $this->paymentService->createIntent($mid, $intentData);

            // Construct white-labeled checkout URL using the primary custom domain mapping.
            $urlService = $this->c->get(\OwnPay\Service\Domain\DomainUrlService::class);
            $checkoutUrl = $urlService->buildCheckoutUrl($mid, $intent['token'], $req);

            return Response::json([
                'success'      => true,
                'payment_id'   => $intent['uuid'],
                'token'        => $intent['token'],
                'checkout_url' => $checkoutUrl,
                'status'       => $intent['status'],
            ], 201);
        } catch (\Throwable $e) {
            $this->c->get(\OwnPay\Service\System\Logger::class)->error('Payment initiation failed', ['error' => $e->getMessage(), 'merchant' => $mid]);
            return Response::json(['success' => false, 'error' => 'Payment processing failed'], 500);
        }
    }

    /**
     * Retrieve details for a specific transaction by transaction ID.
     *
     * GET /api/v1/payments/{trx_id}
     *
     * @param Request $req The incoming HTTP request.
     * @return Response The JSON response detailing transaction parameters or error states.
     */
    public function show(Request $req): Response
    {
        $trxId = trim($req->param('trx_id'));
        $mid = (int) $req->getAttribute('merchant_id');

        if ($trxId === '') {
            return Response::json(['success' => false, 'error' => 'Transaction ID required'], 422);
        }

        $payment = $this->transactions->forTenant($mid)->findByTrxId($trxId);

        if ($payment === null) {
            return Response::json(['success' => false, 'error' => 'Payment not found'], 404);
        }

        $response = [
            'id'           => $payment['id'],
            'trx_id'       => $payment['trx_id'],
            'amount'       => $payment['amount'],
            'currency'     => $payment['currency'],
            'fee'          => $payment['fee'] ?? '0.00',
            'status'       => $payment['status'],
            'gateway'      => $payment['gateway_slug'] ?? null,
            'method'       => $payment['method'] ?? null,
            'reference'    => $payment['reference'] ?? null,
            'created_at'   => $payment['created_at'],
            'completed_at' => $payment['completed_at'] ?? null,
        ];

        // Resolve decrypted customer PII details mapped via foreign key.
        if (!empty($payment['customer_id'])) {
            try {
                $pii = $this->c->get(\OwnPay\Service\Customer\CustomerPiiService::class);
                $customer = $pii->get($mid, (int) $payment['customer_id']);
                if ($customer) {
                    $response['customer'] = [
                        'name'  => $customer['name'] ?? null,
                        'email' => $customer['email'] ?? null,
                    ];
                }
            } catch (\Throwable $e) {
                // Skip appending customer details if lookup fails.
            }
        }

        return Response::json(['success' => true, 'payment' => $response]);
    }
}
