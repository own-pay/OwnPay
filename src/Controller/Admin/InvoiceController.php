<?php
declare(strict_types=1);

namespace OwnPay\Controller\Admin;

use OwnPay\Http\RequestContext;
use OwnPay\Service\System\CrudService;
use OwnPay\Service\System\InputSanitizer;
use OwnPay\Service\Auth\PermissionGuard;

class InvoiceController
{
    public static function handle(string $action, ?RequestContext $ctx = null): void
    {
        $ctx ??= $GLOBALS['requestContext'] ?? throw new \RuntimeException('RequestContext not available');

        $global_user_login = $ctx->isLoggedIn;
        $new_csrf_token = $ctx->csrfToken;

        if ($global_user_login != true) {
            echo json_encode(['status' => 'false', 'title' => 'Request Failed', 'message' => 'Invalid request', 'csrf_token' => $new_csrf_token]);
            exit;
        }

        switch ($action) {
            case 'invoice-list':
                self::list($ctx);
                break;
            case 'invoice-create':
                self::create($ctx);
                break;
            case 'invoice-edit':
                self::edit($ctx);
                break;
            case 'invoice-manageStatus':
                self::manageStatus($ctx);
                break;
            case 'invoice-bulk-action':
                self::bulkAction($ctx);
                break;
            case 'invoice-delete':
                self::delete($ctx);
                break;
        }
    }

