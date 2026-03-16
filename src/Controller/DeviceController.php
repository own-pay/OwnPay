<?php
declare(strict_types=1);

namespace AnirbanPay\Controller;

use AnirbanPay\Http\RequestContext;

class DeviceController
{
    public static function handle(string $action, RequestContext $ctx): void
    {
        $global_user_login = $ctx->isLoggedIn;
        $global_response_permission = $ctx->permissionResponse;
        $global_user_response = $ctx->userResponse;
        $new_csrf_token = $ctx->csrfToken;
        $db_prefix = $ctx->dbPrefix;
        $global_response_brand = $ctx->brandResponse;
        $ap_admin = $ctx->isAdmin();

        $request = \AnirbanPay\Http\Request::createFromGlobals();

        // Inline extracted code from adapter.php
        if ($action == "device-list") {
            if ($global_user_login == true) {
                if (!canAccessPage(json_decode($global_response_permission['response'][0]['permission'], true), 'device', $global_user_response['response'][0]['role'])) {
                    echo json_encode(['status' => 'false', 'title' => 'Access denied', 'message' => 'You need permission to perform this action. Please contact the admin.', 'csrf_token' => $new_csrf_token]);
                    exit();
                }

                $search_input = $request->post('search_input', '');
                $show_limit_raw = $request->post('show_limit', '5');

                /* Filters */
                $filter_status = $request->post('filter_status', '');
                $filter_start = $request->post('filter_start', '');
                $filter_end = $request->post('filter_end', '');

                $where = [];
                $params_device = [':status_used' => 'used'];

                if ($filter_start !== '') {
                    $where[] = "created_date >= :filter_start";
                    $params_device[':filter_start'] = "{$filter_start} 00:00:00";
                }

                if ($filter_end !== '') {
                    $where[] = "created_date <= :filter_end";
                    $params_device[':filter_end'] = "{$filter_end} 23:59:59";
                }

                if ($filter_status !== '') {
                    if ($filter_status === 'connected') {
                        $where[] = "updated_date >= (NOW() - INTERVAL 6 MINUTE)";
                    } elseif ($filter_status === 'disconnected') {
                        $where[] = "updated_date < (NOW() - INTERVAL 6 MINUTE)";
                    }
                }

                $where_sql = $where ? implode(' AND ', $where) . ' AND ' : '';
                /* Filters */


                $page = max(1, (int) $request->post('page', '1'));
                $show_limit_val = ($request->post('show_limit') == '') ? 999999 : (int) $request->post('show_limit');
                $offset = ($page - 1) * $show_limit_val;
                $show_limit = $show_limit_val;

                $sql_query = '';

                if ($search_input !== '') {
                    $sql_query .= " AND ( name LIKE :search OR model LIKE :search OR android_level LIKE :search )";
                    $params_device[':search'] = "%$search_input%";
                }

                $sql_limit = '';
                if ($show_limit_val == 'all') {

                } else {
                    $sql_limit = " LIMIT $offset, $show_limit_val";
                }

                $response_result = json_decode(getData($db_prefix . 'device', ' WHERE ' . $where_sql . ' status = :status_used ' . $sql_query . ' ORDER BY 1 DESC ' . $sql_limit, '* FROM', $params_device), true);
                if ($response_result['status'] == true) {
                    $response = [];

                    foreach ($response_result['response'] as $row) {
                        $response[] = [
                            "id" => $row['device_id'],
                            "name" => $row['name'],
                            "model" => $row['model'],
                            "android_level" => $row['android_level'],
                            "status" => $row['status'],
                            "created_date" => convertUTCtoUserTZ($row['created_date'], ($global_response_brand['response'][0]['timezone'] === '--' || $global_response_brand['response'][0]['timezone'] === '') ? 'Asia/Dhaka' : $global_response_brand['response'][0]['timezone'], "M d, Y h:i A"),
                            "updated_date" => convertUTCtoUserTZ($row['updated_date'], ($global_response_brand['response'][0]['timezone'] === '--' || $global_response_brand['response'][0]['timezone'] === '') ? 'Asia/Dhaka' : $global_response_brand['response'][0]['timezone'], "M d, Y h:i A"),
                            "last_sync" => ($row['last_sync'] == "--") ? '' : convertUTCtoUserTZ($row['last_sync'], ($global_response_brand['response'][0]['timezone'] === '--' || $global_response_brand['response'][0]['timezone'] === '') ? 'Asia/Dhaka' : $global_response_brand['response'][0]['timezone'], "M d, Y h:i A")
                        ];
                    }

                    $count_data = json_decode(getData($db_prefix . 'device', ' WHERE ' . $where_sql . ' status = :status_used ' . $sql_query, '* FROM', $params_device), true);


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

        if ($action == "device-delete") {
            if ($global_user_login == true) {
                if (!canAccessPage(json_decode($global_response_permission['response'][0]['permission'], true), 'device', $global_user_response['response'][0]['role'])) {
                    echo json_encode(['status' => 'false', 'title' => 'Access denied', 'message' => 'You need permission to perform this action. Please contact the admin.', 'csrf_token' => $new_csrf_token]);
                    exit();
                }

                if (!hasPermission(json_decode($global_response_permission['response'][0]['permission'], true), 'device', 'delete', $global_user_response['response'][0]['role'])) {
                    echo json_encode(['status' => 'false', 'title' => 'Access denied', 'message' => 'You need permission to perform this action. Please contact the admin.', 'csrf_token' => $new_csrf_token]);
                    exit();
                }

                $ItemID = $request->post('ItemID', '');
                $params_item = [':id' => $ItemID];

                $response_brand = json_decode(getData($db_prefix . 'device', 'WHERE device_id = :id', '* FROM', $params_item), true);
                if ($response_brand['status'] == true) {
                    $condition = "device_id = :id";
                    $whereParams = [':id' => $ItemID];

                    deleteData($db_prefix . 'device', $condition, $whereParams);

                    $condition = "device_id = :id";

                    deleteData($db_prefix . 'balance_verification', $condition, $whereParams);
                }

                echo json_encode(['status' => 'true', 'title' => 'Device Deleted', 'message' => 'The selected device have been deleted successfully.', 'csrf_token' => $new_csrf_token]);
            } else {
                echo json_encode(['status' => 'false', 'title' => 'Request Failed', 'message' => 'Invalid request', 'csrf_token' => $new_csrf_token]);
            }
        }

        if ($action == "device-bulk-action") {
            if ($global_user_login == true) {
                if (!canAccessPage(json_decode($global_response_permission['response'][0]['permission'], true), 'device', $global_user_response['response'][0]['role'])) {
                    echo json_encode(['status' => 'false', 'title' => 'Access denied', 'message' => 'You need permission to perform this action. Please contact the admin.', 'csrf_token' => $new_csrf_token]);
                    exit();
                }

                $actionID = $request->post('actionID', '');
                $selected_ids_json = $request->post('selected_ids', '[]');
                $selected_ids = json_decode($selected_ids_json, true);

                if (!empty($selected_ids)) {
                    foreach ($selected_ids as $id) {
                        $itemID = escape_string($id);
                        $params_item = [':id' => $itemID];

                        $response_brand = json_decode(getData($db_prefix . 'device', 'WHERE device_id = :id', '* FROM', $params_item), true);
                        if ($response_brand['status'] == true) {
                            if ($actionID == "deleted") {
                                if (hasPermission(json_decode($global_response_permission['response'][0]['permission'], true), 'device', 'delete', $global_user_response['response'][0]['role'])) {

                                    $condition = "device_id = :id";
                                    $whereParams = [':id' => $itemID];

                                    deleteData($db_prefix . 'device', $condition, $whereParams);

                                    $condition = "device_id = :id";

                                    deleteData($db_prefix . 'balance_verification', $condition, $whereParams);
                                }
                            }
                        }
                    }

                    echo json_encode(['status' => 'true', 'title' => 'Devices ' . $actionID, 'message' => 'The selected devices have been ' . $actionID . ' successfully.', 'csrf_token' => $new_csrf_token]);
                } else {
                    echo json_encode(['status' => 'false', 'title' => 'Request Failed', 'message' => 'No devices selected.', 'csrf_token' => $new_csrf_token]);
                }
            } else {
                echo json_encode(['status' => 'false', 'title' => 'Request Failed', 'message' => 'Invalid request', 'csrf_token' => $new_csrf_token]);
            }
        }

        if ($action == "device-connect-info") {
            if ($global_user_login == true) {
                if (!canAccessPage(json_decode($global_response_permission['response'][0]['permission'], true), 'device', $global_user_response['response'][0]['role'])) {
                    echo json_encode(['status' => 'false', 'title' => 'Access denied', 'message' => 'You need permission to perform this action. Please contact the admin.', 'csrf_token' => $new_csrf_token]);
                    exit();
                }

                if (!hasPermission(json_decode($global_response_permission['response'][0]['permission'], true), 'device', 'connect', $global_user_response['response'][0]['role'])) {
                    echo json_encode(['status' => 'false', 'title' => 'Access denied', 'message' => 'You need permission to perform this action. Please contact the admin.', 'csrf_token' => $new_csrf_token]);
                    exit();
                }

                $otp = generateItemID();
                $params_dev = [':status' => 'processing', ':d_id' => $ap_admin];

                $response_brand = json_decode(getData($db_prefix . 'device', 'WHERE status = :status AND d_id = :d_id', '* FROM', $params_dev), true);
                if ($response_brand['status'] == true) {
                    $columns = ['otp', 'updated_date'];
                    $values = [$otp, getCurrentDatetime('Y-m-d H:i:s')];
                    $condition = "id = :id";
                    $whereParams = [':id' => $response_brand['response'][0]['id']];

                    updateData($db_prefix . 'device', $columns, $values, $condition, $whereParams);

                    echo json_encode(['status' => 'true', 'otp' => $otp, 'csrf_token' => $new_csrf_token]);
                } else {
                    $device_id = generateItemID();

                    $columns = ['d_id', 'device_id', 'otp', 'created_date', 'updated_date'];
                    $values = [$ap_admin, $device_id, $otp, getCurrentDatetime('Y-m-d H:i:s'), getCurrentDatetime('Y-m-d H:i:s')];

                    insertData($db_prefix . 'device', $columns, $values);

                    echo json_encode(['status' => 'true', 'otp' => $otp, 'csrf_token' => $new_csrf_token]);
                }
            } else {
                echo json_encode(['status' => 'false', 'title' => 'Request Failed', 'message' => 'Invalid request', 'csrf_token' => $new_csrf_token]);
            }
        }

    }
}
