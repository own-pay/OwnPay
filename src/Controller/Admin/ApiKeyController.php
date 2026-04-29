<?php
declare(strict_types=1);

namespace OwnPay\Controller\Admin;

use OwnPay\Http\RequestContext;
use OwnPay\Service\System\CrudService;
use OwnPay\Service\System\InputSanitizer;
use OwnPay\Service\Auth\PermissionGuard;

class ApiKeyController
{
    public static function handle(string $action, RequestContext $ctx): void
    {
        $global_user_login = $ctx->isLoggedIn;
        $new_csrf_token = $ctx->csrfToken;
        $db_prefix = $ctx->dbPrefix;
        $global_response_brand = $ctx->brandResponse;

        $request = \OwnPay\Http\Request::createFromGlobals();

        // Inline extracted code from adapter.php
        if ($action == "api-create") {
            if ($global_user_login == true) {
                if (!PermissionGuard::canAccess($ctx, 'brand_settings') || !PermissionGuard::has($ctx, 'api_settings', 'view') || !PermissionGuard::has($ctx, 'api_settings', 'create')) {
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
                    $response = CrudService::select($db_prefix . 'api', 'WHERE brand_id = :brand_id AND name = :name', '* FROM', [':brand_id' => $global_response_brand['response'][0]['brand_id'], ':name' => $api_name]);
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
                            $apiExpiryDate = null;
                        }

                        $api_key = bin2hex(random_bytes(25));
                        $scopes_json = json_encode($scopes);

                        $columns = ['brand_id', 'name', 'api_key', 'expired_date', 'status', 'api_scopes', 'created_date', 'updated_date'];
                        $values = [$global_response_brand['response'][0]['brand_id'], $api_name, $api_key, $apiExpiryDate, $api_status, $scopes_json, getCurrentDatetime('Y-m-d H:i:s'), getCurrentDatetime('Y-m-d H:i:s')];

                        CrudService::insert($db_prefix . 'api', $columns, $values);

                        echo json_encode(['status' => 'true', 'title' => 'Api Created', 'message' => 'The api has been created successfully.', 'csrf_token' => $new_csrf_token]);
                    }
                }
            } else {
                echo json_encode(['status' => 'false', 'title' => 'Request Failed', 'message' => 'Invalid request', 'csrf_token' => $new_csrf_token]);
            }
        }

        if ($action == "api-list") {
            if ($global_user_login == true) {
                if (!PermissionGuard::canAccess($ctx, 'brand_settings') || !PermissionGuard::has($ctx, 'api_settings', 'view')) {
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

                $pag = \OwnPay\Service\System\PaginationService::resolve($request->post('page', '1'), $request->post('show_limit'));
                $page = $pag['page'];
                $show_limit_val = $pag['perPage'];
                $offset = $pag['offset'];
                $show_limit = $show_limit_val;

                $sql_query = '';

                if ($search_input !== '') {
                    $sql_query .= " AND ( name LIKE :search )";
                    $params_api[':search'] = "%$search_input%";
                }

                $sql_limit = '';
                if ($pag['isAll']) {

                } else {
                    $sql_limit = " LIMIT $offset, $show_limit_val";
                }

                $response_result = CrudService::select($db_prefix . 'api', ' WHERE ' . $where_sql . ' brand_id = :brand_id ' . $sql_query . ' ORDER BY 1 DESC ' . $sql_limit, '* FROM', $params_api);
                if ($response_result['status'] == true) {
                    $response = [];

                    foreach ($response_result['response'] as $row) {
                        if (empty($row['expired_date'])) {
                            $status = $row['status'];
                        } else {
                            if (isExpired($row['expired_date'])) {
                                $status = 'expired';
                            } else {
                                $status = $row['status'];
                            }
                        }

                        // Mask API key: show first 8 chars + masked remainder
                        $rawKey = $row['api_key'] ?? '';
                        $maskedKey = strlen($rawKey) > 8 ? substr($rawKey, 0, 8) . str_repeat('*', 24) : str_repeat('*', 32);

                        $response[] = [
                            "id" => $row['id'],
                            "name" => $row['name'],
                            "api_key" => $maskedKey,
                            "expired_date" => $row['expired_date'],
                            "status" => $status,
                            "created_date" => convertUTCtoUserTZ($row['created_date'], ($global_response_brand['response'][0]['timezone'] === null || $global_response_brand['response'][0]['timezone'] === '') ? 'Asia/Dhaka' : $global_response_brand['response'][0]['timezone'], "M d, Y h:i A"),
                            "updated_date" => convertUTCtoUserTZ($row['updated_date'], ($global_response_brand['response'][0]['timezone'] === null || $global_response_brand['response'][0]['timezone'] === '') ? 'Asia/Dhaka' : $global_response_brand['response'][0]['timezone'], "M d, Y h:i A")
                        ];
                    }

                    $count_data = CrudService::select($db_prefix . 'api', ' WHERE ' . $where_sql . ' brand_id = :brand_id ' . $sql_query, '* FROM', $params_api);

                    $total_records = count($count_data['response'] ?? []);
                    $pagHtml = \OwnPay\Service\System\PaginationService::render($page, $total_records, $show_limit, $offset);
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

        if ($action == "api-info-byID") {
            if ($global_user_login == true) {
                if (!PermissionGuard::canAccess($ctx, 'brand_settings') || !PermissionGuard::has($ctx, 'api_settings', 'view') || !PermissionGuard::has($ctx, 'api_settings', 'edit')) {
                    echo json_encode(['status' => 'false', 'title' => 'Access denied', 'message' => 'You need permission to perform this action. Please contact the admin.', 'csrf_token' => $new_csrf_token]);
                    exit();
                }

                $ItemID = $request->post('ItemID', '');

                $response_brand = CrudService::select($db_prefix . 'api', 'WHERE id = :ItemID AND brand_id = :brand_id', '* FROM', [':ItemID' => $ItemID, ':brand_id' => $global_response_brand['response'][0]['brand_id']]);
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
                if (!PermissionGuard::canAccess($ctx, 'brand_settings') || !PermissionGuard::has($ctx, 'api_settings', 'view')) {
                    echo json_encode(['status' => 'false', 'title' => 'Access denied', 'message' => 'You need permission to perform this action. Please contact the admin.', 'csrf_token' => $new_csrf_token]);
                    exit();
                }

                $actionID = $request->post('actionID', '');
                $selected_ids_json = $request->post('selected_ids', '[]');
                $selected_ids = json_decode($selected_ids_json, true);

                if (!empty($selected_ids)) {
                    foreach ($selected_ids as $id) {
                        $itemID = InputSanitizer::trim($id);

                        $response_brand = CrudService::select($db_prefix . 'api', 'WHERE id = :itemID AND brand_id = :brand_id', '* FROM', [':itemID' => $itemID, ':brand_id' => $global_response_brand['response'][0]['brand_id']]);
                        if ($response_brand['status'] == true) {
                            if ($actionID == "deleted") {
                                if (PermissionGuard::has($ctx, 'api_settings', 'delete')) {

                                    $condition = "id = :itemID";
                                    $whereParams = [':itemID' => $itemID];

                                    CrudService::delete($db_prefix . 'api', $condition, $whereParams);

                                }
                            }

                            if ($actionID == "activated") {
                                if (PermissionGuard::has($ctx, 'api_settings', 'edit')) {

                                    $columns = ['status', 'updated_date'];
                                    $values = ['active', getCurrentDatetime('Y-m-d H:i:s')];
                                    $condition = "id = :itemID";
                                    $whereParams = [':itemID' => $itemID];

                                    CrudService::update($db_prefix . 'api', $columns, $values, $condition, $whereParams);

                                }
                            }

                            if ($actionID == "inactivated") {
                                if (PermissionGuard::has($ctx, 'api_settings', 'edit')) {

                                    $columns = ['status', 'updated_date'];
                                    $values = ['inactive', getCurrentDatetime('Y-m-d H:i:s')];
                                    $condition = "id = :itemID";
                                    $whereParams = [':itemID' => $itemID];

                                    CrudService::update($db_prefix . 'api', $columns, $values, $condition, $whereParams);

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
                if (!PermissionGuard::canAccess($ctx, 'brand_settings') || !PermissionGuard::has($ctx, 'api_settings', 'view') || !PermissionGuard::has($ctx, 'api_settings', 'delete')) {
                    echo json_encode(['status' => 'false', 'title' => 'Access denied', 'message' => 'You need permission to perform this action. Please contact the admin.', 'csrf_token' => $new_csrf_token]);
                    exit();
                }

                $ItemID = $request->post('ItemID', '');

                $response_brand = CrudService::select($db_prefix . 'api', 'WHERE id = :ItemID AND brand_id = :brand_id', '* FROM', [':ItemID' => $ItemID, ':brand_id' => $global_response_brand['response'][0]['brand_id']]);
                if ($response_brand['status'] == true) {
                    $condition = "id = :ItemID AND brand_id = :brand_id";
                    $whereParams = [':ItemID' => $ItemID, ':brand_id' => $global_response_brand['response'][0]['brand_id']];

                    CrudService::delete($db_prefix . 'api', $condition, $whereParams);
                }

                echo json_encode(['status' => 'true', 'title' => 'Api Key Deleted', 'message' => 'The selected api key have been deleted successfully.', 'csrf_token' => $new_csrf_token]);
            } else {
                echo json_encode(['status' => 'false', 'title' => 'Request Failed', 'message' => 'Invalid request', 'csrf_token' => $new_csrf_token]);
            }
        }


        if ($action == "api-edit") {
            if ($global_user_login == true) {
                if (!PermissionGuard::canAccess($ctx, 'brand_settings') || !PermissionGuard::has($ctx, 'api_settings', 'view') || !PermissionGuard::has($ctx, 'api_settings', 'edit')) {
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
                    $responseApi = CrudService::select($db_prefix . 'api', 'WHERE brand_id = :brand_id AND id = :api_id', '* FROM', [':brand_id' => $global_response_brand['response'][0]['brand_id'], ':api_id' => $api_id]);
                    if ($responseApi['status'] == true) {
                        $response = CrudService::select($db_prefix . 'api', 'WHERE brand_id = :brand_id AND name = :name', '* FROM', [':brand_id' => $global_response_brand['response'][0]['brand_id'], ':name' => $api_name]);
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
                            $apiExpiryDate = null;
                        }

                        $scopes_json = json_encode($scopes);

                        $columns = ['name', 'expired_date', 'status', 'api_scopes', 'updated_date'];
                        $values = [$api_name, $apiExpiryDate, $api_status, $scopes_json, getCurrentDatetime('Y-m-d H:i:s')];

                        $condition = "id = :api_id AND brand_id = :brand_id";
                        $whereParams = [':api_id' => $api_id, ':brand_id' => $global_response_brand['response'][0]['brand_id']];

                        CrudService::update($db_prefix . 'api', $columns, $values, $condition, $whereParams);

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
