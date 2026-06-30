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
     * POST /api/v1/payments
     *
     * @param Request $req The incoming HTTP request.
     * @return Response The JSON response detailing initiation status or validation failures.
     */
    public function initiate(Request $req): Response
    {
        $midVal = $req->getAttribute('merchant_id');
        $mid = is_int($midVal) || is_string($midVal) ? (int)$midVal : 0;
        $body = $req->json();
        if (!is_array($body)) {
            $body = [];
        }

        $bodyAmount = $body['amount'] ?? null;
        $bodyCurrency = $body['currency'] ?? null;
        $bodyCallbackUrl = $body['callback_url'] ?? null;
        $bodyRedirectUrl = $body['redirect_url'] ?? null; //success url
        $bodyCancelUrl = $body['cancel_url'] ?? null;
        $bodyCustomerEmail = $body['customer_mail'] ?? $body['customer_email'] ?? null;
        $bodyCustomerName = $body['customer_name'] ?? null;
        $bodyCustomerPhone = $body['customer_phone'] ?? null;
        $bodyReference = $body['reference'] ?? null;
        $bodyGateway = $body['gateway'] ?? null;
        $bodyMetadata = $body['metadata'] ?? null;

        $amountStr = (is_string($bodyAmount) || is_numeric($bodyAmount)) ? (string) $bodyAmount : '';
        $currencyStr = is_string($bodyCurrency) ? $bodyCurrency : '';
        $callbackUrlStr = is_string($bodyCallbackUrl) ? $bodyCallbackUrl : null;
        $redirectUrlStr = is_string($bodyRedirectUrl) ? $bodyRedirectUrl : null;
        $cancelUrlStr = is_string($bodyCancelUrl) ? $bodyCancelUrl : null;
        $customerEmailStr = is_string($bodyCustomerEmail) ? $bodyCustomerEmail : null;
        $customerNameStr = is_string($bodyCustomerName) ? $bodyCustomerName : null;
        $customerPhoneStr = is_string($bodyCustomerPhone) || is_numeric($bodyCustomerPhone) ? (string) $bodyCustomerPhone : null;
        $referenceStr = is_string($bodyReference) ? $bodyReference : null;
        $gatewayStr = is_string($bodyGateway) ? $bodyGateway : null;
        $metadataArr = is_array($bodyMetadata) ? $bodyMetadata : [];

        // Validate required request parameters for input validation standards.
        $errors = [];
        if ($amountStr === '' || !is_numeric($amountStr) || bccomp($amountStr, '0', 2) <= 0) {
            $errors[] = 'amount must be a positive number';
        }
        if ($currencyStr === '') {
            $errors[] = 'currency is required';
        }

        // Validate currency formatting and verify existence against registered codes.
        if ($currencyStr !== '') {
            $currencyCode = strtoupper(InputSanitizer::string($currencyStr));
            if (strlen($currencyCode) !== 3 || !preg_match('/^[A-Z]{3}$/', $currencyCode)) {
                $errors[] = 'currency must be a valid 3-letter ISO code';
            } else {
                $currSvc = $this->c->get(\OwnPay\Service\Payment\CurrencyService::class);
                if ($currSvc instanceof \OwnPay\Service\Payment\CurrencyService) {
                    if (!in_array($currencyCode, $currSvc->supported(), true)) {
                        $errors[] = "currency '{$currencyCode}' is not supported";
                    }
                } else {
                    $errors[] = 'Currency verification service unavailable';
                }
            }
        }

        // Verify callback schemes enforce safe transport standards.
        $urlsToCheck = [
            'callback_url' => $callbackUrlStr,
            'redirect_url' => $redirectUrlStr,
            'cancel_url' => $cancelUrlStr,
        ];
        foreach ($urlsToCheck as $urlField => $urlVal) {
            if ($urlVal !== null && $urlVal !== '') {
                $validatedUrl = filter_var($urlVal, FILTER_VALIDATE_URL);
                if ($validatedUrl === false) {
                    $errors[] = "{$urlField} must be a valid URL";
                } else {
                    $scheme = parse_url($validatedUrl, PHP_URL_SCHEME);
                    if (!in_array($scheme, ['http', 'https'], true)) {
                        $errors[] = "{$urlField} must use http or https scheme";
                    }
                }
            }
        }

        if (!empty($errors)) {
            $formatted = [];
            foreach ($errors as $err) {
                $code = 'VALIDATION_FAILED';
                $field = null;
                if (str_contains($err, 'amount')) {
                    $code = 'INVALID_AMOUNT';
                    $field = 'amount';
                } elseif (str_contains($err, 'currency')) {
                    $code = 'INVALID_CURRENCY';
                    $field = 'currency';
                } elseif (str_contains($err, 'callback_url')) {
                    $code = 'INVALID_CALLBACK_URL';
                    $field = 'callback_url';
                } elseif (str_contains($err, 'redirect_url')) {
                    $code = 'INVALID_REDIRECT_URL';
                    $field = 'redirect_url';
                } elseif (str_contains($err, 'cancel_url')) {
                    $code = 'INVALID_CANCEL_URL';
                    $field = 'cancel_url';
                }
                $formatted[] = [
                    'code'    => $code,
                    'message' => $err,
                    'field'   => $field,
                ];
            }
            return Response::apiErrors($formatted, 422);
        }

        // Sanitize customer PII inputs to prevent injection vectors.
        $customerEmail = ($customerEmailStr !== null && $customerEmailStr !== '') ? InputSanitizer::email($customerEmailStr) : null;
        $customerName = ($customerNameStr !== null && $customerNameStr !== '') ? InputSanitizer::string($customerNameStr) : null;
        $customerPhone = ($customerPhoneStr !== null && $customerPhoneStr !== '') ? (string) preg_replace('/[^\d+\-\s()]/', '', $customerPhoneStr) : null;

        // Truncate variable values to enforce database column sizing constraints.
        if ($customerName !== null) $customerName = mb_substr($customerName, 0, 150);
        if ($customerPhone !== null) $customerPhone = mb_substr($customerPhone, 0, 30);

        // Resolve existing customer reference or provision a new customer entity.
        $customerId = null;
        if ($customerEmail !== null && $customerEmail !== '') {
            try {
                $piiSvc = $this->c->get(\OwnPay\Service\Customer\CustomerPiiService::class);
                if ($piiSvc instanceof \OwnPay\Service\Customer\CustomerPiiService) {
                    $existing = $piiSvc->findByEmail($mid, $customerEmail);
                    if (is_array($existing)) {
                        $existingIdVal = $existing['id'] ?? 0;
                        $customerId = is_int($existingIdVal) || is_string($existingIdVal) ? (int)$existingIdVal : 0;
                        
                        $updateData = [];
                        if ($customerName !== null && $customerName !== '' && ($existing['name'] ?? '') !== $customerName) {
                            $updateData['name'] = $customerName;
                        }
                        if ($customerPhone !== null && $customerPhone !== '' && ($existing['phone'] ?? '') !== $customerPhone) {
                            $updateData['phone'] = $customerPhone;
                        }
                        if ($updateData !== []) {
                            $piiSvc->update($mid, $customerId, $updateData);
                        }
                    } else {
                        $created = $piiSvc->create($mid, [
                            'name'  => $customerName ?? 'API Customer',
                            'email' => $customerEmail,
                            'phone' => $customerPhone,
                        ]);
                        $createdIdVal = $created['id'] ?? 0;
                        $customerId = (is_int($createdIdVal) || is_string($createdIdVal)) ? ((int)$createdIdVal ?: null) : null;
                    }
                }
            } catch (\Throwable $e) {
                // Proceed with intent creation without a linked customer reference if mapping fails.
            }
        }

        $redirectVal = ($redirectUrlStr !== null && $redirectUrlStr !== '') ? filter_var($redirectUrlStr, FILTER_VALIDATE_URL) : (($callbackUrlStr !== null && $callbackUrlStr !== '') ? filter_var($callbackUrlStr, FILTER_VALIDATE_URL) : null);
        $cancelVal   = ($cancelUrlStr !== null && $cancelUrlStr !== '') ? filter_var($cancelUrlStr, FILTER_VALIDATE_URL) : (($callbackUrlStr !== null && $callbackUrlStr !== '') ? filter_var($callbackUrlStr, FILTER_VALIDATE_URL) : null);
        $webhookVal  = ($callbackUrlStr !== null && $callbackUrlStr !== '') ? filter_var($callbackUrlStr, FILTER_VALIDATE_URL) : null;

        $redirectVal = is_string($redirectVal) ? $redirectVal : null;
        $cancelVal = is_string($cancelVal) ? $cancelVal : null;
        $webhookVal = is_string($webhookVal) ? $webhookVal : null;

        $intentData = [
            'amount'   => InputSanitizer::decimal($amountStr),
            'currency' => isset($currencyCode) ? $currencyCode : strtoupper(InputSanitizer::string($currencyStr)),
            'metadata' => [
                'reference'       => $referenceStr,
                'customer_email'  => $customerEmail,
                'customer_name'   => $customerName,
                'customer_phone'  => $customerPhone,
                'gateway'         => $gatewayStr,
                'custom_metadata' => $metadataArr,
            ],
        ];

        if ($customerId !== null) {
            $intentData['customer_id'] = $customerId;
        }
        if ($referenceStr !== null && $referenceStr !== '') {
            $intentData['description'] = InputSanitizer::string($referenceStr);
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
            $intentToken = isset($intent['token']) && is_string($intent['token']) ? $intent['token'] : '';
            $intentUuid = isset($intent['uuid']) && is_string($intent['uuid']) ? $intent['uuid'] : '';
            $intentStatus = isset($intent['status']) && is_string($intent['status']) ? $intent['status'] : '';

            // Construct white-labeled checkout URL using the primary custom domain mapping.
            $urlService = $this->c->get(\OwnPay\Service\Domain\DomainUrlService::class);
            if (!$urlService instanceof \OwnPay\Service\Domain\DomainUrlService) {
                throw new \RuntimeException('DomainUrlService not found');
            }
            $checkoutUrl = $urlService->buildCheckoutUrl($mid, $intentToken, $req);

            $data = [
                'payment_id'   => $intentUuid,
                'token'        => $intentToken,
                'checkout_url' => $checkoutUrl,
                'status'       => $intentStatus,
            ];

            return Response::apiSuccess($data, null, 201);
        } catch (\Throwable $e) {
            $logger = $this->c->get(\OwnPay\Service\System\Logger::class);
            if ($logger instanceof \OwnPay\Service\System\Logger) {
                $logger->error('Payment initiation failed', ['error' => $e->getMessage(), 'merchant' => $mid]);
            }
            return Response::apiError('PAYMENT_PROCESSING_FAILED', 'Payment processing failed', null, 500);
        }
    }

    /**
     * Retrieve details for a specific payment intent by payment intent UUID.
     *
     * GET /api/v1/payments/{payment_id}
     *
     * @param Request $req The incoming HTTP request.
     * @return Response The JSON response detailing payment intent parameters or error states.
     */
    public function show(Request $req): Response
    {
        $paymentIdVal = $req->param('payment_id');
        $paymentId = trim($paymentIdVal);
        $midVal = $req->getAttribute('merchant_id');
        $mid = is_int($midVal) || is_string($midVal) ? (int)$midVal : 0;

        if ($paymentId === '') {
            return Response::apiError('PAYMENT_ID_REQUIRED', 'Payment ID required', 'payment_id', 422);
        }

        // Validate UUID format
        if (!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/i', $paymentId)) {
            return Response::apiError('INVALID_PAYMENT_ID', 'Invalid Payment ID format', 'payment_id', 422);
        }

        // Retrieve the payment intent by UUID
        $intent = $this->paymentService->findByUuid($paymentId);

        if ($intent === null) {
            return Response::apiError('PAYMENT_NOT_FOUND', 'Payment not found', null, 404);
        }

        $intentMerchantIdVal = $intent['merchant_id'] ?? 0;
        $intentMerchantId = is_scalar($intentMerchantIdVal) ? (int)$intentMerchantIdVal : 0;

        if ($intentMerchantId !== $mid) {
            return Response::apiError('PAYMENT_NOT_FOUND', 'Payment not found', null, 404);
        }

        // Retrieve the latest transaction linked to this payment intent
        $intentIdVal = $intent['id'] ?? 0;
        $intentId = is_scalar($intentIdVal) ? (int)$intentIdVal : 0;
        $payment = $this->transactions->forTenant($mid)->findByIntentId($intentId);

        if (is_array($payment)) {
            $response = [
                'id'             => $payment['id'] ?? null,
                'trx_id'         => $payment['trx_id'] ?? null,
                'gateway_trx_id' => $payment['gateway_trx_id'] ?? null,
                'amount'         => $payment['amount'] ?? null,
                'currency'       => $payment['currency'] ?? null,
                'fee'            => $payment['fee'] ?? '0.00',
                'status'         => $payment['status'] ?? null,
                'gateway'        => $payment['gateway_slug'] ?? null,
                'method'         => $payment['method'] ?? null,
                'reference'      => $payment['reference'] ?? null,
                'created_at'     => $payment['created_at'] ?? null,
                'completed_at'   => $payment['completed_at'] ?? null,
            ];
        } else {
            // No transaction has been created yet, return the payment intent status
            $response = [
                'id'             => null,
                'trx_id'         => null,
                'gateway_trx_id' => null,
                'amount'         => $intent['amount'] ?? null,
                'currency'       => $intent['currency'] ?? null,
                'fee'            => '0.00',
                'status'         => $intent['status'] ?? null,
                'gateway'        => null,
                'method'         => null,
                'reference'      => $intent['description'] ?? null,
                'created_at'     => $intent['created_at'] ?? null,
                'completed_at'   => null,
            ];
        }

        // Resolve decrypted customer PII details mapped via foreign key.
        $customerIdVal = is_array($payment) ? ($payment['customer_id'] ?? null) : ($intent['customer_id'] ?? null);
        $customerId = (is_int($customerIdVal) || is_string($customerIdVal)) ? (int)$customerIdVal : 0;
        if ($customerId > 0) {
            try {
                $pii = $this->c->get(\OwnPay\Service\Customer\CustomerPiiService::class);
                if ($pii instanceof \OwnPay\Service\Customer\CustomerPiiService) {
                    $customer = $pii->get($mid, $customerId);
                    if (is_array($customer)) {
                        $response['customer'] = [
                            'name'  => $customer['name'] ?? null,
                            'email' => $customer['email'] ?? null,
                        ];
                    }
                }
            } catch (\Throwable $e) {
                // Skip appending customer details if lookup fails.
            }
        }

        return Response::apiSuccess($response);
    }
}
