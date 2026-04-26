<?php
declare(strict_types=1);

namespace OwnPay\Controller;

use OwnPay\Http\RequestContext;
use OwnPay\Service\CrudService;
use OwnPay\Service\InputSanitizer;
use OwnPay\Service\PermissionGuard;

class TransactionController
{

    public static function handle(string $action, ?RequestContext $ctx = null): void
    {
        $ctx ??= $GLOBALS['requestContext'] ?? throw new \RuntimeException('RequestContext not available');

        $controller = new self();

        switch ($action) {
            case 'transaction-list':
                $controller->list($ctx);
                break;
            case 'transaction-bulk-action':
                $controller->bulkAction($ctx);
                break;
            case 'transaction-delete':
                $controller->delete($ctx);
                break;
            case 'transaction-ipn':
                $controller->ipn($ctx);
                break;
            case 'transaction-verify':
                $controller->verify($ctx);
                break;
        }
    }

    private function list(RequestContext $ctx): void
    {
        $db_prefix = $ctx->dbPrefix;
        $global_user_login = $ctx->isLoggedIn;
        $global_response_brand = $ctx->brandResponse;
        $new_csrf_token = $ctx->csrfToken;

        if ($global_user_login == true) {
            if (!PermissionGuard::canAccess($ctx, 'transaction')) {
                echo json_encode(['status' => 'false', 'title' => 'Access denied', 'message' => 'You need permission to perform this action. Please contact the admin.', 'csrf_token' => $new_csrf_token]);
                exit();
            }

            $request = \OwnPay\Http\Request::createFromGlobals();

            $search_input = $request->post('search_input', '');
            $show_limit = $request->post('show_limit', 5);

            $tabType = $request->post('tabType', '');

            /* Filters */
            $filter_status = $request->post('filter_status', '');
            $filter_start = $request->post('filter_start', '');
            $filter_end = $request->post('filter_end', '');

            $where = [];
            $params_trx = [':brand_id' => $global_response_brand['response'][0]['brand_id'], ':initiated' => 'initiated'];

            if ($tabType !== "all") {
                $where[] = "status = :tab_type";
                $params_trx[':tab_type'] = $tabType;
            }

            if ($filter_start !== '') {
                $where[] = "created_date >= :filter_start";
                $params_trx[':filter_start'] = "{$filter_start} 00:00:00";
            }

            if ($filter_end !== '') {
                $where[] = "created_date <= :filter_end";
                $params_trx[':filter_end'] = "{$filter_end} 23:59:59";
            }

            if ($filter_status !== '') {
                $where[] = "status = :filter_status";
                $params_trx[':filter_status'] = $filter_status;
            }

            $where_sql = $where ? implode(' AND ', $where) . ' AND ' : '';
            /* Filters */

            $pag = \OwnPay\Service\PaginationService::resolve($request->post('page', 1), $request->post('show_limit'));
            $page = $pag['page'];
            $show_limit_val = $pag['perPage'];
            $offset = $pag['offset'];

            $sql_query = '';

            if ($search_input !== '') {
                $sql_query .= " AND ( customer_info LIKE :search OR trx_id LIKE :search OR gateway_slug LIKE :search OR sender LIKE :search )";
                $params_trx[':search'] = "%$search_input%";
            }

            $sql_limit = '';
            if ($pag['isAll']) {

            } else {
                $sql_limit = " LIMIT $offset, $show_limit_val";
            }

            $response_result = CrudService::select($db_prefix . 'transaction', ' WHERE ' . $where_sql . ' brand_id = :brand_id AND status NOT IN (:initiated) ' . $sql_query . ' ORDER BY 1 DESC ' . $sql_limit, '* FROM', $params_trx);
            if ($response_result['status'] == true) {
                $response = [];

                foreach ($response_result['response'] as $row) {
                    $customer_info = json_decode($row['customer_info'], true);

                    $params_curr = [':brand_id' => $global_response_brand['response'][0]['brand_id'], ':code' => $row['currency']];
                    $response_currency = CrudService::select($db_prefix . 'currency', ' WHERE brand_id = :brand_id AND code = :code', '* FROM', $params_curr);

                    $currency = $response_currency['response'][0]['symbol'] ?? '';

                    $params_gw = [':brand_id' => $global_response_brand['response'][0]['brand_id'], ':gateway_id' => $row['gateway_id']];
                    $response_gateway = CrudService::select($db_prefix . 'gateways', ' WHERE brand_id = :brand_id AND gateway_id = :gateway_id', '* FROM', $params_gw);

                    $gateway = $response_gateway['response'][0]['name'] ?? '';

                    $amount = money_sanitize($row['amount']);
                    $processing_fee = money_sanitize($row['processing_fee']);
                    $discount = money_sanitize($row['discount_amount']);

                    $net = money_sub(money_add($amount, $processing_fee), $discount);

                    $response[] = [
                        "id" => $row['ref'],
                        "c_id" => $customer_info['id'] ?? 'N/A',
                        "name" => $customer_info['name'] ?? 'Unknown',
                        "email" => $customer_info['email'] ?? '',
                        "mobile" => $customer_info['mobile'] ?? '',
                        "status" => $row['status'],
                        "gateway" => $gateway,
                        "trx_id" => !empty($row['trx_id']) ? $row['trx_id'] : '',
                        "net_amount" => $currency . money_round($net, 2),
                        "amount" => $currency . money_round($amount, 2),
                        "created_date" => convertUTCtoUserTZ($row['created_date'], !empty($global_response_brand['response'][0]['timezone']) ? $global_response_brand['response'][0]['timezone'] : 'Asia/Dhaka', "M d, Y h:i A"),
                        "updated_date" => convertUTCtoUserTZ($row['updated_date'], !empty($global_response_brand['response'][0]['timezone']) ? $global_response_brand['response'][0]['timezone'] : 'Asia/Dhaka', "M d, Y h:i A")
                    ];
                }

                $count_data = CrudService::select($db_prefix . 'transaction', ' WHERE ' . $where_sql . ' brand_id = :brand_id AND status NOT IN (:initiated) ' . $sql_query, '* FROM', $params_trx);

                $total_records = count($count_data['response'] ?? []);
                $pagHtml = \OwnPay\Service\PaginationService::render($page, $total_records, $show_limit_val, $offset);
                $pagination = $pagHtml['pagination'];
                $datatableInfo = $pagHtml['datatableInfo'];

                echo json_encode(['status' => "true", 'response' => $response, 'datatableInfo' => $datatableInfo, 'pagination' => $pagination, 'csrf_token' => $new_csrf_token]);
            } else {
                echo json_encode(['status' => "false", 'title' => 'Nothing Here Yet', 'message' => 'No data is available at the moment.', 'csrf_token' => $new_csrf_token]);
                exit();
            }
        } else {
            echo json_encode(['status' => 'false', 'title' => 'Request Failed', 'message' => 'Invalid request', 'csrf_token' => $new_csrf_token]);
        }
    }

