<?php
declare(strict_types=1);

namespace OwnPay\Controller\Admin;

use OwnPay\Http\RequestContext;
use OwnPay\Service\System\CrudService;
use OwnPay\Service\System\InputSanitizer;
use OwnPay\Service\Auth\PermissionGuard;

class SmsDataController
{
    public static function handle(string $action, ?RequestContext $ctx = null): void
    {
        $ctx ??= $GLOBALS['requestContext'] ?? throw new \RuntimeException('RequestContext not available');
        $global_user_login = $ctx->isLoggedIn;
        $new_csrf_token = $ctx->csrfToken;
        $db_prefix = $ctx->dbPrefix;
        $global_response_brand = $ctx->brandResponse;

        if ($action == "sms-data-list") {
            if ($global_user_login == true) {
                if (!PermissionGuard::canAccess($ctx, 'sms_data')) {
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
                $params_sms = [':zero_device' => '00', ':error_status' => 'error'];

                if ($tabType !== "all") {
                    $where[] = "status = :tab_type";
                    $params_sms[':tab_type'] = $tabType;
                }

                if ($filter_start !== '') {
                    $where[] = "created_date >= :filter_start";
                    $params_sms[':filter_start'] = "{$filter_start} 00:00:00";
                }

                if ($filter_end !== '') {
                    $where[] = "created_date <= :filter_end";
                    $params_sms[':filter_end'] = "{$filter_end} 23:59:59";
                }

                if ($filter_status !== '') {
                    $where[] = "status = :filter_status";
                    $params_sms[':filter_status'] = $filter_status;
                }

                $where_sql = $where ? implode(' AND ', $where) . ' AND ' : '';
                /* Filters */


                $pag = \OwnPay\Service\System\PaginationService::resolve($request->post('page', 1), $request->post('show_limit'));
                $page = $pag['page'];
                $show_limit_val = $pag['perPage'];
                $offset = $pag['offset'];

                $sql_query = '';

                if ($search_input !== '') {
                    $sql_query .= " AND ( sender_key LIKE :search OR amount LIKE :search OR currency LIKE :search OR trx_id LIKE :search OR message LIKE :search )";
                    $params_sms[':search'] = "%$search_input%";
                }

                $sql_limit = '';
                if ($pag['isAll']) {

                } else {
                    $sql_limit = " LIMIT $offset, $show_limit_val";
                }

                $response_result = CrudService::select($db_prefix . 'sms_data', ' WHERE ' . $where_sql . ' device_id NOT IN (:zero_device) AND status NOT IN (:error_status) ' . $sql_query . ' ORDER BY 1 DESC ' . $sql_limit, '* FROM', $params_sms);
                if ($response_result['status'] == true) {
                    $response = [];

                    foreach ($response_result['response'] as $row) {
                        $device_name = '';
                        $params_dev = [':device_id' => $row['device_id']];
                        $response_device = CrudService::select($db_prefix . 'device', ' WHERE device_id = :device_id', '* FROM', $params_dev);
                        if ($response_device['status'] == true) {
                            $device_name = $response_device['response'][0]['name'];
                        }

                        $provider = senderWhitelist(null, $row['sender_key']);

                        if ($provider) {
                            $payment_method = $provider['name'];     // Ipay
                            $currency = $provider['currency']; // BDT
                        } else {
                            $payment_method = '';     // Ipay
                            $currency = ''; // BDT
                        }

                        $response[] = [
                            "id" => $row['id'],
                            "device" => $device_name,
                            "payment_method" => $payment_method,
                            "type" => $row['type'] ?? '',
                            "mobileNumber" => $row['number'] ?? '',
                            "transaction_id" => $row['trx_id'] ?? '',
                            "amount" => empty($row['currency']) ? '' : $row['currency'] . ' ' . money_round($row['amount'], 2),
                            "balance" => empty($row['currency']) ? '' : $row['currency'] . ' ' . money_round($row['balance'], 2),
                            "status" => $row['status'],
                            "created_date" => convertUTCtoUserTZ($row['created_date'], (empty($global_response_brand['response'][0]['timezone'])) ? 'Asia/Dhaka' : $global_response_brand['response'][0]['timezone'], "M d, Y h:i A"),
                            "updated_date" => convertUTCtoUserTZ($row['updated_date'], (empty($global_response_brand['response'][0]['timezone'])) ? 'Asia/Dhaka' : $global_response_brand['response'][0]['timezone'], "M d, Y h:i A")
                        ];
                    }

                    $count_data = CrudService::select($db_prefix . 'sms_data', ' WHERE ' . $where_sql . ' device_id NOT IN (:zero_device) AND status NOT IN (:error_status) ' . $sql_query, '* FROM', $params_sms);


                    $total_records = count($count_data['response'] ?? []);
                    $pagHtml = \OwnPay\Service\System\PaginationService::render($page, $total_records, $show_limit_val, $offset);
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

        if ($action == "sms-data-delete") {
            if ($global_user_login == true) {
                if (!PermissionGuard::canAccess($ctx, 'sms_data') || !PermissionGuard::has($ctx, 'sms_data', 'delete')) {
                    echo json_encode(['status' => 'false', 'title' => 'Access denied', 'message' => 'You need permission to perform this action. Please contact the admin.', 'csrf_token' => $new_csrf_token]);
                    exit();
                }

                $request = \OwnPay\Http\Request::createFromGlobals();

                $ItemID = $request->post('ItemID', '');
                $params_item = [':id' => $ItemID];

                $response_brand = CrudService::select($db_prefix . 'sms_data', 'WHERE id = :id', '* FROM', $params_item);
                if ($response_brand['status'] == true) {
                    $condition = "id = :id";
                    $whereParams = [':id' => $ItemID];

                    CrudService::delete($db_prefix . 'sms_data', $condition, $whereParams);
                }

                echo json_encode(['status' => 'true', 'title' => 'SMS Data Deleted', 'message' => 'The selected sms data have been deleted successfully.', 'csrf_token' => $new_csrf_token]);
            } else {
                echo json_encode(['status' => 'false', 'title' => 'Request Failed', 'message' => 'Invalid request', 'csrf_token' => $new_csrf_token]);
            }
        }

        if ($action == "sms-data-bulk-action") {
            if ($global_user_login == true) {
                if (!PermissionGuard::canAccess($ctx, 'sms_data')) {
                    echo json_encode(['status' => 'false', 'title' => 'Access denied', 'message' => 'You need permission to perform this action. Please contact the admin.', 'csrf_token' => $new_csrf_token]);
                    exit();
                }

                $request = \OwnPay\Http\Request::createFromGlobals();

                $actionID = $request->post('actionID', '');
                $selected_ids_json = $request->post('selected_ids', '[]');
                $selected_ids = json_decode($selected_ids_json, true);

                if (!empty($selected_ids)) {
                    foreach ($selected_ids as $id) {
                        $itemID = InputSanitizer::trim($id);
                        $params_item = [':id' => $itemID];

                        $response_brand = CrudService::select($db_prefix . 'sms_data', 'WHERE id = :id', '* FROM', $params_item);
                        if ($response_brand['status'] == true) {
                            if ($actionID == "deleted") {
                                if (PermissionGuard::has($ctx, 'sms_data', 'delete')) {

                                    $condition = "id = :id";
                                    $whereParams = [':id' => $itemID];

                                    CrudService::delete($db_prefix . 'sms_data', $condition, $whereParams);

                                }
                            }

                            if ($actionID !== "deleted") {
                                if (PermissionGuard::has($ctx, 'sms_data', 'edit')) {

                                    $columns = ['status', 'updated_date'];
                                    $values = [$actionID, getCurrentDatetime('Y-m-d H:i:s')];
                                    $condition = "id = :id";
                                    $whereParams = [':id' => $itemID];

                                    CrudService::update($db_prefix . 'sms_data', $columns, $values, $condition, $whereParams);

                                }
                            }
                        }
                    }

                    echo json_encode(['status' => 'true', 'title' => 'SMS Data ' . $actionID, 'message' => 'The selected sms datas have been ' . $actionID . ' successfully.', 'csrf_token' => $new_csrf_token]);
                } else {
                    echo json_encode(['status' => 'false', 'title' => 'Request Failed', 'message' => 'No customers selected.', 'csrf_token' => $new_csrf_token]);
                }
            } else {
                echo json_encode(['status' => 'false', 'title' => 'Request Failed', 'message' => 'Invalid request', 'csrf_token' => $new_csrf_token]);
            }
        }



        if ($action == "sms-data-create") {
            if ($global_user_login == true) {
                if (!PermissionGuard::canAccess($ctx, 'sms_data') || !PermissionGuard::has($ctx, 'sms_data', 'create')) {
                    echo json_encode(['status' => 'false', 'title' => 'Access denied', 'message' => 'You need permission to perform this action. Please contact the admin.', 'csrf_token' => $new_csrf_token]);
                    exit();
                }

                $request = \OwnPay\Http\Request::createFromGlobals();

                $device_id = $request->post('device', '');
                $entry_type = $request->post('entry_type', '');
                $sender_key = $request->post('sender_key', '');
                $status = $request->post('status', '');
                $message = $request->post('message', '');
                $type = $request->post('type', '');
                $amount = $request->post('amount', '');
                $phone_number = $request->post('phone_number', '');
                $transaction_id = $request->post('transaction_id', '');
                $currency = $request->post('currency', '');

                if ($entry_type == "" || $sender_key == "" || $status == "") {
                    echo json_encode(['status' => "false", 'title' => 'Incomplete Information', 'message' => 'Please fill in all required fields before proceeding.', 'csrf_token' => $new_csrf_token]);
                } else {
                    if ($entry_type == "automatic") {
                        if ($message == "") {
                            echo json_encode(['status' => "false", 'title' => 'Incomplete Information', 'message' => 'Please fill in all required fields before proceeding.', 'csrf_token' => $new_csrf_token]);
                            exit();
                        } else {
                            $result = MFSMessageVerified($sender_key, $message);

                            if ($result === false) {
                                echo json_encode(['status' => "false", 'title' => 'Invalid or unknown MFS message', 'message' => 'Please fill in all required fields before proceeding.', 'csrf_token' => $new_csrf_token]);
                                exit();
                            } else {
                                $type = InputSanitizer::trim($result['type'] ?? '');
                                $amount = InputSanitizer::trim($result['amount'] ?? '');
                                $balance = InputSanitizer::trim($result['balance'] ?? '');
                                $phone_number = InputSanitizer::trim($result['sender'] ?? '');
                                $transaction_id = InputSanitizer::trim($result['trxid'] ?? '');

                                if ($type == "" || $amount == "" || $phone_number == "" || $transaction_id == "") {
                                    echo json_encode(['status' => "false", 'title' => 'Invalid or unknown MFS message', 'message' => 'Please fill in all required fields before proceeding.', 'csrf_token' => $new_csrf_token]);
                                    exit();
                                }
                                $params = [':sender_key' => $sender_key, ':trx_id' => $transaction_id];

                                $response = CrudService::select($db_prefix . 'sms_data', 'WHERE sender_key = :sender_key AND trx_id = :trx_id', '* FROM', $params);
                                if ($response['status'] == false) {
                                    if ($device_id == "") {
                                        $device_id = null;
                                    }

                                    /*$response_balance_verification = json_decode(getData($db_prefix.'balance_verification','WHERE device_id ="'.$device_id.'" AND sender_key = "'.$sender_key.'" AND payment_type = "'.$type.'"'),true);
                                    if($response_balance_verification['status'] == true){
                                        $balance = $response_balance_verification['response'][0]['current_balance']+number_validator($amount);

                                        $columns = ['current_balance', 'updated_date'];
                                        $values = [$balance, getCurrentDatetime('Y-m-d H:i:s')];
                                        $condition = "id = '".$response_balance_verification['response'][0]['id']."'";

                                        updateData($db_prefix.'balance_verification', $columns, $values, $condition);
                                    }*/

                                    $columns = ['device_id', 'sender_key', 'number', 'amount', 'currency', 'trx_id', 'balance', 'type', 'entry_type', 'status', 'message', 'created_date', 'updated_date'];
                                    $values = [$device_id, $sender_key, $phone_number, money_sanitize($amount), $currency, $transaction_id, money_sanitize($balance), $type, $entry_type, $status, $message, getCurrentDatetime('Y-m-d H:i:s'), getCurrentDatetime('Y-m-d H:i:s')];

                                    CrudService::insert($db_prefix . 'sms_data', $columns, $values);

                                    echo json_encode(['status' => 'true', 'title' => 'SMS Data Created', 'message' => 'The sms data has been created successfully.' . $amount, 'csrf_token' => $new_csrf_token]);
                                } else {
                                    echo json_encode(['status' => 'false', 'title' => 'Duplicate Transaction', 'message' => 'The provided Transaction ID already exists in our system.', 'csrf_token' => $new_csrf_token]);
                                }
                            }
                        }
                    } else {
                        if ($type == "" || $amount == "" || $phone_number == "" || $transaction_id == "") {
                            echo json_encode(['status' => "false", 'title' => 'Incomplete Information', 'message' => 'Please fill in all required fields before proceeding.', 'csrf_token' => $new_csrf_token]);
                            exit();
                        } else {
                            $params = [':sender_key' => $sender_key, ':trx_id' => $transaction_id];

                            $response = CrudService::select($db_prefix . 'sms_data', 'WHERE sender_key = :sender_key AND trx_id = :trx_id', '* FROM', $params);
                            if ($response['status'] == false) {
                                if ($device_id == "") {
                                    $device_id = null;
                                }

                                $balance = 0;

                                /*$response_balance_verification = json_decode(getData($db_prefix.'balance_verification','WHERE device_id ="'.$device_id.'" AND sender_key = "'.$sender_key.'" AND payment_type = "'.$type.'"'),true);
                                if($response_balance_verification['status'] == true){
                                    $balance = $response_balance_verification['response'][0]['current_balance']+number_validator($amount);

                                    $columns = ['current_balance', 'updated_date'];
                                    $values = [$balance, getCurrentDatetime('Y-m-d H:i:s')];
                                    $condition = "id = '".$response_balance_verification['response'][0]['id']."'";

                                    updateData($db_prefix.'balance_verification', $columns, $values, $condition);
                                }*/

                                $columns = ['device_name', 'sender_key', 'number', 'amount', 'currency', 'trx_id', 'balance', 'type', 'entry_type', 'status', 'message', 'created_date', 'updated_date'];
                                $values = [$device, $sender_key, $phone_number, money_sanitize($amount), $currency, $transaction_id, money_sanitize($balance), $type, $entry_type, $status, $message, getCurrentDatetime('Y-m-d H:i:s'), getCurrentDatetime('Y-m-d H:i:s')];

                                CrudService::insert($db_prefix . 'sms_data', $columns, $values);

                                echo json_encode(['status' => 'true', 'title' => 'SMS Data Created', 'message' => 'The sms data has been created successfully.', 'csrf_token' => $new_csrf_token]);
                            } else {
                                echo json_encode(['status' => 'false', 'title' => 'Duplicate Transaction', 'message' => 'The provided Transaction ID already exists in our system.', 'csrf_token' => $new_csrf_token]);
                            }
                        }
                    }
                }
            } else {
                echo json_encode(['status' => 'false', 'title' => 'Request Failed', 'message' => 'Invalid request', 'csrf_token' => $new_csrf_token]);
            }
        }

        if ($action == "sms-data-info-byID") {
            if ($global_user_login == true) {
                if (!PermissionGuard::canAccess($ctx, 'sms_data') || !PermissionGuard::has($ctx, 'sms_data', 'edit')) {
                    echo json_encode(['status' => 'false', 'title' => 'Access denied', 'message' => 'You need permission to perform this action. Please contact the admin.', 'csrf_token' => $new_csrf_token]);
                    exit();
                }

                $request = \OwnPay\Http\Request::createFromGlobals();

                $ItemID = $request->post('ItemID', '');

                $response_brand = CrudService::select($db_prefix . 'sms_data', 'WHERE id = :id', '* FROM', [':id' => $ItemID]);
                if ($response_brand['status'] == true) {
                    echo json_encode(['status' => 'true', 'device_id' => $response_brand['response'][0]['device_id'], 'sender_key' => $response_brand['response'][0]['sender_key'], 'number' => $response_brand['response'][0]['number'], 'amount' => money_round($response_brand['response'][0]['amount'], 2), 'currency' => $response_brand['response'][0]['currency'], 'trx_id' => $response_brand['response'][0]['trx_id'], 'balance' => money_round($response_brand['response'][0]['balance'], 2), 'message' => $response_brand['response'][0]['message'], 'type' => $response_brand['response'][0]['type'], 'entry_type' => $response_brand['response'][0]['entry_type'], 'istatus' => $response_brand['response'][0]['status'], 'reason' => $response_brand['response'][0]['reason'], 'csrf_token' => $new_csrf_token]);
                } else {
                    echo json_encode(['status' => 'false', 'title' => 'Request Failed', 'message' => 'Invalid request', 'csrf_token' => $new_csrf_token]);
                }
            } else {
                echo json_encode(['status' => 'false', 'title' => 'Request Failed', 'message' => 'Invalid request', 'csrf_token' => $new_csrf_token]);
            }
        }

        if ($action == "sms-data-edit") {
            if ($global_user_login == true) {
                if (!PermissionGuard::canAccess($ctx, 'sms_data') || !PermissionGuard::has($ctx, 'sms_data', 'edit')) {
                    echo json_encode(['status' => 'false', 'title' => 'Access denied', 'message' => 'You need permission to perform this action. Please contact the admin.', 'csrf_token' => $new_csrf_token]);
                    exit();
                }

                $request = \OwnPay\Http\Request::createFromGlobals();

                $itemid = $request->post('itemid', '');
                $device_id = $request->post('device', '');
                $sender_key = $request->post('sender_key', '');
                $status = $request->post('status', '');
                $message = $request->post('message', '');
                $type = $request->post('type', '');
                $amount = $request->post('amount', '');
                $phone_number = $request->post('phone_number', '');
                $transaction_id = $request->post('transaction_id', '');
                $currency = $request->post('currency', '');

                $responseV = CrudService::select($db_prefix . 'sms_data', 'WHERE id = :id', '* FROM', [':id' => $itemid]);
                if ($responseV['status'] == false) {
                    echo json_encode(['status' => 'false', 'title' => 'Request Failed', 'message' => 'Invalid request', 'csrf_token' => $new_csrf_token]);
                    exit();
                } else {
                    $entry_type = $responseV['response'][0]['entry_type'];
                }

                if ($entry_type == "" || $sender_key == "" || $status == "") {
                    echo json_encode(['status' => "false", 'title' => 'Incomplete Information', 'message' => 'Please fill in all required fields before proceeding.', 'csrf_token' => $new_csrf_token]);
                } else {
                    if ($entry_type == "automatic") {
                        if ($message == "") {
                            echo json_encode(['status' => "false", 'title' => 'Incomplete Information', 'message' => 'Please fill in all required fields before proceeding.', 'csrf_token' => $new_csrf_token]);
                            exit();
                        } else {
                            $result = MFSMessageVerified($sender_key, $message);

                            if ($result === false) {
                                echo json_encode(['status' => "false", 'title' => 'Invalid or unknown MFS message', 'message' => 'Please fill in all required fields before proceeding.', 'csrf_token' => $new_csrf_token]);
                                exit();
                            } else {
                                $type = InputSanitizer::trim($result['type'] ?? '');
                                $amount = InputSanitizer::trim($result['amount'] ?? '');
                                $balance = InputSanitizer::trim($result['balance'] ?? '');
                                $phone_number = InputSanitizer::trim($result['sender'] ?? '');
                                $transaction_id = InputSanitizer::trim($result['trxid'] ?? '');

                                if ($type == "" || $amount == "" || $phone_number == "" || $transaction_id == "") {
                                    echo json_encode(['status' => "false", 'title' => 'Invalid or unknown MFS message', 'message' => 'Please fill in all required fields before proceeding.', 'csrf_token' => $new_csrf_token]);
                                    exit();
                                }

                                $params = [':sender_key' => $sender_key, ':trx_id' => $transaction_id];

                                $response = CrudService::select($db_prefix . 'sms_data', 'WHERE sender_key = :sender_key AND trx_id = :trx_id', '* FROM', $params);
                                if ($response['status'] == false) {
                                    if ($response['response'][0]['id'] == $itemid) {

                                    } else {
                                        echo json_encode(['status' => 'false', 'title' => 'Duplicate Transaction', 'message' => 'The provided Transaction ID already exists in our system.', 'csrf_token' => $new_csrf_token]);
                                        exit();
                                    }
                                }

                                if ($device_id == "") {
                                    $device_id = null;
                                }

                                /*$response_balance_verification = json_decode(getData($db_prefix.'balance_verification','WHERE device_id ="'.$device_id.'" AND sender_key = "'.$sender_key.'" AND payment_type = "'.$type.'"'),true);
                                if($response_balance_verification['status'] == true){
                                    $balance = $response_balance_verification['response'][0]['current_balance']-$responseV['response'][0]['amount']+number_validator($amount);

                                    $columns = ['current_balance', 'updated_date'];
                                    $values = [$balance, getCurrentDatetime('Y-m-d H:i:s')];
                                    $condition = "id = '".$response_balance_verification['response'][0]['id']."'";

                                    updateData($db_prefix.'balance_verification', $columns, $values, $condition);
                                }*/

                                $columns = ['device_id', 'sender_key', 'number', 'amount', 'currency', 'trx_id', 'balance', 'type', 'entry_type', 'status', 'message', 'updated_date'];
                                $values = [$device_id, $sender_key, $phone_number, money_sanitize($amount), $currency, $transaction_id, money_sanitize($balance), $type, $entry_type, $status, $message, getCurrentDatetime('Y-m-d H:i:s')];

                                $condition = "id = :id";
                                $whereParams = [':id' => $itemid];

                                CrudService::update($db_prefix . 'sms_data', $columns, $values, $condition, $whereParams);

                                echo json_encode(['status' => 'true', 'title' => 'SMS Data Updated', 'message' => 'The sms data has been updated successfully.', 'csrf_token' => $new_csrf_token]);
                            }
                        }
                    } else {
                        if ($type == "" || $amount == "" || $phone_number == "" || $transaction_id == "") {
                            echo json_encode(['status' => "false", 'title' => 'Incomplete Information', 'message' => 'Please fill in all required fields before proceeding.', 'csrf_token' => $new_csrf_token]);
                            exit();
                        } else {
                            $params = [':sender_key' => $sender_key, ':trx_id' => $transaction_id];

                            $response = CrudService::select($db_prefix . 'sms_data', 'WHERE sender_key = :sender_key AND trx_id = :trx_id', '* FROM', $params);
                            if ($response['status'] == false) {
                                if ($response['response'][0]['id'] == $itemid) {

                                } else {
                                    echo json_encode(['status' => 'false', 'title' => 'Duplicate Transaction', 'message' => 'The provided Transaction ID already exists in our system.', 'csrf_token' => $new_csrf_token]);
                                    exit();
                                }
                            }

                            if ($device_id == "") {
                                $device_id = null;
                            }

                            $balance = 0;

                            /*$response_balance_verification = json_decode(getData($db_prefix.'balance_verification','WHERE device_id ="'.$device_id.'" AND sender_key = "'.$sender_key.'" AND payment_type = "'.$type.'"'),true);
                            if($response_balance_verification['status'] == true){
                                $balance = $response_balance_verification['response'][0]['current_balance']-$responseV['response'][0]['amount']+number_validator($amount);

                                $columns = ['current_balance', 'updated_date'];
                                $values = [$balance, getCurrentDatetime('Y-m-d H:i:s')];
                                $condition = "id = '".$response_balance_verification['response'][0]['id']."'";

                                updateData($db_prefix.'balance_verification', $columns, $values, $condition);
                            }*/

                            $columns = ['device_id', 'sender_key', 'number', 'amount', 'currency', 'trx_id', 'balance', 'type', 'entry_type', 'status', 'message', 'updated_date'];
                            $values = [$device_id, $sender_key, $phone_number, money_sanitize($amount), $currency, $transaction_id, money_sanitize($balance), $type, $entry_type, $status, $message, getCurrentDatetime('Y-m-d H:i:s')];

                            $condition = "id = :id";
                            $whereParams = [':id' => $itemid];

                            CrudService::update($db_prefix . 'sms_data', $columns, $values, $condition, $whereParams);

                            echo json_encode(['status' => 'true', 'title' => 'SMS Data Updated', 'message' => 'The sms data has been updated successfully.', 'csrf_token' => $new_csrf_token]);
                        }
                    }
                }
            } else {
                echo json_encode(['status' => 'false', 'title' => 'Request Failed', 'message' => 'Invalid request', 'csrf_token' => $new_csrf_token]);
            }
        }

    }
}
