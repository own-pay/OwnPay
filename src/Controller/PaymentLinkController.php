<?php
declare(strict_types=1);

namespace AnirbanPay\Controller;

use AnirbanPay\Http\RequestContext;

class PaymentLinkController
{
    public static function handle(string $action, ?RequestContext $ctx = null): void
    {
        $ctx ??= $GLOBALS['requestContext'] ?? throw new \RuntimeException('RequestContext not available');

        $controller = new self();

        switch ($action) {
            case 'paymentLink-list':
                $controller->list($ctx);
                break;
            case 'paymentLink-bulk-action':
                $controller->bulkAction($ctx);
                break;
            case 'paymentLink-delete':
                $controller->delete($ctx);
                break;
            case 'paymentLink-create':
                $controller->create($ctx);
                break;
            case 'paymentLink-edit':
                $controller->edit($ctx);
                break;
            case 'paymentLink-defaultLinkCurrency':
                $controller->defaultLinkCurrency($ctx);
                break;
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
            if (!canAccessPage(json_decode($global_response_permission['response'][0]['permission'], true), 'payment_link', $global_user_response['response'][0]['role'])) {
                echo json_encode(['status' => 'false', 'title' => 'Access denied', 'message' => 'You need permission to perform this action. Please contact the admin.', 'csrf_token' => $new_csrf_token]);
                exit();
            }

            $request = \AnirbanPay\Http\Request::createFromGlobals();

            $search_input = $request->post('search_input', '');
            $show_limit = $request->post('show_limit', '5');

            /* Filters */
            $filter_status = $request->post('filter_status', '');
            $filter_start = $request->post('filter_start', '');
            $filter_end = $request->post('filter_end', '');

            $where = [];
            $params_pl = [':brand_id' => $global_response_brand['response'][0]['brand_id']];

            if ($filter_start !== '') {
                $where[] = "created_date >= :filter_start";
                $params_pl[':filter_start'] = "{$filter_start} 00:00:00";
            }

            if ($filter_end !== '') {
                $where[] = "created_date <= :filter_end";
                $params_pl[':filter_end'] = "{$filter_end} 23:59:59";
            }

            if ($filter_status !== '') {
                $where[] = "status = :filter_status";
                $params_pl[':filter_status'] = $filter_status;
            }

            $where_sql = $where ? implode(' AND ', $where) . ' AND ' : '';
            /* Filters */

            $page = max(1, (int) $request->post('page', '1'));
            $show_limit_val = ($request->post('show_limit') == '') ? 999999 : (int) $request->post('show_limit');
            $offset = ($page - 1) * $show_limit_val;

            $sql_query = '';

            if ($search_input !== '') {
                $sql_query .= " AND ( product_info LIKE :search )";
                $params_pl[':search'] = "%$search_input%";
            }

            $sql_limit = '';
            if ($show_limit_val == 'all') {

            } else {
                $sql_limit = " LIMIT $offset, $show_limit_val";
            }

            $response_result = json_decode(getData($db_prefix . 'payment_link', ' WHERE ' . $where_sql . ' brand_id = :brand_id ' . $sql_query . ' ORDER BY 1 DESC ' . $sql_limit, '* FROM', $params_pl), true);
            if ($response_result['status'] == true) {
                $response = [];

                foreach ($response_result['response'] as $row) {
                    $product_info = json_decode($row['product_info'], true);

                    $params_curr = [':brand_id' => $global_response_brand['response'][0]['brand_id'], ':code' => $row['currency']];
                    $response_currency = json_decode(getData($db_prefix . 'currency', ' WHERE brand_id = :brand_id AND code = :code', '* FROM', $params_curr), true);

                    $currency = $response_currency['response'][0]['symbol'] ?? '';

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
                        "id" => $row['ref'],
                        "title" => $product_info['title'] ?? 'N/A',
                        "description" => $product_info['description'] ?? 'N/A',
                        "status" => $status,
                        "quantity" => $row['quantity'],
                        "amount" => $currency . money_round($row['amount'], 2),
                        "created_date" => convertUTCtoUserTZ($row['created_date'], ($global_response_brand['response'][0]['timezone'] === '--' || $global_response_brand['response'][0]['timezone'] === '') ? 'Asia/Dhaka' : $global_response_brand['response'][0]['timezone'], "M d, Y h:i A"),
                        "updated_date" => convertUTCtoUserTZ($row['updated_date'], ($global_response_brand['response'][0]['timezone'] === '--' || $global_response_brand['response'][0]['timezone'] === '') ? 'Asia/Dhaka' : $global_response_brand['response'][0]['timezone'], "M d, Y h:i A")
                    ];
                }

                $count_data = json_decode(getData($db_prefix . 'payment_link', ' WHERE ' . $where_sql . ' brand_id = :brand_id ' . $sql_query, '* FROM', $params_pl), true);

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
            if (!canAccessPage(json_decode($global_response_permission['response'][0]['permission'], true), 'payment_link', $global_user_response['response'][0]['role'])) {
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

                    $response_brand = json_decode(getData($db_prefix . 'payment_link', 'WHERE ref = :itemID AND brand_id = :brand_id', '* FROM', [':itemID' => $itemID, ':brand_id' => $global_response_brand['response'][0]['brand_id']]), true);
                    if ($response_brand['status'] == true) {
                        if ($actionID == "deleted") {
                            if (hasPermission(json_decode($global_response_permission['response'][0]['permission'], true), 'payment_link', 'delete', $global_user_response['response'][0]['role'])) {

                                $condition = "paymentLinkID = :itemID";
                                $whereParams_field = [':itemID' => $itemID];

                                deleteData($db_prefix . 'payment_link_field', $condition, $whereParams_field);

                                $condition = "ref = :itemID";
                                $whereParams = [':itemID' => $itemID];

                                deleteData($db_prefix . 'payment_link', $condition, $whereParams);

                            }
                        }

                        if ($actionID == "activated") {
                            if (hasPermission(json_decode($global_response_permission['response'][0]['permission'], true), 'payment_link', 'edit', $global_user_response['response'][0]['role'])) {

                                $columns = ['status', 'updated_date'];
                                $values = ['active', getCurrentDatetime('Y-m-d H:i:s')];
                                $condition = "ref = :itemID";
                                $whereParams = [':itemID' => $itemID];

                                updateData($db_prefix . 'payment_link', $columns, $values, $condition, $whereParams);

                            }
                        }

                        if ($actionID == "inactivated") {
                            if (hasPermission(json_decode($global_response_permission['response'][0]['permission'], true), 'payment_link', 'edit', $global_user_response['response'][0]['role'])) {

                                $columns = ['status', 'updated_date'];
                                $values = ['inactive', getCurrentDatetime('Y-m-d H:i:s')];
                                $condition = "ref = :itemID";
                                $whereParams = [':itemID' => $itemID];

                                updateData($db_prefix . 'payment_link', $columns, $values, $condition, $whereParams);

                            }
                        }
                    }
                }

                echo json_encode(['status' => 'true', 'title' => 'Payment Links ' . $actionID, 'message' => 'The selected payment links have been ' . $actionID . ' successfully.', 'csrf_token' => $new_csrf_token]);
            } else {
                echo json_encode(['status' => 'false', 'title' => 'Payment Links Failed', 'message' => 'No payment links selected.', 'csrf_token' => $new_csrf_token]);
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
            if (!canAccessPage(json_decode($global_response_permission['response'][0]['permission'], true), 'payment_link', $global_user_response['response'][0]['role'])) {
                echo json_encode(['status' => 'false', 'title' => 'Access denied', 'message' => 'You need permission to perform this action. Please contact the admin.', 'csrf_token' => $new_csrf_token]);
                exit();
            }

            if (!hasPermission(json_decode($global_response_permission['response'][0]['permission'], true), 'payment_link', 'delete', $global_user_response['response'][0]['role'])) {
                echo json_encode(['status' => 'false', 'title' => 'Access denied', 'message' => 'You need permission to perform this action. Please contact the admin.', 'csrf_token' => $new_csrf_token]);
                exit();
            }

            $request = \AnirbanPay\Http\Request::createFromGlobals();

            $ItemID = $request->post('ItemID', '');

            $response_brand = json_decode(getData($db_prefix . 'payment_link', 'WHERE ref = :ItemID AND brand_id = :brand_id', '* FROM', [':ItemID' => $ItemID, ':brand_id' => $global_response_brand['response'][0]['brand_id']]), true);
            if ($response_brand['status'] == true) {
                $condition = "paymentLinkID = :ItemID";
                $whereParams_field = [':ItemID' => $ItemID];

                deleteData($db_prefix . 'payment_link_field', $condition, $whereParams_field);

                $condition = "ref = :ItemID";
                $whereParams = [':ItemID' => $ItemID];

                deleteData($db_prefix . 'payment_link', $condition, $whereParams);
            }

            echo json_encode(['status' => 'true', 'title' => 'Payment Links Deleted', 'message' => 'The selected payment link have been deleted successfully.', 'csrf_token' => $new_csrf_token]);
        } else {
            echo json_encode(['status' => 'false', 'title' => 'Request Failed', 'message' => 'Invalid request', 'csrf_token' => $new_csrf_token]);
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
            if (!canAccessPage(json_decode($global_response_permission['response'][0]['permission'], true), 'payment_link', $global_user_response['response'][0]['role'])) {
                echo json_encode(['status' => 'false', 'title' => 'Access denied', 'message' => 'You need permission to perform this action. Please contact the admin.', 'csrf_token' => $new_csrf_token]);
                exit();
            }

            if (!hasPermission(json_decode($global_response_permission['response'][0]['permission'], true), 'payment_link', 'create', $global_user_response['response'][0]['role'])) {
                echo json_encode(['status' => 'false', 'title' => 'Access denied', 'message' => 'You need permission to perform this action. Please contact the admin.', 'csrf_token' => $new_csrf_token]);
                exit();
            }

            $request = \AnirbanPay\Http\Request::createFromGlobals();

            $title = $request->post('title', '');
            $quantity = $request->post('quantity', '');
            $description = $request->post('description', '');
            $currency = $request->post('currency', '');
            $amount = $request->post('amount', '');
            $expiry_date = $request->post('expiry_date', '');
            $status = $request->post('status', '');

            $items = $request->post('items', []);

            if ($expiry_date !== "") {
                if (dateformat($expiry_date, 'Y-m-d')) {

                } else {
                    echo json_encode(['status' => "false", 'title' => 'Invalid expiry date format', 'message' => 'Please enter the expiry date in the correct format (DD/MM/YYYY).', 'csrf_token' => $new_csrf_token]);
                    exit();
                }
            } else {
                $expiry_date = "--";
            }

            if ($title == "" || $quantity == "" || $description == "" || $currency == "" || $amount == "" || $status == "") {
                echo json_encode(['status' => "false", 'title' => 'Incomplete Information', 'message' => 'Please fill in all required fields before proceeding.', 'csrf_token' => $new_csrf_token]);
            } else {
                $paymentLinkID = generateItemID(27, 27);

                $product_info = json_encode([
                    'title' => $title,
                    'description' => $description
                ]);

                $columns = ['ref', 'brand_id', 'product_info', 'amount', 'quantity', 'currency', 'expired_date', 'status', 'created_date', 'updated_date'];
                $values = [$paymentLinkID, $global_response_brand['response'][0]['brand_id'], $product_info, money_sanitize($amount), money_sanitize($quantity), $currency, $expiry_date, $status, getCurrentDatetime('Y-m-d H:i:s'), getCurrentDatetime('Y-m-d H:i:s')];

                insertData($db_prefix . 'payment_link', $columns, $values);

                foreach ($items as $uniqueId => $item) {
                    $formType = $item['formType'] ?? '';
                    $fieldName = $item['fieldName'] ?? '';
                    $required = $item['required'] ?? '';
                    $fileExtensions = $item['fileExtensions'] ?? []; // array
                    $addOptions = $item['addOptions'] ?? [];         // array

                    $value = '--';

                    if ($formType === 'file') {
                        $value = implode(', ', $fileExtensions);
                    }
                    if ($formType === 'select' || $formType === 'checkbox' || $formType === 'radio') {
                        $value = implode(', ', $addOptions);
                    }

                    $columns = ['paymentLinkID', 'formType', 'fieldName', 'required', 'value', 'created_date', 'updated_date'];
                    $values = [$paymentLinkID, $formType, $fieldName, $required, $value, getCurrentDatetime('Y-m-d H:i:s'), getCurrentDatetime('Y-m-d H:i:s')];

                    insertData($db_prefix . 'payment_link_field', $columns, $values);
                }

                echo json_encode(['status' => 'true', 'title' => 'Payment Link Created', 'message' => 'The payment link has been created successfully.', 'csrf_token' => $new_csrf_token]);
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
            if (!canAccessPage(json_decode($global_response_permission['response'][0]['permission'], true), 'payment_link', $global_user_response['response'][0]['role'])) {
                echo json_encode(['status' => 'false', 'title' => 'Access denied', 'message' => 'You need permission to perform this action. Please contact the admin.', 'csrf_token' => $new_csrf_token]);
                exit();
            }

            if (!hasPermission(json_decode($global_response_permission['response'][0]['permission'], true), 'payment_link', 'edit', $global_user_response['response'][0]['role'])) {
                echo json_encode(['status' => 'false', 'title' => 'Access denied', 'message' => 'You need permission to perform this action. Please contact the admin.', 'csrf_token' => $new_csrf_token]);
                exit();
            }

            $request = \AnirbanPay\Http\Request::createFromGlobals();

            $paymentLinkID = $request->post('paymentLinkID', '');
            $title = $request->post('title', '');
            $quantity = $request->post('quantity', '');
            $description = $request->post('description', '');
            $currency = $request->post('currency', '');
            $amount = $request->post('amount', '');
            $expiry_date = $request->post('expiry_date', '');
            $status = $request->post('status', '');
            $deletedItems = explode(',', $request->post('deleted_items', ''));

            $items = $request->post('items', []);

            if ($expiry_date !== "") {
                if (dateformat($expiry_date, 'Y-m-d')) {

                } else {
                    echo json_encode(['status' => "false", 'title' => 'Invalid expiry date format', 'message' => 'Please enter the expiry date in the correct format (DD/MM/YYYY).', 'csrf_token' => $new_csrf_token]);
                    exit();
                }
            } else {
                $expiry_date = "--";
            }

            if ($paymentLinkID == "" || $title == "" || $quantity == "" || $description == "" || $currency == "" || $amount == "" || $status == "") {
                echo json_encode(['status' => "false", 'title' => 'Incomplete Information', 'message' => 'Please fill in all required fields before proceeding.', 'csrf_token' => $new_csrf_token]);
            } else {
                $product_info = json_encode([
                    'title' => $title,
                    'description' => $description
                ]);

                $columns = ['product_info', 'amount', 'quantity', 'currency', 'expired_date', 'status', 'updated_date'];
                $values = [$product_info, money_sanitize($amount), money_sanitize($quantity), $currency, $expiry_date, $status, getCurrentDatetime('Y-m-d H:i:s')];

                $condition = "ref = :paymentLinkID";
                $whereParams = [':paymentLinkID' => $paymentLinkID];

                updateData($db_prefix . 'payment_link', $columns, $values, $condition, $whereParams);

                foreach ($deletedItems as $itemId) {
                    $condition = "id = :itemId";
                    $whereParams_field = [':itemId' => $itemId];

                    deleteData($db_prefix . 'payment_link_field', $condition, $whereParams_field);
                }

                foreach ($items as $uniqueId => $item) {
                    $fieldID = $item['fieldID'] ?? '';
                    $formType = $item['formType'] ?? '';
                    $fieldName = $item['fieldName'] ?? '';
                    $required = $item['required'] ?? '';
                    $fileExtensions = $item['fileExtensions'] ?? []; // array
                    $addOptions = $item['addOptions'] ?? [];         // array

                    $value = '--';

                    if ($formType === 'file') {
                        $value = implode(', ', $fileExtensions);
                    }
                    if ($formType === 'select' || $formType === 'checkbox' || $formType === 'radio') {
                        $value = implode(', ', $addOptions);
                    }

                    if ($fieldID == "") {
                        $columns = ['paymentLinkID', 'formType', 'fieldName', 'required', 'value', 'created_date', 'updated_date'];
                        $values = [$paymentLinkID, $formType, $fieldName, $required, $value, getCurrentDatetime('Y-m-d H:i:s'), getCurrentDatetime('Y-m-d H:i:s')];

                        insertData($db_prefix . 'payment_link_field', $columns, $values);
                    } else {
                        $columns = ['formType', 'fieldName', 'required', 'value', 'updated_date'];
                        $values = [$formType, $fieldName, $required, $value, getCurrentDatetime('Y-m-d H:i:s')];

                        $condition = "id = :fieldID";
                        $whereParams_field = [':fieldID' => $fieldID];

                        updateData($db_prefix . 'payment_link_field', $columns, $values, $condition, $whereParams_field);
                    }
                }

                echo json_encode(['status' => 'true', 'title' => 'Payment Link Updated', 'message' => 'The payment link has been updated successfully.', 'csrf_token' => $new_csrf_token]);
            }
        } else {
            echo json_encode(['status' => 'false', 'title' => 'Request Failed', 'message' => 'Invalid request', 'csrf_token' => $new_csrf_token]);
        }
    }

    private function defaultLinkCurrency(RequestContext $ctx): void
    {
        $db_prefix = $ctx->dbPrefix;
        $global_user_login = $ctx->isLoggedIn;
        $global_response_permission = $ctx->permissionResponse;
        $global_user_response = $ctx->userResponse;
        $global_response_brand = $ctx->brandResponse;
        $new_csrf_token = $ctx->csrfToken;
        if ($global_user_login == true) {
            if (!canAccessPage(json_decode($global_response_permission['response'][0]['permission'], true), 'payment_link', $global_user_response['response'][0]['role'])) {
                echo json_encode(['status' => 'false', 'title' => 'Access denied', 'message' => 'You need permission to perform this action. Please contact the admin.', 'csrf_token' => $new_csrf_token]);
                exit();
            }

            if (!hasPermission(json_decode($global_response_permission['response'][0]['permission'], true), 'payment_link', 'edit', $global_user_response['response'][0]['role'])) {
                echo json_encode(['status' => 'false', 'title' => 'Access denied', 'message' => 'You need permission to perform this action. Please contact the admin.', 'csrf_token' => $new_csrf_token]);
                exit();
            }

            $request = \AnirbanPay\Http\Request::createFromGlobals();

            $DefaultCurrency = $request->post('DefaultCurrency', '');

            set_env('payment-link-default-currency', $DefaultCurrency, $global_response_brand['response'][0]['brand_id']);

            echo json_encode(['status' => 'true', 'title' => 'Default Currency Updated', 'message' => 'The default payment link currency has been updated successfully.', 'csrf_token' => $new_csrf_token]);
        } else {
            echo json_encode(['status' => 'false', 'title' => 'Request Failed', 'message' => 'Invalid request', 'csrf_token' => $new_csrf_token]);
        }
    }
}