    private function bulkAction(RequestContext $ctx): void
    {
        $db_prefix = $ctx->dbPrefix;
        $global_user_login = $ctx->isLoggedIn;
        $global_response_brand = $ctx->brandResponse;
        $new_csrf_token = $ctx->csrfToken;

        if ($global_user_login == true) {
            if (!PermissionGuard::canAccess($ctx, 'transaction')) {
                echo json_encode(['status' => 'false', 'title' => 'Access denied', 'message' => 'You need permission to perform this action. Please contact the admin.', 'csrf_token' => $new_csrf_token]);
                exit();
            }

            $request = \OwnPay\Http\Request::createFromGlobals();

            $actionID = $request->post('actionID', '');
            $selected_ids_json = $request->post('selected_ids', '[]');
            $selected_ids = json_decode($selected_ids_json, true);
            $actionsID = $actionID;

            if (!empty($selected_ids)) {
                $all_transactions = [];

                $jobs = [];

                foreach ($selected_ids as $id) {
                    $itemID = InputSanitizer::trim($id);
                    $params_item = [':ref' => $itemID, ':brand_id' => $global_response_brand['response'][0]['brand_id']];

                    $response_brand = CrudService::select($db_prefix . 'transaction', 'WHERE ref = :ref AND brand_id = :brand_id', '* FROM', $params_item);
                    if ($response_brand['status'] == true) {
                        if ($actionID == "deleted") {
                            if (PermissionGuard::has($ctx, 'transaction', 'delete')) {
                                $condition = "ref = :ref";
                                $whereParams = [':ref' => $itemID];

                                CrudService::delete($db_prefix . 'transaction', $condition, $whereParams);
                            }
                        }

                        if ($actionID == "approved") {
                            if (PermissionGuard::has($ctx, 'transaction', 'approve')) {
                                $currentStatus = $response_brand['response'][0]['status'];
                                if (class_exists('\OwnPay\Service\StatusGuard') && !\OwnPay\Service\StatusGuard::canTransition($currentStatus, 'completed')) {
                                    // Skip: invalid transition (e.g., refunded → completed)
                                } else {
                                    $columns = ['status', 'updated_date'];
                                    $values = ['completed', getCurrentDatetime('Y-m-d H:i:s')];

                                    $condition = "ref = :ref";
                                    $whereParams = [':ref' => $itemID];
                                    $txnVersion = (int) ($response_brand['response'][0]['version'] ?? 1);

                                    $affected = CrudService::optimisticUpdate($db_prefix . 'transaction', $columns, $values, $condition, $whereParams, $txnVersion);

                                    // Phase 2.0 — Task 2.4: Post ledger entry for completed payment
                                    try {
                                        if (class_exists('\OwnPay\Service\LedgerService')) {
                                            $ledger = new \OwnPay\Service\LedgerService();
                                            $ledger->postPaymentCompleted(
                                                (int) $response_brand['response'][0]['brand_id'],
                                                $response_brand['response'][0]['ref'],
                                                money_sanitize($response_brand['response'][0]['amount']),
                                                $response_brand['response'][0]['currency']
                                            );
                                        }
                                    } catch (\Throwable $e) {
                                        error_log('[OwnPay] Ledger entry failed for txn ' . $itemID . ': ' . $e->getMessage());
                                    }
                                }
                            }
                        }

                        if ($actionID == "refunded") {
                            if (PermissionGuard::has($ctx, 'transaction', 'refund')) {
                                $currentStatus = $response_brand['response'][0]['status'];
                                if (class_exists('\OwnPay\Service\StatusGuard') && !\OwnPay\Service\StatusGuard::canTransition($currentStatus, 'refunded')) {
                                    // Skip: invalid transition (e.g., initiated → refunded)
                                } else {
                                    $columns = ['status', 'updated_date'];
                                    $values = ['refunded', getCurrentDatetime('Y-m-d H:i:s')];

                                    $condition = "ref = :ref";
                                    $whereParams = [':ref' => $itemID];
                                    $txnVersion = (int) ($response_brand['response'][0]['version'] ?? 1);

                                    CrudService::optimisticUpdate($db_prefix . 'transaction', $columns, $values, $condition, $whereParams, $txnVersion);
                                }
                            }
                        }

                        if ($actionID == "canceled") {
                            if (PermissionGuard::has($ctx, 'transaction', 'cancel')) {
                                $currentStatus = $response_brand['response'][0]['status'];
                                if (class_exists('\OwnPay\Service\StatusGuard') && !\OwnPay\Service\StatusGuard::canTransition($currentStatus, 'canceled')) {
                                    // Skip: invalid transition (e.g., refunded → canceled)
                                } else {
                                    $columns = ['status', 'updated_date'];
                                    $values = ['canceled', getCurrentDatetime('Y-m-d H:i:s')];

                                    $condition = "ref = :ref";
                                    $whereParams = [':ref' => $itemID];
                                    $txnVersion = (int) ($response_brand['response'][0]['version'] ?? 1);

                                    CrudService::optimisticUpdate($db_prefix . 'transaction', $columns, $values, $condition, $whereParams, $txnVersion);
                                }
                            }
                        }

                        if ($actionID == "ipnsend") {
                            if (PermissionGuard::has($ctx, 'transaction', 'send_ipn')) {
                                $actionsID = 'IPN Triggered';

                                if (!empty($response_brand['response'][0]['webhook_url'])) {
                                    $metadata = json_decode($response_brand['response'][0]['metadata'], true) ?: [];

                                    $params_gw = [':brand_id' => $global_response_brand['response'][0]['brand_id'], ':gateway_id' => $response_brand['response'][0]['gateway_id']];
                                    $response_gateway = CrudService::select($db_prefix . 'gateways', ' WHERE brand_id = :brand_id AND gateway_id = :gateway_id', '* FROM', $params_gw);

                                    $gateway = $response_gateway['response'][0]['name'] ?? '';

                                    $customer_info = json_decode($response_brand['response'][0]['customer_info'], true) ?: [];

                                    $net = money_sub(money_add($response_brand['response'][0]['amount'], $response_brand['response'][0]['processing_fee']), $response_brand['response'][0]['discount_amount']);

                                    $ipnData = [
                                        "op_id" => $response_brand['response'][0]['ref'],
                                        "full_name" => $customer_info['name'] ?? 'N/A',
                                        "email_address" => $customer_info['email'] ?? 'N/A',
                                        "mobile_number" => $customer_info['mobile'] ?? 'N/A',
                                        "gateway" => $gateway,
                                        "amount" => money_round($response_brand['response'][0]['amount']),
                                        "fee" => money_round($response_brand['response'][0]['processing_fee']),
                                        "discount_amount" => money_round($response_brand['response'][0]['discount_amount']),
                                        "total" => money_round($net),
                                        "local_net_amount" => money_round($response_brand['response'][0]['local_net_amount']),
                                        "currency" => $response_brand['response'][0]['currency'],
                                        "local_currency" => $response_brand['response'][0]['local_currency'],
                                        "metadata" => $metadata, // ← AS-IS
                                        "sender" => $response_brand['response'][0]['sender'],
                                        "transaction_id" => $response_brand['response'][0]['trx_id'],
                                        "status" => $response_brand['response'][0]['status'],
                                        "date" => convertUTCtoUserTZ($response_brand['response'][0]['created_date'], !empty($global_response_brand['response'][0]['timezone']) ? $global_response_brand['response'][0]['timezone'] : 'Asia/Dhaka', "M d, Y h:i A")
                                    ];

                                    $payload = json_encode($ipnData, JSON_UNESCAPED_UNICODE);

                                    $jobs[] = [
                                        'id' => random_int(100000000, PHP_INT_MAX),
                                        'url' => $response_brand['response'][0]['webhook_url'],
                                        'payload' => json_decode($payload, true),
                                    ];
                                }
                            }
                        }

                        if ($actionID == "refunded" || $actionID == "canceled" || $actionID == "approved") {
                            $metadata = json_decode($response_brand['response'][0]['metadata'], true) ?: [];

                            $params_gw = [':brand_id' => $global_response_brand['response'][0]['brand_id'], ':gateway_id' => $response_brand['response'][0]['gateway_id']];
                            $response_gateway = CrudService::select($db_prefix . 'gateways', ' WHERE brand_id = :brand_id AND gateway_id = :gateway_id', '* FROM', $params_gw);

                            $gateway = $response_gateway['response'][0]['name'] ?? '';

                            $customer_info = json_decode($response_brand['response'][0]['customer_info'], true) ?: [];

                            $net = money_sub(money_add($response_brand['response'][0]['amount'], $response_brand['response'][0]['processing_fee']), $response_brand['response'][0]['discount_amount']);

                            $all_transactions[] = [
                                "op_id" => $response_brand['response'][0]['ref'],
                                "full_name" => $customer_info['name'] ?? 'N/A',
                                "email_address" => $customer_info['email'] ?? 'N/A',
                                "mobile_number" => $customer_info['mobile'] ?? 'N/A',
                                "gateway" => $gateway,
                                "amount" => money_round($response_brand['response'][0]['amount']),
                                "fee" => money_round($response_brand['response'][0]['processing_fee']),
                                "discount_amount" => money_round($response_brand['response'][0]['discount_amount']),
                                "total" => money_round($net),
                                "local_net_amount" => money_round($response_brand['response'][0]['local_net_amount']),
                                "currency" => $response_brand['response'][0]['currency'],
                                "local_currency" => $response_brand['response'][0]['local_currency'],
                                "metadata" => $metadata, // ← AS-IS
                                "sender" => $response_brand['response'][0]['sender'],
                                "transaction_id" => $response_brand['response'][0]['trx_id'],
                                "status" => $response_brand['response'][0]['status'],
                                "date" => convertUTCtoUserTZ($response_brand['response'][0]['created_date'], !empty($global_response_brand['response'][0]['timezone']) ? $global_response_brand['response'][0]['timezone'] : 'Asia/Dhaka', "M d, Y h:i A")
                            ];
                        }
                    }
                }

                $results = sendIPNMulti($jobs);

                foreach ($jobs as $job) {
                    $code = $results[$job['id']] ?? 0;
                    $status = ($code === 200) ? 'completed' : 'pending';

                    if ($status == 'completed') {

                    } else {
                        $columns = ['ref', 'brand_id', 'payload', 'url', 'created_date', 'updated_date'];
                        $values = [random_int(100000000, PHP_INT_MAX), $response_brand['response'][0]['brand_id'], json_encode($job['payload']), $job['url'], getCurrentDatetime('Y-m-d H:i:s'), getCurrentDatetime('Y-m-d H:i:s')];

                        CrudService::insert($db_prefix . 'webhook_log', $columns, $values);
                    }
                }

                if (!empty($all_transactions)) {
                    do_action('transactions.updated', $all_transactions);
                }

                echo json_encode(['status' => 'true', 'title' => 'Transactions ' . $actionsID, 'message' => 'The selected transactions have been ' . $actionsID . ' successfully.', 'csrf_token' => $new_csrf_token]);
            } else {
                echo json_encode(['status' => 'false', 'title' => 'Transactions Failed', 'message' => 'No transactions selected.', 'csrf_token' => $new_csrf_token]);
            }
        } else {
            echo json_encode(['status' => 'false', 'title' => 'Request Failed', 'message' => 'Invalid request', 'csrf_token' => $new_csrf_token]);
        }
    }

