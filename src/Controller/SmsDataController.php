<?php
declare(strict_types=1);

namespace AnirbanPay\Controller;

use AnirbanPay\Http\RequestContext;

class SmsDataController
{
    public static function handle(string $action, ?RequestContext $ctx = null): void
    {
        $ctx ??= $GLOBALS['requestContext'] ?? throw new \RuntimeException('RequestContext not available');
        $global_user_login = $ctx->isLoggedIn;
        $global_response_permission = $ctx->permissionResponse;
        $global_user_response = $ctx->userResponse;
        $new_csrf_token = $ctx->csrfToken;
        $db_prefix = $ctx->dbPrefix;
        $global_response_brand = $ctx->brandResponse;

        if ($action == "sms-data-list") {
            if ($global_user_login == true) {
                if (!canAccessPage(json_decode($global_response_permission['response'][0]['permission'], true), 'sms_data', $global_user_response['response'][0]['role'])) {
                    echo json_encode(['status' => 'false', 'title' => 'Access denied', 'message' => 'You need permission to perform this action. Please contact the admin.', 'csrf_token' => $new_csrf_token]);
                    exit();
                }

                $request = \AnirbanPay\Http\Request::createFromGlobals();

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


                $page = max(1, (int) $request->post('page', 1));
                $show_limit_val = ($request->post('show_limit') == '') ? 999999 : (int) $request->post('show_limit');
                $offset = ($page - 1) * $show_limit_val;

                $sql_query = '';

                if ($search_input !== '') {
                    $sql_query .= " AND ( sender_key LIKE :search OR amount LIKE :search OR currency LIKE :search OR trx_id LIKE :search OR message LIKE :search )";
                    $params_sms[':search'] = "%$search_input%";
                }

                $sql_limit = '';
                if ($show_limit == 'all') {

                } else {
                    $sql_limit = " LIMIT $offset, $show_limit";
                }

                $response_result = json_decode(getData($db_prefix . 'sms_data', ' WHERE ' . $where_sql . ' device_id NOT IN (:zero_device) AND status NOT IN (:error_status) ' . $sql_query . ' ORDER BY 1 DESC ' . $sql_limit, '* FROM', $params_sms), true);
                if ($response_result['status'] == true) {
                    $response = [];

                    foreach ($response_result['response'] as $row) {
                        $device_name = '';
                        $params_dev = [':device_id' => $row['device_id']];
                        $response_device = json_decode(getData($db_prefix . 'device', ' WHERE device_id = :device_id', '* FROM', $params_dev), true);
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
                            "type" => ($row['type'] === '--') ? '' : $row['type'],
                            "mobileNumber" => ($row['number'] === '--') ? '' : $row['number'],
                            "transaction_id" => ($row['trx_id'] === '--') ? '' : $row['trx_id'],
                            "amount" => ($row['currency'] === '--') ? '' : $row['currency'] . ' ' . money_round($row['amount'], 2),
                            "balance" => ($row['currency'] === '--') ? '' : $row['currency'] . ' ' . money_round($row['balance'], 2),
                            "status" => $row['status'],
                            "created_date" => convertUTCtoUserTZ($row['created_date'], ($global_response_brand['response'][0]['timezone'] === '--' || $global_response_brand['response'][0]['timezone'] === '') ? 'Asia/Dhaka' : $global_response_brand['response'][0]['timezone'], "M d, Y h:i A"),
                            "updated_date" => convertUTCtoUserTZ($row['updated_date'], ($global_response_brand['response'][0]['timezone'] === '--' || $global_response_brand['response'][0]['timezone'] === '') ? 'Asia/Dhaka' : $global_response_brand['response'][0]['timezone'], "M d, Y h:i A")
                        ];
                    }

                    $count_data = json_decode(getData($db_prefix . 'sms_data', ' WHERE ' . $where_sql . ' device_id NOT IN (:zero_device) AND status NOT IN (:error_status) ' . $sql_query, '* FROM', $params_sms), true);


                    $total_records = count($count_data['response'] ?? []);
                    $total_pages = ceil($total_records / $show_limit);

                    $pagination = '<ul class="pagination m-0 ms-auto">';

                    // Prev button
                    $pagination .= '<li class="page-item' . ($page <= 1 ? ' disabled' : '') . '">
                            <button class="page-link" ' . ($page > 1 ? 'data-page="' . ($page - 1) . '"' : '') . '>
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="icon icon-1">
                                    <path d="M15 6l-6 6l6 6"></path>
                                </svg>
                            </button>
                        </li>';

                    // Page numbers
                    for ($i = 1; $i <= $total_pages; $i++) {
                        $pagination .= '<li class="page-item' . ($i == $page ? ' active' : '') . '">
                                <button class="page-link" data-page="' . $i . '">' . $i . '</button>
                            </li>';
                    }

                    // Next button
                    $pagination .= '<li class="page-item' . ($page >= $total_pages ? ' disabled' : '') . '">
                            <button class="page-link" ' . ($page < $total_pages ? 'data-page="' . ($page + 1) . '"' : '') . '>
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="icon icon-1">
                                    <path d="M9 6l6 6l-6 6"></path>
                                </svg>
                            </button>
                        </li>';

                    $pagination .= '</ul>';

                    $start = ($offset + 1);
                    $end = min($offset + $show_limit, $total_records);

                    $datatableInfo = "Showing <strong>$start to $end</strong> of <strong>$total_records entries</strong>";

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
                if (!canAccessPage(json_decode($global_response_permission['response'][0]['permission'], true), 'sms_data', $global_user_response['response'][0]['role'])) {
                    echo json_encode(['status' => 'false', 'title' => 'Access denied', 'message' => 'You need permission to perform this action. Please contact the admin.', 'csrf_token' => $new_csrf_token]);
                    exit();
                }

                if (!hasPermission(json_decode($global_response_permission['response'][0]['permission'], true), 'sms_data', 'delete', $global_user_response['response'][0]['role'])) {
                    echo json_encode(['status' => 'false', 'title' => 'Access denied', 'message' => 'You need permission to perform this action. Please contact the admin.', 'csrf_token' => $new_csrf_token]);
                    exit();
                }

                $request = \AnirbanPay\Http\Request::createFromGlobals();

                $ItemID = $request->post('ItemID', '');
                $params_item = [':id' => $ItemID];

                $response_brand = json_decode(getData($db_prefix . 'sms_data', 'WHERE id = :id', '* FROM', $params_item), true);
                if ($response_brand['status'] == true) {
                    $condition = "id = :id";
                    $whereParams = [':id' => $ItemID];

                    deleteData($db_prefix . 'sms_data', $condition, $whereParams);
                }

                echo json_encode(['status' => 'true', 'title' => 'SMS Data Deleted', 'message' => 'The selected sms data have been deleted successfully.', 'csrf_token' => $new_csrf_token]);
            } else {
                echo json_encode(['status' => 'false', 'title' => 'Request Failed', 'message' => 'Invalid request', 'csrf_token' => $new_csrf_token]);
            }
        }

        if ($action == "sms-data-bulk-action") {
            if ($global_user_login == true) {
                if (!canAccessPage(json_decode($global_response_permission['response'][0]['permission'], true), 'sms_data', $global_user_response['response'][0]['role'])) {
                    echo json_encode(['status' => 'false', 'title' => 'Access denied', 'message' => 'You need permission to perform this action. Please contact the admin.', 'csrf_token' => $new_csrf_token]);
                    exit();
                }

                $request = \AnirbanPay\Http\Request::createFromGlobals();

                $actionID = $request->post('actionID', '');
                $selected_ids_json = $request->post('selected_ids', '[]');
                $selected_ids = json_decode($selected_ids_json, true);

                if (!empty($selected_ids)) {
                    foreach ($selected_ids as $id) {
                        $itemID = escape_string($id);
                        $params_item = [':id' => $itemID];

                        $response_brand = json_decode(getData($db_prefix . 'sms_data', 'WHERE id = :id', '* FROM', $params_item), true);
                        if ($response_brand['status'] == true) {
                            if ($actionID == "deleted") {
                                if (hasPermission(json_decode($global_response_permission['response'][0]['permission'], true), 'sms_data', 'delete', $global_user_response['response'][0]['role'])) {

                                    $condition = "id = :id";
                                    $whereParams = [':id' => $itemID];

                                    deleteData($db_prefix . 'sms_data', $condition, $whereParams);

                                }
                            }

                            if ($actionID !== "deleted") {
                                if (hasPermission(json_decode($global_response_permission['response'][0]['permission'], true), 'sms_data', 'edit', $global_user_response['response'][0]['role'])) {

                                    $columns = ['status', 'updated_date'];
                                    $values = [$actionID, getCurrentDatetime('Y-m-d H:i:s')];
                                    $condition = "id = :id";
                                    $whereParams = [':id' => $itemID];

                                    updateData($db_prefix . 'sms_data', $columns, $values, $condition, $whereParams);

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
                if (!canAccessPage(json_decode($global_response_permission['response'][0]['permission'], true), 'sms_data', $global_user_response['response'][0]['role'])) {
                    echo json_encode(['status' => 'false', 'title' => 'Access denied', 'message' => 'You need permission to perform this action. Please contact the admin.', 'csrf_token' => $new_csrf_token]);
                    exit();
                }

                if (!hasPermission(json_decode($global_response_permission['response'][0]['permission'], true), 'sms_data', 'create', $global_user_response['response'][0]['role'])) {
                    echo json_encode(['status' => 'false', 'title' => 'Access denied', 'message' => 'You need permission to perform this action. Please contact the admin.', 'csrf_token' => $new_csrf_token]);
                    exit();
                }

                $request = \AnirbanPay\Http\Request::createFromGlobals();

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
                                $type = escape_string($result['type'] ?? '');
                                $amount = escape_string($result['amount'] ?? '');
                                $balance = escape_string($result['balance'] ?? '');
                                $phone_number = escape_string($result['sender'] ?? '');
                                $transaction_id = escape_string($result['trxid'] ?? '');

                                if ($type == "" || $amount == "" || $phone_number == "" || $transaction_id == "") {
                                    echo json_encode(['status' => "false", 'title' => 'Invalid or unknown MFS message', 'message' => 'Please fill in all required fields before proceeding.', 'csrf_token' => $new_csrf_token]);
                                    exit();
                                }
                                $params = [':sender_key' => $sender_key, ':trx_id' => $transaction_id];

                                $response = json_decode(getData($db_prefix . 'sms_data', 'WHERE sender_key = :sender_key AND trx_id = :trx_id', '* FROM', $params), true);
                                if ($response['status'] == false) {
                                    if ($device_id == "") {
                                        $device_id = '--';
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

                                    insertData($db_prefix . 'sms_data', $columns, $values);

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

                            $response = json_decode(getData($db_prefix . 'sms_data', 'WHERE sender_key = :sender_key AND trx_id = :trx_id', '* FROM', $params), true);
                            if ($response['status'] == false) {
                                if ($device_id == "") {
                                    $device_id = '--';
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

                                insertData($db_prefix . 'sms_data', $columns, $values);

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
                if (!canAccessPage(json_decode($global_response_permission['response'][0]['permission'], true), 'sms_data', $global_user_response['response'][0]['role'])) {
                    echo json_encode(['status' => 'false', 'title' => 'Access denied', 'message' => 'You need permission to perform this action. Please contact the admin.', 'csrf_token' => $new_csrf_token]);
                    exit();
                }

                if (!hasPermission(json_decode($global_response_permission['response'][0]['permission'], true), 'sms_data', 'edit', $global_user_response['response'][0]['role'])) {
                    echo json_encode(['status' => 'false', 'title' => 'Access denied', 'message' => 'You need permission to perform this action. Please contact the admin.', 'csrf_token' => $new_csrf_token]);
                    exit();
                }

                $request = \AnirbanPay\Http\Request::createFromGlobals();

                $ItemID = $request->post('ItemID', '');

                $response_brand = json_decode(getData($db_prefix . 'sms_data', 'WHERE id = :id', '* FROM', [':id' => $ItemID]), true);
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
                if (!canAccessPage(json_decode($global_response_permission['response'][0]['permission'], true), 'sms_data', $global_user_response['response'][0]['role'])) {
                    echo json_encode(['status' => 'false', 'title' => 'Access denied', 'message' => 'You need permission to perform this action. Please contact the admin.', 'csrf_token' => $new_csrf_token]);
                    exit();
                }

                if (!hasPermission(json_decode($global_response_permission['response'][0]['permission'], true), 'sms_data', 'edit', $global_user_response['response'][0]['role'])) {
                    echo json_encode(['status' => 'false', 'title' => 'Access denied', 'message' => 'You need permission to perform this action. Please contact the admin.', 'csrf_token' => $new_csrf_token]);
                    exit();
                }

                $request = \AnirbanPay\Http\Request::createFromGlobals();

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

                $responseV = json_decode(getData($db_prefix . 'sms_data', 'WHERE id = :id', '* FROM', [':id' => $itemid]), true);
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
                                $type = escape_string($result['type'] ?? '');
                                $amount = escape_string($result['amount'] ?? '');
                                $balance = escape_string($result['balance'] ?? '');
                                $phone_number = escape_string($result['sender'] ?? '');
                                $transaction_id = escape_string($result['trxid'] ?? '');

                                if ($type == "" || $amount == "" || $phone_number == "" || $transaction_id == "") {
                                    echo json_encode(['status' => "false", 'title' => 'Invalid or unknown MFS message', 'message' => 'Please fill in all required fields before proceeding.', 'csrf_token' => $new_csrf_token]);
                                    exit();
                                }

                                $params = [':sender_key' => $sender_key, ':trx_id' => $transaction_id];

                                $response = json_decode(getData($db_prefix . 'sms_data', 'WHERE sender_key = :sender_key AND trx_id = :trx_id', '* FROM', $params), true);
                                if ($response['status'] == false) {
                                    if ($response['response'][0]['id'] == $itemid) {

                                    } else {
                                        echo json_encode(['status' => 'false', 'title' => 'Duplicate Transaction', 'message' => 'The provided Transaction ID already exists in our system.', 'csrf_token' => $new_csrf_token]);
                                        exit();
                                    }
                                }

                                if ($device_id == "") {
                                    $device_id = '--';
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

                                updateData($db_prefix . 'sms_data', $columns, $values, $condition, $whereParams);

                                echo json_encode(['status' => 'true', 'title' => 'SMS Data Updated', 'message' => 'The sms data has been updated successfully.', 'csrf_token' => $new_csrf_token]);
                            }
                        }
                    } else {
                        if ($type == "" || $amount == "" || $phone_number == "" || $transaction_id == "") {
                            echo json_encode(['status' => "false", 'title' => 'Incomplete Information', 'message' => 'Please fill in all required fields before proceeding.', 'csrf_token' => $new_csrf_token]);
                            exit();
                        } else {
                            $params = [':sender_key' => $sender_key, ':trx_id' => $transaction_id];

                            $response = json_decode(getData($db_prefix . 'sms_data', 'WHERE sender_key = :sender_key AND trx_id = :trx_id', '* FROM', $params), true);
                            if ($response['status'] == false) {
                                if ($response['response'][0]['id'] == $itemid) {

                                } else {
                                    echo json_encode(['status' => 'false', 'title' => 'Duplicate Transaction', 'message' => 'The provided Transaction ID already exists in our system.', 'csrf_token' => $new_csrf_token]);
                                    exit();
                                }
                            }

                            if ($device_id == "") {
                                $device_id = '--';
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

                            updateData($db_prefix . 'sms_data', $columns, $values, $condition, $whereParams);

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