    private static function list(RequestContext $ctx): void
    {
        $db_prefix = $ctx->dbPrefix;
        $global_response_brand = $ctx->brandResponse;
        $new_csrf_token = $ctx->csrfToken;

        if (!PermissionGuard::canAccess($ctx, 'invoice')) {
            echo json_encode(['status' => 'false', 'title' => 'Access denied', 'message' => 'You need permission to perform this action. Please contact the admin.', 'csrf_token' => $new_csrf_token]);
            exit();
        }

        $request = \OwnPay\Http\Request::createFromGlobals();

        $search_input = $request->post('search_input', '');
        $show_limit = $request->post('show_limit', '5');

        $tabType = $request->post('tabType', '');

        /* Filters */
        $filter_status = $request->post('filter_status', '');
        $filter_start = $request->post('filter_start', '');
        $filter_end = $request->post('filter_end', '');

        $where = [];
        $params_inv = [':brand_id' => $global_response_brand['response'][0]['brand_id']];

        if ($tabType !== "all") {
            $where[] = "status = :tabType";
            $params_inv[':tabType'] = $tabType;
        }

        if ($filter_start !== '') {
            $where[] = "created_date >= :filter_start";
            $params_inv[':filter_start'] = "{$filter_start} 00:00:00";
        }

        if ($filter_end !== '') {
            $where[] = "created_date <= :filter_end";
            $params_inv[':filter_end'] = "{$filter_end} 23:59:59";
        }

        if ($filter_status !== '') {
            $where[] = "status = :filter_status";
            $params_inv[':filter_status'] = $filter_status;
        }

        $where_sql = $where ? implode(' AND ', $where) . ' AND ' : '';
        /* Filters */

        $pag = \OwnPay\Service\System\PaginationService::resolve($request->post('page', '1'), $request->post('show_limit'));
        $page = $pag['page'];
        $show_limit_val = $pag['perPage'];
        $offset = $pag['offset'];

        $sql_query = '';

        if ($search_input !== '') {
            $sql_query .= " AND ( customer_info LIKE :search OR currency LIKE :search )";
            $params_inv[':search'] = "%$search_input%";
        }

        $sql_limit = '';
        if ($pag['isAll']) {

        } else {
            $sql_limit = " LIMIT $offset, $show_limit_val";
        }

        $response_result = CrudService::select($db_prefix . 'invoice', ' WHERE ' . $where_sql . ' brand_id = :brand_id ' . $sql_query . ' ORDER BY 1 DESC ' . $sql_limit, '* FROM', $params_inv);
        if ($response_result['status'] == true) {
            $response = [];

            foreach ($response_result['response'] as $row) {
                $customer_info = json_decode($row['customer_info'], true);

                $params_curr = [':brand_id' => $global_response_brand['response'][0]['brand_id'], ':code' => $row['currency']];
                $response_currency = CrudService::select($db_prefix . 'currency', ' WHERE brand_id = :brand_id AND code = :code', '* FROM', $params_curr);

                $total = "0";
                $items_count = 0;

                $params_items = [':brand_id' => $global_response_brand['response'][0]['brand_id'], ':invoice_id' => $row['ref']];
                $response_items = CrudService::select($db_prefix . 'invoice_items', ' WHERE brand_id = :brand_id AND invoice_id = :invoice_id', '* FROM', $params_items);

                if (!empty($response_items['response']) && is_array($response_items['response'])) {
                    foreach ($response_items['response'] as $items) {
                        $items_count++;

                        $item_cost = money_mul($items['amount'], $items['quantity']);

                        $item_total_cost = money_sub($item_cost, $items['discount']);

                        $vat_amount = money_div(money_mul($item_total_cost, $items['vat']), "100");

                        $item_total_cost_with_vat = money_add($item_total_cost, $vat_amount);

                        $total = money_add($total, $item_total_cost_with_vat);
                    }
                }

                $total = money_add($total, $row['shipping']);

                $currency = $response_currency['response'][0]['symbol'] ?? '';

                $response[] = [
                    "id" => $row['ref'],
                    "c_id" => $customer_info['id'] ?? 'N/A',
                    "name" => $customer_info['name'] ?? 'Unknown',
                    "email" => $customer_info['email'] ?? '',
                    "mobile" => $customer_info['mobile'] ?? '',
                    "status" => $row['status'],
                    "items" => $items_count,
                    "amount" => $currency . money_round($total, 2),
                    "created_date" => convertUTCtoUserTZ($row['created_date'], (empty($global_response_brand['response'][0]['timezone'])) ? 'Asia/Dhaka' : $global_response_brand['response'][0]['timezone'], "M d, Y h:i A"),
                    "updated_date" => convertUTCtoUserTZ($row['updated_date'], (empty($global_response_brand['response'][0]['timezone'])) ? 'Asia/Dhaka' : $global_response_brand['response'][0]['timezone'], "M d, Y h:i A")
                ];
            }

            $count_data = CrudService::select($db_prefix . 'invoice', ' WHERE ' . $where_sql . ' brand_id = :brand_id ' . $sql_query, '* FROM', $params_inv);

            $total_records = count($count_data['response'] ?? []);
            $pagHtml = \OwnPay\Service\System\PaginationService::render($page, $total_records, $show_limit_val, $offset);
            $pagination = $pagHtml['pagination'];
            $datatableInfo = $pagHtml['datatableInfo'];

            echo json_encode(['status' => "true", 'response' => $response, 'datatableInfo' => $datatableInfo, 'pagination' => $pagination, 'csrf_token' => $new_csrf_token]);
        } else {
            echo json_encode(['status' => "false", 'title' => 'Nothing Here Yet', 'message' => 'No data is available at the moment.', 'csrf_token' => $new_csrf_token]);
            exit();
        }
    }