    private function delete(RequestContext $ctx): void
    {
        $db_prefix = $ctx->dbPrefix;
        $global_user_login = $ctx->isLoggedIn;
        $global_response_brand = $ctx->brandResponse;
        $new_csrf_token = $ctx->csrfToken;
        if ($global_user_login == true) {
            if (!PermissionGuard::canAccess($ctx, 'transaction') || !PermissionGuard::has($ctx, 'transaction', 'delete')) {
                echo json_encode(['status' => 'false', 'title' => 'Access denied', 'message' => 'You need permission to perform this action. Please contact the admin.', 'csrf_token' => $new_csrf_token]);
                exit();
            }

            $request = \OwnPay\Http\Request::createFromGlobals();

            $ItemID = $request->post('ItemID', '');
            $params_item = [':ref' => $ItemID, ':brand_id' => $global_response_brand['response'][0]['brand_id']];

            $response_brand = CrudService::select($db_prefix . 'transaction', 'WHERE ref = :ref AND brand_id = :brand_id', '* FROM', $params_item);
            if ($response_brand['status'] == true) {
                $condition = "ref = :ref";
                $whereParams = [':ref' => $ItemID];

                CrudService::delete($db_prefix . 'transaction', $condition, $whereParams);
            }

            echo json_encode(['status' => 'true', 'title' => 'Transaction Deleted', 'message' => 'The selected Transaction have been deleted successfully.', 'csrf_token' => $new_csrf_token]);
        } else {
            echo json_encode(['status' => 'false', 'title' => 'Request Failed', 'message' => 'Invalid request', 'csrf_token' => $new_csrf_token]);
        }
    }

