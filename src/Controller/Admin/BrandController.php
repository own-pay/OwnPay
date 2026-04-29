<?php
declare(strict_types=1);

namespace OwnPay\Controller\Admin;

use OwnPay\Http\RequestContext;
use OwnPay\Service\Auth\AuthSessionService;
use OwnPay\Service\System\CrudService;
use OwnPay\Service\System\InputSanitizer;
use OwnPay\Service\Auth\PermissionGuard;

class BrandController
{
    public static function handle(string $action, ?RequestContext $ctx = null): void
    {
        $ctx ??= $GLOBALS['requestContext'] ?? throw new \RuntimeException('RequestContext not available');
        $controller = new self();

        switch ($action) {
            case 'create-new-brand':
                $controller->create($ctx);
                break;
            case 'all-brand-list':
                $controller->list($ctx);
                break;
            case 'brand-bulk-action':
                $controller->bulkAction($ctx);
                break;
            case 'brand-delete':
                $controller->delete($ctx);
                break;
            case 'edit-brand':
                $controller->edit($ctx);
                break;
        }
    }

    private function create(RequestContext $ctx): void
    {
        $db_prefix = $ctx->dbPrefix;
        $global_user_login = $ctx->isLoggedIn;
        $global_user_response = $ctx->userResponse;
        $new_csrf_token = $ctx->csrfToken;

        if ($global_user_login == true) {
            if (!PermissionGuard::canAccess($ctx, 'brands') || !PermissionGuard::has($ctx, 'brands', 'create')) {
                echo json_encode(['status' => 'false', 'title' => 'Access denied', 'message' => 'You need permission to perform this action. Please contact the admin.', 'csrf_token' => $new_csrf_token]);
                exit();
            }

            $request = \OwnPay\Http\Request::createFromGlobals();

            $brand_name = $request->post('brand-name', '');

            if ($brand_name == "") {
                echo json_encode(['status' => "false", 'title' => 'Incomplete Information', 'message' => 'Please fill in all required fields before proceeding.', 'csrf_token' => $new_csrf_token]);
            } else {
                $response = CrudService::select($db_prefix . 'brands', 'WHERE identify_name = :name', '* FROM', [':name' => $brand_name]);
                if ($response['status'] == false) {
                    $brand_id = generateItemID();

                    $columns = ['brand_id', 'identify_name', 'created_date', 'updated_date'];
                    $values = [$brand_id, $brand_name, getCurrentDatetime('Y-m-d H:i:s'), getCurrentDatetime('Y-m-d H:i:s')];

                    CrudService::insert($db_prefix . 'brands', $columns, $values);

                    $columns = ['brand_id', 'a_id', 'permission', 'created_date', 'updated_date'];
                    $values = [$brand_id, $global_user_response['response'][0]['a_id'], json_encode(permissionSchema()), getCurrentDatetime('Y-m-d H:i:s'), getCurrentDatetime('Y-m-d H:i:s')];

                    CrudService::insert($db_prefix . 'permission', $columns, $values);

                    $columns = ['brand_id', 'code', 'symbol', 'created_date', 'updated_date'];
                    $values = [$brand_id, 'BDT', '৳', getCurrentDatetime('Y-m-d H:i:s'), getCurrentDatetime('Y-m-d H:i:s')];

                    CrudService::insert($db_prefix . 'currency', $columns, $values);

                    if ($global_user_response['response'][0]['role'] !== 'admin') {
                        $response_admin = CrudService::select($db_prefix . 'admin', 'WHERE role = :role', '* FROM', [':role' => 'admin']);
                        foreach ($response_admin['response'] as $admins) {
                            $columns = ['brand_id', 'a_id', 'permission', 'created_date', 'updated_date'];
                            $values = [$brand_id, $admins['a_id'], json_encode(permissionSchema()), getCurrentDatetime('Y-m-d H:i:s'), getCurrentDatetime('Y-m-d H:i:s')];

                            CrudService::insert($db_prefix . 'permission', $columns, $values);
                        }
                    }

                    AuthSessionService::setCookie('op_brand', $brand_id);

                    echo json_encode(['status' => 'true', 'title' => 'Brand Created', 'message' => 'The brand has been created successfully.', 'csrf_token' => $new_csrf_token]);
                } else {
                    echo json_encode(['status' => 'false', 'title' => 'Duplicate Brand', 'message' => 'A brand with this name already exists. Please choose a different name.', 'csrf_token' => $new_csrf_token]);
                }
            }
        } else {
            echo json_encode(['status' => 'false', 'title' => 'Request Failed', 'message' => 'Invalid request', 'csrf_token' => $new_csrf_token]);
        }
    }

