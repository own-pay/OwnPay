<?php
declare(strict_types=1);

namespace AnirbanPay\Controller;

use AnirbanPay\Http\RequestContext;

class ApiKeyController
{
    public static function handle(string $action, RequestContext $ctx): void
    {
        $global_user_login = $ctx->isLoggedIn;
        $global_response_permission = $ctx->permissionResponse;
        $global_user_response = $ctx->userResponse;
        $new_csrf_token = $ctx->csrfToken;
        $db_prefix = $ctx->dbPrefix;
        $global_response_brand = $ctx->brandResponse;

        $request = \AnirbanPay\Http\Request::createFromGlobals();

        // Inline extracted code from adapter.php
        if ($action == "api-create") {
            if ($global_user_login == true) {
                if (!canAccessPage(json_decode($global_response_permission['response'][0]['permission'], true), 'brand_settings', $global_user_response['response'][0]['role'])) {
                    echo json_encode(['status' => 'false', 'title' => 'Access denied', 'message' => 'You need permission to perform this action. Please contact the admin.', 'csrf_token' => $new_csrf_token]);
                    exit();
                }

                if (!hasPermission(json_decode($global_response_permission['response'][0]['permission'], true), 'api_settings', 'view', $global_user_response['response'][0]['role'])) {
                    echo json_encode(['status' => 'false', 'title' => 'Access denied', 'message' => 'You need permission to perform this action. Please contact the admin.', 'csrf_token' => $new_csrf_token]);
                    exit();
                }

                if (!hasPermission(json_decode($global_response_permission['response'][0]['permission'], true), 'api_settings', 'create', $global_user_response['response'][0]['role'])) {
                    echo json_encode(['status' => 'false', 'title' => 'Access denied', 'message' => 'You need permission to perform this action. Please contact the admin.', 'csrf_token' => $new_csrf_token]);
                    exit();
                }

                $api_name = $request->post('api_name', '');
                $apiExpiryDate = $request->post('apiExpiryDate', '');
                $api_status = $request->post('api_status', '');
                $scopes = $request->post('scopes', []);

                if ($api_name == "") {
                    echo json_encode(['status' => "false", 'title' => 'Incomplete Information', 'message' => 'Please fill in all required fields before proceeding.', 'csrf_token' => $new_csrf_token]);
                } else {
                    $response = json_decode(getData($db_prefix . 'api', 'WHERE brand_id = :brand_id AND name = :name', '* FROM', [':brand_id' => $global_response_brand['response'][0]['brand_id'], ':name' => $api_name]), true);
                    if ($response['status'] == true) {
                        echo json_encode(['status' => 'false', 'title' => 'API Name Already Exists', 'message' => 'This API name is already in use. Please choose a different name.', 'csrf_token' => $new_csrf_token]);
                    } else {
                        if ($apiExpiryDate !== "") {
                            if (dateformat($apiExpiryDate, 'Y-m-d')) {

                            } else {
                                echo json_encode(['status' => "false", 'title' => 'Invalid expiry date format', 'message' => 'Please enter the expiry date in the correct format (DD/MM/YYYY).', 'csrf_token' => $new_csrf_token]);
                                exit();
                            }
                        } else {
                            $apiExpiryDate = "--";
                        }

                        $api_key = bin2hex(random_bytes(25));
                        $scopes_json = json_encode($scopes);

                        $columns = ['brand_id', 'name', 'api_key', 'expired_date', 'status', 'api_scopes', 'created_date', 'updated_date'];
                        $values = [$global_response_brand['response'][0]['brand_id'], $api_name, $api_key, $apiExpiryDate, $api_status, $scopes_json, getCurrentDatetime('Y-m-d H:i:s'), getCurrentDatetime('Y-m-d H:i:s')];

                        insertData($db_prefix . 'api', $columns, $values);

                        echo json_encode(['status' => 'true', 'title' => 'Api Created', 'message' => 'The api has been created successfully.', 'csrf_token' => $new_csrf_token]);
                    }
                }
            } else {
                echo json_encode(['status' => 'false', 'title' => 'Request Failed', 'message' => 'Invalid request', 'csrf_token' => $new_csrf_token]);
            }
        }

        if ($action == "api-list") {
            if ($global_user_login == true) {
                if (!canAccessPage(json_decode($global_response_permission['response'][0]['permission'], true), 'brand_settings', $global_user_response['response'][0]['role'])) {
                    echo json_encode(['status' => 'false', 'title' => 'Access denied', 'message' => 'You need permission to perform this action. Please contact the admin.', 'csrf_token' => $new_csrf_token]);
                    exit();
                }

                if (!hasPermission(json_decode($global_response_permission['response'][0]['permission'], true), 'api_settings', 'view', $global_user_response['response'][0]['role'])) {
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
                $params_api = [':brand_id' => $global_response_brand['response'][0]['brand_id']];

                if ($filter_start !== '') {
                    $where[] = "created_date >= :filter_start";
                    $params_api[':filter_start'] = "{$filter_start} 00:00:00";
                }

                if ($filter_end !== '') {
                    $where[] = "created_date <= :filter_end";
                    $params_api[':filter_end'] = "{$filter_end} 23:59:59";
                }

                if ($filter_status !== '') {
                    $where[] = "status = :filter_status";
                    $params_api[':filter_status'] = $filter_status;
                }

                $where_sql = $where ? implode(' AND ', $where) . ' AND ' : '';
                /* Filters */

                $page = max(1, (int) $request->post('page', '1'));
                $show_limit_val = ($request->post('show_limit') == '') ? 999999 : (int) $request->post('show_limit');
                $offset = ($page - 1) * $show_limit_val;
                $show_limit = $show_limit_val;

                $sql_query = '';

                if ($search_input !== '') {
                    $sql_query .= " AND ( name LIKE :search )";
                    $params_api[':search'] = "%$search_input%";
                }

                $sql_limit = '';
                if ($show_limit_val == 'all') {

                } else {
                    $sql_limit = " LIMIT $offset, $show_limit_val";
                }

                $response_result = json_decode(getData($db_prefix . 'api', ' WHERE ' . $where_sql . ' brand_id = :brand_id ' . $sql_query . ' ORDER BY 1 DESC ' . $sql_limit, '* FROM', $params_api), true);
                if ($response_result['status'] == true) {
                    $response = [];

                    foreach ($response_result['response'] as $row) {
                        if ($row['expired_date'] == "--") {
                            $status = $row['status'];
                        } else {
                            if (isExpired($row['expired_date'])) {
                                $status = 'expired';
                            } else {
                                $status = $row['status'];
                            }
                        }

                        $response[] = [
                            "id" => $row['id'],
                            "name" => $row['name'],
                            "api_key" => $row['api_key'],
                            "expired_date" => $row['expired_date'],
                            "status" => $status,
                            "created_date" => convertUTCtoUserTZ($row['created_date'], ($global_response_brand['response'][0]['timezone'] === '--' || $global_response_brand['response'][0]['timezone'] === '') ? 'Asia/Dhaka' : $global_response_brand['response'][0]['timezone'], "M d, Y h:i A"),
                            "updated_date" => convertUTCtoUserTZ($row['updated_date'], ($global_response_brand['response'][0]['timezone'] === '--' || $global_response_brand['response'][0]['timezone'] === '') ? 'Asia/Dhaka' : $global_response_brand['response'][0]['timezone'], "M d, Y h:i A")
                        ];
                    }

                    $count_data = json_decode(getData($db_prefix . 'api', ' WHERE ' . $where_sql . ' brand_id = :brand_id ' . $sql_query, '* FROM', $params_api), true);

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

        if ($action == "api-info-byID") {
            if ($global_user_login == true) {
                if (!canAccessPage(json_decode($global_response_permission['response'][0]['permission'], true), 'brand_settings', $global_user_response['response'][0]['role'])) {
                    echo json_encode(['status' => 'false', 'title' => 'Access denied', 'message' => 'You need permission to perform this action. Please contact the admin.', 'csrf_token' => $new_csrf_token]);
                    exit();
                }

                if (!hasPermission(json_decode($global_response_permission['response'][0]['permission'], true), 'api_settings', 'view', $global_user_response['response'][0]['role'])) {
                    echo json_encode(['status' => 'false', 'title' => 'Access denied', 'message' => 'You need permission to perform this action. Please contact the admin.', 'csrf_token' => $new_csrf_token]);
                    exit();
                }

                if (!hasPermission(json_decode($global_response_permission['response'][0]['permission'], true), 'api_settings', 'edit', $global_user_response['response'][0]['role'])) {
                    echo json_encode(['status' => 'false', 'title' => 'Access denied', 'message' => 'You need permission to perform this action. Please contact the admin.', 'csrf_token' => $new_csrf_token]);
                    exit();
                }

                $ItemID = $request->post('ItemID', '');

                $response_brand = json_decode(getData($db_prefix . 'api', 'WHERE id = :ItemID AND brand_id = :brand_id', '* FROM', [':ItemID' => $ItemID, ':brand_id' => $global_response_brand['response'][0]['brand_id']]), true);
                if ($response_brand['status'] == true) {
                    echo json_encode(['status' => 'true', 'name' => $response_brand['response'][0]['name'], 'expired_date' => $response_brand['response'][0]['expired_date'], 'api_scopes' => json_decode($response_brand['response'][0]['api_scopes'], true), 'astatus' => $response_brand['response'][0]['status'], 'csrf_token' => $new_csrf_token]);
                } else {
                    echo json_encode(['status' => 'false', 'title' => 'Request Failed', 'message' => 'Invalid request', 'csrf_token' => $new_csrf_token]);
                }
            } else {
                echo json_encode(['status' => 'false', 'title' => 'Request Failed', 'message' => 'Invalid request', 'csrf_token' => $new_csrf_token]);
            }
        }

        if ($action == "api-bulk-action") {
            if ($global_user_login == true) {
                if (!canAccessPage(json_decode($global_response_permission['response'][0]['permission'], true), 'brand_settings', $global_user_response['response'][0]['role'])) {
                    echo json_encode(['status' => 'false', 'title' => 'Access denied', 'message' => 'You need permission to perform this action. Please contact the admin.', 'csrf_token' => $new_csrf_token]);
                    exit();
                }

                if (!hasPermission(json_decode($global_response_permission['response'][0]['permission'], true), 'api_settings', 'view', $global_user_response['response'][0]['role'])) {
                    echo json_encode(['status' => 'false', 'title' => 'Access denied', 'message' => 'You need permission to perform this action. Please contact the admin.', 'csrf_token' => $new_csrf_token]);
                    exit();
                }

                $actionID = $request->post('actionID', '');
                $selected_ids_json = $request->post('selected_ids', '[]');
                $selected_ids = json_decode($selected_ids_json, true);

                if (!empty($selected_ids)) {
                    foreach ($selected_ids as $id) {
                        $itemID = escape_string($id);

                        $response_brand = json_decode(getData($db_prefix . 'api', 'WHERE id = :itemID AND brand_id = :brand_id', '* FROM', [':itemID' => $itemID, ':brand_id' => $global_response_brand['response'][0]['brand_id']]), true);
                        if ($response_brand['status'] == true) {
                            if ($actionID == "deleted") {
                                if (hasPermission(json_decode($global_response_permission['response'][0]['permission'], true), 'api_settings', 'delete', $global_user_response['response'][0]['role'])) {

                                    $condition = "id = :itemID";
                                    $whereParams = [':itemID' => $itemID];

                                    deleteData($db_prefix . 'api', $condition, $whereParams);

                                }
                            }

                            if ($actionID == "activated") {
                                if (hasPermission(json_decode($global_response_permission['response'][0]['permission'], true), 'api_settings', 'edit', $global_user_response['response'][0]['role'])) {

                                    $columns = ['status', 'updated_date'];
                                    $values = ['active', getCurrentDatetime('Y-m-d H:i:s')];
                                    $condition = "id = :itemID";
                                    $whereParams = [':itemID' => $itemID];

                                    updateData($db_prefix . 'api', $columns, $values, $condition, $whereParams);

                                }
                            }

                            if ($actionID == "inactivated") {
                                if (hasPermission(json_decode($global_response_permission['response'][0]['permission'], true), 'api_settings', 'edit', $global_user_response['response'][0]['role'])) {

                                    $columns = ['status', 'updated_date'];
                                    $values = ['inactive', getCurrentDatetime('Y-m-d H:i:s')];
                                    $condition = "id = :itemID";
                                    $whereParams = [':itemID' => $itemID];

                                    updateData($db_prefix . 'api', $columns, $values, $condition, $whereParams);

                                }
                            }
                        }
                    }

                    echo json_encode(['status' => 'true', 'title' => 'Api Key ' . $actionID, 'message' => 'The selected api key have been ' . $actionID . ' successfully.', 'csrf_token' => $new_csrf_token]);
                } else {
                    echo json_encode(['status' => 'false', 'title' => 'Api Key Failed', 'message' => 'No api selected.', 'csrf_token' => $new_csrf_token]);
                }
            } else {
                echo json_encode(['status' => 'false', 'title' => 'Request Failed', 'message' => 'Invalid request', 'csrf_token' => $new_csrf_token]);
            }
        }

        if ($action == "api-delete") {
            if ($global_user_login == true) {
                if (!canAccessPage(json_decode($global_response_permission['response'][0]['permission'], true), 'brand_settings', $global_user_response['response'][0]['role'])) {
                    echo json_encode(['status' => 'false', 'title' => 'Access denied', 'message' => 'You need permission to perform this action. Please contact the admin.', 'csrf_token' => $new_csrf_token]);
                    exit();
                }

                if (!hasPermission(json_decode($global_response_permission['response'][0]['permission'], true), 'api_settings', 'view', $global_user_response['response'][0]['role'])) {
                    echo json_encode(['status' => 'false', 'title' => 'Access denied', 'message' => 'You need permission to perform this action. Please contact the admin.', 'csrf_token' => $new_csrf_token]);
                    exit();
                }

                if (!hasPermission(json_decode($global_response_permission['response'][0]['permission'], true), 'api_settings', 'delete', $global_user_response['response'][0]['role'])) {
                    echo json_encode(['status' => 'false', 'title' => 'Access denied', 'message' => 'You need permission to perform this action. Please contact the admin.', 'csrf_token' => $new_csrf_token]);
                    exit();
                }

                $ItemID = $request->post('ItemID', '');

                $response_brand = json_decode(getData($db_prefix . 'api', 'WHERE id = :ItemID AND brand_id = :brand_id', '* FROM', [':ItemID' => $ItemID, ':brand_id' => $global_response_brand['response'][0]['brand_id']]), true);
                if ($response_brand['status'] == true) {
                    $condition = "id = :ItemID";
                    $whereParams = [':ItemID' => $ItemID];

                    deleteData($db_prefix . 'api', $condition, $whereParams);
                }

                echo json_encode(['status' => 'true', 'title' => 'Api Key Deleted', 'message' => 'The selected api key have been deleted successfully.', 'csrf_token' => $new_csrf_token]);
            } else {
                echo json_encode(['status' => 'false', 'title' => 'Request Failed', 'message' => 'Invalid request', 'csrf_token' => $new_csrf_token]);
            }
        }


        if ($action == "api-edit") {
            if ($global_user_login == true) {
                if (!canAccessPage(json_decode($global_response_permission['response'][0]['permission'], true), 'brand_settings', $global_user_response['response'][0]['role'])) {
                    echo json_encode(['status' => 'false', 'title' => 'Access denied', 'message' => 'You need permission to perform this action. Please contact the admin.', 'csrf_token' => $new_csrf_token]);
                    exit();
                }

                if (!hasPermission(json_decode($global_response_permission['response'][0]['permission'], true), 'api_settings', 'view', $global_user_response['response'][0]['role'])) {
                    echo json_encode(['status' => 'false', 'title' => 'Access denied', 'message' => 'You need permission to perform this action. Please contact the admin.', 'csrf_token' => $new_csrf_token]);
                    exit();
                }

                if (!hasPermission(json_decode($global_response_permission['response'][0]['permission'], true), 'api_settings', 'edit', $global_user_response['response'][0]['role'])) {
                    echo json_encode(['status' => 'false', 'title' => 'Access denied', 'message' => 'You need permission to perform this action. Please contact the admin.', 'csrf_token' => $new_csrf_token]);
                    exit();
                }

                $api_id = $request->post('api_id', '');
                $api_name = $request->post('api_name', '');
                $apiExpiryDate = $request->post('apiExpiryDate', '');
                $api_status = $request->post('api_status', '');
                $scopes = $request->post('scopes', []);

                if ($api_name == "" || $api_status == "") {
                    echo json_encode(['status' => "false", 'title' => 'Incomplete Information', 'message' => 'Please fill in all required fields before proceeding.', 'csrf_token' => $new_csrf_token]);
                } else {
                    $responseApi = json_decode(getData($db_prefix . 'api', 'WHERE brand_id = :brand_id AND id = :api_id', '* FROM', [':brand_id' => $global_response_brand['response'][0]['brand_id'], ':api_id' => $api_id]), true);
                    if ($responseApi['status'] == true) {
                        $response = json_decode(getData($db_prefix . 'api', 'WHERE brand_id = :brand_id AND name = :name', '* FROM', [':brand_id' => $global_response_brand['response'][0]['brand_id'], ':name' => $api_name]), true);
                        if ($response['status'] == true) {
                            if ($response['response'][0]['id'] == $api_id) {

                            } else {
                                echo json_encode(['status' => 'false', 'title' => 'API Name Already Exists', 'message' => 'This API name is already in use. Please choose a different name.', 'csrf_token' => $new_csrf_token]);
                                exit();
                            }
                        }

                        if ($apiExpiryDate !== "") {
                            if (dateformat($apiExpiryDate, 'Y-m-d')) {

                            } else {
                                echo json_encode(['status' => "false", 'title' => 'Invalid expiry date format', 'message' => 'Please enter the expiry date in the correct format (DD/MM/YYYY).', 'csrf_token' => $new_csrf_token]);
                                exit();
                            }
                        } else {
                            $apiExpiryDate = "--";
                        }

                        $scopes_json = json_encode($scopes);

                        $columns = ['name', 'expired_date', 'status', 'api_scopes', 'updated_date'];
                        $values = [$api_name, $apiExpiryDate, $api_status, $scopes_json, getCurrentDatetime('Y-m-d H:i:s')];

                        $condition = "id = :api_id";
                        $whereParams = [':api_id' => $api_id];

                        updateData($db_prefix . 'api', $columns, $values, $condition, $whereParams);

                        echo json_encode(['status' => 'true', 'title' => 'Api Updated', 'message' => 'The api has been updated successfully.', 'csrf_token' => $new_csrf_token]);
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