    private static function create(RequestContext $ctx): void
    {
        $db_prefix = $ctx->dbPrefix;
        $global_response_brand = $ctx->brandResponse;
        $new_csrf_token = $ctx->csrfToken;

        if (!PermissionGuard::canAccess($ctx, 'invoice') || !PermissionGuard::has($ctx, 'invoice', 'create')) {
            echo json_encode(['status' => 'false', 'title' => 'Access denied', 'message' => 'You need permission to perform this action. Please contact the admin.', 'csrf_token' => $new_csrf_token]);
            exit();
        }

        $request = \OwnPay\Http\Request::createFromGlobals();

        $customer = $request->post('customers', []);
        $currency = $request->post('currency', '');
        $due_date = $request->post('due_date', '');
        $status = $request->post('status', '');
        $shipping = $request->post('shipping', '');
        $note = $request->post('note', '');
        $private_note_content = $request->post('private-note-content', '');

        $item_description = $request->post('item-description', []);
        $item_quantity = $request->post('item-quantity', []);
        $item_amount = $request->post('item-amount', []);
        $item_discount = $request->post('item-discount', []);
        $item_vat = $request->post('item-vat', []);

        $item_description = (array) $item_description;
        $item_quantity = (array) $item_quantity;
        $item_amount = (array) $item_amount;
        $item_discount = (array) $item_discount;
        $item_vat = (array) $item_vat;

        if ($note == "") {
            $note = null;
        }
        if ($private_note_content == "") {
            $private_note_content = null;
        }

        if ($due_date !== "") {
            if (!dateformat($due_date, 'Y-m-d')) {
                echo json_encode(['status' => "false", 'title' => 'Invalid due date format', 'message' => 'Please enter the due date in the correct format (DD/MM/YYYY).', 'csrf_token' => $new_csrf_token]);
                exit();
            }
        } else {
            $due_date = null;
        }

        if ($currency == "" || $status == "" || $shipping == "") {
            echo json_encode(['status' => "false", 'title' => 'Incomplete Information', 'message' => 'Please fill in all required fields before proceeding.', 'csrf_token' => $new_csrf_token]);
        } else {
            $insert_result = false;

            $all_invoices = [];

            foreach ($customer as $customer_id) {
                $response = CrudService::select($db_prefix . 'customer', 'WHERE (brand_id = :brand_id AND ref = :id) OR (brand_id = :brand_id AND email = :id)', '* FROM', [':brand_id' => $global_response_brand['response'][0]['brand_id'], ':id' => $customer_id]);
                if ($response['status'] == true) {
                    $invoice_id = generateItemID(27, 27);

                    $customer_info = json_encode([
                        'id' => $response['response'][0]['ref'],
                        'name' => $response['response'][0]['name'],
                        'email' => $response['response'][0]['email'],
                        'mobile' => $response['response'][0]['mobile']
                    ]);

                    $invoice_items_array = [];

                    if (count($item_description) > 0) {
                        for ($i = 0; $i < count($item_description); $i++) {
                            $descriptions = InputSanitizer::trim($item_description[$i] ?? '');
                            $quantities = InputSanitizer::trim($item_quantity[$i] ?? '');
                            $amounts = InputSanitizer::trim($item_amount[$i] ?? '');
                            $discounts = InputSanitizer::trim($item_discount[$i] ?? '');
                            $vats = InputSanitizer::trim($item_vat[$i] ?? '');

                            $columns = ['invoice_id', 'brand_id', 'description', 'amount', 'quantity', 'discount', 'vat', 'created_date', 'updated_date'];
                            $values = [$invoice_id, $global_response_brand['response'][0]['brand_id'], $descriptions, money_sanitize($amounts), money_sanitize($quantities), money_sanitize($discounts), money_sanitize($vats), getCurrentDatetime('Y-m-d H:i:s'), getCurrentDatetime('Y-m-d H:i:s')];

                            CrudService::insert($db_prefix . 'invoice_items', $columns, $values);

                            $invoice_items_array[] = [
                                'description' => $descriptions,
                                'amount' => money_round($amounts),
                                'quantity' => money_round($quantities),
                                'discount' => money_round($discounts),
                                'vat' => money_round($vats)
                            ];
                        }

                        $insert_result = true;
                    } else {
                        echo json_encode(['status' => "false", 'title' => 'Add Item Required', 'message' => 'Please add at least 1 item to create an invoice.', 'csrf_token' => $new_csrf_token]);
                        exit();
                    }

                    $columns = ['ref', 'brand_id', 'customer_info', 'currency', 'due_date', 'shipping', 'status', 'note', 'private_note', 'created_date', 'updated_date'];
                    $values = [$invoice_id, $global_response_brand['response'][0]['brand_id'], $customer_info, $currency, $due_date, money_sanitize($shipping), $status, $note, $private_note_content, getCurrentDatetime('Y-m-d H:i:s'), getCurrentDatetime('Y-m-d H:i:s')];

                    CrudService::insert($db_prefix . 'invoice', $columns, $values);

                    $all_invoices['invoice_' . $invoice_id] = [
                        'customer_info' => $customer_info,
                        'invoice_info' => [
                            'invoice_id' => $invoice_id,
                            'brand_id' => $global_response_brand['response'][0]['brand_id'],
                            'currency' => $currency,
                            'due_date' => $due_date,
                            'shipping' => money_round($shipping),
                            'status' => $status,
                            'note' => $note,
                            'private_note' => $private_note_content,
                            'created_date' => convertUTCtoUserTZ(getCurrentDatetime('Y-m-d H:i:s'), (empty($global_response_brand['response'][0]['timezone'])) ? 'Asia/Dhaka' : $global_response_brand['response'][0]['timezone'], "M d, Y h:i A"),
                            'updated_date' => convertUTCtoUserTZ(getCurrentDatetime('Y-m-d H:i:s'), (empty($global_response_brand['response'][0]['timezone'])) ? 'Asia/Dhaka' : $global_response_brand['response'][0]['timezone'], "M d, Y h:i A")
                        ],
                        'invoice_items' => $invoice_items_array
                    ];
                }
            }

            if ($insert_result == true) {
                if (!empty($all_invoices)) {
                    do_action('invoices.created', $all_invoices);
                }

                echo json_encode(['status' => 'true', 'title' => 'Invoice Created', 'message' => 'The invoice has been created successfully.', 'csrf_token' => $new_csrf_token]);
            } else {
                echo json_encode(['status' => "false", 'title' => 'Incomplete Information', 'message' => 'Please fill in all required fields before proceeding.', 'csrf_token' => $new_csrf_token]);
            }
        }
    }

