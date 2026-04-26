<?php
declare(strict_types=1);

namespace OwnPay\Controller;

use OwnPay\Http\RequestContext;
use OwnPay\Service\CrudService;
use OwnPay\Service\PermissionGuard;
use OwnPay\Service\InputSanitizer;

class FaqController
{
    public static function handle(string $action, RequestContext $ctx): void
    {
        $global_user_login = $ctx->isLoggedIn;
        $global_response_permission = $ctx->permissionResponse;
        $global_user_response = $ctx->userResponse;
        $new_csrf_token = $ctx->csrfToken;
        $db_prefix = $ctx->dbPrefix;
        $global_response_brand = $ctx->brandResponse;

        $request = \OwnPay\Http\Request::createFromGlobals();

        // Inline extracted code from adapter.php
        if ($action == "faq-list") {
            if ($global_user_login == true) {
                if (PermissionGuard::denyUnlessCanAccess($ctx, 'brand_settings')) { return; }

                if (PermissionGuard::denyUnlessHas($ctx, 'faq_settings', 'view')) { return; }

                $search_input = $request->post('search_input', '');
                $show_limit_raw = $request->post('show_limit', '5');

                /* Filters */
                $filter_status = $request->post('filter_status', '');
                $filter_start = $request->post('filter_start', '');
                $filter_end = $request->post('filter_end', '');

                $where = [];
                $params_faq = [':brand_id' => $global_response_brand['response'][0]['brand_id']];

                if ($filter_start !== '') {
                    $where[] = "created_date >= :filter_start";
                    $params_faq[':filter_start'] = "{$filter_start} 00:00:00";
                }

                if ($filter_end !== '') {
                    $where[] = "created_date <= :filter_end";
                    $params_faq[':filter_end'] = "{$filter_end} 23:59:59";
                }

                if ($filter_status !== '') {
                    $where[] = "status = :filter_status";
                    $params_faq[':filter_status'] = $filter_status;
                }

                $where_sql = $where ? implode(' AND ', $where) . ' AND ' : '';
                /* Filters */

                $pag = \OwnPay\Service\PaginationService::resolve($request->post('page', '1'), $request->post('show_limit'));
                $page = $pag['page'];
                $show_limit_val = $pag['perPage'];
                $offset = $pag['offset'];
                $show_limit = $show_limit_val;

                $sql_query = '';

                if ($search_input !== '') {
                    $sql_query .= " AND ( title LIKE :search OR description LIKE :search )";
                    $params_faq[':search'] = "%$search_input%";
                }

                $sql_limit = '';
                if ($pag['isAll']) {

                } else {
                    $sql_limit = " LIMIT $offset, $show_limit_val";
                }

                $response_result = CrudService::select($db_prefix . 'faq', ' WHERE ' . $where_sql . ' brand_id = :brand_id ' . $sql_query . ' ORDER BY 1 DESC ' . $sql_limit, '* FROM', $params_faq);
                if ($response_result['status'] == true) {
                    $response = [];

                    foreach ($response_result['response'] as $row) {
                        $response[] = [
                            "id" => $row['id'],
                            "title" => $row['title'],
                            "description" => $row['description'],
                            "status" => $row['status'],
                            "created_date" => convertUTCtoUserTZ($row['created_date'], ($global_response_brand['response'][0]['timezone'] === null || $global_response_brand['response'][0]['timezone'] === '') ? 'Asia/Dhaka' : $global_response_brand['response'][0]['timezone'], "M d, Y h:i A"),
                            "updated_date" => convertUTCtoUserTZ($row['updated_date'], ($global_response_brand['response'][0]['timezone'] === null || $global_response_brand['response'][0]['timezone'] === '') ? 'Asia/Dhaka' : $global_response_brand['response'][0]['timezone'], "M d, Y h:i A")
                        ];
                    }

                    $count_data = CrudService::select($db_prefix . 'faq', ' WHERE ' . $where_sql . ' brand_id = :brand_id ' . $sql_query, '* FROM', $params_faq);

                    $total_records = count($count_data['response'] ?? []);
                    $pagHtml = \OwnPay\Service\PaginationService::render($page, $total_records, $show_limit, $offset);
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

        if ($action == "faq-create") {
            if ($global_user_login == true) {
                if (PermissionGuard::denyUnlessCanAccess($ctx, 'brand_settings')) { return; }

                if (PermissionGuard::denyUnlessHas($ctx, 'faq_settings', 'create')) { return; }

                $faq_title = $request->post('faq_title', '');
                $faq_description = $request->post('faq_description', '');
                $faq_status = $request->post('faq_status', '');

                if ($faq_title == "" || $faq_description == "" || $faq_status == "") {
                    echo json_encode(['status' => "false", 'title' => 'Incomplete Information', 'message' => 'Please fill in all required fields before proceeding.', 'csrf_token' => $new_csrf_token]);
                } else {
                    $columns = ['brand_id', 'title', 'description', 'status', 'created_date', 'updated_date'];
                    $values = [$global_response_brand['response'][0]['brand_id'], $faq_title, $faq_description, $faq_status, getCurrentDatetime('Y-m-d H:i:s'), getCurrentDatetime('Y-m-d H:i:s')];

                    CrudService::insert($db_prefix . 'faq', $columns, $values);

                    echo json_encode(['status' => 'true', 'title' => 'FAQ Created', 'message' => 'The faq has been created successfully.', 'csrf_token' => $new_csrf_token]);
                }
            } else {
                echo json_encode(['status' => 'false', 'title' => 'Request Failed', 'message' => 'Invalid request', 'csrf_token' => $new_csrf_token]);
            }
        }

        if ($action == "faq-info-byID") {
            if ($global_user_login == true) {
                if (PermissionGuard::denyUnlessCanAccess($ctx, 'brand_settings')) { return; }

                if (PermissionGuard::denyUnlessHas($ctx, 'faq_settings', 'edit')) { return; }

                $ItemID = $request->post('ItemID', '');

                $response_brand = CrudService::select($db_prefix . 'faq', 'WHERE id = :ItemID AND brand_id = :brand_id', '* FROM', [':ItemID' => $ItemID, ':brand_id' => $global_response_brand['response'][0]['brand_id']]);
                if ($response_brand['status'] == true) {
                    echo json_encode(['status' => 'true', 'title' => $response_brand['response'][0]['title'], 'description' => $response_brand['response'][0]['description'], 'fstatus' => $response_brand['response'][0]['status'], 'csrf_token' => $new_csrf_token]);
                } else {
                    echo json_encode(['status' => 'false', 'title' => 'Request Failed', 'message' => 'Invalid request', 'csrf_token' => $new_csrf_token]);
                }
            } else {
                echo json_encode(['status' => 'false', 'title' => 'Request Failed', 'message' => 'Invalid request', 'csrf_token' => $new_csrf_token]);
            }
        }

        if ($action == "faq-edit") {
            if ($global_user_login == true) {
                if (PermissionGuard::denyUnlessCanAccess($ctx, 'brand_settings')) { return; }

                if (PermissionGuard::denyUnlessHas($ctx, 'faq_settings', 'edit')) { return; }

                $faq_id = $request->post('faq_id', '');
                $faq_title = $request->post('faq_title', '');
                $faq_description = $request->post('faq_description', '');
                $faq_status = $request->post('faq_status', '');

                if ($faq_id == "" || $faq_title == "" || $faq_description == "" || $faq_status == "") {
                    echo json_encode(['status' => "false", 'title' => 'Incomplete Information', 'message' => 'Please fill in all required fields before proceeding.', 'csrf_token' => $new_csrf_token]);
                } else {
                    $response_faq = CrudService::select($db_prefix . 'faq', 'WHERE id = :id AND brand_id = :brand_id', '* FROM', [':id' => $faq_id, ':brand_id' => $global_response_brand['response'][0]['brand_id']]);
                    if ($response_faq['status'] == true) {

                        $columns = ['title', 'description', 'status', 'updated_date'];
                        $values = [$faq_title, $faq_description, $faq_status, getCurrentDatetime('Y-m-d H:i:s')];

                        $condition = "id = :id";
                        $whereParams = [':id' => $faq_id];

                        CrudService::update($db_prefix . 'faq', $columns, $values, $condition, $whereParams);

                        echo json_encode(['status' => 'true', 'title' => 'FAQ Updated', 'message' => 'The faq has been updated successfully.', 'csrf_token' => $new_csrf_token]);
                    } else {
                        echo json_encode(['status' => 'false', 'title' => 'Request Failed', 'message' => 'Invalid request', 'csrf_token' => $new_csrf_token]);
                    }
                }
            } else {
                echo json_encode(['status' => 'false', 'title' => 'Request Failed', 'message' => 'Invalid request', 'csrf_token' => $new_csrf_token]);
            }
        }

        if ($action == "faq-bulk-action") {
            if ($global_user_login == true) {
                if (PermissionGuard::denyUnlessCanAccess($ctx, 'brand_settings')) { return; }

                if (PermissionGuard::denyUnlessHas($ctx, 'faq_settings', 'delete')) { return; }

                $actionID = $request->post('actionID', '');
                $selected_ids_json = $request->post('selected_ids', '[]');
                $selected_ids = json_decode($selected_ids_json, true);

                if (!empty($selected_ids)) {
                    foreach ($selected_ids as $id) {
                        $itemID = InputSanitizer::trim($id);

                        $response_brand = CrudService::select($db_prefix . 'faq', 'WHERE id = :itemID AND brand_id = :brand_id', '* FROM', [':itemID' => $itemID, ':brand_id' => $global_response_brand['response'][0]['brand_id']]);
                        if ($response_brand['status'] == true) {
                            if ($actionID == "deleted") {
                                if (PermissionGuard::has($ctx, 'faq_settings', 'delete')) {
                                    $condition = "id = :itemID";
                                    $whereParams = [':itemID' => $itemID];

                                    CrudService::delete($db_prefix . 'faq', $condition, $whereParams);
                                }
                            }

                            if ($actionID == "activated") {
                                if (PermissionGuard::has($ctx, 'faq_settings', 'edit')) {

                                    $columns = ['status', 'updated_date'];
                                    $values = ['active', getCurrentDatetime('Y-m-d H:i:s')];
                                    $condition = "id = :itemID";
                                    $whereParams = [':itemID' => $itemID];

                                    CrudService::update($db_prefix . 'faq', $columns, $values, $condition, $whereParams);

                                }
                            }

                            if ($actionID == "inactivated") {
                                if (PermissionGuard::has($ctx, 'faq_settings', 'edit')) {

                                    $columns = ['status', 'updated_date'];
                                    $values = ['inactive', getCurrentDatetime('Y-m-d H:i:s')];
                                    $condition = "id = :itemID";
                                    $whereParams = [':itemID' => $itemID];

                                    CrudService::update($db_prefix . 'faq', $columns, $values, $condition, $whereParams);

                                }
                            }
                        }
                    }

                    echo json_encode(['status' => 'true', 'title' => 'FAQ ' . $actionID, 'message' => 'The selected faqs have been ' . $actionID . ' successfully.', 'csrf_token' => $new_csrf_token]);
                } else {
                    echo json_encode(['status' => 'false', 'title' => 'FAQ Failed', 'message' => 'No faqs selected.', 'csrf_token' => $new_csrf_token]);
                }
            } else {
                echo json_encode(['status' => 'false', 'title' => 'Request Failed', 'message' => 'Invalid request', 'csrf_token' => $new_csrf_token]);
            }
        }

        if ($action == "faq-delete") {
            if ($global_user_login == true) {
                if (PermissionGuard::denyUnlessCanAccess($ctx, 'brand_settings')) { return; }

                if (PermissionGuard::denyUnlessHas($ctx, 'faq_settings', 'delete')) { return; }

                $ItemID = $request->post('ItemID', '');

                $response_brand = CrudService::select($db_prefix . 'faq', 'WHERE id = :ItemID AND brand_id = :brand_id', '* FROM', [':ItemID' => $ItemID, ':brand_id' => $global_response_brand['response'][0]['brand_id']]);
                if ($response_brand['status'] == true) {
                    $condition = "id = :ItemID";
                    $whereParams = [':ItemID' => $ItemID];

                    CrudService::delete($db_prefix . 'faq', $condition, $whereParams);
                }

                echo json_encode(['status' => 'true', 'title' => 'FAQ Deleted', 'message' => 'The selected faq have been deleted successfully.', 'csrf_token' => $new_csrf_token]);
            } else {
                echo json_encode(['status' => 'false', 'title' => 'Request Failed', 'message' => 'Invalid request', 'csrf_token' => $new_csrf_token]);
            }
        }

    }
}