    private function ipn(RequestContext $ctx): void
    {
        $db_prefix = $ctx->dbPrefix;
        $global_user_login = $ctx->isLoggedIn;
        $global_response_brand = $ctx->brandResponse;
        $new_csrf_token = $ctx->csrfToken;
        if ($global_user_login == true) {
            if (!PermissionGuard::canAccess($ctx, 'transaction') || !PermissionGuard::has($ctx, 'transaction', 'send_ipn')) {
                echo json_encode(['status' => 'false', 'title' => 'Access denied', 'message' => 'You need permission to perform this action. Please contact the admin.', 'csrf_token' => $new_csrf_token]);
                exit();
            }

            $request = \OwnPay\Http\Request::createFromGlobals();

            $ItemID = $request->post('ItemID', '');
            $params_item = [':ref' => $ItemID, ':brand_id' => $global_response_brand['response'][0]['brand_id']];

            $response_brand = CrudService::select($db_prefix . 'transaction', 'WHERE ref = :ref AND brand_id = :brand_id', '* FROM', $params_item);
            if ($response_brand['status'] == true) {
                $metadata = json_decode($response_brand['response'][0]['metadata'], true) ?: [];

                $params_gw = [':brand_id' => $global_response_brand['response'][0]['brand_id'], ':gateway_id' => $response_brand['response'][0]['gateway_id']];
                $response_gateway = CrudService::select($db_prefix . 'gateways', ' WHERE brand_id = :brand_id AND gateway_id = :gateway_id', '* FROM', $params_gw);

                $gateway = $response_gateway['response'][0]['name'] ?? '';

                $customer_info = json_decode($response_brand['response'][0]['customer_info'], true) ?: [];

                $net = money_sub(money_add($response_brand['response'][0]['amount'], $response_brand['response'][0]['processing_fee']), $response_brand['response'][0]['discount_amount']);

                $ipnData = [
                    "op_id" => $response_brand['response'][0]['ref'],
                    "full_name" => $response_brand['response'][0]['name'] ?? 'N/A',
                    "email_address" => $response_brand['response'][0]['email'] ?? 'N/A',
                    "mobile_number" => $response_brand['response'][0]['mobile'] ?? 'N/A',
                    "gateway" => $gateway,
                    "amount" => money_round($response_brand['response'][0]['amount']),
                    "fee" => money_round($response_brand['response'][0]['processing_fee']),
                    "discount_amount" => money_round($response_brand['response'][0]['discount_amount']),
                    "total" => money_round($net),
                    "local_net_amount" => money_round($response_brand['response'][0]['local_net_amount']),
                    "currency" => $response_brand['response'][0]['currency'],
                    "metadata" => $metadata, // ← AS-IS
                    "sender" => $response_brand['response'][0]['sender'],
                    "transaction_id" => $response_brand['response'][0]['trx_id'],
                    "status" => $response_brand['response'][0]['status'],
                    "date" => convertUTCtoUserTZ($response_brand['response'][0]['created_date'], !empty($global_response_brand['response'][0]['timezone']) ? $global_response_brand['response'][0]['timezone'] : 'Asia/Dhaka', "M d, Y h:i A")
                ];

                if (!empty($response_brand['response'][0]['webhook_url'])) {
                    sendIPN($response_brand['response'][0]['webhook_url'], $ipnData);
                }
            }

            echo json_encode(['status' => 'true', 'title' => 'Transaction IPN Triggered', 'message' => 'The IPN for the transaction has been sent successfully.', 'csrf_token' => $new_csrf_token]);
        } else {
            echo json_encode(['status' => 'false', 'title' => 'Request Failed', 'message' => 'Invalid request', 'csrf_token' => $new_csrf_token]);
        }
    }

