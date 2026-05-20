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
 * Payment API — initiate and query payments.
 * OWASP: Input validation, no PII in error responses.
 * PCI: Never logs/stores card data. Tokenized via gateway.
 */
final class PaymentController
{
    private Container $c;
    private GatewayApiService $gatewayApi;
    private TransactionRepository $transactions;
    private EventManager $events;

    public function __construct(Container $c, GatewayApiService $gatewayApi, TransactionRepository $transactions, EventManager $events)
    {
        $this->c = $c;
        $this->gatewayApi = $gatewayApi;
        $this->transactions = $transactions;
        $this->events = $events;
    }

    /**
     * POST /api/v1/payments/initiate
     * Body: { amount, currency, gateway?, customer_email?, customer_name?, customer_phone?, metadata?, callback_url? }
     * Gateway is optional — if omitted, uses brand's default gateway.
     */
    public function initiate(Request $req): Response
    {
        $mid = (int) $req->getAttribute('merchant_id');
        $body = $req->json();

        // OWASP: Validate required fields (gateway is optional now)
        $errors = [];
        if (empty($body['amount']) || !is_numeric($body['amount']) || bccomp((string) $body['amount'], '0', 2) <= 0) {
            $errors[] = 'amount must be a positive number';
        }
        if (empty($body['currency'])) {
            $errors[] = 'currency is required';
        }

        // CHK-009 FIX: Validate currency format + existence in DB
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

        // CHK-008 FIX: Validate callback_url scheme (http/https only)
        if (!empty($body['callback_url'])) {
            $cbUrl = filter_var($body['callback_url'], FILTER_VALIDATE_URL);
            if ($cbUrl === false) {
                $errors[] = 'callback_url must be a valid URL';
            } else {
                $scheme = parse_url($cbUrl, PHP_URL_SCHEME);
                if (!in_array($scheme, ['http', 'https'], true)) {
                    $errors[] = 'callback_url must use http or https scheme';
                }
            }
        }

        if (!empty($errors)) {
            return Response::json(['success' => false, 'errors' => $errors], 422);
        }

        // Resolve gateway: use provided or fallback to brand default
        $gatewaySlug = !empty($body['gateway'])
            ? InputSanitizer::slug($body['gateway'])
            : $this->resolveDefaultGateway($mid);

        if ($gatewaySlug === null || $gatewaySlug === '') {
            return Response::json(['success' => false, 'error' => 'No gateway available. Configure a gateway or provide one in request.'], 400);
        }

        // CHK-008 FIX: Sanitize customer PII fields
        $customerEmail = !empty($body['customer_email']) ? InputSanitizer::email($body['customer_email']) : null;
        $customerName = !empty($body['customer_name']) ? InputSanitizer::string($body['customer_name']) : null;
        $customerPhone = !empty($body['customer_phone']) ? preg_replace('/[^\d+\-\s()]/', '', (string) $body['customer_phone']) : null;

        // Truncate to safe lengths
        if ($customerName !== null) $customerName = mb_substr($customerName, 0, 150);
        if ($customerPhone !== null) $customerPhone = mb_substr($customerPhone, 0, 30);

        // CHK-010 FIX: Resolve or create customer_id from PII
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
            } catch (\Throwable) {
                // Customer resolution failed — proceed without customer_id
            }
        }

        $data = [
            'merchant_id'    => $mid,
            'gateway'        => $gatewaySlug,
            'amount'         => InputSanitizer::decimal($body['amount']),
            'currency'       => $currencyCode ?? strtoupper(InputSanitizer::string($body['currency'])),
            'customer_id'    => $customerId,
            'customer_email' => $customerEmail,
            'customer_name'  => $customerName,
            'customer_phone' => $customerPhone,
            'callback_url'   => !empty($body['callback_url']) ? filter_var($body['callback_url'], FILTER_VALIDATE_URL) : null,
            'reference'      => $body['reference'] ?? null,
            'metadata'       => $body['metadata'] ?? [],
            'ip_address'     => $req->ip(),
        ];

        $this->events->doAction('api.payment.before', $data);

        try {
            $result = $this->gatewayApi->initiatePayment($mid, $gatewaySlug, $data);
            if (!$result['success']) {
                throw new \InvalidArgumentException($result['error']);
            }
            $transaction = $result['transaction'];
            $this->events->doAction('api.payment.initiated', $transaction);
            return Response::json([
                'success'      => true,
                'payment_id'   => $transaction['id'],
                'trx_id'       => $transaction['trx_id'],
                'checkout_url' => $result['redirect_url'] ?? null,
                'status'       => $transaction['status'],
            ], 201);
        } catch (\InvalidArgumentException $e) {
            return Response::json(['success' => false, 'error' => $e->getMessage()], 400);
        } catch (\Throwable $e) {
            // OWASP: Don't leak internal errors
            $this->c->get(\OwnPay\Service\System\Logger::class)->error('Payment initiation failed', ['error' => $e->getMessage(), 'merchant' => $mid]);
            return Response::json(['success' => false, 'error' => 'Payment processing failed'], 500);
        }
    }

    /**
     * GET /api/v1/payments/{trx_id}
     * Lookup by transaction ID (TXN-XXXX format), NOT database ID.
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

        // Resolve customer from FK — columns are encrypted (name_enc, email_enc)
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
            } catch (\Throwable) {
                // Customer lookup failed — skip
            }
        }

        return Response::json(['success' => true, 'payment' => $response]);
    }

    /**
     * Resolve brand's default gateway slug.
     */
    private function resolveDefaultGateway(int $merchantId): ?string
    {
        try {
            $db = $this->c->get(\OwnPay\Core\Database::class);
            $config = $db->fetchOne(
                "SELECT g.slug FROM op_gateway_configs gc
                 JOIN op_gateways g ON g.id = gc.gateway_id
                 WHERE gc.merchant_id = :mid AND gc.status = 'active'
                 ORDER BY gc.created_at ASC LIMIT 1",
                ['mid' => $merchantId]
            );
            return $config['slug'] ?? null;
        } catch (\Throwable) {
            return null;
        }
    }
}