    private static function edit(RequestContext $ctx): void
    {
        $db_prefix = $ctx->dbPrefix;
        $global_response_brand = $ctx->brandResponse;
        $new_csrf_token = $ctx->csrfToken;

        if (!PermissionGuard::canAccess($ctx, 'invoice') || !PermissionGuard::has($ctx, 'invoice', 'edit')) {
            echo json_encode(['status' => 'false', 'title' => 'Access denied', 'message' => 'You need permission to perform this action. Please contact the admin.', 'csrf_token' => $new_csrf_token]);
            exit();
        }

        $request = \OwnPay\Http\Request::createFromGlobals();

        $invoiceID = $request->post('invoiceID', '');
        $currency = $request->post('currency', '');
        $due_date = $request->post('due_date', '');
        $status = $request->post('status', '');
        $shipping = $request->post('shipping', '');
        $note = $request->post('note', '');
        $private_note_content = $request->post('private-note-content', '');
        $deletedItems = explode(',', $request->post('deleted_items', ''));

        $item_description = $request->post('item-description', []);
        $item_quantity = $request->post('item-quantity', []);
        $item_amount = $request->post('item-amount', []);
        $item_discount = $request->post('item-discount', []);
        $item_vat = $request->post('item-vat', []);
        $item_id = $request->post('item-id', []);

        $item_description = (array) $item_description;
        $item_quantity = (array) $item_quantity;
        $item_amount = (array) $item_amount;
        $item_discount = (array) $item_discount;
        $item_vat = (array) $item_vat;
        $item_id = (array) $item_id;

        if ($note == "") {
            $note = null;
        }
        if ($private_note_content == "") {
            $private_note_content = null;
        }

        if ($due_date !== "") {
            if (!dateformat($due_date, 'Y-m-d')) {
                echo json_encode(['status' => "false", 'title' => 'Invalid due date format', 'message' => 'Please enter the due date in the correct format (DD/MM/YYYY).', 'csrf_token' => $new_csrf_token]);
                exit();
            }
        } else {
            $due_date = null;
        }

        if ($currency == "" || $status == "" || $shipping == "") {
            echo json_encode(['status' => "false", 'title' => 'Incomplete Information', 'message' => 'Please fill in all required fields before proceeding.', 'csrf_token' => $new_csrf_token]);
        } else {
            $response = CrudService::select($db_prefix . 'invoice', 'WHERE brand_id = :brand_id AND ref = :invoiceID', '* FROM', [':brand_id' => $global_response_brand['response'][0]['brand_id'], ':invoiceID' => $invoiceID]);
            if ($response['status'] == true) {
                $columns = ['currency', 'due_date', 'shipping', 'status', 'note', 'private_note', 'updated_date'];
                $values = [$currency, $due_date, money_sanitize($shipping), $status, $note, $private_note_content, getCurrentDatetime('Y-m-d H:i:s')];

                $condition = "ref = :invoiceID";
                $whereParams = [':invoiceID' => $invoiceID];

                CrudService::update($db_prefix . 'invoice', $columns, $values, $condition, $whereParams);

                foreach ($deletedItems as $itemId) {
                    $condition = "id = :itemId";
                    $whereParams_item = [':itemId' => $itemId];

                    CrudService::delete($db_prefix . 'invoice_items', $condition, $whereParams_item);
                }

                $invoice_items_array = [];

                if (count($item_description) > 0) {
                    for ($i = 0; $i < count($item_description); $i++) {
                        $descriptions = InputSanitizer::trim($item_description[$i] ?? '');
                        $quantities = InputSanitizer::trim($item_quantity[$i] ?? '');
                        $amounts = InputSanitizer::trim($item_amount[$i] ?? '');
                        $discounts = InputSanitizer::trim($item_discount[$i] ?? '');
                        $vats = InputSanitizer::trim($item_vat[$i] ?? '');
                        $itemidS = InputSanitizer::trim($item_id[$i] ?? '');

                        if ($itemidS !== "") {
                            $columns = ['description', 'amount', 'quantity', 'discount', 'vat', 'updated_date'];
                            $values = [$descriptions, money_sanitize($amounts), money_sanitize($quantities), money_sanitize($discounts), money_sanitize($vats), getCurrentDatetime('Y-m-d H:i:s')];
                            $condition = "id = :itemidS";
                            $whereParams_item = [':itemidS' => $itemidS];

                            CrudService::update($db_prefix . 'invoice_items', $columns, $values, $condition, $whereParams_item);
                        } else {
                            $columns = ['invoice_id', 'brand_id', 'description', 'amount', 'quantity', 'discount', 'vat', 'created_date', 'updated_date'];
                            $values = [$invoiceID, $global_response_brand['response'][0]['brand_id'], $descriptions, money_sanitize($amounts), money_sanitize($quantities), money_sanitize($discounts), money_sanitize($vats), getCurrentDatetime('Y-m-d H:i:s'), getCurrentDatetime('Y-m-d H:i:s')];

                            CrudService::insert($db_prefix . 'invoice_items', $columns, $values);
                        }

                        $invoice_items_array[] = [
                            'description' => $descriptions,
                            'amount' => money_round($amounts),
                            'quantity' => money_round($quantities),
                            'discount' => money_round($discounts),
                            'vat' => money_round($vats)
                        ];
                    }
                }

                $all_invoices = [
                    'customer_info' => $response['response'][0]['customer_info'],
                    'invoice_info' => [
                        'invoice_id' => $invoiceID,
                        'brand_id' => $global_response_brand['response'][0]['brand_id'],
                        'currency' => $currency,
                        'due_date' => $due_date,
                        'shipping' => money_round($shipping),
                        'status' => $status,
                        'note' => $note,
                        'private_note' => $private_note_content,
                        'created_date' => convertUTCtoUserTZ($response['response'][0]['created_date'], (empty($global_response_brand['response'][0]['timezone'])) ? 'Asia/Dhaka' : $global_response_brand['response'][0]['timezone'], "M d, Y h:i A"),
                        'updated_date' => convertUTCtoUserTZ(getCurrentDatetime('Y-m-d H:i:s'), (empty($global_response_brand['response'][0]['timezone'])) ? 'Asia/Dhaka' : $global_response_brand['response'][0]['timezone'], "M d, Y h:i A")
                    ],
                    'invoice_items' => $invoice_items_array
                ];
                if (!empty($all_invoices)) {
                    do_action('invoices.updated', $all_invoices);
                }

                echo json_encode(['status' => 'true', 'title' => 'Invoice Updated', 'message' => 'The invoice has been updated successfully.', 'csrf_token' => $new_csrf_token]);
            } else {
                echo json_encode(['status' => 'false', 'title' => 'Request Failed', 'message' => 'Invalid request', 'csrf_token' => $new_csrf_token]);
            }
        }
    }

