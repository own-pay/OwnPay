<?php
declare(strict_types=1);

namespace AnirbanPay\Controller;

use AnirbanPay\Http\RequestContext;

class StaffController
{
    public static function handle(string $action, ?RequestContext $ctx = null): void
    {
        $ctx ??= $GLOBALS['requestContext'] ?? throw new \RuntimeException('RequestContext not available');

        $controller = new self();

        switch ($action) {
            case 'staff-management-list':
                $controller->listStaff($ctx);
                break;
            case 'staff-bulk-action':
                $controller->bulkActionStaff($ctx);
                break;
            case 'staff-delete':
                $controller->deleteStaff($ctx);
                break;
            case 'staff-create':
                $controller->createStaff($ctx);
                break;
            case 'staff-update':
                $controller->updateStaff($ctx);
                break;
            case 'staff-permissions':
                $controller->listPermissions($ctx);
                break;
            case 'staff-permission-bulk-action':
                $controller->bulkActionPermission($ctx);
                break;
            case 'staff-permission-delete':
                $controller->deletePermission($ctx);
                break;
            case 'staff-brand-add':
                $controller->addBrandPermission($ctx);
                break;
            case 'staff-update-permission':
                $controller->updatePermission($ctx);
                break;
        }
    }

    private function listStaff(RequestContext $ctx): void
    {
        $db_prefix = $ctx->dbPrefix;
        $global_user_login = $ctx->isLoggedIn;
        $global_response_permission = $ctx->permissionResponse;
        $global_user_response = $ctx->userResponse;
        $global_response_brand = $ctx->brandResponse;
        $new_csrf_token = $ctx->csrfToken;
        if ($global_user_login == true) {
            if (!canAccessPage(json_decode($global_response_permission['response'][0]['permission'], true), 'staff_management', $global_user_response['response'][0]['role'])) {
                echo json_encode(['status' => 'false', 'title' => 'Access denied', 'message' => 'You need permission to perform this action. Please contact the admin.', 'csrf_token' => $new_csrf_token]);
                exit();
            }

            $request = \AnirbanPay\Http\Request::createFromGlobals();

            $search_input = $request->post('search_input', '');
            $show_limit_val = $request->post('show_limit', '5');

            /* Filters */
            $filter_status = $request->post('filter_status', '');
            $filter_start = $request->post('filter_start', '');
            $filter_end = $request->post('filter_end', '');

            $where = [];
            $params_staff = [':a_id' => $global_user_response['response'][0]['a_id'], ':role' => 'staff'];

            if ($filter_start !== '') {
                $where[] = "created_date >= :filter_start";
                $params_staff[':filter_start'] = "{$filter_start} 00:00:00";
            }

            if ($filter_end !== '') {
                $where[] = "created_date <= :filter_end";
                $params_staff[':filter_end'] = "{$filter_end} 23:59:59";
            }

            if ($filter_status !== '') {
                $where[] = "status = :filter_status";
                $params_staff[':filter_status'] = $filter_status;
            }

            $where_sql = $where ? implode(' AND ', $where) . ' AND ' : '';
            /* Filters */

            $page = max(1, (int) $request->post('page', '1'));
            $show_limit = ($request->post('show_limit') == '') ? 999999 : (int) $request->post('show_limit');
            $offset = ($page - 1) * $show_limit;

            $sql_query = '';

            if ($search_input !== '') {
                $sql_query .= " AND ( full_name LIKE :search OR email LIKE :search OR username LIKE :search)";
                $params_staff[':search'] = "%$search_input%";
            }

            $sql_limit = '';
            if ($show_limit_val == 'all') {

            } else {
                $sql_limit = " LIMIT $offset, $show_limit";
            }

            $response_result = json_decode(getData($db_prefix . 'admin', 'WHERE ' . $where_sql . '  role = :role AND a_id NOT IN (:a_id) ' . $sql_query . ' ORDER BY 1 DESC ' . $sql_limit, '* FROM', $params_staff), true);
            if ($response_result['status'] == true) {
                $response = [];

                foreach ($response_result['response'] as $row) {
                    $response[] = [
                        "id" => $row['a_id'],
                        "name" => $row['full_name'],
                        "username" => $row['username'],
                        "email" => $row['email'],
                        "status" => $row['status'],
                        "role" => $row['role'],
                        "created_date" => convertUTCtoUserTZ($row['created_date'], ($global_response_brand['response'][0]['timezone'] === '--' || $global_response_brand['response'][0]['timezone'] === '') ? 'Asia/Dhaka' : $global_response_brand['response'][0]['timezone'], "M d, Y h:i A"),
                        "updated_date" => convertUTCtoUserTZ($row['updated_date'], ($global_response_brand['response'][0]['timezone'] === '--' || $global_response_brand['response'][0]['timezone'] === '') ? 'Asia/Dhaka' : $global_response_brand['response'][0]['timezone'], "M d, Y h:i A")
                    ];
                }

                $count_data = json_decode(getData($db_prefix . 'admin', 'WHERE ' . $where_sql . ' role="staff" AND a_id NOT IN (:a_id) ' . $sql_query, '* FROM', [':a_id' => $global_user_response['response'][0]['a_id']]), true);

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

    private function bulkActionStaff(RequestContext $ctx): void
    {
        $db_prefix = $ctx->dbPrefix;
        $global_user_login = $ctx->isLoggedIn;
        $global_response_permission = $ctx->permissionResponse;
        $global_user_response = $ctx->userResponse;
        $new_csrf_token = $ctx->csrfToken;
        if ($global_user_login == true) {
            if (!canAccessPage(json_decode($global_response_permission['response'][0]['permission'], true), 'staff_management', $global_user_response['response'][0]['role'])) {
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

                    $response_staff = json_decode(getData($db_prefix . 'admin', 'WHERE role = "staff" AND a_id = :id', '* FROM', [':id' => $itemID]), true);
                    if ($response_staff['status'] == true) {
                        if ($itemID == $global_user_response['response'][0]['a_id']) {

                        } else {
                            if ($actionID == "deleted") {
                                if (hasPermission(json_decode($global_response_permission['response'][0]['permission'], true), 'staff', 'delete', $global_user_response['response'][0]['role'])) {

                                    $condition = "a_id = :a_id";
                                    $whereParams = [':a_id' => $response_staff['response'][0]['a_id']];

                                    deleteData($db_prefix . 'permission', $condition, $whereParams);

                                    deleteData($db_prefix . 'browser_log', $condition, $whereParams);

                                    deleteData($db_prefix . 'admin', $condition, $whereParams);

                                }
                            }

                            if ($actionID == "activated") {
                                if (hasPermission(json_decode($global_response_permission['response'][0]['permission'], true), 'staff', 'edit', $global_user_response['response'][0]['role'])) {

                                    $columns = ['status', 'updated_date'];
                                    $values = ['active', getCurrentDatetime('Y-m-d H:i:s')];
                                    $condition = "a_id = :id";
                                    $whereParams = [':id' => $itemID];

                                    updateData($db_prefix . 'admin', $columns, $values, $condition, $whereParams);

                                }
                            }

                            if ($actionID == "suspended") {
                                if (hasPermission(json_decode($global_response_permission['response'][0]['permission'], true), 'staff', 'edit', $global_user_response['response'][0]['role'])) {

                                    $columns = ['status', 'updated_date'];
                                    $values = ['suspend', getCurrentDatetime('Y-m-d H:i:s')];
                                    $condition = "a_id = :id";
                                    $whereParams = [':id' => $itemID];

                                    updateData($db_prefix . 'admin', $columns, $values, $condition, $whereParams);

                                }
                            }
                        }
                    }
                }

                echo json_encode(['status' => 'true', 'title' => 'Staff ' . $actionID, 'message' => 'The selected staff members have been ' . $actionID . ' successfully.', 'csrf_token' => $new_csrf_token]);
            } else {
                echo json_encode(['status' => 'false', 'title' => 'Request Failed', 'message' => 'No Staff selected.', 'csrf_token' => $new_csrf_token]);
            }
        } else {
            echo json_encode(['status' => 'false', 'title' => 'Request Failed', 'message' => 'Invalid request', 'csrf_token' => $new_csrf_token]);
        }
    }

    private function deleteStaff(RequestContext $ctx): void
    {
        $db_prefix = $ctx->dbPrefix;
        $global_user_login = $ctx->isLoggedIn;
        $global_response_permission = $ctx->permissionResponse;
        $global_user_response = $ctx->userResponse;
        $new_csrf_token = $ctx->csrfToken;
        if ($global_user_login == true) {
            if (!canAccessPage(json_decode($global_response_permission['response'][0]['permission'], true), 'staff_management', $global_user_response['response'][0]['role'])) {
                echo json_encode(['status' => 'false', 'title' => 'Access denied', 'message' => 'You need permission to perform this action. Please contact the admin.', 'csrf_token' => $new_csrf_token]);
                exit();
            }

            if (!hasPermission(json_decode($global_response_permission['response'][0]['permission'], true), 'staff', 'delete', $global_user_response['response'][0]['role'])) {
                echo json_encode(['status' => 'false', 'title' => 'Access denied', 'message' => 'You need permission to perform this action. Please contact the admin.', 'csrf_token' => $new_csrf_token]);
                exit();
            }

            $request = \AnirbanPay\Http\Request::createFromGlobals();

            $ItemID = $request->post('ItemID', '');

            $response_staff = json_decode(getData($db_prefix . 'admin', 'WHERE role = "staff" AND a_id = :id', '* FROM', [':id' => $ItemID]), true);
            if ($response_staff['status'] == true) {
                if ($ItemID == $global_user_response['response'][0]['a_id']) {
                    echo json_encode(['status' => 'false', 'title' => 'Request Failed', 'message' => 'You cannot delete your own account.', 'csrf_token' => $new_csrf_token]);
                } else {
                    $condition = "a_id = :a_id";
                    $whereParams = [':a_id' => $response_staff['response'][0]['a_id']];

                    deleteData($db_prefix . 'permission', $condition, $whereParams);

                    deleteData($db_prefix . 'browser_log', $condition, $whereParams);

                    $condition = "id = :id";
                    $whereParams = [':id' => $response_staff['response'][0]['id']];

                    deleteData($db_prefix . 'admin', $condition, $whereParams);

                    echo json_encode(['status' => 'true', 'title' => 'Staff Deleted', 'message' => 'The staff member have been deleted successfully.', 'csrf_token' => $new_csrf_token]);
                }
            } else {
                echo json_encode(['status' => 'false', 'title' => 'Request Failed', 'message' => 'No Staff selected.', 'csrf_token' => $new_csrf_token]);
            }
        } else {
            echo json_encode(['status' => 'false', 'title' => 'Request Failed', 'message' => 'Invalid request', 'csrf_token' => $new_csrf_token]);
        }
    }

    private function createStaff(RequestContext $ctx): void
    {
        $db_prefix = $ctx->dbPrefix;
        $global_user_login = $ctx->isLoggedIn;
        $global_response_permission = $ctx->permissionResponse;
        $global_user_response = $ctx->userResponse;
        $new_csrf_token = $ctx->csrfToken;
        if ($global_user_login == true) {
            if (!canAccessPage(json_decode($global_response_permission['response'][0]['permission'], true), 'staff_management', $global_user_response['response'][0]['role'])) {
                echo json_encode(['status' => 'false', 'title' => 'Access denied', 'message' => 'You need permission to perform this action. Please contact the admin.', 'csrf_token' => $new_csrf_token]);
                exit();
            }

            if (!hasPermission(json_decode($global_response_permission['response'][0]['permission'], true), 'staff', 'create', $global_user_response['response'][0]['role'])) {
                echo json_encode(['status' => 'false', 'title' => 'Access denied', 'message' => 'You need permission to perform this action. Please contact the admin.', 'csrf_token' => $new_csrf_token]);
                exit();
            }

            $request = \AnirbanPay\Http\Request::createFromGlobals();

            $fullname = $request->post('full-name', '');
            $username = $request->post('username', '');
            $email_address = $request->post('email-address', '');
            $password = $request->post('password', '');
            $brands = $request->post('brands', []);

            if ($fullname == "" || $username == "" || $email_address == "" || $password == "") {
                echo json_encode(['status' => "false", 'title' => 'Incomplete Information', 'message' => 'Please fill in all required fields before proceeding.', 'csrf_token' => $new_csrf_token]);
            } else {
                if (filter_var($email_address, FILTER_VALIDATE_EMAIL)) {

                    $count_brand = 0;

                    foreach ($brands as $count) {
                        $count_brand = $count_brand + 1;
                    }

                    if ($count_brand == 0) {
                        echo json_encode(['status' => 'false', 'title' => 'Request Failed', 'message' => 'You need to allow minimum 1 brand to create a staff', 'csrf_token' => $new_csrf_token]);
                        exit();
                    }

                    $response = json_decode(getData($db_prefix . 'admin', 'WHERE username = :username', '* FROM', [':username' => $username]), true);
                    if ($response['status'] == true) {
                        echo json_encode(['status' => "false", 'title' => 'Incomplete Information', 'message' => 'Username already exits.', 'csrf_token' => $new_csrf_token]);
                        exit();
                    }

                    $response = json_decode(getData($db_prefix . 'admin', 'WHERE email = :email', '* FROM', [':email' => $email_address]), true);
                    if ($response['status'] == true) {
                        echo json_encode(['status' => "false", 'title' => 'Incomplete Information', 'message' => 'Email Address already exits.', 'csrf_token' => $new_csrf_token]);
                        exit();
                    }

                    $new_temp_password = generateStrongPassword(8);
                    $password = password_hash($password, PASSWORD_ARGON2ID);
                    $temp_password = password_hash($new_temp_password, PASSWORD_ARGON2ID);

                    $a_id = generateItemID();

                    $columns = ['a_id', 'full_name', 'username', 'email', 'password', 'temp_password', 'role', 'created_date', 'updated_date'];
                    $values = [$a_id, $fullname, $username, $email_address, $password, $temp_password, 'staff', getCurrentDatetime('Y-m-d H:i:s'), getCurrentDatetime('Y-m-d H:i:s')];

                    insertData($db_prefix . 'admin', $columns, $values);

                    $schema = permissionSchema();

                    $inputPermissions = json_decode($request->post('permissions_json', '{}'), true);

                    $newPermissions = [
                        'resources' => [],
                        'pages' => []
                    ];

                    foreach ($schema['resources'] as $module => $actions) {
                        foreach ($actions as $action => $_) {
                            $newPermissions['resources'][$module][$action] =
                                !empty($inputPermissions['resources'][$module][$action]);
                        }
                    }

                    foreach ($schema['pages'] as $page => $_) {
                        $newPermissions['pages'][$page] =
                            !empty($inputPermissions['pages'][$page]);
                    }

                    $permission_json = json_encode($newPermissions);

                    foreach ($brands as $brand_id) {
                        $brand_id = escape_string($brand_id);

                        $response = json_decode(getData($db_prefix . 'brands', 'WHERE brand_id = :brand_id', '* FROM', [':brand_id' => $brand_id]), true);
                        if ($response['status'] == true) {

                            $columns = ['brand_id', 'a_id', 'permission', 'created_date', 'updated_date'];
                            $values = [$brand_id, $a_id, $permission_json, getCurrentDatetime('Y-m-d H:i:s'), getCurrentDatetime('Y-m-d H:i:s')];

                            insertData($db_prefix . 'permission', $columns, $values);

                        }
                    }

                    echo json_encode(['status' => 'true', 'title' => 'Staff Created', 'message' => 'The staff account has been created successfully.', 'csrf_token' => $new_csrf_token]);
                } else {
                    echo json_encode(['status' => "false", 'title' => 'Invalid Email', 'message' => 'Please enter a valid email address.', 'csrf_token' => $new_csrf_token]);
                }
            }
        } else {
            echo json_encode(['status' => 'false', 'title' => 'Request Failed', 'message' => 'Invalid request', 'csrf_token' => $new_csrf_token]);
        }
    }

    private function updateStaff(RequestContext $ctx): void
    {
        $db_prefix = $ctx->dbPrefix;
        $global_user_login = $ctx->isLoggedIn;
        $global_response_permission = $ctx->permissionResponse;
        $global_user_response = $ctx->userResponse;
        $new_csrf_token = $ctx->csrfToken;
        if ($global_user_login == true) {
            if (!canAccessPage(json_decode($global_response_permission['response'][0]['permission'], true), 'staff_management', $global_user_response['response'][0]['role'])) {
                echo json_encode(['status' => 'false', 'title' => 'Access denied', 'message' => 'You need permission to perform this action. Please contact the admin.', 'csrf_token' => $new_csrf_token]);
                exit();
            }
            if (!hasPermission(json_decode($global_response_permission['response'][0]['permission'], true), 'staff', 'edit', $global_user_response['response'][0]['role'])) {
                echo json_encode(['status' => 'false', 'title' => 'Access denied', 'message' => 'You need permission to perform this action. Please contact the admin.', 'csrf_token' => $new_csrf_token]);
                exit();
            }

            $request = \AnirbanPay\Http\Request::createFromGlobals();

            $fullname = $request->post('full-name', '');
            $username = $request->post('username', '');
            $email_address = $request->post('email-address', '');
            $password = $request->post('password', '');
            $itemID = $request->post('itemID', '');

            $response_staff = json_decode(getData($db_prefix . 'admin', 'WHERE role = "staff" AND a_id = :id', '* FROM', [':id' => $itemID]), true);
            if ($response_staff['status'] == true) {
                if ($global_user_response['response'][0]['a_id'] == $itemID) {
                    echo json_encode(['status' => "false", 'title' => 'Edit Staff Failed', 'message' => 'You are not allowed to edit your own staff information.', 'csrf_token' => $new_csrf_token]);
                    exit();
                }

                if ($fullname == "" || $username == "" || $email_address == "") {
                    echo json_encode(['status' => "false", 'title' => 'Incomplete Information', 'message' => 'Please fill in all required fields before proceeding.', 'csrf_token' => $new_csrf_token]);
                } else {
                    if (filter_var($email_address, FILTER_VALIDATE_EMAIL)) {
                        if ($fullname == "") {
                            $fullname = $response_staff['response'][0]['full_name'];
                        }

                        if ($username !== $response_staff['response'][0]['username']) {
                            $response = json_decode(getData($db_prefix . 'admin', 'WHERE username = :username', '* FROM', [':username' => $username]), true);
                            if ($response['status'] == true) {
                                echo json_encode(['status' => "false", 'title' => 'Incomplete Information', 'message' => 'Username already exits.', 'csrf_token' => $new_csrf_token]);
                                exit();
                            }
                        }

                        if ($email_address !== $response_staff['response'][0]['email']) {
                            $response = json_decode(getData($db_prefix . 'admin', 'WHERE email = :email', '* FROM', [':email' => $email_address]), true);
                            if ($response['status'] == true) {
                                echo json_encode(['status' => "false", 'title' => 'Incomplete Information', 'message' => 'Email Address already exits.', 'csrf_token' => $new_csrf_token]);
                                exit();
                            }
                        }

                        if ($password == "") {
                            $password = $response_staff['response'][0]['password'];
                            $temp_password = $response_staff['response'][0]['temp_password'];
                        } else {
                            $new_temp_password = generateStrongPassword(8);
                            $password = password_hash($password, PASSWORD_ARGON2ID);
                            $temp_password = password_hash($new_temp_password, PASSWORD_ARGON2ID);
                        }

                        $columns = ['full_name', 'username', 'email', 'password', 'temp_password', 'updated_date'];
                        $values = [$fullname, $username, $email_address, $password, $temp_password, getCurrentDatetime('Y-m-d H:i:s')];
                        $condition = "a_id = :a_id";
                        $whereParams = [':a_id' => $response_staff['response'][0]['a_id']];

                        updateData($db_prefix . 'admin', $columns, $values, $condition, $whereParams);

                        echo json_encode(['status' => 'true', 'title' => 'Staff Profile Updated', 'message' => 'Staff profile information has been updated successfully.', 'csrf_token' => $new_csrf_token]);
                    } else {
                        echo json_encode(['status' => "false", 'title' => 'Invalid Email', 'message' => 'Please enter a valid email address.', 'csrf_token' => $new_csrf_token]);
                    }
                }
            }
        } else {
            echo json_encode(['status' => 'false', 'title' => 'Request Failed', 'message' => 'Invalid request', 'csrf_token' => $new_csrf_token]);
        }
    }

    private function listPermissions(RequestContext $ctx): void
    {
        $db_prefix = $ctx->dbPrefix;
        $global_user_login = $ctx->isLoggedIn;
        $global_response_permission = $ctx->permissionResponse;
        $global_user_response = $ctx->userResponse;
        $global_response_brand = $ctx->brandResponse;
        $new_csrf_token = $ctx->csrfToken;
        if ($global_user_login == true) {
            if (!canAccessPage(json_decode($global_response_permission['response'][0]['permission'], true), 'staff_management', $global_user_response['response'][0]['role'])) {
                echo json_encode(['status' => 'false', 'title' => 'Access denied', 'message' => 'You need permission to perform this action. Please contact the admin.', 'csrf_token' => $new_csrf_token]);
                exit();
            }

            if (!hasPermission(json_decode($global_response_permission['response'][0]['permission'], true), 'staff', 'view_permission_list', $global_user_response['response'][0]['role'])) {
                echo json_encode(['status' => 'false', 'title' => 'Access denied', 'message' => 'You need permission to perform this action. Please contact the admin.', 'csrf_token' => $new_csrf_token]);
                exit();
            }

            $request = \AnirbanPay\Http\Request::createFromGlobals();

            $show_limit_val = $request->post('show_limit', '5');
            $a_id = $request->post('a_id', '');

            /* Filters */
            $filter_status = $request->post('filter_status', '');
            $filter_start = $request->post('filter_start', '');
            $filter_end = $request->post('filter_end', '');

            $where = [];

            if ($filter_start !== '') {
                $where[] = "created_date >= '{$filter_start} 00:00:00'";
            }

            if ($filter_end !== '') {
                $where[] = "created_date <= '{$filter_end} 23:59:59'";
            }

            if ($filter_status !== '') {
                $where[] = "status = '{$filter_status}'";
            }

            $where_sql = $where ? implode(' AND ', $where) . ' AND ' : '';
            /* Filters */

            $page = max(1, (int) $request->post('page', '1'));
            $show_limit = ($request->post('show_limit') == '') ? 999999 : (int) $request->post('show_limit');
            $offset = ($page - 1) * $show_limit;

            $sql_limit = '';
            if ($show_limit_val == 'all') {

            } else {
                $sql_limit = " LIMIT $offset, $show_limit";
            }

            $response_staff = json_decode(getData($db_prefix . 'admin', 'WHERE a_id = :id AND id NOT IN (:global_id) AND role = :role', '* FROM', [':id' => $a_id, ':global_id' => $global_user_response['response'][0]['id'], ':role' => 'staff']), true);
            if ($response_staff['status'] == true) {
                if ($global_user_response['response'][0]['a_id'] == $a_id) {
                    echo json_encode(['status' => 'false', 'title' => 'Request Failed', 'message' => "You can't edit your info", 'csrf_token' => $new_csrf_token]);
                    exit();
                }
            } else {
                echo json_encode(['status' => 'false', 'title' => 'Request Failed', 'message' => "Invalid Staff ID", 'csrf_token' => $new_csrf_token]);
                exit();
            }

            $response_result = json_decode(getData($db_prefix . 'permission', 'WHERE ' . $where_sql . ' a_id = :a_id ORDER BY 1 DESC ' . $sql_limit, '* FROM', [':a_id' => $response_staff['response'][0]['a_id']]), true);
            if ($response_result['status'] == true) {
                $response = [];

                foreach ($response_result['response'] as $row) {
                    $response_brand = json_decode(getData($db_prefix . 'brands', 'WHERE brand_id = :brand_id', '* FROM', [':brand_id' => $row['brand_id']]), true);
                    if ($response_brand['status'] == true) {
                        $response[] = [
                            "id" => $row['id'],
                            "identify_name" => $response_brand['response'][0]['identify_name'],
                            "brandname" => $response_brand['response'][0]['name'],
                            "status" => $row['status'],
                            "created_date" => convertUTCtoUserTZ($row['created_date'], ($global_response_brand['response'][0]['timezone'] === '--' || $global_response_brand['response'][0]['timezone'] === '') ? 'Asia/Dhaka' : $global_response_brand['response'][0]['timezone'], "M d, Y h:i A"),
                            "updated_date" => convertUTCtoUserTZ($row['updated_date'], ($global_response_brand['response'][0]['timezone'] === '--' || $global_response_brand['response'][0]['timezone'] === '') ? 'Asia/Dhaka' : $global_response_brand['response'][0]['timezone'], "M d, Y h:i A")
                        ];
                    }
                }

                $count_data = json_decode(getData($db_prefix . 'permission', 'WHERE a_id = :a_id', '* FROM', [':a_id' => $response_staff['response'][0]['a_id']]), true);

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

    private function bulkActionPermission(RequestContext $ctx): void
    {
        $db_prefix = $ctx->dbPrefix;
        $global_user_login = $ctx->isLoggedIn;
        $global_response_permission = $ctx->permissionResponse;
        $global_user_response = $ctx->userResponse;
        $new_csrf_token = $ctx->csrfToken;
        if ($global_user_login == true) {
            if (!canAccessPage(json_decode($global_response_permission['response'][0]['permission'], true), 'staff_management', $global_user_response['response'][0]['role'])) {
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

                    $response_brand = json_decode(getData($db_prefix . 'permission', 'WHERE id = :id', '* FROM', [':id' => $itemID]), true);
                    if ($response_brand['status'] == true) {
                        if ($response_brand['response'][0]['a_id'] == $global_user_response['response'][0]['a_id']) {

                        } else {
                            $response_admin = json_decode(getData($db_prefix . 'admin', 'WHERE role = "admin" AND a_id = :a_id', '* FROM', [':a_id' => $response_brand['response'][0]['a_id']]), true);
                            if ($response_admin['status'] == true) {

                            } else {
                                if ($actionID == "deleted") {
                                    if (hasPermission(json_decode($global_response_permission['response'][0]['permission'], true), 'staff', 'delete_permission_of', $global_user_response['response'][0]['role'])) {
                                        $condition = "id = :id";
                                        $whereParams = [':id' => $itemID];

                                        deleteData($db_prefix . 'permission', $condition, $whereParams);
                                    }
                                }

                                if ($actionID == "activated") {
                                    if (hasPermission(json_decode($global_response_permission['response'][0]['permission'], true), 'staff', 'edit_permission', $global_user_response['response'][0]['role'])) {
                                        $columns = ['status', 'updated_date'];
                                        $values = ['active', getCurrentDatetime('Y-m-d H:i:s')];
                                        $condition = "id = :id";
                                        $whereParams = [':id' => $itemID];

                                        updateData($db_prefix . 'permission', $columns, $values, $condition, $whereParams);
                                    }
                                }

                                if ($actionID == "suspended") {
                                    if (hasPermission(json_decode($global_response_permission['response'][0]['permission'], true), 'staff', 'edit_permission', $global_user_response['response'][0]['role'])) {
                                        $columns = ['status', 'updated_date'];
                                        $values = ['suspend', getCurrentDatetime('Y-m-d H:i:s')];
                                        $condition = "id = :id";
                                        $whereParams = [':id' => $itemID];

                                        updateData($db_prefix . 'permission', $columns, $values, $condition, $whereParams);
                                    }
                                }
                            }
                        }
                    }
                }

                echo json_encode(['status' => 'true', 'title' => 'Staff Permissions ' . $actionID, 'message' => 'The selected staff permissions have been ' . $actionID . ' successfully.', 'csrf_token' => $new_csrf_token]);
            } else {
                echo json_encode(['status' => 'false', 'title' => 'Request Failed', 'message' => 'No Staff selected.', 'csrf_token' => $new_csrf_token]);
            }
        } else {
            echo json_encode(['status' => 'false', 'title' => 'Request Failed', 'message' => 'Invalid request', 'csrf_token' => $new_csrf_token]);
        }
    }

    private function deletePermission(RequestContext $ctx): void
    {
        $db_prefix = $ctx->dbPrefix;
        $global_user_login = $ctx->isLoggedIn;
        $global_response_permission = $ctx->permissionResponse;
        $global_user_response = $ctx->userResponse;
        $new_csrf_token = $ctx->csrfToken;
        if ($global_user_login == true) {
            if (!canAccessPage(json_decode($global_response_permission['response'][0]['permission'], true), 'staff_management', $global_user_response['response'][0]['role'])) {
                echo json_encode(['status' => 'false', 'title' => 'Access denied', 'message' => 'You need permission to perform this action. Please contact the admin.', 'csrf_token' => $new_csrf_token]);
                exit();
            }
            if (!hasPermission(json_decode($global_response_permission['response'][0]['permission'], true), 'staff', 'delete_permission', $global_user_response['response'][0]['role'])) {
                echo json_encode(['status' => 'false', 'title' => 'Access denied', 'message' => 'You need permission to perform this action. Please contact the admin.', 'csrf_token' => $new_csrf_token]);
                exit();
            }

            $request = \AnirbanPay\Http\Request::createFromGlobals();

            $ItemID = $request->post('ItemID', '');

            $response_permision = json_decode(getData($db_prefix . 'permission', 'WHERE id = :id', '* FROM', [':id' => $ItemID]), true);
            if ($response_permision['status'] == true) {
                $response_staff = json_decode(getData($db_prefix . 'admin', 'WHERE role = "staff" AND a_id = :a_id', '* FROM', [':a_id' => $response_permision['response'][0]['a_id']]), true);
                if ($response_staff['status'] == true) {
                    if ($response_staff['response'][0]['id'] == $global_user_response['response'][0]['id']) {
                        echo json_encode(['status' => 'false', 'title' => 'Request Failed', 'message' => 'You cannot delete your own permission.', 'csrf_token' => $new_csrf_token]);
                    } else {
                        $condition = "id = :id";
                        $whereParams = [':id' => $ItemID];

                        deleteData($db_prefix . 'permission', $condition, $whereParams);

                        echo json_encode(['status' => 'true', 'title' => 'Staff Permission Deleted', 'message' => 'The staff member permission have been deleted successfully.', 'csrf_token' => $new_csrf_token]);
                    }
                } else {
                    echo json_encode(['status' => 'false', 'title' => 'Request Failed', 'message' => 'No Staff selected.', 'csrf_token' => $new_csrf_token]);
                }
            } else {
                echo json_encode(['status' => 'false', 'title' => 'Request Failed', 'message' => 'Invalid Permission ID', 'csrf_token' => $new_csrf_token]);
            }
        } else {
            echo json_encode(['status' => 'false', 'title' => 'Request Failed', 'message' => 'Invalid request', 'csrf_token' => $new_csrf_token]);
        }
    }

    private function addBrandPermission(RequestContext $ctx): void
    {
        $db_prefix = $ctx->dbPrefix;
        $global_user_login = $ctx->isLoggedIn;
        $global_response_permission = $ctx->permissionResponse;
        $global_user_response = $ctx->userResponse;
        $new_csrf_token = $ctx->csrfToken;
        if ($global_user_login == true) {
            if (!canAccessPage(json_decode($global_response_permission['response'][0]['permission'], true), 'staff_management', $global_user_response['response'][0]['role'])) {
                echo json_encode(['status' => 'false', 'title' => 'Access denied', 'message' => 'You need permission to perform this action. Please contact the admin.', 'csrf_token' => $new_csrf_token]);
                exit();
            }

            if (!hasPermission(json_decode($global_response_permission['response'][0]['permission'], true), 'staff', 'assign_brand_to', $global_user_response['response'][0]['role'])) {
                echo json_encode(['status' => 'false', 'title' => 'Access denied', 'message' => 'You need permission to perform this action. Please contact the admin.', 'csrf_token' => $new_csrf_token]);
                exit();
            }

            $request = \AnirbanPay\Http\Request::createFromGlobals();

            $staffID = $request->post('staff_id', '');
            $brands = $request->post('brands', []);

            $count_brand = 0;

            foreach ($brands as $count) {
                $count_brand = $count_brand + 1;
            }

            if ($count_brand == 0) {
                echo json_encode(['status' => 'false', 'title' => 'Request Failed', 'message' => 'You need to allow minimum 1 brand to create a permission', 'csrf_token' => $new_csrf_token]);
                exit();
            }

            $response_staff = json_decode(getData($db_prefix . 'admin', 'WHERE role = "staff" AND a_id = :id', '* FROM', [':id' => $staffID]), true);
            if ($response_staff['status'] == true) {
                if ($global_user_response['response'][0]['a_id'] == $staffID) {
                    echo json_encode(['status' => "false", 'title' => 'Edit Staff Failed', 'message' => 'You are not allowed to edit your own permissions.', 'csrf_token' => $new_csrf_token]);
                    exit();
                }

                foreach ($brands as $brandid) {
                    $response_brand = json_decode(getData($db_prefix . 'brands', ' WHERE brand_id = :id', '* FROM', [':id' => $brandid]), true);
                    if ($response_brand['status'] == true) {
                        foreach ($response_brand['response'] as $row) {
                            $response_permission = json_decode(getData($db_prefix . 'permission', ' WHERE a_id = :a_id AND brand_id = :brand_id', '* FROM', [':a_id' => $response_staff['response'][0]['a_id'], ':brand_id' => $row['brand_id']]), true);

                            if ($response_permission['status'] == true) {

                            } else {

                                $columns = ['brand_id', 'a_id', 'permission', 'created_date', 'updated_date'];
                                $values = [$brandid, $response_staff['response'][0]['a_id'], json_encode(permissionSchema()), getCurrentDatetime('Y-m-d H:i:s'), getCurrentDatetime('Y-m-d H:i:s')];

                                insertData($db_prefix . 'permission', $columns, $values);

                            }
                        }
                    }
                }

                echo json_encode(['status' => 'true', 'title' => 'Brand Assigned Successfully', 'message' => 'The brand has been successfully assigned to the staff member.', 'csrf_token' => $new_csrf_token]);
            } else {
                echo json_encode(['status' => 'false', 'title' => 'Request Failed', 'message' => 'Invalid request', 'csrf_token' => $new_csrf_token]);
            }
        } else {
            echo json_encode(['status' => 'false', 'title' => 'Request Failed', 'message' => 'Invalid request', 'csrf_token' => $new_csrf_token]);
        }
    }

    private function updatePermission(RequestContext $ctx): void
    {
        $db_prefix = $ctx->dbPrefix;
        $global_user_login = $ctx->isLoggedIn;
        $global_response_permission = $ctx->permissionResponse;
        $global_user_response = $ctx->userResponse;
        $new_csrf_token = $ctx->csrfToken;
        if ($global_user_login == true) {
            if (!canAccessPage(json_decode($global_response_permission['response'][0]['permission'], true), 'staff_management', $global_user_response['response'][0]['role'])) {
                echo json_encode(['status' => 'false', 'title' => 'Access denied', 'message' => 'You need permission to perform this action. Please contact the admin.', 'csrf_token' => $new_csrf_token]);
                exit();
            }

            if (!hasPermission(json_decode($global_response_permission['response'][0]['permission'], true), 'staff', 'edit_permission', $global_user_response['response'][0]['role'])) {
                echo json_encode(['status' => 'false', 'title' => 'Access denied', 'message' => 'You need permission to perform this action. Please contact the admin.', 'csrf_token' => $new_csrf_token]);
                exit();
            }

            $request = \AnirbanPay\Http\Request::createFromGlobals();

            $permission_id = $request->post('staff_id', '');
            $status = $request->post('status', '');

            $schema = permissionSchema();

            $inputPermissions = json_decode($request->post('permissions_json', '{}'), true);

            $newPermissions = [
                'resources' => [],
                'pages' => []
            ];

            foreach ($schema['resources'] as $module => $actions) {
                foreach ($actions as $action => $_) {
                    $newPermissions['resources'][$module][$action] =
                        !empty($inputPermissions['resources'][$module][$action]);
                }
            }

            foreach ($schema['pages'] as $page => $_) {
                $newPermissions['pages'][$page] =
                    !empty($inputPermissions['pages'][$page]);
            }

            $permission_json = json_encode($newPermissions);

            $response = json_decode(getData($db_prefix . 'permission', 'WHERE id = :id', '* FROM', [':id' => $permission_id]), true);
            if ($response['status'] == true) {
                $response_staff = json_decode(getData($db_prefix . 'admin', 'WHERE role = "staff" AND a_id = :a_id', '* FROM', [':a_id' => $response['response'][0]['a_id']]), true);
                if ($response_staff['status'] == true) {
                    if ($global_user_response['response'][0]['a_id'] == $response['response'][0]['a_id']) {
                        echo json_encode(['status' => "false", 'title' => 'Edit Staff Failed', 'message' => 'You are not allowed to edit your own permissions.', 'csrf_token' => $new_csrf_token]);
                        exit();
                    }

                    $columns = ['permission', 'updated_date', 'status'];
                    $values = [$permission_json, getCurrentDatetime('Y-m-d H:i:s'), $status];

                    $condition = "id = :id";
                    $whereParams = [':id' => $permission_id];

                    updateData($db_prefix . 'permission', $columns, $values, $condition, $whereParams);

                    echo json_encode(['status' => 'true', 'title' => 'Permissions Updated', 'message' => 'The staff brand permissions has been created successfully.', 'csrf_token' => $new_csrf_token]);
                } else {
                    echo json_encode(['status' => 'false', 'title' => 'Request Failed', 'message' => 'Invalid request', 'csrf_token' => $new_csrf_token]);
                }
            } else {
                echo json_encode(['status' => 'false', 'title' => 'Request Failed', 'message' => 'Invalid request', 'csrf_token' => $new_csrf_token]);
            }
        } else {
            echo json_encode(['status' => 'false', 'title' => 'Request Failed', 'message' => 'Invalid request', 'csrf_token' => $new_csrf_token]);
        }
    }
}
