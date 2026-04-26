<?php
declare(strict_types=1);

namespace OwnPay\Controller\Frontend;

use OwnPay\Http\RequestContext;
use OwnPay\Service\CrudService;
use OwnPay\Service\InputSanitizer;

class ApiController
{
    public static function handle(array $context, ?RequestContext $ctx = null) {
        $ctx ??= $GLOBALS['requestContext'] ?? throw new \RuntimeException('RequestContext not available');
        extract($context);

                    $api_type = $segments[1] ?? null;

                    header('Content-Type: application/json');

                    $apiKey = getAuthorizationHeader();

                    $params = [':api_key' => $apiKey, ':status' => 'active'];

                    $response_api = CrudService::select($db_prefix . 'api', 'WHERE api_key = :api_key AND status = :status', '* FROM', $params);
                    if ($response_api['status'] == false) {
                        http_response_code(400);

                        echo json_encode([
                            'error' => [
                                'code' => 'INVALID_API_KEY',
                                'message' => 'The API key provided is incorrect or invalid.'
                            ]
                        ]);
                        exit;
                    }

                    // Rate limiting per API key
                    $rateLimiter = new \OwnPay\Middleware\RateLimiterMiddleware();
                    $rateLimiter->enforce(
                        (int) $response_api['response'][0]['id'],
                        $response_api['response'][0]['api_key_prefix'] ?? '',
                        $_SERVER['REQUEST_METHOD'] ?? 'GET'
                    );

                    if (isExpired($response_api['response'][0]['expired_date'])) {
                        http_response_code(400);

                        echo json_encode([
                            'error' => [
                                'code' => 'INVALID_API_KEY',
                                'message' => 'The API key provided is incorrect or expired.'
                            ]
                        ]);
                        exit;
                    }

                    $rawInput = file_get_contents("php://input");

                    $data = json_decode($rawInput, true);

                    if (!$data) {
                        http_response_code(400);
                        echo json_encode([
                            'error' => [
                                'code' => 'INVALID_JSON_PAYLOAD',
                                'message' => 'The JSON payload is invalid or malformed.'
                            ]
                        ]);
                        exit;
                    }

                    if ($api_type == "checkout") {
                        $api_scopes = $response_api['response'][0]['api_scopes'] ?? [];
                        if (is_string($api_scopes)) {
                            $api_scopes = json_decode($api_scopes, true);
                        }

                        if (!in_array("create_payment", $api_scopes)) {
                            $requiredScope = 'Create Payment';

                            http_response_code(400);
                            echo json_encode([
                                'error' => [
                                    'code' => 'INSUFFICIENT_SCOPE',
                                    'message' => "The API key does not have the required permission: {$requiredScope}"
                                ]
                            ]);
                            exit;
                        }

                        $checkout_type = $segments[2] ?? null;

                        if ($checkout_type == "redirect") {
                            $fullName = $data['full_name'] ?? '';
                            $email = $data['email_address'] ?? '';
                            $mobile = $data['mobile_number'] ?? '';
                            $amount = $data['amount'] ?? '0';
                            $currency = $data['currency'] ?? 'BDT';
                            $returnUrl = $data['return_url'] ?? '';
                            $webhookUrl = $data['webhook_url'] ?? '';
                            $metadataRaw = $data['metadata'] ?? '{}';

                            function getDomainFromUrl($url)
                            {
                                // Check if it's a valid URL
                                if (filter_var($url, FILTER_VALIDATE_URL)) {
                                    // Parse the URL to get host
                                    $parsed = parse_url($url, PHP_URL_HOST);
                                    return $parsed;
                                }
                                return false; // Invalid URL
                            }

                            if ($returnUrl == "") {
                                $returnUrl = null;
                            } else {
                                $returnDomain = getDomainFromUrl($returnUrl);

                                if (!$returnDomain) {
                                    http_response_code(400);
                                    echo json_encode([
                                        'error' => [
                                            'code' => 'INVALID_URL',
                                            'message' => 'Return URL is invalid.'
                                        ]
                                    ]);
                                    exit;
                                } else {
                                    $params = [':domain' => $returnDomain];

                                    $response_urlCheck = CrudService::select($db_prefix . 'domain', 'WHERE domain = :domain', '* FROM', $params);
                                    if ($response_urlCheck['status'] == true) {
                                        if ($response_urlCheck['response'][0]['status'] !== "active") {
                                            http_response_code(400);
                                            echo json_encode([
                                                'error' => [
                                                    'code' => 'INVALID_URL',
                                                    'message' => 'The Return URL ("' . $returnDomain . '") is whitelisted but not active. Please activate this domain in the "Domains" section to proceed.'
                                                ]
                                            ]);
                                            exit;
                                        }
                                    } else {
                                        http_response_code(400);
                                        echo json_encode([
                                            'error' => [
                                                'code' => 'INVALID_URL',
                                                'message' => 'The provided Return URL ("' . $returnDomain . '") is not whitelisted. Please add this domain in the "Domains" section to continue.'
                                            ]
                                        ]);
                                        exit;
                                    }
                                }
                            }

                            if ($webhookUrl == "") {
                                $webhookUrl = null;
                            } else {
                                $webhookDomain = getDomainFromUrl($webhookUrl);

                                if (!$webhookDomain) {
                                    http_response_code(400);
                                    echo json_encode([
                                        'error' => [
                                            'code' => 'INVALID_URL',
                                            'message' => 'Webhook URL is invalid.'
                                        ]
                                    ]);
                                    exit;
                                } else {
                                    $params = [':domain' => $webhookDomain];

                                    $response_urlCheck = CrudService::select($db_prefix . 'domain', 'WHERE domain = :domain', '* FROM', $params);
                                    if ($response_urlCheck['status'] == true) {
                                        if ($response_urlCheck['response'][0]['status'] !== "active") {
                                            http_response_code(400);
                                            echo json_encode([
                                                'error' => [
                                                    'code' => 'INVALID_URL',
                                                    'message' => 'The Webhook URL ("' . $webhookDomain . '") is whitelisted but not active. Please activate this domain in the "Domains" section to proceed.'
                                                ]
                                            ]);
                                            exit;
                                        }
                                    } else {
                                        http_response_code(400);
                                        echo json_encode([
                                            'error' => [
                                                'code' => 'INVALID_URL',
                                                'message' => 'The provided Webhook URL ("' . $webhookDomain . '") is not whitelisted. Please add this domain in the "Domains" section to continue.'
                                            ]
                                        ]);
                                        exit;
                                    }
                                }
                            }

                            if (is_string($metadataRaw)) {
                                $metadata = json_decode($metadataRaw, true);
                                if ($metadata === null && json_last_error() !== JSON_ERROR_NONE) {
                                    http_response_code(400);
                                    echo json_encode([
                                        'error' => [
                                            'code' => 'INVALID_JSON',
                                            'message' => 'The metadata JSON is invalid.'
                                        ]
                                    ]);
                                    exit;
                                }
                            } elseif (is_array($metadataRaw)) {
                                $metadata = $metadataRaw;
                            } else {
                                http_response_code(400);
                                echo json_encode([
                                    'error' => [
                                        'code' => 'INVALID_METADATA',
                                        'message' => 'Metadata must be an array or valid JSON string.'
                                    ]
                                ]);
                                exit;
                            }

                            if (empty($fullName)) {
                                http_response_code(400);
                                echo json_encode([
                                    'error' => [
                                        'code' => 'MISSING_FIELD',
                                        'message' => 'Full name is required.'
                                    ]
                                ]);
                                exit;
                            }

                            if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                                http_response_code(400);
                                echo json_encode([
                                    'error' => [
                                        'code' => 'INVALID_EMAIL',
                                        'message' => 'A valid email address is required.'
                                    ]
                                ]);
                                exit;
                            }

                            if (empty($mobile)) {
                                http_response_code(400);
                                echo json_encode([
                                    'error' => [
                                        'code' => 'MISSING_FIELD',
                                        'message' => 'Mobile number is required.'
                                    ]
                                ]);
                                exit;
                            }

                            if (!is_numeric($amount) || $amount <= 0) {
                                http_response_code(400);
                                echo json_encode([
                                    'error' => [
                                        'code' => 'INVALID_AMOUNT',
                                        'message' => 'Amount must be a positive number.'
                                    ]
                                ]);
                                exit;
                            }

                            /*
                            |--------------------------------------------------------------
                            | Idempotency Check (Phase 2.0 — Task 2.2)
                            |--------------------------------------------------------------
                            */
                            $idemKey = $_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? $data['idempotency_key'] ?? null;
                            $idemRowId = null;

                            if ($idemKey !== null && $idemKey !== '' && class_exists('\OwnPay\Service\LegacyIdempotencyBridge')) {
                                $idemBridge = new \OwnPay\Service\LegacyIdempotencyBridge($db_prefix);
                                $idemResult = $idemBridge->acquire(
                                    'checkout',
                                    $response_api['response'][0]['brand_id'],
                                    $idemKey,
                                    hash('sha256', $rawInput)
                                );

                                if ($idemResult['replay']) {
                                    http_response_code($idemResult['cached_status']);
                                    echo $idemResult['cached_response'];
                                    exit;
                                }

                                if ($idemResult['conflict']) {
                                    http_response_code(409);
                                    echo json_encode([
                                        'error' => [
                                            'code' => 'IDEMPOTENCY_CONFLICT',
                                            'message' => 'A request with this idempotency key is already being processed.'
                                        ]
                                    ]);
                                    exit;
                                }

                                $idemRowId = $idemResult['row_id'];
                            }

                            $params = [':brand_id' => $response_api['response'][0]['brand_id'], ':code' => $currency];

                            $response_currency = CrudService::select($db_prefix . 'currency', 'WHERE brand_id = :brand_id AND code = :code', '* FROM', $params);
                            if ($response_currency['status'] == true) {
                                $params = [':brand_id' => $response_api['response'][0]['brand_id'], ':email' => $email, ':status' => 'suspend'];

                                $check_customer = CrudService::select($db_prefix . 'customer', 'WHERE brand_id = :brand_id AND email = :email AND status = :status', '* FROM', $params);
                                if ($check_customer['status'] == true) {
                                    http_response_code(400);
                                    echo json_encode([
                                        'error' => [
                                            'code' => 'INVALID_CUSTOMER',
                                            'message' => empty($check_customer['response'][0]['suspend_reason']) ? 'Customer is already suspended by the admin.' : 'Customer is already suspended by the admin. Reason: ' . InputSanitizer::html($check_customer['response'][0]['suspend_reason'])
                                        ]
                                    ]);
                                    exit;
                                }

                                $payment_id = generateItemID(27, 27);

                                $customerInfoJson = json_encode([
                                    'name' => $fullName,
                                    'email' => $email,
                                    'mobile' => $mobile
                                ], JSON_UNESCAPED_UNICODE);

                                $columns = ['brand_id', 'ref', 'customer_info', 'amount', 'currency', 'metadata', 'return_url', 'webhook_url', 'created_date', 'updated_date'];
                                $values = [$response_api['response'][0]['brand_id'], $payment_id, $customerInfoJson, money_sanitize($amount), $currency, json_encode($metadata), $returnUrl, $webhookUrl, getCurrentDatetime('Y-m-d H:i:s'), getCurrentDatetime('Y-m-d H:i:s')];

                                CrudService::insert($db_prefix . 'transaction', $columns, $values);

                                $params = [':brand_id' => $response_api['response'][0]['brand_id'], ':email' => $email];

                                $response_customer = CrudService::select($db_prefix . 'customer', 'WHERE brand_id = :brand_id AND email = :email', '* FROM', $params);
                                if ($response_customer['status'] == false) {
                                    $ref = generateItemID();

                                    $columns = ['ref', 'brand_id', 'name', 'email', 'mobile', 'created_date', 'updated_date'];
                                    $values = [$ref, $response_api['response'][0]['brand_id'], $fullName, $email, $mobile, getCurrentDatetime('Y-m-d H:i:s'), getCurrentDatetime('Y-m-d H:i:s')];

                                    CrudService::insert($db_prefix . 'customer', $columns, $values);
                                }

                                $checkoutResponse = json_encode(['op_id' => $payment_id, 'op_url' => $site_url . $path_payment . '/' . $payment_id]);

                                // Cache the response for idempotency replay
                                if ($idemRowId !== null && isset($idemBridge)) {
                                    $idemBridge->complete($idemRowId, $checkoutResponse, 200);
                                }

                                echo $checkoutResponse;
                            } else {
                                http_response_code(400);
                                echo json_encode([
                                    'error' => [
                                        'code' => 'INVALID_CURRENCY',
                                        'message' => 'Currency not supported.'
                                    ]
                                ]);
                                exit;
                            }
                        } else {
                            if ($checkout_type == "popup") {
                                $fullName = $data['full_name'] ?? '';
                                $email = $data['email_address'] ?? '';
                                $mobile = $data['mobile_number'] ?? '';
                                $amount = $data['amount'] ?? '0';
                                $currency = $data['currency'] ?? 'BDT';
                                $webhookUrl = $data['webhook_url'] ?? null;
                                $metadataRaw = $data['metadata'] ?? '{}';

                                if ($webhookUrl == "") {
                                    $webhookUrl = null;
                                }

                                if (is_string($metadataRaw)) {
                                    $metadata = json_decode($metadataRaw, true);
                                    if ($metadata === null && json_last_error() !== JSON_ERROR_NONE) {
                                        http_response_code(400);
                                        echo json_encode([
                                            'error' => [
                                                'code' => 'INVALID_JSON',
                                                'message' => 'The metadata JSON is invalid.'
                                            ]
                                        ]);
                                        exit;
                                    }
                                } elseif (is_array($metadataRaw)) {
                                    $metadata = $metadataRaw;
                                } else {
                                    http_response_code(400);
                                    echo json_encode([
                                        'error' => [
                                            'code' => 'INVALID_METADATA',
                                            'message' => 'Metadata must be an array or valid JSON string.'
                                        ]
                                    ]);
                                    exit;
                                }

                                if (empty($fullName)) {
                                    http_response_code(400);
                                    echo json_encode([
                                        'error' => [
                                            'code' => 'MISSING_FIELD',
                                            'message' => 'Full name is required.'
                                        ]
                                    ]);
                                    exit;
                                }

                                if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                                    http_response_code(400);
                                    echo json_encode([
                                        'error' => [
                                            'code' => 'INVALID_EMAIL',
                                            'message' => 'A valid email address is required.'
                                        ]
                                    ]);
                                    exit;
                                }

                                if (empty($mobile)) {
                                    http_response_code(400);
                                    echo json_encode([
                                        'error' => [
                                            'code' => 'MISSING_FIELD',
                                            'message' => 'Mobile number is required.'
                                        ]
                                    ]);
                                    exit;
                                }

                                if (!is_numeric($amount) || $amount <= 0) {
                                    http_response_code(400);
                                    echo json_encode([
                                        'error' => [
                                            'code' => 'INVALID_AMOUNT',
                                            'message' => 'Amount must be a positive number.'
                                        ]
                                    ]);
                                    exit;
                                }

                                /*
                                |--------------------------------------------------------------
                                | Idempotency Check (Phase 2.0 — Task 2.2)
                                |--------------------------------------------------------------
                                */
                                $idemKey = $_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? $data['idempotency_key'] ?? null;
                                $idemRowId = null;

                                if ($idemKey !== null && $idemKey !== '' && class_exists('\OwnPay\Service\LegacyIdempotencyBridge')) {
                                    $idemBridge = new \OwnPay\Service\LegacyIdempotencyBridge($db_prefix);
                                    $idemResult = $idemBridge->acquire(
                                        'checkout',
                                        $response_api['response'][0]['brand_id'],
                                        $idemKey,
                                        hash('sha256', $rawInput)
                                    );

                                    if ($idemResult['replay']) {
                                        http_response_code($idemResult['cached_status']);
                                        echo $idemResult['cached_response'];
                                        exit;
                                    }

                                    if ($idemResult['conflict']) {
                                        http_response_code(409);
                                        echo json_encode([
                                            'error' => [
                                                'code' => 'IDEMPOTENCY_CONFLICT',
                                                'message' => 'A request with this idempotency key is already being processed.'
                                            ]
                                        ]);
                                        exit;
                                    }

                                    $idemRowId = $idemResult['row_id'];
                                }

                                $params = [':brand_id' => $response_api['response'][0]['brand_id'], ':code' => $currency];

                                $response_currency = CrudService::select($db_prefix . 'currency', 'WHERE brand_id = :brand_id AND code = :code', '* FROM', $params);
                                if ($response_currency['status'] == true) {
                                    $params = [':brand_id' => $response_api['response'][0]['brand_id'], ':email' => $email, ':status' => 'suspend'];

                                    $check_customer = CrudService::select($db_prefix . 'customer', 'WHERE brand_id = :brand_id AND email = :email AND status = :status', '* FROM', $params);
                                    if ($check_customer['status'] == true) {
                                        http_response_code(400);
                                        echo json_encode([
                                            'error' => [
                                                'code' => 'INVALID_CUSTOMER',
                                                'message' => empty($check_customer['response'][0]['suspend_reason']) ? 'Customer is already suspended by the admin.' : 'Customer is already suspended by the admin. Reason: ' . InputSanitizer::html($check_customer['response'][0]['suspend_reason'])
                                            ]
                                        ]);
                                        exit;
                                    }


                                    $payment_id = generateItemID(27, 27);

                                    $customerInfoJson = json_encode([
                                        'name' => $fullName,
                                        'email' => $email,
                                        'mobile' => $mobile
                                    ], JSON_UNESCAPED_UNICODE);

                                    $columns = ['brand_id', 'ref', 'customer_info', 'amount', 'currency', 'metadata', 'webhook_url', 'created_date', 'updated_date'];
                                    $values = [$response_api['response'][0]['brand_id'], $payment_id, $customerInfoJson, money_sanitize($amount), $currency, json_encode($metadata), $webhookUrl, getCurrentDatetime('Y-m-d H:i:s'), getCurrentDatetime('Y-m-d H:i:s')];

                                    CrudService::insert($db_prefix . 'transaction', $columns, $values);

                                    $params = [':brand_id' => $response_api['response'][0]['brand_id'], ':email' => $email];

                                    $response_customer = CrudService::select($db_prefix . 'customer', 'WHERE brand_id = :brand_id AND email = :email', '* FROM', $params);
                                    if ($response_customer['status'] == false) {
                                        $ref = generateItemID();

                                        $columns = ['ref', 'brand_id', 'name', 'email', 'mobile', 'created_date', 'updated_date'];
                                        $values = [$ref, $response_api['response'][0]['brand_id'], $fullName, $email, $mobile, getCurrentDatetime('Y-m-d H:i:s'), getCurrentDatetime('Y-m-d H:i:s')];

                                        CrudService::insert($db_prefix . 'customer', $columns, $values);
                                    }

                                    $checkoutResponse = json_encode(['op_id' => $payment_id, 'op_url' => $site_url . $path_payment . '/' . $payment_id]);

                                    // Cache the response for idempotency replay
                                    if ($idemRowId !== null && isset($idemBridge)) {
                                        $idemBridge->complete($idemRowId, $checkoutResponse, 200);
                                    }

                                    echo $checkoutResponse;
                                } else {
                                    http_response_code(400);
                                    echo json_encode([
                                        'error' => [
                                            'code' => 'INVALID_CURRENCY',
                                            'message' => 'Currency not supported.'
                                        ]
                                    ]);
                                    exit;
                                }
                            } else {
                                http_response_code(400);
                                echo json_encode([
                                    'error' => [
                                        'code' => 'INVALID_JSON_PAYLOAD',
                                        'message' => 'The JSON payload is invalid or malformed.'
                                    ]
                                ]);
                            }
                        }
                    } else {
                        if ($api_type == "verify-payment") {
                            $api_scopes = $response_api['response'][0]['api_scopes'] ?? [];
                            if (is_string($api_scopes)) {
                                $api_scopes = json_decode($api_scopes, true);
                            }

                            if (!in_array("verify_payment", $api_scopes)) {
                                $requiredScope = 'Verify Payment';

                                http_response_code(400);
                                echo json_encode([
                                    'error' => [
                                        'code' => 'INSUFFICIENT_SCOPE',
                                        'message' => "The API key does not have the required permission: {$requiredScope}"
                                    ]
                                ]);
                                exit;
                            }

                            $op_id = $data['op_id'] ?? '';

                            if ($op_id == "") {
                                http_response_code(400);
                                echo json_encode([
                                    'error' => [
                                        'code' => 'INVALID_PP_ID',
                                        'message' => 'A valid bp id is required.'
                                    ]
                                ]);
                                exit;
                            } else {
                                $params = [':ref' => $op_id];

                                $response_transaction = CrudService::select($db_prefix . 'transaction', 'WHERE ref = :ref', '* FROM', $params);
                                if ($response_transaction['status'] == true) {
                                    $metadata = json_decode($response_transaction['response'][0]['metadata'], true) ?: [];

                                    $response_gateway = CrudService::select($db_prefix . 'gateways', 'WHERE brand_id = :brand_id AND gateway_id = :gateway_id', '* FROM', [':brand_id' => $response_transaction['response'][0]['brand_id'], ':gateway_id' => $response_transaction['response'][0]['gateway_id']]);

                                    $gateway = $response_gateway['response'][0]['display'] ?? '';

                                    $customer_info = json_decode($response_transaction['response'][0]['customer_info'], true) ?: [];

                                    $params = [':brand_id' => $response_transaction['response'][0]['brand_id']];

                                    $response_brand = CrudService::select($db_prefix . 'brands', 'WHERE brand_id = :brand_id', '* FROM', $params);

                                    $net = money_sub(money_add($response_transaction['response'][0]['amount'], $response_transaction['response'][0]['processing_fee']), $response_transaction['response'][0]['discount_amount']);

                                    $transactions = [
                                        "op_id" => $response_transaction['response'][0]['ref'],
                                        "full_name" => $customer_info['name'] ?? 'N/A',
                                        "email_address" => $customer_info['email'] ?? 'N/A',
                                        "mobile_number" => $customer_info['mobile'] ?? 'N/A',
                                        "gateway" => $gateway,
                                        "amount" => money_round($response_transaction['response'][0]['amount']),
                                        "fee" => money_round($response_transaction['response'][0]['processing_fee']),
                                        "discount_amount" => money_round($response_transaction['response'][0]['discount_amount']),
                                        "total" => money_round($net),
                                        "local_net_amount" => money_round($response_transaction['response'][0]['local_net_amount']),
                                        "currency" => $response_transaction['response'][0]['currency'],
                                        "local_currency" => $response_transaction['response'][0]['local_currency'],
                                        "metadata" => $metadata, // ← AS-IS
                                        "sender" => $response_transaction['response'][0]['sender'],
                                        "transaction_id" => $response_transaction['response'][0]['trx_id'],
                                        "status" => $response_transaction['response'][0]['status'],
                                        "date" => convertUTCtoUserTZ($response_transaction['response'][0]['created_date'], empty($response_brand['response'][0]['timezone']) ? 'Asia/Dhaka' : $response_brand['response'][0]['timezone'], "M d, Y h:i A")
                                    ];

                                    echo json_encode($transactions);
                                } else {
                                    http_response_code(400);
                                    echo json_encode([
                                        'error' => [
                                            'code' => 'INVALID_PP_ID',
                                            'message' => 'A valid bp id is required.'
                                        ]
                                    ]);
                                    exit;
                                }
                            }
                        } else {
                            if ($api_type == "refund-payment") {
                                $api_scopes = $response_api['response'][0]['api_scopes'] ?? [];
                                if (is_string($api_scopes)) {
                                    $api_scopes = json_decode($api_scopes, true);
                                }

                                if (!in_array("refund_payment", $api_scopes)) {
                                    $requiredScope = 'Refund Payment';

                                    http_response_code(400);
                                    echo json_encode([
                                        'error' => [
                                            'code' => 'INSUFFICIENT_SCOPE',
                                            'message' => "The API key does not have the required permission: {$requiredScope}"
                                        ]
                                    ]);
                                    exit;
                                }

                                $op_id = $data['op_id'] ?? '';

                                if ($op_id == "") {
                                    http_response_code(400);
                                    echo json_encode([
                                        'error' => [
                                            'code' => 'INVALID_PP_ID',
                                            'message' => 'A valid bp id is required.'
                                        ]
                                    ]);
                                    exit;
                                } else {
                                    $params = [':ref' => $op_id];

                                    $response_transaction = CrudService::select($db_prefix . 'transaction', 'WHERE ref = :ref', '* FROM', $params);
                                    if ($response_transaction['status'] == true) {
                                        $columns = ['status', 'updated_date'];
                                        $values = ['refunded', getCurrentDatetime('Y-m-d H:i:s')];
                                        $condition = 'id = :id';
                                        $whereParams = [':id' => $response_transaction['response'][0]['id']];

                                        CrudService::update($db_prefix . 'transaction', $columns, $values, $condition, $whereParams);


                                        $metadata = json_decode($response_transaction['response'][0]['metadata'], true) ?: [];

                                        $response_gateway = CrudService::select($db_prefix . 'gateways', 'WHERE brand_id = :brand_id AND gateway_id = :gateway_id', '* FROM', [':brand_id' => $response_transaction['response'][0]['brand_id'], ':gateway_id' => $response_transaction['response'][0]['gateway_id']]);

                                        $gateway = $response_gateway['response'][0]['display'] ?? '';

                                        $customer_info = json_decode($response_transaction['response'][0]['customer_info'], true) ?: [];

                                        $params = [':brand_id' => $response_transaction['response'][0]['brand_id']];

                                        $response_brand = CrudService::select($db_prefix . 'brands', 'WHERE brand_id = :brand_id', '* FROM', $params);

                                        $net = money_sub(money_add($response_transaction['response'][0]['amount'], $response_transaction['response'][0]['processing_fee']), $response_transaction['response'][0]['discount_amount']);

                                        $transactions = [
                                            "op_id" => $response_transaction['response'][0]['ref'],
                                            "full_name" => $customer_info['name'] ?? 'N/A',
                                            "email_address" => $customer_info['email'] ?? 'N/A',
                                            "mobile_number" => $customer_info['mobile'] ?? 'N/A',
                                            "gateway" => $gateway,
                                            "amount" => money_round($response_transaction['response'][0]['amount']),
                                            "fee" => money_round($response_transaction['response'][0]['processing_fee']),
                                            "discount_amount" => money_round($response_transaction['response'][0]['discount_amount']),
                                            "total" => money_round($net),
                                            "local_net_amount" => money_round($response_transaction['response'][0]['local_net_amount']),
                                            "currency" => $response_transaction['response'][0]['currency'],
                                            "local_currency" => $response_transaction['response'][0]['local_currency'],
                                            "metadata" => $metadata, // ← AS-IS
                                            "sender" => $response_transaction['response'][0]['sender'],
                                            "transaction_id" => $response_transaction['response'][0]['trx_id'],
                                            "status" => 'refunded',
                                            "date" => convertUTCtoUserTZ($response_transaction['response'][0]['created_date'], empty($response_brand['response'][0]['timezone']) ? 'Asia/Dhaka' : $response_brand['response'][0]['timezone'], "M d, Y h:i A")
                                        ];

                                        echo json_encode($transactions);
                                    } else {
                                        http_response_code(400);
                                        echo json_encode([
                                            'error' => [
                                                'code' => 'INVALID_PP_ID',
                                                'message' => 'A valid bp id is required.'
                                            ]
                                        ]);
                                        exit;
                                    }
                                }
                            } else {
                                http_response_code(400);
                                echo json_encode([
                                    'error' => [
                                        'code' => 'INVALID_JSON_PAYLOAD',
                                        'message' => 'The JSON payload is invalid or malformed.'
                                    ]
                                ]);
                            }
                        }
                    }

    }
}
