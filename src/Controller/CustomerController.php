<?php
declare(strict_types=1);

namespace AnirbanPay\Controller;

use AnirbanPay\Http\RequestContext;

class CustomerController
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

        $request = \AnirbanPay\Http\Request::createFromGlobals();

        if ($action == "customer-list") {
            if ($global_user_login == true) {
                if (!canAccessPage(json_decode($global_response_permission['response'][0]['permission'], true), 'customers', $global_user_response['response'][0]['role'])) {
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


                $page = max(1, (int) $request->post('page', '1'));
                $show_limit_val = ($request->post('show_limit') == '') ? 999999 : (($request->post('show_limit') == 'all') ? 999999 : (int) $request->post('show_limit'));
                $offset = ($page - 1) * $show_limit_val;

                $sql_query = '';

                if ($search_input !== '') {
                    $sql_query .= " AND ( name LIKE :search OR email LIKE :search OR mobile LIKE :search )";
                    $params_cust[':search'] = "%$search_input%";
                }

                $sql_limit = '';
                if ($request->post('show_limit') == 'all') {

                } else {
                    $sql_limit = " LIMIT $offset, $show_limit_val";
                }

                $response_result = json_decode(getData($db_prefix . 'customer', ' WHERE ' . $where_sql . ' brand_id = :brand_id ' . $sql_query . ' ORDER BY 1 DESC ' . $sql_limit, '* FROM', $params_cust), true);
                if ($response_result['status'] == true) {
                    $response = [];

                    foreach ($response_result['response'] as $row) {
                        $response[] = [
                            "id" => $row['ref'],
                            "name" => $row['name'],
                            "email" => $row['email'],
                            "mobile" => $row['mobile'],
                            "status" => $row['status'],
                            'suspend_reason' => ($row['suspend_reason'] == "--") ? '' : $row['suspend_reason'],
                            "created_date" => convertUTCtoUserTZ($row['created_date'], ($global_response_brand['response'][0]['timezone'] === '--' || $global_response_brand['response'][0]['timezone'] === '') ? 'Asia/Dhaka' : $global_response_brand['response'][0]['timezone'], "M d, Y h:i A"),
                            "updated_date" => convertUTCtoUserTZ($row['updated_date'], ($global_response_brand['response'][0]['timezone'] === '--' || $global_response_brand['response'][0]['timezone'] === '') ? 'Asia/Dhaka' : $global_response_brand['response'][0]['timezone'], "M d, Y h:i A")
                        ];
                    }

                    $count_data = json_decode(getData($db_prefix . 'customer', ' WHERE ' . $where_sql . ' brand_id = :brand_id ' . $sql_query, '* FROM', $params_cust), true);


                    $total_records = count($count_data['response'] ?? []);
                    $total_pages = ceil($total_records / (intval($show_limit) == 0 ? 1 : intval($show_limit)));

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
                    $end = min($offset + $show_limit_val, $total_records);

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

        if ($action == "customers-create") {
            if ($global_user_login == true) {
                if (!canAccessPage(json_decode($global_response_permission['response'][0]['permission'], true), 'customers', $global_user_response['response'][0]['role'])) {
                    echo json_encode(['status' => 'false', 'title' => 'Access denied', 'message' => 'You need permission to perform this action. Please contact the admin.', 'csrf_token' => $new_csrf_token]);
                    exit();
                }

                if (!hasPermission(json_decode($global_response_permission['response'][0]['permission'], true), 'customers', 'create', $global_user_response['response'][0]['role'])) {
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
                        $suspend_reason = "--";
                    }

                    if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                        $response = json_decode(getData($db_prefix . 'customer', 'WHERE brand_id = :brand_id AND email = :email', '* FROM', [':brand_id' => $global_response_brand['response'][0]['brand_id'], ':email' => $email]), true);
                        if ($response['status'] == false) {
                            $ref = generateItemID();

                            $columns = ['ref', 'brand_id', 'name', 'email', 'mobile', 'status', 'suspend_reason', 'created_date', 'updated_date'];
                            $values = [$ref, $global_response_brand['response'][0]['brand_id'], $name, $email, $mobile, $status, $suspend_reason, getCurrentDatetime('Y-m-d H:i:s'), getCurrentDatetime('Y-m-d H:i:s')];

                            insertData($db_prefix . 'customer', $columns, $values);

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
                if (!canAccessPage(json_decode($global_response_permission['response'][0]['permission'], true), 'customers', $global_user_response['response'][0]['role'])) {
                    echo json_encode(['status' => 'false', 'title' => 'Access denied', 'message' => 'You need permission to perform this action. Please contact the admin.', 'csrf_token' => $new_csrf_token]);
                    exit();
                }

                $actionID = $request->post('actionID', '');
                $selected_ids_json = $request->post('selected_ids', '[]');
                $selected_ids = json_decode($selected_ids_json, true);

                if (!empty($selected_ids)) {
                    foreach ($selected_ids as $id) {
                        $itemID = escape_string($id);

                        $response_brand = json_decode(getData($db_prefix . 'customer', 'WHERE ref = :itemID AND brand_id = :brand_id', '* FROM', [':itemID' => $itemID, ':brand_id' => $global_response_brand['response'][0]['brand_id']]), true);
                        if ($response_brand['status'] == true) {
                            if ($actionID == "deleted") {
                                if (hasPermission(json_decode($global_response_permission['response'][0]['permission'], true), 'customers', 'delete', $global_user_response['response'][0]['role'])) {

                                    $condition = "ref = :itemID";
                                    $whereParams = [':itemID' => $itemID];

                                    deleteData($db_prefix . 'customer', $condition, $whereParams);

                                }
                            }

                            if ($actionID == "activated") {
                                if (hasPermission(json_decode($global_response_permission['response'][0]['permission'], true), 'customers', 'edit', $global_user_response['response'][0]['role'])) {

                                    $columns = ['status', 'updated_date'];
                                    $values = ['active', getCurrentDatetime('Y-m-d H:i:s')];
                                    $condition = "ref = :itemID";
                                    $whereParams = [':itemID' => $itemID];

                                    updateData($db_prefix . 'customer', $columns, $values, $condition, $whereParams);

                                }
                            }

                            if ($actionID == "suspended") {
                                if (hasPermission(json_decode($global_response_permission['response'][0]['permission'], true), 'customers', 'edit', $global_user_response['response'][0]['role'])) {

                                    $columns = ['status', 'updated_date'];
                                    $values = ['suspend', getCurrentDatetime('Y-m-d H:i:s')];
                                    $condition = "ref = :itemID";
                                    $whereParams = [':itemID' => $itemID];

                                    updateData($db_prefix . 'customer', $columns, $values, $condition, $whereParams);

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
                if (!canAccessPage(json_decode($global_response_permission['response'][0]['permission'], true), 'customers', $global_user_response['response'][0]['role'])) {
                    echo json_encode(['status' => 'false', 'title' => 'Access denied', 'message' => 'You need permission to perform this action. Please contact the admin.', 'csrf_token' => $new_csrf_token]);
                    exit();
                }

                if (!hasPermission(json_decode($global_response_permission['response'][0]['permission'], true), 'customers', 'delete', $global_user_response['response'][0]['role'])) {
                    echo json_encode(['status' => 'false', 'title' => 'Access denied', 'message' => 'You need permission to perform this action. Please contact the admin.', 'csrf_token' => $new_csrf_token]);
                    exit();
                }

                $ItemID = $request->post('ItemID', '');

                $response_brand = json_decode(getData($db_prefix . 'customer', 'WHERE ref = :ItemID AND brand_id = :brand_id', '* FROM', [':ItemID' => $ItemID, ':brand_id' => $global_response_brand['response'][0]['brand_id']]), true);
                if ($response_brand['status'] == true) {
                    $condition = "ref = :ItemID";
                    $whereParams = [':ItemID' => $ItemID];

                    deleteData($db_prefix . 'customer', $condition, $whereParams);
                }

                echo json_encode(['status' => 'true', 'title' => 'Customer Deleted', 'message' => 'The selected customer have been deleted successfully.', 'csrf_token' => $new_csrf_token]);
            } else {
                echo json_encode(['status' => 'false', 'title' => 'Request Failed', 'message' => 'Invalid request', 'csrf_token' => $new_csrf_token]);
            }
        }

        if ($action == "customers-info-byID") {
            if ($global_user_login == true) {
                if (!canAccessPage(json_decode($global_response_permission['response'][0]['permission'], true), 'customers', $global_user_response['response'][0]['role'])) {
                    echo json_encode(['status' => 'false', 'title' => 'Access denied', 'message' => 'You need permission to perform this action. Please contact the admin.', 'csrf_token' => $new_csrf_token]);
                    exit();
                }

                if (!hasPermission(json_decode($global_response_permission['response'][0]['permission'], true), 'customers', 'edit', $global_user_response['response'][0]['role'])) {
                    echo json_encode(['status' => 'false', 'title' => 'Access denied', 'message' => 'You need permission to perform this action. Please contact the admin.', 'csrf_token' => $new_csrf_token]);
                    exit();
                }

                $ItemID = $request->post('ItemID', '');

                $response_brand = json_decode(getData($db_prefix . 'customer', 'WHERE ref = :ItemID AND brand_id = :brand_id', '* FROM', [':ItemID' => $ItemID, ':brand_id' => $global_response_brand['response'][0]['brand_id']]), true);
                if ($response_brand['status'] == true) {
                    echo json_encode(['status' => 'true', 'name' => $response_brand['response'][0]['name'], 'email' => $response_brand['response'][0]['email'], 'mobile' => $response_brand['response'][0]['mobile'], 'istatus' => $response_brand['response'][0]['status'], 'suspend_reason' => ($response_brand['response'][0]['suspend_reason'] === "--") ? "" : $response_brand['response'][0]['suspend_reason'], 'csrf_token' => $new_csrf_token]);
                } else {
                    echo json_encode(['status' => 'false', 'title' => 'Request Failed', 'message' => 'Invalid request', 'csrf_token' => $new_csrf_token]);
                }
            } else {
                echo json_encode(['status' => 'false', 'title' => 'Request Failed', 'message' => 'Invalid request', 'csrf_token' => $new_csrf_token]);
            }
        }

        if ($action == "customers-edit") {
            if ($global_user_login == true) {
                if (!canAccessPage(json_decode($global_response_permission['response'][0]['permission'], true), 'customers', $global_user_response['response'][0]['role'])) {
                    echo json_encode(['status' => 'false', 'title' => 'Access denied', 'message' => 'You need permission to perform this action. Please contact the admin.', 'csrf_token' => $new_csrf_token]);
                    exit();
                }

                if (!hasPermission(json_decode($global_response_permission['response'][0]['permission'], true), 'customers', 'edit', $global_user_response['response'][0]['role'])) {
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
                    $suspend_reason = "--";
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
                        $response = json_decode(getData($db_prefix . 'customer', 'WHERE brand_id = :brand_id AND ref = :customer_id', '* FROM', [':brand_id' => $global_response_brand['response'][0]['brand_id'], ':customer_id' => $customer_id]), true);
                        if ($response['status'] == true) {
                            if ($response['response'][0]['email'] == $email) {

                            } else {
                                $responseCheck = json_decode(getData($db_prefix . 'customer', 'WHERE brand_id = :brand_id AND email = :email', '* FROM', [':brand_id' => $global_response_brand['response'][0]['brand_id'], ':email' => $email]), true);
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

                            updateData($db_prefix . 'customer', $columns, $values, $condition, $whereParams);

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