    private static function manageStatus(RequestContext $ctx): void
    {
        $db_prefix = $ctx->dbPrefix;
        $global_response_brand = $ctx->brandResponse;
        $new_csrf_token = $ctx->csrfToken;

        if (!PermissionGuard::canAccess($ctx, 'invoice') || !PermissionGuard::has($ctx, 'invoice', 'edit')) {
            echo json_encode(['status' => 'false', 'title' => 'Access denied', 'message' => 'You need permission to perform this action. Please contact the admin.', 'csrf_token' => $new_csrf_token]);
            exit();
        }

        $request = \OwnPay\Http\Request::createFromGlobals();

        $invoiceID = $request->post('invoice-id', '');
        $status = $request->post('status', '');

        $response = CrudService::select($db_prefix . 'invoice', 'WHERE brand_id = :brand_id AND ref = :invoiceID', '* FROM', [':brand_id' => $global_response_brand['response'][0]['brand_id'], ':invoiceID' => $invoiceID]);
        if ($response['status'] == true) {
            $columns = ['status', 'updated_date'];
            $values = [$status, getCurrentDatetime('Y-m-d H:i:s')];

            $condition = "ref = :invoiceID";
            $whereParams = [':invoiceID' => $invoiceID];

            CrudService::update($db_prefix . 'invoice', $columns, $values, $condition, $whereParams);

            $invoice_items_array = [];

            $response_items = CrudService::select($db_prefix . 'invoice_items', 'WHERE brand_id = :brand_id AND invoice_id = :invoiceID', '* FROM', [':brand_id' => $global_response_brand['response'][0]['brand_id'], ':invoiceID' => $invoiceID]);
            foreach ($response_items['response'] as $rowItem) {
                $invoice_items_array[] = [
                    'description' => $rowItem['description'],
                    'amount' => money_round($rowItem['amount']),
                    'quantity' => money_round($rowItem['quantity']),
                    'discount' => money_round($rowItem['discount']),
                    'vat' => money_round($rowItem['vat'])
                ];
            }

            $all_invoices = [
                'customer_info' => $response['response'][0]['customer_info'],
                'invoice_info' => [
                    'invoice_id' => $invoiceID,
                    'brand_id' => $global_response_brand['response'][0]['brand_id'],
                    'currency' => $response['response'][0]['currency'],
                    'due_date' => $response['response'][0]['due_date'],
                    'shipping' => money_round($response['response'][0]['shipping']),
                    'status' => $status,
                    'note' => $response['response'][0]['note'],
                    'private_note' => $response['response'][0]['private_note'],
                    'created_date' => convertUTCtoUserTZ($response['response'][0]['created_date'], (empty($global_response_brand['response'][0]['timezone'])) ? 'Asia/Dhaka' : $global_response_brand['response'][0]['timezone'], "M d, Y h:i A"),
                    'updated_date' => convertUTCtoUserTZ(getCurrentDatetime('Y-m-d H:i:s'), (empty($global_response_brand['response'][0]['timezone'])) ? 'Asia/Dhaka' : $global_response_brand['response'][0]['timezone'], "M d, Y h:i A")
                ],
                'invoice_items' => $invoice_items_array
            ];
            if (!empty($all_invoices)) {
                do_action('invoices.updated.status', $all_invoices);
            }

            echo json_encode(['status' => 'true', 'title' => 'Invoice Updated', 'message' => 'The invoice has been updated successfully.', 'csrf_token' => $new_csrf_token]);
        } else {
            echo json_encode(['status' => 'false', 'title' => 'Request Failed', 'message' => 'Invalid request', 'csrf_token' => $new_csrf_token]);
        }
    }