    private function verify(RequestContext $ctx): void
    {
        $db_prefix = $ctx->dbPrefix;
        $site_url = $ctx->siteUrl;

        $request = \OwnPay\Http\Request::createFromGlobals();

        $gateway_id = $request->post('gateway-id', '');
        $transaction_id = trim((string) $request->post('transaction-id', ''));

        if ($gateway_id == "" || $transaction_id == "") {
            echo json_encode(['status' => "false", 'title' => 'Incomplete Information', 'message' => 'Please fill in all required fields before proceeding.']);
        } else {
            $params = [':ref' => $transaction_id, ':status' => 'initiated'];

            $response_transaction = CrudService::select($db_prefix . 'transaction', 'WHERE ref = :ref AND status = :status ', '* FROM', $params);
            if ($response_transaction['status'] == true) {
                $params = [':brand_id' => $response_transaction['response'][0]['brand_id']];

                $response_brand = CrudService::select($db_prefix . 'brands', 'WHERE brand_id = :brand_id', '* FROM', $params);
                if ($response_brand['status'] == true) {
                    $params = [':gateway_id' => $gateway_id, ':brand_id' => $response_brand['response'][0]['brand_id'], ':status' => 'active'];

                    $response_gateway = CrudService::select($db_prefix . 'gateways', 'WHERE gateway_id = :gateway_id AND brand_id = :brand_id AND status = :status', '* FROM', $params);
                    if ($response_gateway['status'] == true) {
                        $options = [];

                        $params = [':gateway_id' => $gateway_id];
                        $response_gateways_parameter = CrudService::select($db_prefix . 'gateways_parameter', 'WHERE gateway_id = :gateway_id', '* FROM', $params);
                        foreach ($response_gateways_parameter['response'] as $field) {
                            $value = $field['value'];

                            if (!empty($field['multiple']) && !empty($value)) {
                                $value = is_array($value) ? $value : json_decode($value, true);
                            }

                            $options[$field['option_name']] = $value;
                        }

                        $currencyRates = [];

                        $params_curr = [':brand_id' => $response_gateway['response'][0]['brand_id']];
                        $currencyRes = CrudService::select($db_prefix . 'currency', ' WHERE brand_id = :brand_id', '* FROM', $params_curr);

                        if (!empty($currencyRes['response'])) {
                            foreach ($currencyRes['response'] as $c) {
                                $currencyRates[$c['code']] = money_sanitize($c['rate']);
                            }
                        }

                        $txnAmount = money_sanitize($response_transaction['response'][0]['amount']);
                        $txnCurrency = $response_transaction['response'][0]['currency'];

                        $gatewayCurrency = $response_gateway['response'][0]['currency'];

                        if ($txnCurrency === $gatewayCurrency) {
                            $convertedAmount = $txnAmount;
                        } else {
                            if (isset($currencyRates[$gatewayCurrency])) {
                                $convertedAmount = money_div($txnAmount, $currencyRates[$gatewayCurrency]);
                            } else {
                                $convertedAmount = "0";
                            }
                        }

                        $fixed_discount = money_sanitize($response_gateway['response'][0]['fixed_discount']);
                        $percentage_discount = money_sanitize($response_gateway['response'][0]['percentage_discount']);

                        $fixed_charge = money_sanitize($response_gateway['response'][0]['fixed_charge']);
                        $percentage_charge = money_sanitize($response_gateway['response'][0]['percentage_charge']);

                        $percentageDiscountAmount = money_div(money_mul($convertedAmount, $percentage_discount, 8), "100", 8);
                        $totalDiscount = money_add($fixed_discount, $percentageDiscountAmount, 8);

                        $percentageChargeAmount = money_div(money_mul($convertedAmount, $percentage_charge, 8), "100", 8);
                        $totalProcessingFee = money_add($fixed_charge, $percentageChargeAmount, 8);

                        $convertedAmount = money_add(money_sub($convertedAmount, $totalDiscount, 8), $totalProcessingFee, 8);

                        if ($txnCurrency !== $gatewayCurrency && isset($currencyRates[$gatewayCurrency])) {
                            $rate = $currencyRates[$gatewayCurrency];

                            $totalDiscount = money_mul($totalDiscount, $rate);
                            $totalProcessingFee = money_mul($totalProcessingFee, $rate);
                        }

                        if (file_exists(__DIR__ . '/../../modules/gateways/' . $response_gateway['response'][0]['slug'] . '/class.php')) {
                            require_once __DIR__ . '/../../modules/gateways/' . $response_gateway['response'][0]['slug'] . '/class.php';

                            $class = str_replace(' ', '', ucwords(str_replace('-', ' ', $response_gateway['response'][0]['slug']))) . 'Gateway';

                            $gateway = new $class();

                            $gateway_info = $gateway->info();
                            $supported_languages = $gateway->supported_languages();
                            $lang_text = $gateway->lang_text();
                        } else {
                            if ($response_gateway['response'][0]['tab'] == 'bank') {
                                $gateway = '';

                                $gateway_info = [
                                    'gateway_type' => 'manual',
                                    'verify_by' => 'slip',
                                ];
                            } else {
                                echo json_encode(['status' => "false", 'title' => 'Request Failed. Code 104', 'message' => 'Please fill in all required fields before proceeding.']);
                                exit();
                            }
                        }

                        if (isset($gateway_info)) {
                            $all_transactions = [];

                            if (isset($gateway_info['gateway_type']) && $gateway_info['gateway_type'] == "automation") {
                                $trxid = $request->post('trxid', '');

                                if ($trxid == "") {
                                    echo json_encode(['status' => "false", 'title' => 'Missing Transaction ID', 'message' => 'The Transaction ID field cannot be empty. Please provide a valid Transaction ID.']);
                                } else {
                                    $params = [':trx_id' => $trxid];

                                    $response_Checktransaction = CrudService::select($db_prefix . 'transaction', 'WHERE trx_id = :trx_id', '* FROM', $params);
                                    if ($response_Checktransaction['status'] == true) {
                                        echo json_encode(['status' => "false", 'title' => 'Duplicate Transaction ID', 'message' => 'This Transaction ID is already exits. Please provide a different one.']);
                                    } else {
                                        $params = [':sender_key' => $gateway_info['sender_key'], ':type' => $gateway_info['sender_type'], ':trx_id' => $trxid, ':status' => 'approved'];

                                        $response_pending_SMSTransaction = CrudService::select($db_prefix . 'sms_data', 'WHERE sender_key = :sender_key AND type = :type AND trx_id = :trx_id AND status = :status', '* FROM', $params);
                                        if ($response_pending_SMSTransaction['status'] == true) {

                                            $params_brand = [':brand_id' => $response_transaction['response'][0]['brand_id']];
                                            $response_brand = CrudService::select($db_prefix . 'brands', ' WHERE brand_id = :brand_id', '* FROM', $params_brand);
                                            if ($response_brand['status'] == true) {

                                                if (verifyPaymentTolerance($convertedAmount, $response_pending_SMSTransaction['response'][0]['amount'], $response_brand['response'][0]['payment_tolerance'])) {
                                                    $columns = ['status', 'updated_date'];
                                                    $values = ['used', getCurrentDatetime('Y-m-d H:i:s')];
                                                    $params_sms_upd = [':id' => $response_pending_SMSTransaction['response'][0]['id']];
                                                    CrudService::update($db_prefix . 'sms_data', $columns, $values, 'id = :id', $params_sms_upd);

                                                    $columns = ['processing_fee', 'discount_amount', 'local_net_amount', 'local_currency', 'gateway_id', 'sender_key', 'status', 'sender', 'trx_id', 'updated_date'];
                                                    $values = [money_sanitize($totalProcessingFee), money_sanitize($totalDiscount), money_sanitize($convertedAmount), $response_gateway['response'][0]['currency'], $gateway_id, $gateway_info['sender_key'], 'completed', $response_pending_SMSTransaction['response'][0]['number'], $trxid, getCurrentDatetime('Y-m-d H:i:s')];
                                                    $params_trx_upd = [':id' => $response_transaction['response'][0]['id']];
                                                    CrudService::update($db_prefix . 'transaction', $columns, $values, 'id = :id', $params_trx_upd);

                                                    // Phase 2.0 — Task 2.4: Post ledger entry for completed payment
                                                    try {
                                                        if (class_exists('\OwnPay\Service\LedgerService')) {
                                                            $ledger = new \OwnPay\Service\LedgerService();
                                                            $ledger->postPaymentCompleted(
                                                                (int) $response_brand['response'][0]['id'],
                                                                $response_transaction['response'][0]['ref'],
                                                                money_sanitize($response_transaction['response'][0]['amount']),
                                                                $response_transaction['response'][0]['currency']
                                                            );
                                                        }
                                                    } catch (\Throwable $e) {
                                                        error_log('[OwnPay] Ledger entry failed for txn ' . $transaction_id . ': ' . $e->getMessage());
                                                    }

                                                    $params = [':ref' => $transaction_id, ':status' => 'completed'];

                                                    $response_transaction = CrudService::select($db_prefix . 'transaction', 'WHERE ref = :ref AND status = :status ', '* FROM', $params);

                                                    $metadata = json_decode($response_transaction['response'][0]['metadata'], true) ?: [];

                                                    $params_brand_id = [':brand_id' => $response_brand['response'][0]['brand_id'], ':gateway_id' => $gateway_id];
                                                    $response_gateway = CrudService::select($db_prefix . 'gateways', ' WHERE brand_id = :brand_id AND gateway_id = :gateway_id', '* FROM', $params_brand_id);

                                                    $gateway = $response_gateway['response'][0]['name'] ?? '';

                                                    $customer_info = json_decode($response_transaction['response'][0]['customer_info'], true) ?: [];

                                                    $all_transactions[] = [
                                                        "op_id" => $response_transaction['response'][0]['ref'],
                                                        "full_name" => $customer_info['name'] ?? 'N/A',
                                                        "email_address" => $customer_info['email'] ?? 'N/A',
                                                        "mobile_number" => $customer_info['mobile'] ?? 'N/A',
                                                        "gateway" => $gateway,
                                                        "amount" => money_round($response_transaction['response'][0]['amount']),
                                                        "fee" => money_round($response_transaction['response'][0]['processing_fee']),
                                                        "discount_amount" => money_round($response_transaction['response'][0]['discount_amount']),
                                                        "total" => money_sub(money_add($response_transaction['response'][0]['amount'], $response_transaction['response'][0]['processing_fee']), $response_transaction['response'][0]['discount_amount']),
                                                        "local_net_amount" => money_round($response_transaction['response'][0]['local_net_amount']),
                                                        "currency" => $response_transaction['response'][0]['currency'],
                                                        "local_currency" => $response_transaction['response'][0]['local_currency'],
                                                        "metadata" => $metadata, // ← AS-IS
                                                        "sender" => $response_pending_SMSTransaction['response'][0]['number'],
                                                        "transaction_id" => $response_transaction['response'][0]['trx_id'],
                                                        "status" => $response_transaction['response'][0]['status'],
                                                        "date" => convertUTCtoUserTZ($response_transaction['response'][0]['created_date'], !empty($response_brand['response'][0]['timezone']) ? $response_brand['response'][0]['timezone'] : 'Asia/Dhaka', "M d, Y h:i A")
                                                    ];

                                                    if (!empty($response_transaction['response'][0]['webhook_url'])) {
                                                        $ipnData = [
                                                            "op_id" => $response_transaction['response'][0]['ref'],
                                                            "full_name" => $customer_info['name'] ?? 'N/A',
                                                            "email_address" => $customer_info['email'] ?? 'N/A',
                                                            "mobile_number" => $customer_info['mobile'] ?? 'N/A',
                                                            "gateway" => $gateway,
                                                            "amount" => money_round($response_transaction['response'][0]['amount']),
                                                            "fee" => money_round($response_transaction['response'][0]['processing_fee']),
                                                            "discount_amount" => money_round($response_transaction['response'][0]['discount_amount']),
                                                            "total" => money_sub(money_add($response_transaction['response'][0]['amount'], $response_transaction['response'][0]['processing_fee']), $response_transaction['response'][0]['discount_amount']),
                                                            "local_net_amount" => money_round($response_transaction['response'][0]['local_net_amount']),
                                                            "currency" => $response_transaction['response'][0]['currency'],
                                                            "local_currency" => $response_transaction['response'][0]['local_currency'],
                                                            "metadata" => $metadata, // ← AS-IS
                                                            "sender" => $response_pending_SMSTransaction['response'][0]['number'],
                                                            "transaction_id" => $response_transaction['response'][0]['trx_id'],
                                                            "status" => $response_transaction['response'][0]['status'],
                                                            "date" => convertUTCtoUserTZ($response_transaction['response'][0]['created_date'], !empty($response_brand['response'][0]['timezone']) ? $response_brand['response'][0]['timezone'] : 'Asia/Dhaka', "M d, Y h:i A")
                                                        ];

                                                        $payload = json_encode($ipnData, JSON_UNESCAPED_UNICODE);

                                                        $jobs = [
                                                            [
                                                                'id' => random_int(100000000, PHP_INT_MAX),
                                                                'url' => $response_transaction['response'][0]['webhook_url'],
                                                                'payload' => json_decode($payload, true),
                                                            ]
                                                        ];

                                                        $results = sendIPNMulti($jobs);

                                                        foreach ($jobs as $job) {
                                                            $code = $results[$job['id']] ?? 0;
                                                            $status = ($code === 200) ? 'completed' : 'pending';

                                                            if ($status == 'completed') {

                                                            } else {
                                                                $columns = ['ref', 'brand_id', 'payload', 'url', 'created_date', 'updated_date'];
                                                                $values = [random_int(100000000, PHP_INT_MAX), $response_brand['response'][0]['brand_id'], $payload, $response_transaction['response'][0]['webhook_url'], getCurrentDatetime('Y-m-d H:i:s'), getCurrentDatetime('Y-m-d H:i:s')];

                                                                CrudService::insert($db_prefix . 'webhook_log', $columns, $values);
                                                            }
                                                        }
                                                    }

                                                    echo json_encode(['status' => "true", 'title' => 'Transaction Verified', 'message' => 'The Transaction ID has been successfully verified.']);
                                                } else {
                                                    echo json_encode(['status' => "false", 'title' => 'Transaction Not Found', 'message' => 'The Transaction ID you entered could not be verified. Please check the ID and try again after some time.']);
                                                }
                                            }
                                        } else {
                                            if (isset($options['pending_payment']) && $options['pending_payment'] == "enable") {
                                                $mobile_number = $request->post('mobile_number', '');

                                                if ($mobile_number == "") {
                                                    echo json_encode(['status' => "false", 'title' => 'Transaction Not Matched', 'message' => 'The Transaction ID you entered could not be verified. Please try again after some time, or enter your phone number and submit for manual approval.', 'visible_number' => "true"]);
                                                } else {
                                                    $columns = ['processing_fee', 'discount_amount', 'local_net_amount', 'local_currency', 'gateway_id', 'sender_key', 'status', 'sender', 'trx_id', 'updated_date'];
                                                    $values = [money_sanitize($totalProcessingFee), money_sanitize($totalDiscount), money_sanitize($convertedAmount), $response_gateway['response'][0]['currency'], $gateway_id, $gateway_info['sender_key'], 'pending', $mobile_number, $trxid, getCurrentDatetime('Y-m-d H:i:s')];
                                                    $params_trx_upd = [':id' => $response_transaction['response'][0]['id']];
                                                    CrudService::update($db_prefix . 'transaction', $columns, $values, 'id = :id', $params_trx_upd);
                                                    echo json_encode(['status' => "true", 'title' => 'Transaction Submitted', 'message' => 'Your Transaction ID has been successfully submitted']);
                                                }
                                            } else {
                                                echo json_encode(['status' => "false", 'title' => 'Transaction Not Found', 'message' => 'The Transaction ID you entered could not be verified. Please check the ID and try again after some time.']);
                                            }
                                        }
                                    }
                                }
                            }
                            if (isset($gateway_info['gateway_type']) && $gateway_info['gateway_type'] == "manual") {
                                if (isset($gateway_info['verify_by']) && $gateway_info['verify_by'] == "trxid") {
                                    $trxid = $request->post('trxid', '');

                                    if ($trxid == "") {
                                        echo json_encode(['status' => "false", 'title' => 'Missing Transaction ID', 'message' => 'The Transaction ID field cannot be empty. Please provide a valid Transaction ID.']);
                                    } else {
                                        $params = [':trx_id' => $trxid];

                                        $response_Checktransaction = CrudService::select($db_prefix . 'transaction', 'WHERE trx_id = :trx_id', '* FROM', $params);
                                        if ($response_Checktransaction['status'] == true) {
                                            echo json_encode(['status' => "false", 'title' => 'Duplicate Transaction ID', 'message' => 'This Transaction ID is already exits. Please provide a different one.']);
                                        } else {
                                            $params_brand = [':brand_id' => $response_transaction['response'][0]['brand_id']];
                                            $response_brand = CrudService::select($db_prefix . 'brands', ' WHERE brand_id = :brand_id', '* FROM', $params_brand);
                                            if ($response_brand['status'] == true) {
                                                $columns = ['processing_fee', 'discount_amount', 'local_net_amount', 'local_currency', 'gateway_id', 'status', 'trx_id', 'updated_date'];
                                                $values = [money_sanitize($totalProcessingFee), money_sanitize($totalDiscount), money_sanitize($convertedAmount), $response_gateway['response'][0]['currency'], $gateway_id, 'pending', $trxid, getCurrentDatetime('Y-m-d H:i:s')];
                                                $params_trx_upd = [':id' => $response_transaction['response'][0]['id']];
                                                CrudService::update($db_prefix . 'transaction', $columns, $values, 'id = :id', $params_trx_upd);

                                                $params = [':ref' => $transaction_id, ':status' => 'pending'];

                                                $response_transaction = CrudService::select($db_prefix . 'transaction', 'WHERE ref = :ref AND status = :status ', '* FROM', $params);

                                                $metadata = json_decode($response_transaction['response'][0]['metadata'], true) ?: [];

                                                $params_gw = [':brand_id' => $response_brand['response'][0]['brand_id'], ':gateway_id' => $gateway_id];
                                                $response_gateway = CrudService::select($db_prefix . 'gateways', ' WHERE brand_id = :brand_id AND gateway_id = :gateway_id', '* FROM', $params_gw);

                                                $gateway = $response_gateway['response'][0]['name'] ?? '';

                                                $customer_info = json_decode($response_transaction['response'][0]['customer_info'], true) ?: [];

                                                $all_transactions[] = [
                                                    "op_id" => $response_transaction['response'][0]['ref'],
                                                    "full_name" => $customer_info['name'] ?? 'N/A',
                                                    "email_address" => $customer_info['email'] ?? 'N/A',
                                                    "mobile_number" => $customer_info['mobile'] ?? 'N/A',
                                                    "gateway" => $gateway,
                                                    "amount" => money_round($response_transaction['response'][0]['amount']),
                                                    "fee" => money_round($response_transaction['response'][0]['processing_fee']),
                                                    "discount_amount" => money_round($response_transaction['response'][0]['discount_amount']),
                                                    "total" => money_sub(money_add($response_transaction['response'][0]['amount'], $response_transaction['response'][0]['processing_fee']), $response_transaction['response'][0]['discount_amount']),
                                                    "local_net_amount" => money_round($response_transaction['response'][0]['local_net_amount']),
                                                    "currency" => $response_transaction['response'][0]['currency'],
                                                    "local_currency" => $response_transaction['response'][0]['local_currency'],
                                                    "metadata" => $metadata, // ← AS-IS
                                                    "sender" => null,
                                                    "transaction_id" => $response_transaction['response'][0]['trx_id'],
                                                    "status" => $response_transaction['response'][0]['status'],
                                                    "date" => convertUTCtoUserTZ($response_transaction['response'][0]['created_date'], !empty($response_brand['response'][0]['timezone']) ? $response_brand['response'][0]['timezone'] : 'Asia/Dhaka', "M d, Y h:i A")
                                                ];

                                                echo json_encode(['status' => "true", 'title' => 'Transaction Submitted', 'message' => 'Your Transaction ID has been successfully submitted']);
                                            }
                                        }
                                    }
                                } else {
                                    if (isset($gateway_info['verify_by']) && $gateway_info['verify_by'] == "slip") {
                                        $slip = InputSanitizer::trim($_FILES['slip'] ?? '');

                                        if ($slip == "") {
                                            echo json_encode(['status' => "false", 'title' => 'Missing Transaction Slip', 'message' => 'The Transaction slip field cannot be empty. Please provide a valid Transaction Slip.']);
                                        } else {
                                            $params_brand = [':brand_id' => $response_transaction['response'][0]['brand_id']];
                                            $response_brand = CrudService::select($db_prefix . 'brands', ' WHERE brand_id = :brand_id', '* FROM', $params_brand);
                                            if ($response_brand['status'] == true) {
                                                $max_file_size = 5 * 1024 * 1024;

                                                $mediaUpload = json_decode(uploadImage($slip ?? null, $max_file_size), true);
                                                if ($mediaUpload['status'] == true) {
                                                    $trx_slip = $site_url . 'media/storage/' . $mediaUpload['file'];
                                                } else {
                                                    echo json_encode(['status' => "false", 'title' => 'Missing Transaction Slip', 'message' => 'The Transaction slip field cannot be empty. Please provide a valid Transaction Slip.']);
                                                    exit();
                                                }

                                                $columns = ['processing_fee', 'discount_amount', 'local_net_amount', 'local_currency', 'gateway_id', 'status', 'trx_slip', 'updated_date'];
                                                $values = [money_sanitize($totalProcessingFee), money_sanitize($totalDiscount), money_sanitize($convertedAmount), $response_gateway['response'][0]['currency'], $gateway_id, 'pending', $trx_slip, getCurrentDatetime('Y-m-d H:i:s')];
                                                $params_trx_upd = [':id' => $response_transaction['response'][0]['id']];
                                                CrudService::update($db_prefix . 'transaction', $columns, $values, 'id = :id', $params_trx_upd);

                                                $params = [':ref' => $transaction_id, ':status' => 'pending'];

                                                $response_transaction = CrudService::select($db_prefix . 'transaction', 'WHERE ref = :ref AND status = :status ', '* FROM', $params);

                                                $metadata = json_decode($response_transaction['response'][0]['metadata'], true) ?: [];

                                                $params_gw = [':brand_id' => $response_brand['response'][0]['brand_id'], ':gateway_id' => $gateway_id];
                                                $response_gateway = CrudService::select($db_prefix . 'gateways', ' WHERE brand_id = :brand_id AND gateway_id = :gateway_id', '* FROM', $params_gw);

                                                $gateway = $response_gateway['response'][0]['name'] ?? '';

                                                $customer_info = json_decode($response_transaction['response'][0]['customer_info'], true) ?: [];

                                                $all_transactions[] = [
                                                    "op_id" => $response_transaction['response'][0]['ref'],
                                                    "full_name" => $customer_info['name'] ?? 'N/A',
                                                    "email_address" => $customer_info['email'] ?? 'N/A',
                                                    "mobile_number" => $customer_info['mobile'] ?? 'N/A',
                                                    "gateway" => $gateway,
                                                    "amount" => money_round($response_transaction['response'][0]['amount']),
                                                    "fee" => money_round($response_transaction['response'][0]['processing_fee']),
                                                    "discount_amount" => money_round($response_transaction['response'][0]['discount_amount']),
                                                    "total" => money_sub(money_add($response_transaction['response'][0]['amount'], $response_transaction['response'][0]['processing_fee']), $response_transaction['response'][0]['discount_amount']),
                                                    "local_net_amount" => money_round($response_transaction['response'][0]['local_net_amount']),
                                                    "currency" => $response_transaction['response'][0]['currency'],
                                                    "local_currency" => $response_transaction['response'][0]['local_currency'],
                                                    "metadata" => $metadata, // ← AS-IS
                                                    "sender" => null,
                                                    "transaction_id" => null,
                                                    "status" => $response_transaction['response'][0]['status'],
                                                    "date" => convertUTCtoUserTZ($response_transaction['response'][0]['created_date'], !empty($response_brand['response'][0]['timezone']) ? $response_brand['response'][0]['timezone'] : 'Asia/Dhaka', "M d, Y h:i A")
                                                ];

                                                echo json_encode(['status' => "true", 'title' => 'Transaction Submitted', 'message' => 'Your Transaction ID has been successfully submitted']);
                                            }
                                        }
                                    } else {
                                        echo json_encode(['status' => "false", 'title' => 'Request Failed. Code 106', 'message' => 'Please fill in all required fields before proceeding.']);
                                    }
                                }
                            }

                            if (!empty($all_transactions)) {
                                do_action('transactions.updated', $all_transactions);
                            }
                        } else {
                            echo json_encode(['status' => "false", 'title' => 'Request Failed. Code 105', 'message' => 'Please fill in all required fields before proceeding.']);
                        }
                    } else {
                        echo json_encode(['status' => "false", 'title' => 'Request Failed. Code 103', 'message' => 'Please fill in all required fields before proceeding.']);
                    }
                } else {
                    echo json_encode(['status' => "false", 'title' => 'Request Failed. Code 102', 'message' => 'Please fill in all required fields before proceeding.']);
                }
            } else {
                echo json_encode(['status' => "false", 'title' => 'Request Failed. Code 101', 'message' => 'Please fill in all required fields before proceeding.']);
            }
        }
    }
}
