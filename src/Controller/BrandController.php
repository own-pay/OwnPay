<?php
declare(strict_types=1);

namespace AnirbanPay\Controller;

use AnirbanPay\Http\RequestContext;

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
        $global_response_permission = $ctx->permissionResponse;
        $global_user_response = $ctx->userResponse;
        $global_response_brand = $ctx->brandResponse;
        $new_csrf_token = $ctx->csrfToken;

        if ($global_user_login == true) {
            if (!canAccessPage(json_decode($global_response_permission['response'][0]['permission'], true), 'brands', $global_user_response['response'][0]['role'])) {
                echo json_encode(['status' => 'false', 'title' => 'Access denied', 'message' => 'You need permission to perform this action. Please contact the admin.', 'csrf_token' => $new_csrf_token]);
                exit();
            }

            if (!hasPermission(json_decode($global_response_permission['response'][0]['permission'], true), 'brands', 'create', $global_user_response['response'][0]['role'])) {
                echo json_encode(['status' => 'false', 'title' => 'Access denied', 'message' => 'You need permission to perform this action. Please contact the admin.', 'csrf_token' => $new_csrf_token]);
                exit();
            }

            $request = \AnirbanPay\Http\Request::createFromGlobals();

            $brand_name = $request->post('brand-name', '');

            if ($brand_name == "") {
                echo json_encode(['status' => "false", 'title' => 'Incomplete Information', 'message' => 'Please fill in all required fields before proceeding.', 'csrf_token' => $new_csrf_token]);
            } else {
                $response = json_decode(getData($db_prefix . 'brands', 'WHERE identify_name = :name', '* FROM', [':name' => $brand_name]), true);
                if ($response['status'] == false) {
                    $brand_id = generateItemID();

                    $columns = ['brand_id', 'identify_name', 'created_date', 'updated_date'];
                    $values = [$brand_id, $brand_name, getCurrentDatetime('Y-m-d H:i:s'), getCurrentDatetime('Y-m-d H:i:s')];

                    insertData($db_prefix . 'brands', $columns, $values);

                    $columns = ['brand_id', 'a_id', 'permission', 'created_date', 'updated_date'];
                    $values = [$brand_id, $global_user_response['response'][0]['a_id'], json_encode(permissionSchema()), getCurrentDatetime('Y-m-d H:i:s'), getCurrentDatetime('Y-m-d H:i:s')];

                    insertData($db_prefix . 'permission', $columns, $values);

                    $columns = ['brand_id', 'code', 'symbol', 'created_date', 'updated_date'];
                    $values = [$brand_id, 'BDT', '৳', getCurrentDatetime('Y-m-d H:i:s'), getCurrentDatetime('Y-m-d H:i:s')];

                    insertData($db_prefix . 'currency', $columns, $values);

                    if ($global_user_response['response'][0]['role'] !== 'admin') {
                        $response_admin = json_decode(getData($db_prefix . 'admin', 'WHERE role = :role', '* FROM', [':role' => 'admin']), true);
                        foreach ($response_admin['response'] as $admins) {
                            $columns = ['brand_id', 'a_id', 'permission', 'created_date', 'updated_date'];
                            $values = [$brand_id, $admins['a_id'], json_encode(permissionSchema()), getCurrentDatetime('Y-m-d H:i:s'), getCurrentDatetime('Y-m-d H:i:s')];

                            insertData($db_prefix . 'permission', $columns, $values);
                        }
                    }

                    setsCookie('ap_brand', $brand_id);

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
        $global_response_permission = $ctx->permissionResponse;
        $global_user_response = $ctx->userResponse;
        $global_response_brand = $ctx->brandResponse;
        $new_csrf_token = $ctx->csrfToken;

        if ($global_user_login == true) {
            if (!canAccessPage(json_decode($global_response_permission['response'][0]['permission'], true), 'brands', $global_user_response['response'][0]['role'])) {
                echo json_encode(['status' => 'false', 'title' => 'Access denied', 'message' => 'You need permission to perform this action. Please contact the admin.', 'csrf_token' => $new_csrf_token]);
                exit();
            }

            $request = \AnirbanPay\Http\Request::createFromGlobals();

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

            $page = max(1, (int) $request->post('page', '1'));
            $show_limit_val = ($request->post('show_limit') == '') ? 999999 : (int) $request->post('show_limit');
            $offset = ($page - 1) * $show_limit_val;

            $sql_query = '';

            if ($search_input !== '') {
                $sql_query .= " AND ( identify_name LIKE :search OR name LIKE :search )";
                $params_brand[':search'] = "%$search_input%";
            }

            $sql_limit = '';
            if ($show_limit == 'all') {

            } else {
                $sql_limit = " LIMIT $offset, $show_limit";
            }

            $response_result = json_decode(getData($db_prefix . 'brands', ' WHERE ' . $where_sql . ' identify_name NOT IN (:empty) ' . $sql_query . ' ORDER BY 1 DESC ' . $sql_limit, '* FROM', $params_brand), true);
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
                        "created_date" => convertUTCtoUserTZ($row['created_date'], ($global_response_brand['response'][0]['timezone'] === '--' || $global_response_brand['response'][0]['timezone'] === '') ? 'Asia/Dhaka' : $global_response_brand['response'][0]['timezone'], "M d, Y h:i A"),
                        "updated_date" => convertUTCtoUserTZ($row['updated_date'], ($global_response_brand['response'][0]['timezone'] === '--' || $global_response_brand['response'][0]['timezone'] === '') ? 'Asia/Dhaka' : $global_response_brand['response'][0]['timezone'], "M d, Y h:i A")
                    ];
                }

                $count_data = json_decode(getData($db_prefix . 'brands', ' WHERE identify_name NOT IN (:empty) ' . $sql_query, '* FROM', $params_brand), true);

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

    private function bulkAction(RequestContext $ctx): void
    {
        $db_prefix = $ctx->dbPrefix;
        $global_user_login = $ctx->isLoggedIn;
        $global_response_permission = $ctx->permissionResponse;
        $global_user_response = $ctx->userResponse;
        $global_response_brand = $ctx->brandResponse;
        $new_csrf_token = $ctx->csrfToken;

        if ($global_user_login == true) {
            if (!canAccessPage(json_decode($global_response_permission['response'][0]['permission'], true), 'brands', $global_user_response['response'][0]['role'])) {
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

                    $response_brand = json_decode(getData($db_prefix . 'brands', 'WHERE brand_id = :id', '* FROM', $params_item), true);
                    if ($response_brand['status'] == true) {
                        if ($actionID == "deleted") {
                            if ($response_brand['response'][0]['id'] == 1 || $response_brand['response'][0]['brand_id'] == $global_response_brand['response'][0]['brand_id']) {

                            } else {
                                if (hasPermission(json_decode($global_response_permission['response'][0]['permission'], true), 'brands', 'delete', $global_user_response['response'][0]['role'])) {

                                    $condition = "brand_id = :id";
                                    $whereParams = [':id' => $itemID];

                                    deleteData($db_prefix . 'brands', $condition, $whereParams);


                                    $condition = "brand_id = '" . $response_brand['response'][0]['brand_id'] . "'";

                                    deleteData($db_prefix . 'api', $condition);

                                    $condition = "brand_id = '" . $response_brand['response'][0]['brand_id'] . "'";

                                    deleteData($db_prefix . 'currency', $condition);

                                    $condition = "brand_id = '" . $response_brand['response'][0]['brand_id'] . "'";

                                    deleteData($db_prefix . 'customer', $condition);

                                    $condition = "brand_id = '" . $response_brand['response'][0]['brand_id'] . "'";

                                    deleteData($db_prefix . 'env', $condition);

                                    $condition = "brand_id = '" . $response_brand['response'][0]['brand_id'] . "'";

                                    deleteData($db_prefix . 'faq', $condition);

                                    $condition = "brand_id = '" . $response_brand['response'][0]['brand_id'] . "'";

                                    $condition = "brand_id = :brand_id";
                                    $whereParams = [':brand_id' => $response_brand['response'][0]['brand_id']];

                                    deleteData($db_prefix . 'gateways', $condition, $whereParams);

                                    deleteData($db_prefix . 'gateways_parameter', $condition, $whereParams);

                                    deleteData($db_prefix . 'invoice', $condition, $whereParams);

                                    deleteData($db_prefix . 'invoice_items', $condition, $whereParams);

                                    $response_payment_link_filed = json_decode(getData($db_prefix . 'payment_link', 'WHERE brand_id = :brand_id', '* FROM', [':brand_id' => $response_brand['response'][0]['brand_id']]), true);
                                    foreach ($response_payment_link_filed['response'] as $row_paymentfiled) {
                                        $condition = "paymentLinkID = :ref";
                                        $whereParams_field = [':ref' => $row_paymentfiled['ref']];

                                        deleteData($db_prefix . 'payment_link_field', $condition, $whereParams_field);
                                    }

                                    $condition = "brand_id = :brand_id";
                                    $whereParams = [':brand_id' => $response_brand['response'][0]['brand_id']];

                                    deleteData($db_prefix . 'payment_link', $condition, $whereParams);

                                    deleteData($db_prefix . 'permission', $condition, $whereParams);

                                    deleteData($db_prefix . 'transaction', $condition, $whereParams);

                                    deleteData($db_prefix . 'webhook_log', $condition, $whereParams);
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
        $global_response_permission = $ctx->permissionResponse;
        $global_user_response = $ctx->userResponse;
        $global_response_brand = $ctx->brandResponse;
        $new_csrf_token = $ctx->csrfToken;

        if ($global_user_login == true) {
            if (!canAccessPage(json_decode($global_response_permission['response'][0]['permission'], true), 'brands', $global_user_response['response'][0]['role'])) {
                echo json_encode(['status' => 'false', 'title' => 'Access denied', 'message' => 'You need permission to perform this action. Please contact the admin.', 'csrf_token' => $new_csrf_token]);
                exit();
            }

            if (!hasPermission(json_decode($global_response_permission['response'][0]['permission'], true), 'brands', 'delete', $global_user_response['response'][0]['role'])) {
                echo json_encode(['status' => 'false', 'title' => 'Access denied', 'message' => 'You need permission to perform this action. Please contact the admin.', 'csrf_token' => $new_csrf_token]);
                exit();
            }

            $request = \AnirbanPay\Http\Request::createFromGlobals();

            $ItemID = $request->post('ItemID', '');

            $response_brand = json_decode(getData($db_prefix . 'brands', 'WHERE brand_id = :id', '* FROM', [':id' => $ItemID]), true);
            if ($response_brand['status'] == true) {
                if ($response_brand['response'][0]['id'] == 1 || $response_brand['response'][0]['brand_id'] == $global_response_brand['response'][0]['brand_id']) {
                    echo json_encode(['status' => 'false', 'title' => 'Request Failed', 'message' => 'Invalid request', 'csrf_token' => $new_csrf_token]);
                } else {
                    if ($response_brand['response'][0]['id'] == 1 || $response_brand['response'][0]['brand_id'] == $global_response_brand['response'][0]['brand_id']) {
                        echo json_encode(['status' => 'false', 'title' => 'Request Failed', 'message' => 'Invalid request', 'csrf_token' => $new_csrf_token]);
                    } else {
                        $condition = "brand_id = :id";
                        $whereParams = [':id' => $ItemID];

                        deleteData($db_prefix . 'brands', $condition, $whereParams);


                        $condition = "brand_id = :brand_id";
                        $whereParams_cascade = [':brand_id' => $response_brand['response'][0]['brand_id']];

                        deleteData($db_prefix . 'api', $condition, $whereParams_cascade);

                        deleteData($db_prefix . 'currency', $condition, $whereParams_cascade);

                        deleteData($db_prefix . 'customer', $condition, $whereParams_cascade);

                        deleteData($db_prefix . 'env', $condition, $whereParams_cascade);

                        deleteData($db_prefix . 'faq', $condition, $whereParams_cascade);

                        deleteData($db_prefix . 'gateways', $condition, $whereParams_cascade);

                        deleteData($db_prefix . 'gateways_parameter', $condition, $whereParams_cascade);

                        deleteData($db_prefix . 'invoice', $condition, $whereParams_cascade);

                        deleteData($db_prefix . 'invoice_items', $condition, $whereParams_cascade);

                        $response_payment_link_filed = json_decode(getData($db_prefix . 'payment_link', 'WHERE brand_id = :brand_id', '* FROM', [':brand_id' => $response_brand['response'][0]['brand_id']]), true);
                        foreach ($response_payment_link_filed['response'] as $row_paymentfiled) {
                            $condition = "paymentLinkID = :ref";
                            $whereParams_field = [':ref' => $row_paymentfiled['ref']];

                            deleteData($db_prefix . 'payment_link_field', $condition, $whereParams_field);
                        }

                        $condition = "brand_id = :brand_id";
                        $whereParams_cascade = [':brand_id' => $response_brand['response'][0]['brand_id']];

                        deleteData($db_prefix . 'payment_link', $condition, $whereParams_cascade);

                        deleteData($db_prefix . 'permission', $condition, $whereParams_cascade);

                        deleteData($db_prefix . 'transaction', $condition, $whereParams_cascade);

                        deleteData($db_prefix . 'webhook_log', $condition, $whereParams_cascade);

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
        $global_response_permission = $ctx->permissionResponse;
        $global_user_response = $ctx->userResponse;
        $global_response_brand = $ctx->brandResponse;
        $new_csrf_token = $ctx->csrfToken;

        if ($global_user_login == true) {
            if (!canAccessPage(json_decode($global_response_permission['response'][0]['permission'], true), 'brands', $global_user_response['response'][0]['role'])) {
                echo json_encode(['status' => 'false', 'title' => 'Access denied', 'message' => 'You need permission to perform this action. Please contact the admin.', 'csrf_token' => $new_csrf_token]);
                exit();
            }

            if (!hasPermission(json_decode($global_response_permission['response'][0]['permission'], true), 'brands', 'edit', $global_user_response['response'][0]['role'])) {
                echo json_encode(['status' => 'false', 'title' => 'Access denied', 'message' => 'You need permission to perform this action. Please contact the admin.', 'csrf_token' => $new_csrf_token]);
                exit();
            }

            $request = \AnirbanPay\Http\Request::createFromGlobals();

            $brand_name = $request->post('brand-name', '');
            $brand_id = $request->post('b_id', '');

            if ($brand_name == "" || $brand_id == "") {
                echo json_encode(['status' => "false", 'title' => 'Incomplete Information', 'message' => 'Please fill in all required fields before proceeding.', 'csrf_token' => $new_csrf_token]);
            } else {
                $response = json_decode(getData($db_prefix . 'brands', 'WHERE brand_id = :id', '* FROM', [':id' => $brand_id]), true);
                if ($response['status'] == true) {
                    if ($response['response'][0]['id'] == 1 || $response['response'][0]['brand_id'] == $global_response_brand['response'][0]['brand_id']) {
                        echo json_encode(['status' => 'false', 'title' => 'Request Failed', 'message' => 'Invalid request', 'csrf_token' => $new_csrf_token]);
                        exit();
                    }

                    if ($response['response'][0]['identify_name'] !== $brand_name) {
                        $responseNameCheck = json_decode(getData($db_prefix . 'brands', 'WHERE identify_name = :name', '* FROM', [':name' => $brand_name]), true);
                        if ($responseNameCheck['status'] == true) {
                            echo json_encode(['status' => 'false', 'title' => 'Duplicate Brand', 'message' => 'A brand with this name already exists. Please choose a different name.', 'csrf_token' => $new_csrf_token]);
                            exit();
                        }
                    }

                    $columns = ['identify_name', 'updated_date'];
                    $values = [$brand_name, getCurrentDatetime('Y-m-d H:i:s')];
                    $condition = "brand_id = :id";
                    $whereParams = [':id' => $brand_id];

                    updateData($db_prefix . 'brands', $columns, $values, $condition, $whereParams);

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