    private static function bulkAction(RequestContext $ctx): void
    {
        $db_prefix = $ctx->dbPrefix;
        $global_response_brand = $ctx->brandResponse;
        $new_csrf_token = $ctx->csrfToken;

        if (!PermissionGuard::canAccess($ctx, 'invoice')) {
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

                $response_brand = CrudService::select($db_prefix . 'invoice', 'WHERE ref = :itemID AND brand_id = :brand_id', '* FROM', [':itemID' => $itemID, ':brand_id' => $global_response_brand['response'][0]['brand_id']]);
                if ($response_brand['status'] == true) {
                    if ($actionID == "deleted") {
                        if (PermissionGuard::has($ctx, 'invoice', 'delete')) {
                            $condition = "invoice_id = :itemID";
                            $whereParams_item = [':itemID' => $itemID];

                            CrudService::delete($db_prefix . 'invoice_items', $condition, $whereParams_item);

                            $condition = "ref = :itemID";
                            $whereParams = [':itemID' => $itemID];

                            CrudService::delete($db_prefix . 'invoice', $condition, $whereParams);
                        }
                    }
                }
            }

            echo json_encode(['status' => 'true', 'title' => 'Invoices ' . $actionID, 'message' => 'The selected invoices have been ' . $actionID . ' successfully.', 'csrf_token' => $new_csrf_token]);
        } else {
            echo json_encode(['status' => 'false', 'title' => 'Invoices Failed', 'message' => 'No invoices selected.', 'csrf_token' => $new_csrf_token]);
        }
    }

    private static function delete(RequestContext $ctx): void
    {
        $db_prefix = $ctx->dbPrefix;
        $global_response_brand = $ctx->brandResponse;
        $new_csrf_token = $ctx->csrfToken;

        if (!PermissionGuard::canAccess($ctx, 'invoice') || !PermissionGuard::has($ctx, 'invoice', 'delete')) {
            echo json_encode(['status' => 'false', 'title' => 'Access denied', 'message' => 'You need permission to perform this action. Please contact the admin.', 'csrf_token' => $new_csrf_token]);
            exit();
        }

        $request = \OwnPay\Http\Request::createFromGlobals();

        $ItemID = $request->post('ItemID', '');

        $response_brand = CrudService::select($db_prefix . 'invoice', 'WHERE ref = :ItemID AND brand_id = :brand_id', '* FROM', [':ItemID' => $ItemID, ':brand_id' => $global_response_brand['response'][0]['brand_id']]);
        if ($response_brand['status'] == true) {
            $condition = "invoice_id = :ItemID";
            $whereParams_item = [':ItemID' => $ItemID];

            CrudService::delete($db_prefix . 'invoice_items', $condition, $whereParams_item);

            $condition = "ref = :ItemID";
            $whereParams = [':ItemID' => $ItemID];

            CrudService::delete($db_prefix . 'invoice', $condition, $whereParams);
        }

        echo json_encode(['status' => 'true', 'title' => 'Invoice Deleted', 'message' => 'The selected invoice have been deleted successfully.', 'csrf_token' => $new_csrf_token]);
    }
}