    private function list(RequestContext $ctx): void
    {
        $db_prefix = $ctx->dbPrefix;
        $global_user_login = $ctx->isLoggedIn;
        $global_response_brand = $ctx->brandResponse;
        $new_csrf_token = $ctx->csrfToken;

        if ($global_user_login == true) {
            if (!PermissionGuard::canAccess($ctx, 'brands')) {
                echo json_encode(['status' => 'false', 'title' => 'Access denied', 'message' => 'You need permission to perform this action. Please contact the admin.', 'csrf_token' => $new_csrf_token]);
                exit();
            }

            $request = \OwnPay\Http\Request::createFromGlobals();

            $search_input = $request->post('search_input', '');
            $show_limit = $request->post('show_limit', '5');

            /* Filters */
            $filter_start = $request->post('filter_start', '');
            $filter_end = $request->post('filter_end', '');

            $where = [];
            $params_brand = [':empty' => ''];

            if ($filter_start !== '') {
                $where[] = "created_date >= :filter_start";
                $params_brand[':filter_start'] = "{$filter_start} 00:00:00";
            }

            if ($filter_end !== '') {
                $where[] = "created_date <= :filter_end";
                $params_brand[':filter_end'] = "{$filter_end} 23:59:59";
            }

            $where_sql = $where ? implode(' AND ', $where) . ' AND ' : '';
            /* Filters */

            $pag = \OwnPay\Service\System\PaginationService::resolve($request->post('page', '1'), $request->post('show_limit'));
            $page = $pag['page'];
            $show_limit_val = $pag['perPage'];
            $offset = $pag['offset'];

            $sql_query = '';

            if ($search_input !== '') {
                $sql_query .= " AND ( identify_name LIKE :search OR name LIKE :search )";
                $params_brand[':search'] = "%$search_input%";
            }

            $sql_limit = '';
            if ($pag['isAll']) {

            } else {
                $sql_limit = " LIMIT $offset, $show_limit_val";
            }

            $response_result = CrudService::select($db_prefix . 'brands', ' WHERE ' . $where_sql . ' identify_name NOT IN (:empty) ' . $sql_query . ' ORDER BY 1 DESC ' . $sql_limit, '* FROM', $params_brand);
            if ($response_result['status'] == true) {
                $response = [];

                foreach ($response_result['response'] as $row) {
                    $deleteable = 'true';

                    if ($row['id'] == 1 || $row['brand_id'] == $global_response_brand['response'][0]['brand_id']) {
                        $deleteable = 'false';
                    }

                    $response[] = [
                        "id" => $row['brand_id'],
                        "deleteable" => $deleteable,
                        "identify_name" => $row['identify_name'],
                        "name" => $row['name'],
                        "created_date" => convertUTCtoUserTZ($row['created_date'], ($global_response_brand['response'][0]['timezone'] === null || $global_response_brand['response'][0]['timezone'] === '') ? 'Asia/Dhaka' : $global_response_brand['response'][0]['timezone'], "M d, Y h:i A"),
                        "updated_date" => convertUTCtoUserTZ($row['updated_date'], ($global_response_brand['response'][0]['timezone'] === null || $global_response_brand['response'][0]['timezone'] === '') ? 'Asia/Dhaka' : $global_response_brand['response'][0]['timezone'], "M d, Y h:i A")
                    ];
                }

                $count_data = CrudService::select($db_prefix . 'brands', ' WHERE identify_name NOT IN (:empty) ' . $sql_query, '* FROM', $params_brand);

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

    private function bulkAction(RequestContext $ctx): void
    {
        $db_prefix = $ctx->dbPrefix;
        $global_user_login = $ctx->isLoggedIn;
        $global_response_brand = $ctx->brandResponse;
        $new_csrf_token = $ctx->csrfToken;

        if ($global_user_login == true) {
            if (!PermissionGuard::canAccess($ctx, 'brands')) {
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

                    $response_brand = CrudService::select($db_prefix . 'brands', 'WHERE brand_id = :id', '* FROM', $params_item);
                    if ($response_brand['status'] == true) {
                        if ($actionID == "deleted") {
                            if ($response_brand['response'][0]['id'] == 1 || $response_brand['response'][0]['brand_id'] == $global_response_brand['response'][0]['brand_id']) {

                            } else {
                                if (PermissionGuard::has($ctx, 'brands', 'delete')) {

                                    $condition = "brand_id = :id";
                                    $whereParams = [':id' => $itemID];

                                    CrudService::delete($db_prefix . 'brands', $condition, $whereParams);


                                    $condition = "brand_id = '" . $response_brand['response'][0]['brand_id'] . "'";

                                    CrudService::delete($db_prefix . 'api', $condition);

                                    $condition = "brand_id = '" . $response_brand['response'][0]['brand_id'] . "'";

                                    CrudService::delete($db_prefix . 'currency', $condition);

                                    $condition = "brand_id = '" . $response_brand['response'][0]['brand_id'] . "'";

                                    CrudService::delete($db_prefix . 'customer', $condition);

                                    $condition = "brand_id = '" . $response_brand['response'][0]['brand_id'] . "'";

                                    CrudService::delete($db_prefix . 'env', $condition);

                                    $condition = "brand_id = '" . $response_brand['response'][0]['brand_id'] . "'";

                                    CrudService::delete($db_prefix . 'faq', $condition);

                                    $condition = "brand_id = '" . $response_brand['response'][0]['brand_id'] . "'";

                                    $condition = "brand_id = :brand_id";
                                    $whereParams = [':brand_id' => $response_brand['response'][0]['brand_id']];

                                    CrudService::delete($db_prefix . 'gateways', $condition, $whereParams);

                                    CrudService::delete($db_prefix . 'gateways_parameter', $condition, $whereParams);

                                    CrudService::delete($db_prefix . 'invoice', $condition, $whereParams);

                                    CrudService::delete($db_prefix . 'invoice_items', $condition, $whereParams);

                                    $response_payment_link_filed = CrudService::select($db_prefix . 'payment_link', 'WHERE brand_id = :brand_id', '* FROM', [':brand_id' => $response_brand['response'][0]['brand_id']]);
                                    foreach ($response_payment_link_filed['response'] as $row_paymentfiled) {
                                        $condition = "paymentLinkID = :ref";
                                        $whereParams_field = [':ref' => $row_paymentfiled['ref']];

                                        CrudService::delete($db_prefix . 'payment_link_field', $condition, $whereParams_field);
                                    }

                                    $condition = "brand_id = :brand_id";
                                    $whereParams = [':brand_id' => $response_brand['response'][0]['brand_id']];

                                    CrudService::delete($db_prefix . 'payment_link', $condition, $whereParams);

                                    CrudService::delete($db_prefix . 'permission', $condition, $whereParams);

                                    CrudService::delete($db_prefix . 'transaction', $condition, $whereParams);

                                    CrudService::delete($db_prefix . 'webhook_log', $condition, $whereParams);
                                }
                            }
                        }
                    }
                }

                echo json_encode(['status' => 'true', 'title' => 'Brands ' . $actionID, 'message' => 'The selected brands have been ' . $actionID . ' successfully.', 'csrf_token' => $new_csrf_token]);
            } else {
                echo json_encode(['status' => 'false', 'title' => 'Request Failed', 'message' => 'No brands selected.', 'csrf_token' => $new_csrf_token]);
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
            if (!PermissionGuard::canAccess($ctx, 'brands') || !PermissionGuard::has($ctx, 'brands', 'delete')) {
                echo json_encode(['status' => 'false', 'title' => 'Access denied', 'message' => 'You need permission to perform this action. Please contact the admin.', 'csrf_token' => $new_csrf_token]);
                exit();
            }

            $request = \OwnPay\Http\Request::createFromGlobals();

            $ItemID = $request->post('ItemID', '');

            $response_brand = CrudService::select($db_prefix . 'brands', 'WHERE brand_id = :id', '* FROM', [':id' => $ItemID]);
            if ($response_brand['status'] == true) {
                if ($response_brand['response'][0]['id'] == 1 || $response_brand['response'][0]['brand_id'] == $global_response_brand['response'][0]['brand_id']) {
                    echo json_encode(['status' => 'false', 'title' => 'Request Failed', 'message' => 'Invalid request', 'csrf_token' => $new_csrf_token]);
                } else {
                    if ($response_brand['response'][0]['id'] == 1 || $response_brand['response'][0]['brand_id'] == $global_response_brand['response'][0]['brand_id']) {
                        echo json_encode(['status' => 'false', 'title' => 'Request Failed', 'message' => 'Invalid request', 'csrf_token' => $new_csrf_token]);
                    } else {
                        $condition = "brand_id = :id";
                        $whereParams = [':id' => $ItemID];

                        CrudService::delete($db_prefix . 'brands', $condition, $whereParams);


                        $condition = "brand_id = :brand_id";
                        $whereParams_cascade = [':brand_id' => $response_brand['response'][0]['brand_id']];

                        CrudService::delete($db_prefix . 'api', $condition, $whereParams_cascade);

                        CrudService::delete($db_prefix . 'currency', $condition, $whereParams_cascade);

                        CrudService::delete($db_prefix . 'customer', $condition, $whereParams_cascade);

                        CrudService::delete($db_prefix . 'env', $condition, $whereParams_cascade);

                        CrudService::delete($db_prefix . 'faq', $condition, $whereParams_cascade);

                        CrudService::delete($db_prefix . 'gateways', $condition, $whereParams_cascade);

                        CrudService::delete($db_prefix . 'gateways_parameter', $condition, $whereParams_cascade);

                        CrudService::delete($db_prefix . 'invoice', $condition, $whereParams_cascade);

                        CrudService::delete($db_prefix . 'invoice_items', $condition, $whereParams_cascade);

                        $response_payment_link_filed = CrudService::select($db_prefix . 'payment_link', 'WHERE brand_id = :brand_id', '* FROM', [':brand_id' => $response_brand['response'][0]['brand_id']]);
                        foreach ($response_payment_link_filed['response'] as $row_paymentfiled) {
                            $condition = "paymentLinkID = :ref";
                            $whereParams_field = [':ref' => $row_paymentfiled['ref']];

                            CrudService::delete($db_prefix . 'payment_link_field', $condition, $whereParams_field);
                        }

                        $condition = "brand_id = :brand_id";
                        $whereParams_cascade = [':brand_id' => $response_brand['response'][0]['brand_id']];

                        CrudService::delete($db_prefix . 'payment_link', $condition, $whereParams_cascade);

                        CrudService::delete($db_prefix . 'permission', $condition, $whereParams_cascade);

                        CrudService::delete($db_prefix . 'transaction', $condition, $whereParams_cascade);

                        CrudService::delete($db_prefix . 'webhook_log', $condition, $whereParams_cascade);

                        echo json_encode(['status' => 'true', 'title' => 'Brands Deleted', 'message' => 'The selected brand have been deleted successfully.', 'csrf_token' => $new_csrf_token]);
                    }
                }
            }
        } else {
            echo json_encode(['status' => 'false', 'title' => 'Request Failed', 'message' => 'Invalid request', 'csrf_token' => $new_csrf_token]);
        }
    }

    private function edit(RequestContext $ctx): void
    {
        $db_prefix = $ctx->dbPrefix;
        $global_user_login = $ctx->isLoggedIn;
        $global_response_brand = $ctx->brandResponse;
        $new_csrf_token = $ctx->csrfToken;

        if ($global_user_login == true) {
            if (!PermissionGuard::canAccess($ctx, 'brands') || !PermissionGuard::has($ctx, 'brands', 'edit')) {
                echo json_encode(['status' => 'false', 'title' => 'Access denied', 'message' => 'You need permission to perform this action. Please contact the admin.', 'csrf_token' => $new_csrf_token]);
                exit();
            }

            $request = \OwnPay\Http\Request::createFromGlobals();

            $brand_name = $request->post('brand-name', '');
            $brand_id = $request->post('b_id', '');

            if ($brand_name == "" || $brand_id == "") {
                echo json_encode(['status' => "false", 'title' => 'Incomplete Information', 'message' => 'Please fill in all required fields before proceeding.', 'csrf_token' => $new_csrf_token]);
            } else {
                $response = CrudService::select($db_prefix . 'brands', 'WHERE brand_id = :id', '* FROM', [':id' => $brand_id]);
                if ($response['status'] == true) {
                    if ($response['response'][0]['id'] == 1 || $response['response'][0]['brand_id'] == $global_response_brand['response'][0]['brand_id']) {
                        echo json_encode(['status' => 'false', 'title' => 'Request Failed', 'message' => 'Invalid request', 'csrf_token' => $new_csrf_token]);
                        exit();
                    }

                    if ($response['response'][0]['identify_name'] !== $brand_name) {
                        $responseNameCheck = CrudService::select($db_prefix . 'brands', 'WHERE identify_name = :name', '* FROM', [':name' => $brand_name]);
                        if ($responseNameCheck['status'] == true) {
                            echo json_encode(['status' => 'false', 'title' => 'Duplicate Brand', 'message' => 'A brand with this name already exists. Please choose a different name.', 'csrf_token' => $new_csrf_token]);
                            exit();
                        }
                    }

                    $columns = ['identify_name', 'updated_date'];
                    $values = [$brand_name, getCurrentDatetime('Y-m-d H:i:s')];
                    $condition = "brand_id = :id";
                    $whereParams = [':id' => $brand_id];

                    CrudService::update($db_prefix . 'brands', $columns, $values, $condition, $whereParams);

                    echo json_encode(['status' => 'true', 'title' => 'Brand Updated', 'message' => 'The brand has been updated successfully.', 'csrf_token' => $new_csrf_token]);
                } else {
                    echo json_encode(['status' => 'false', 'title' => 'Request Failed', 'message' => 'Invalid brand id', 'csrf_token' => $new_csrf_token]);
                }
            }
        } else {
            echo json_encode(['status' => 'false', 'title' => 'Request Failed', 'message' => 'Invalid request', 'csrf_token' => $new_csrf_token]);
        }
    }
}
