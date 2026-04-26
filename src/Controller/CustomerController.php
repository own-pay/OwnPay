<?php
declare(strict_types=1);

namespace OwnPay\Controller;

use OwnPay\Http\RequestContext;
use OwnPay\Service\CrudService;
use OwnPay\Service\InputSanitizer;
use OwnPay\Service\PermissionGuard;

class CustomerController
{

    public static function handle(string $action, ?RequestContext $ctx = null): void
    {
        $ctx ??= $GLOBALS['requestContext'] ?? throw new \RuntimeException('RequestContext not available');

        $global_user_login = $ctx->isLoggedIn;
        $new_csrf_token = $ctx->csrfToken;
        $db_prefix = $ctx->dbPrefix;
        $global_response_brand = $ctx->brandResponse;

        $request = \OwnPay\Http\Request::createFromGlobals();

        if ($action == "customer-list") {
            if ($global_user_login == true) {
                if (!PermissionGuard::canAccess($ctx, 'customers')) {
                    echo json_encode(['status' => 'false', 'title' => 'Access denied', 'message' => 'You need permission to perform this action. Please contact the admin.', 'csrf_token' => $new_csrf_token]);
                    exit();
                }

                $search_input = $request->post('search_input', '');
                $show_limit = $request->post('show_limit', '5');

                $tabType = $request->post('tabType', '');

                /* Filters */
                $filter_status = $request->post('filter_status', '');
                $filter_start = $request->post('filter_start', '');
                $filter_end = $request->post('filter_end', '');

                $where = [];
                $params_cust = [':brand_id' => $global_response_brand['response'][0]['brand_id']];

                if ($tabType !== "all") {
                    $where[] = "inserted_via = :tabType";
                    $params_cust[':tabType'] = $tabType;
                }

                if ($filter_start !== '') {
                    $where[] = "created_date >= :filter_start";
                    $params_cust[':filter_start'] = "{$filter_start} 00:00:00";
                }

                if ($filter_end !== '') {
                    $where[] = "created_date <= :filter_end";
                    $params_cust[':filter_end'] = "{$filter_end} 23:59:59";
                }

                if ($filter_status !== '') {
                    $where[] = "status = :filter_status";
                    $params_cust[':filter_status'] = $filter_status;
                }

                $where_sql = $where ? implode(' AND ', $where) . ' AND ' : '';
                /* Filters */


                $pag = \OwnPay\Service\PaginationService::resolve($request->post('page', '1'), $request->post('show_limit'));
                $page = $pag['page'];
                $show_limit_val = $pag['perPage'];
                $offset = $pag['offset'];

                $sql_query = '';

                if ($search_input !== '') {
                    $sql_query .= " AND ( name LIKE :search OR email LIKE :search OR mobile LIKE :search )";
                    $params_cust[':search'] = "%$search_input%";
                }

                $sql_limit = '';
                if ($pag['isAll']) {

                } else {
                    $sql_limit = " LIMIT $offset, $show_limit_val";
                }

                $response_result = CrudService::select($db_prefix . 'customer', ' WHERE ' . $where_sql . ' brand_id = :brand_id ' . $sql_query . ' ORDER BY 1 DESC ' . $sql_limit, '* FROM', $params_cust);
                if ($response_result['status'] == true) {
                    $response = [];

                    foreach ($response_result['response'] as $row) {
                        $response[] = [
                            "id" => $row['ref'],
                            "name" => $row['name'],
                            "email" => $row['email'],
                            "mobile" => $row['mobile'],
                            "status" => $row['status'],
                            'suspend_reason' => $row['suspend_reason'] ?? '',
                            "created_date" => convertUTCtoUserTZ($row['created_date'], ($global_response_brand['response'][0]['timezone'] === null || $global_response_brand['response'][0]['timezone'] === '') ? 'Asia/Dhaka' : $global_response_brand['response'][0]['timezone'], "M d, Y h:i A"),
                            "updated_date" => convertUTCtoUserTZ($row['updated_date'], ($global_response_brand['response'][0]['timezone'] === null || $global_response_brand['response'][0]['timezone'] === '') ? 'Asia/Dhaka' : $global_response_brand['response'][0]['timezone'], "M d, Y h:i A")
                        ];
                    }

                    $count_data = CrudService::select($db_prefix . 'customer', ' WHERE ' . $where_sql . ' brand_id = :brand_id ' . $sql_query, '* FROM', $params_cust);


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

        if ($action == "customers-create") {
            if ($global_user_login == true) {
                if (!PermissionGuard::canAccess($ctx, 'customers') || !PermissionGuard::has($ctx, 'customers', 'create')) {
                    echo json_encode(['status' => 'false', 'title' => 'Access denied', 'message' => 'You need permission to perform this action. Please contact the admin.', 'csrf_token' => $new_csrf_token]);
                    exit();
                }

                $name = $request->post('name', '');
                $email = $request->post('email', '');
                $mobile = $request->post('mobile', '');
                $status = $request->post('status', '');
                $suspend_reason = $request->post('suspend_reason', '');

                if ($name == "" || $email == "" || $mobile == "" || $status == "") {
                    echo json_encode(['status' => "false", 'title' => 'Incomplete Information', 'message' => 'Please fill in all required fields before proceeding.', 'csrf_token' => $new_csrf_token]);
                } else {
                    if ($status == "active" || $status == "suspend") {

                    } else {
                        echo json_encode(['status' => "false", 'title' => 'Incomplete Information', 'message' => 'Please fill in all required fields before proceeding.', 'csrf_token' => $new_csrf_token]);
                        exit();
                    }

                    if ($suspend_reason == "") {
                        $suspend_reason = null;
                    }

                    if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                        $response = CrudService::select($db_prefix . 'customer', 'WHERE brand_id = :brand_id AND email = :email', '* FROM', [':brand_id' => $global_response_brand['response'][0]['brand_id'], ':email' => $email]);
                        if ($response['status'] == false) {
                            $ref = generateItemID();

                            $columns = ['ref', 'brand_id', 'name', 'email', 'mobile', 'status', 'suspend_reason', 'created_date', 'updated_date'];
                            $values = [$ref, $global_response_brand['response'][0]['brand_id'], $name, $email, $mobile, $status, $suspend_reason, getCurrentDatetime('Y-m-d H:i:s'), getCurrentDatetime('Y-m-d H:i:s')];

                            CrudService::insert($db_prefix . 'customer', $columns, $values);

                            echo json_encode(['status' => 'true', 'title' => 'Customer Created', 'message' => 'The customer has been created successfully.', 'csrf_token' => $new_csrf_token]);
                        } else {
                            echo json_encode(['status' => 'false', 'title' => 'Duplicate Customer', 'message' => 'A customer with this email address already exists. Please choose a different email address.', 'csrf_token' => $new_csrf_token]);
                        }
                    } else {
                        echo json_encode(['status' => "false", 'title' => 'Invalid Email', 'message' => 'Please enter a valid email address.', 'csrf_token' => $new_csrf_token]);
                    }
                }
            } else {
                echo json_encode(['status' => 'false', 'title' => 'Request Failed', 'message' => 'Invalid request', 'csrf_token' => $new_csrf_token]);
            }
        }


        if ($action == "customers-bulk-action") {
            if ($global_user_login == true) {
                if (!PermissionGuard::canAccess($ctx, 'customers')) {
                    echo json_encode(['status' => 'false', 'title' => 'Access denied', 'message' => 'You need permission to perform this action. Please contact the admin.', 'csrf_token' => $new_csrf_token]);
                    exit();
                }

                $actionID = $request->post('actionID', '');
                $selected_ids_json = $request->post('selected_ids', '[]');
                $selected_ids = json_decode($selected_ids_json, true);

                if (!empty($selected_ids)) {
                    foreach ($selected_ids as $id) {
                        $itemID = InputSanitizer::trim($id);

                        $response_brand = CrudService::select($db_prefix . 'customer', 'WHERE ref = :itemID AND brand_id = :brand_id', '* FROM', [':itemID' => $itemID, ':brand_id' => $global_response_brand['response'][0]['brand_id']]);
                        if ($response_brand['status'] == true) {
                            if ($actionID == "deleted") {
                                if (PermissionGuard::has($ctx, 'customers', 'delete')) {

                                    $condition = "ref = :itemID";
                                    $whereParams = [':itemID' => $itemID];

                                    CrudService::delete($db_prefix . 'customer', $condition, $whereParams);

                                }
                            }

                            if ($actionID == "activated") {
                                if (PermissionGuard::has($ctx, 'customers', 'edit')) {

                                    $columns = ['status', 'updated_date'];
                                    $values = ['active', getCurrentDatetime('Y-m-d H:i:s')];
                                    $condition = "ref = :itemID";
                                    $whereParams = [':itemID' => $itemID];

                                    CrudService::update($db_prefix . 'customer', $columns, $values, $condition, $whereParams);

                                }
                            }

                            if ($actionID == "suspended") {
                                if (PermissionGuard::has($ctx, 'customers', 'edit')) {

                                    $columns = ['status', 'updated_date'];
                                    $values = ['suspend', getCurrentDatetime('Y-m-d H:i:s')];
                                    $condition = "ref = :itemID";
                                    $whereParams = [':itemID' => $itemID];

                                    CrudService::update($db_prefix . 'customer', $columns, $values, $condition, $whereParams);

                                }
                            }
                        }
                    }

                    echo json_encode(['status' => 'true', 'title' => 'Customers ' . $actionID, 'message' => 'The selected customers have been ' . $actionID . ' successfully.', 'csrf_token' => $new_csrf_token]);
                } else {
                    echo json_encode(['status' => 'false', 'title' => 'Request Failed', 'message' => 'No customers selected.', 'csrf_token' => $new_csrf_token]);
                }
            } else {
                echo json_encode(['status' => 'false', 'title' => 'Request Failed', 'message' => 'Invalid request', 'csrf_token' => $new_csrf_token]);
            }
        }


        if ($action == "customers-delete") {
            if ($global_user_login == true) {
                if (!PermissionGuard::canAccess($ctx, 'customers') || !PermissionGuard::has($ctx, 'customers', 'delete')) {
                    echo json_encode(['status' => 'false', 'title' => 'Access denied', 'message' => 'You need permission to perform this action. Please contact the admin.', 'csrf_token' => $new_csrf_token]);
                    exit();
                }

                $ItemID = $request->post('ItemID', '');

                $response_brand = CrudService::select($db_prefix . 'customer', 'WHERE ref = :ItemID AND brand_id = :brand_id', '* FROM', [':ItemID' => $ItemID, ':brand_id' => $global_response_brand['response'][0]['brand_id']]);
                if ($response_brand['status'] == true) {
                    $condition = "ref = :ItemID";
                    $whereParams = [':ItemID' => $ItemID];

                    CrudService::delete($db_prefix . 'customer', $condition, $whereParams);
                }

                echo json_encode(['status' => 'true', 'title' => 'Customer Deleted', 'message' => 'The selected customer have been deleted successfully.', 'csrf_token' => $new_csrf_token]);
            } else {
                echo json_encode(['status' => 'false', 'title' => 'Request Failed', 'message' => 'Invalid request', 'csrf_token' => $new_csrf_token]);
            }
        }

        if ($action == "customers-info-byID") {
            if ($global_user_login == true) {
                if (!PermissionGuard::canAccess($ctx, 'customers') || !PermissionGuard::has($ctx, 'customers', 'edit')) {
                    echo json_encode(['status' => 'false', 'title' => 'Access denied', 'message' => 'You need permission to perform this action. Please contact the admin.', 'csrf_token' => $new_csrf_token]);
                    exit();
                }

                $ItemID = $request->post('ItemID', '');

                $response_brand = CrudService::select($db_prefix . 'customer', 'WHERE ref = :ItemID AND brand_id = :brand_id', '* FROM', [':ItemID' => $ItemID, ':brand_id' => $global_response_brand['response'][0]['brand_id']]);
                if ($response_brand['status'] == true) {
                    echo json_encode(['status' => 'true', 'name' => $response_brand['response'][0]['name'], 'email' => $response_brand['response'][0]['email'], 'mobile' => $response_brand['response'][0]['mobile'], 'istatus' => $response_brand['response'][0]['status'], 'suspend_reason' => $response_brand['response'][0]['suspend_reason'] ?? "", 'csrf_token' => $new_csrf_token]);
                } else {
                    echo json_encode(['status' => 'false', 'title' => 'Request Failed', 'message' => 'Invalid request', 'csrf_token' => $new_csrf_token]);
                }
            } else {
                echo json_encode(['status' => 'false', 'title' => 'Request Failed', 'message' => 'Invalid request', 'csrf_token' => $new_csrf_token]);
            }
        }

        if ($action == "customers-edit") {
            if ($global_user_login == true) {
                if (!PermissionGuard::canAccess($ctx, 'customers') || !PermissionGuard::has($ctx, 'customers', 'edit')) {
                    echo json_encode(['status' => 'false', 'title' => 'Access denied', 'message' => 'You need permission to perform this action. Please contact the admin.', 'csrf_token' => $new_csrf_token]);
                    exit();
                }

                $customer_id = $request->post('customer_id', '');
                $name = $request->post('name', '');
                $email = $request->post('email', '');
                $mobile = $request->post('mobile', '');
                $status = $request->post('status', '');
                $suspend_reason = $request->post('suspend_reason', '');

                if ($suspend_reason == "") {
                    $suspend_reason = null;
                }

                if ($status == "active" || $status == "suspend") {

                } else {
                    echo json_encode(['status' => 'false', 'title' => 'Request Failed', 'message' => 'Invalid request', 'csrf_token' => $new_csrf_token]);
                    exit();
                }

                if ($customer_id == "" || $name == "" || $email == "" || $mobile == "") {
                    echo json_encode(['status' => "false", 'title' => 'Incomplete Information', 'message' => 'Please fill in all required fields before proceeding.', 'csrf_token' => $new_csrf_token]);
                } else {
                    if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                        $response = CrudService::select($db_prefix . 'customer', 'WHERE brand_id = :brand_id AND ref = :customer_id', '* FROM', [':brand_id' => $global_response_brand['response'][0]['brand_id'], ':customer_id' => $customer_id]);
                        if ($response['status'] == true) {
                            if ($response['response'][0]['email'] == $email) {

                            } else {
                                $responseCheck = CrudService::select($db_prefix . 'customer', 'WHERE brand_id = :brand_id AND email = :email', '* FROM', [':brand_id' => $global_response_brand['response'][0]['brand_id'], ':email' => $email]);
                                if ($responseCheck['status'] == false) {

                                } else {
                                    echo json_encode(['status' => 'false', 'title' => 'Duplicate Customer', 'message' => 'A customer with this email address already exists. Please choose a different email address.', 'csrf_token' => $new_csrf_token]);
                                    exit();
                                }
                            }

                            $columns = ['name', 'email', 'mobile', 'status', 'suspend_reason', 'updated_date'];
                            $values = [$name, $email, $mobile, $status, $suspend_reason, getCurrentDatetime('Y-m-d H:i:s')];
                            $condition = "ref = :customer_id";
                            $whereParams = [':customer_id' => $customer_id];

                            CrudService::update($db_prefix . 'customer', $columns, $values, $condition, $whereParams);

                            echo json_encode(['status' => 'true', 'title' => 'Customer Updated', 'message' => 'The customer has been updated successfully.', 'csrf_token' => $new_csrf_token]);
                        } else {
                            echo json_encode(['status' => 'false', 'title' => 'Request Failed', 'message' => 'Invalid Customer ID', 'csrf_token' => $new_csrf_token]);
                        }
                    } else {
                        echo json_encode(['status' => "false", 'title' => 'Invalid Email', 'message' => 'Please enter a valid email address.', 'csrf_token' => $new_csrf_token]);
                    }
                }
            } else {
                echo json_encode(['status' => 'false', 'title' => 'Request Failed', 'message' => 'Invalid request', 'csrf_token' => $new_csrf_token]);
            }
        }
    }
}
