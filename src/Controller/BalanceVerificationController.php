<?php
declare(strict_types=1);

namespace OwnPay\Controller;

use OwnPay\Http\RequestContext;
use OwnPay\Service\CrudService;
use OwnPay\Service\InputSanitizer;
use OwnPay\Service\PermissionGuard;

class BalanceVerificationController
{
    public static function handle(string $action, RequestContext $ctx): void
    {
        $global_user_login = $ctx->isLoggedIn;
        $new_csrf_token = $ctx->csrfToken;
        $db_prefix = $ctx->dbPrefix;
        $global_response_brand = $ctx->brandResponse;

        $request = \OwnPay\Http\Request::createFromGlobals();

        // Inline extracted code from adapter.php
        if ($action == "balance-verification-list") {
            if ($global_user_login == true) {
                if (!PermissionGuard::canAccess($ctx, 'device') || !PermissionGuard::has($ctx, 'device', 'balance_verification_for')) {
                    echo json_encode(['status' => 'false', 'title' => 'Access denied', 'message' => 'You need permission to perform this action. Please contact the admin.', 'csrf_token' => $new_csrf_token]);
                    exit();
                }

                $d_id = $request->post('d_id', '');
                $search_input = $request->post('search_input', '');
                $show_limit_raw = $request->post('show_limit', '5');

                /* Filters */
                $filter_status = $request->post('filter_status', '');
                $filter_start = $request->post('filter_start', '');
                $filter_end = $request->post('filter_end', '');

                $where = [];
                $params_bv = [':d_id' => $d_id, ':empty' => '--'];

                if ($filter_start !== '') {
                    $where[] = "created_date >= :filter_start";
                    $params_bv[':filter_start'] = "{$filter_start} 00:00:00";
                }

                if ($filter_end !== '') {
                    $where[] = "created_date <= :filter_end";
                    $params_bv[':filter_end'] = "{$filter_end} 23:59:59";
                }

                if ($filter_status !== '') {
                    $where[] = "status = :filter_status";
                    $params_bv[':filter_status'] = $filter_status;
                }

                $where_sql = $where ? implode(' AND ', $where) . ' AND ' : '';
                /* Filters */


                $pag = \OwnPay\Service\PaginationService::resolve($request->post('page', '1'), $request->post('show_limit'));
                $page = $pag['page'];
                $show_limit_val = $pag['perPage'];
                $offset = $pag['offset'];
                $show_limit = $show_limit_val;

                $sql_query = '';

                if ($search_input !== '') {
                    $sql_query .= " AND ( sender_key LIKE :search OR type LIKE :search OR current_balance LIKE :search )";
                    $params_bv[':search'] = "%$search_input%";
                }

                $sql_limit = '';
                if ($pag['isAll']) {

                } else {
                    $sql_limit = " LIMIT $offset, $show_limit_val";
                }

                $response_result = CrudService::select($db_prefix . 'balance_verification', ' WHERE ' . $where_sql . ' device_id = :d_id AND status NOT IN (:empty) ' . $sql_query . ' ORDER BY 1 DESC ' . $sql_limit, '* FROM', $params_bv);
                if ($response_result['status'] == true) {
                    $response = [];

                    foreach ($response_result['response'] as $row) {
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
                            "simslot" => $row['simslot'],
                            "payment_method" => $payment_method,
                            "payment_type" => $row['type'],
                            "current_balance" => money_round($row['current_balance'], 2),
                            "status" => $row['status'],
                            "created_date" => convertUTCtoUserTZ($row['created_date'], ($global_response_brand['response'][0]['timezone'] === null || $global_response_brand['response'][0]['timezone'] === '') ? 'Asia/Dhaka' : $global_response_brand['response'][0]['timezone'], "M d, Y h:i A"),
                            "updated_date" => convertUTCtoUserTZ($row['updated_date'], ($global_response_brand['response'][0]['timezone'] === null || $global_response_brand['response'][0]['timezone'] === '') ? 'Asia/Dhaka' : $global_response_brand['response'][0]['timezone'], "M d, Y h:i A")
                        ];
                    }

                    $count_data = CrudService::select($db_prefix . 'balance_verification', ' WHERE ' . $where_sql . ' device_id = :d_id AND status NOT IN (:empty) ' . $sql_query, '* FROM', $params_bv);


                    $total_records = count($count_data['response'] ?? []);
                    $pagHtml = \OwnPay\Service\PaginationService::render($page, $total_records, $show_limit, $offset);
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

        if ($action == "balance-verification-bulk-action") {
            if ($global_user_login == true) {
                if (!PermissionGuard::canAccess($ctx, 'device')) {
                    echo json_encode(['status' => 'false', 'title' => 'Access denied', 'message' => 'You need permission to perform this action. Please contact the admin.', 'csrf_token' => $new_csrf_token]);
                    exit();
                }

                $actionID = $request->post('actionID', '');
                $selected_ids_json = $request->post('selected_ids', '[]');
                $selected_ids = json_decode($selected_ids_json, true);

                if (!empty($selected_ids)) {
                    foreach ($selected_ids as $id) {
                        $itemID = InputSanitizer::trim($id);
                        $params_item = [':id' => $itemID];

                        $response_brand = CrudService::select($db_prefix . 'balance_verification', 'WHERE id = :id', '* FROM', $params_item);
                        if ($response_brand['status'] == true) {
                            if ($actionID == "deleted") {
                                if (PermissionGuard::has($ctx, 'device', 'balance_verification_for')) {

                                    $condition = "id = :itemID";
                                    $whereParams = [':itemID' => $itemID];

                                    CrudService::delete($db_prefix . 'balance_verification', $condition, $whereParams);

                                }
                            }

                            if ($actionID == "activated") {
                                if (PermissionGuard::has($ctx, 'device', 'balance_verification_for')) {

                                    $columns = ['status', 'updated_date'];
                                    $values = ['active', getCurrentDatetime('Y-m-d H:i:s')];
                                    $condition = "id = :id";
                                    $whereParams = [':id' => $itemID];

                                    CrudService::update($db_prefix . 'balance_verification', $columns, $values, $condition, $whereParams);

                                }
                            }

                            if ($actionID == "inactivated") {
                                if (PermissionGuard::has($ctx, 'device', 'balance_verification_for')) {

                                    $columns = ['status', 'updated_date'];
                                    $values = ['inactive', getCurrentDatetime('Y-m-d H:i:s')];
                                    $condition = "id = :id";
                                    $whereParams = [':id' => $itemID];

                                    CrudService::update($db_prefix . 'balance_verification', $columns, $values, $condition, $whereParams);

                                }
                            }
                        }
                    }

                    echo json_encode(['status' => 'true', 'title' => 'Balance verifications ' . $actionID, 'message' => 'The selected balance verifications have been ' . $actionID . ' successfully.', 'csrf_token' => $new_csrf_token]);
                } else {
                    echo json_encode(['status' => 'false', 'title' => 'Request Failed', 'message' => 'No balance verifications selected.', 'csrf_token' => $new_csrf_token]);
                }
            } else {
                echo json_encode(['status' => 'false', 'title' => 'Request Failed', 'message' => 'Invalid request', 'csrf_token' => $new_csrf_token]);
            }
        }

        if ($action == "balance-verification-delete") {
            if ($global_user_login == true) {
                if (!PermissionGuard::canAccess($ctx, 'device') || !PermissionGuard::has($ctx, 'device', 'balance_verification_for')) {
                    echo json_encode(['status' => 'false', 'title' => 'Access denied', 'message' => 'You need permission to perform this action. Please contact the admin.', 'csrf_token' => $new_csrf_token]);
                    exit();
                }

                $ItemID = $request->post('ItemID', '');
                $params_item = [':id' => $ItemID];

                $response_brand = CrudService::select($db_prefix . 'balance_verification', 'WHERE id = :id', '* FROM', $params_item);
                if ($response_brand['status'] == true) {
                    $condition = "id = :id";
                    $whereParams = [':id' => $ItemID];

                    CrudService::delete($db_prefix . 'balance_verification', $condition, $whereParams);
                }

                echo json_encode(['status' => 'true', 'title' => 'Balance Verification Deleted', 'message' => 'The selected balance verification have been deleted successfully.', 'csrf_token' => $new_csrf_token]);
            } else {
                echo json_encode(['status' => 'false', 'title' => 'Request Failed', 'message' => 'Invalid request', 'csrf_token' => $new_csrf_token]);
            }
        }

        if ($action == "balance-verification-create") {
            if ($global_user_login == true) {
                if (!PermissionGuard::canAccess($ctx, 'device') || !PermissionGuard::has($ctx, 'device', 'balance_verification_for')) {
                    echo json_encode(['status' => 'false', 'title' => 'Access denied', 'message' => 'You need permission to perform this action. Please contact the admin.', 'csrf_token' => $new_csrf_token]);
                    exit();
                }

                $d_id = $request->post('d_id', '');
                $sender_key = $request->post('sender_key', '');
                $payment_type = $request->post('payment_type', '');
                $simslot = $request->post('simslot', '');
                $current_balance = $request->post('current_balance', '');
                $balance_verification_status = $request->post('balance_verification_status', '');

                if ($sender_key == "" || $payment_type == "" || $simslot == "" || $current_balance == "" || $balance_verification_status == "") {
                    echo json_encode(['status' => "false", 'title' => 'Incomplete Information', 'message' => 'Please fill in all required fields before proceeding.', 'csrf_token' => $new_csrf_token]);
                } else {
                    if ($balance_verification_status == "active" || $balance_verification_status == "inactive") {

                    } else {
                        echo json_encode(['status' => "false", 'title' => 'Incomplete Information', 'message' => 'Please fill in all required fields before proceeding.', 'csrf_token' => $new_csrf_token]);
                        exit();
                    }

                    $params_dev = [':d_id' => $d_id, ':status' => 'used'];
                    $response = CrudService::select($db_prefix . 'device', 'WHERE device_id = :d_id AND status = :status', '* FROM', $params_dev);
                    if ($response['status'] == true) {
                        $params_check = [':d_id' => $d_id, ':sender_key' => $sender_key, ':type' => $payment_type];
                        $responseCheck = CrudService::select($db_prefix . 'balance_verification', 'WHERE device_id = :d_id AND sender_key = :sender_key AND type = :type', '* FROM', $params_check);
                        if ($responseCheck['status'] == true) {
                            echo json_encode(['status' => 'false', 'title' => 'Duplicate Entry', 'message' => 'A record with this info already exists.', 'csrf_token' => $new_csrf_token]);
                            exit();
                        }

                        $columns = ['device_id', 'sender_key', 'type', 'current_balance', 'simslot', 'status', 'created_date', 'updated_date'];
                        $values = [$d_id, $sender_key, $payment_type, money_sanitize($current_balance), $simslot, $balance_verification_status, getCurrentDatetime('Y-m-d H:i:s'), getCurrentDatetime('Y-m-d H:i:s')];

                        CrudService::insert($db_prefix . 'balance_verification', $columns, $values);

                        echo json_encode(['status' => 'true', 'title' => 'Balance Verification Created', 'message' => 'The balance verification has been created successfully.', 'csrf_token' => $new_csrf_token]);
                    } else {
                        echo json_encode(['status' => 'false', 'title' => 'Request Failed', 'message' => 'Invalid request', 'csrf_token' => $new_csrf_token]);
                    }
                }
            } else {
                echo json_encode(['status' => 'false', 'title' => 'Request Failed', 'message' => 'Invalid request', 'csrf_token' => $new_csrf_token]);
            }
        }

        if ($action == "balance-verification-iupdate") {
            if ($global_user_login == true) {
                if (!PermissionGuard::canAccess($ctx, 'device') || !PermissionGuard::has($ctx, 'device', 'balance_verification_for')) {
                    echo json_encode(['status' => 'false', 'title' => 'Access denied', 'message' => 'You need permission to perform this action. Please contact the admin.', 'csrf_token' => $new_csrf_token]);
                    exit();
                }

                $ItemID = $request->post('ItemID', '');
                $balance = $request->post('balance', '');
                $params_item = [':id' => $ItemID];

                $response_brand = CrudService::select($db_prefix . 'balance_verification', 'WHERE id = :id', '* FROM', $params_item);
                if ($response_brand['status'] == true) {
                    if ($balance == "") {
                        $balance = 0;
                    }

                    $columns = ['current_balance', 'updated_date'];
                    $values = [money_sanitize($balance), getCurrentDatetime('Y-m-d H:i:s')];
                    $condition = "id = :id";
                    $whereParams = [':id' => $ItemID];

                    CrudService::update($db_prefix . 'balance_verification', $columns, $values, $condition, $whereParams);
                }

                echo json_encode(['status' => 'true', 'title' => 'Balance Verification Updated', 'message' => 'The selected balance verification have been updated successfully.', 'csrf_token' => $new_csrf_token]);
            } else {
                echo json_encode(['status' => 'false', 'title' => 'Request Failed', 'message' => 'Invalid request', 'csrf_token' => $new_csrf_token]);
            }
        }

        if ($action == "balance-verification-info-byID") {
            if ($global_user_login == true) {
                if (!PermissionGuard::canAccess($ctx, 'device') || !PermissionGuard::has($ctx, 'device', 'balance_verification_for')) {
                    echo json_encode(['status' => 'false', 'title' => 'Access denied', 'message' => 'You need permission to perform this action. Please contact the admin.', 'csrf_token' => $new_csrf_token]);
                    exit();
                }

                $ItemID = $request->post('ItemID', '');
                $params_item = [':id' => $ItemID];

                $response_brand = CrudService::select($db_prefix . 'balance_verification', 'WHERE id = :id', '* FROM', $params_item);
                if ($response_brand['status'] == true) {
                    echo json_encode(['status' => 'true', 'sender_key' => $response_brand['response'][0]['sender_key'], 'type' => $response_brand['response'][0]['type'], 'current_balance' => money_round($response_brand['response'][0]['current_balance'], 2), 'simslot' => $response_brand['response'][0]['simslot'], 'istatus' => $response_brand['response'][0]['status'], 'csrf_token' => $new_csrf_token]);
                } else {
                    echo json_encode(['status' => 'false', 'title' => 'Request Failed', 'message' => 'Invalid request', 'csrf_token' => $new_csrf_token]);
                }
            } else {
                echo json_encode(['status' => 'false', 'title' => 'Request Failed', 'message' => 'Invalid request', 'csrf_token' => $new_csrf_token]);
            }
        }

        if ($action == "balance-verification-update") {
            if ($global_user_login == true) {
                if (!PermissionGuard::canAccess($ctx, 'device') || !PermissionGuard::has($ctx, 'device', 'balance_verification_for')) {
                    echo json_encode(['status' => 'false', 'title' => 'Access denied', 'message' => 'You need permission to perform this action. Please contact the admin.', 'csrf_token' => $new_csrf_token]);
                    exit();
                }

                $itemID = $request->post('itemID', '');
                $sender_key = $request->post('sender_key', '');
                $payment_type = $request->post('payment_type', '');
                $simslot = $request->post('simslot', '');
                $current_balance = $request->post('current_balance', '');
                $balance_verification_status = $request->post('balance_verification_status', '');

                if ($sender_key == "" || $payment_type == "" || $simslot == "" || $current_balance == "" || $balance_verification_status == "") {
                    echo json_encode(['status' => "false", 'title' => 'Incomplete Information', 'message' => 'Please fill in all required fields before proceeding.', 'csrf_token' => $new_csrf_token]);
                } else {
                    if ($balance_verification_status == "active" || $balance_verification_status == "inactive") {

                    } else {
                        echo json_encode(['status' => "false", 'title' => 'Incomplete Information', 'message' => 'Please fill in all required fields before proceeding.', 'csrf_token' => $new_csrf_token]);
                        exit();
                    }

                    $params_item = [':id' => $itemID];
                    $response = CrudService::select($db_prefix . 'balance_verification', 'WHERE id = :id', '* FROM', $params_item);
                    if ($response['status'] == true) {
                        $params_check = [':d_id' => $response['response'][0]['device_id'], ':sender_key' => $sender_key, ':type' => $payment_type];
                        $responseCheck = CrudService::select($db_prefix . 'balance_verification', 'WHERE device_id = :d_id AND sender_key = :sender_key AND type = :type', '* FROM', $params_check);
                        if ($responseCheck['status'] == true) {
                            if ($responseCheck['response'][0]['id'] == $itemID) {

                            } else {
                                echo json_encode(['status' => 'false', 'title' => 'Duplicate Entry', 'message' => 'A record with this info already exists.', 'csrf_token' => $new_csrf_token]);
                                exit();
                            }
                        }

                        $columns = ['sender_key', 'type', 'current_balance', 'simslot', 'status', 'updated_date'];
                        $values = [$sender_key, $payment_type, money_sanitize($current_balance), $simslot, $balance_verification_status, getCurrentDatetime('Y-m-d H:i:s')];

                        $condition = "id = :id";
                        $whereParams = [':id' => $itemID];

                        CrudService::update($db_prefix . 'balance_verification', $columns, $values, $condition, $whereParams);

                        echo json_encode(['status' => 'true', 'title' => 'Balance Verification Updated', 'message' => 'The balance verification has been updated successfully.', 'csrf_token' => $new_csrf_token]);
                    } else {
                        echo json_encode(['status' => 'false', 'title' => 'Request Failed', 'message' => 'Invalid request', 'csrf_token' => $new_csrf_token]);
                    }
                }
            } else {
                echo json_encode(['status' => 'false', 'title' => 'Request Failed', 'message' => 'Invalid request', 'csrf_token' => $new_csrf_token]);
            }
        }

    }
}
