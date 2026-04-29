<?php
declare(strict_types=1);

namespace OwnPay\Controller\Admin;

use OwnPay\Http\RequestContext;
use OwnPay\Service\System\CrudService;
use OwnPay\Service\Auth\PermissionGuard;
use OwnPay\Service\System\InputSanitizer;

class DomainController
{
    public static function handle(string $action, ?RequestContext $ctx = null): void
    {
        $ctx ??= $GLOBALS['requestContext'] ?? throw new \RuntimeException('RequestContext not available');
        $controller = new self();

        switch ($action) {
            case 'all-domain-list':
                $controller->listDomains($ctx);
                break;
            case 'domains-info-byID':
                $controller->getDomainById($ctx);
                break;
            case 'create-domains':
                $controller->createDomain($ctx);
                break;
            case 'domains-edit':
                $controller->editDomain($ctx);
                break;
            case 'domains-delete':
                $controller->deleteDomain($ctx);
                break;
            case 'domain-bulk-action':
                $controller->bulkAction($ctx);
                break;
        }
    }

    private function listDomains(RequestContext $ctx): void
    {
        $db_prefix = $ctx->dbPrefix;
        $global_user_login = $ctx->isLoggedIn;
        $global_response_permission = $ctx->permissionResponse;
        $global_user_response = $ctx->userResponse;
        $global_response_brand = $ctx->brandResponse;
        $new_csrf_token = $ctx->csrfToken;

        if ($global_user_login == true) {
            if (PermissionGuard::denyUnlessCanAccess($ctx, 'domains')) { return; }

            $request = \OwnPay\Http\Request::createFromGlobals();
            $search_input = $request->post('search_input', '');
            $show_limit_raw = $request->post('show_limit', '5');

            /* Filters */
            $filter_status = $request->post('filter_status', '');
            $filter_start = $request->post('filter_start', '');
            $filter_end = $request->post('filter_end', '');

            $where = [];
            $params_domain = [':empty' => ''];

            if ($filter_start !== '') {
                $where[] = "created_date >= :filter_start";
                $params_domain[':filter_start'] = "{$filter_start} 00:00:00";
            }

            if ($filter_end !== '') {
                $where[] = "created_date <= :filter_end";
                $params_domain[':filter_end'] = "{$filter_end} 23:59:59";
            }

            if ($filter_status !== '') {
                $where[] = "status = :filter_status";
                $params_domain[':filter_status'] = $filter_status;
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
                $sql_query .= " AND ( domain LIKE :search )";
                $params_domain[':search'] = "%$search_input%";
            }

            $sql_limit = '';
            if ($pag['isAll']) {

            } else {
                $sql_limit = " LIMIT $offset, $show_limit";
            }

            $response_result = CrudService::select($db_prefix . 'domain', ' WHERE ' . $where_sql . ' status NOT IN (:empty) ' . $sql_query . ' ORDER BY 1 DESC ' . $sql_limit, '* FROM', $params_domain);
            if ($response_result['status'] == true) {
                $response = [];

                foreach ($response_result['response'] as $row) {
                    $response[] = [
                        "id" => $row['id'],
                        "domain" => $row['domain'],
                        "status" => $row['status'],
                        "created_date" => convertUTCtoUserTZ($row['created_date'], ($global_response_brand['response'][0]['timezone'] === null || $global_response_brand['response'][0]['timezone'] === '') ? 'Asia/Dhaka' : $global_response_brand['response'][0]['timezone'], "M d, Y h:i A"),
                        "updated_date" => convertUTCtoUserTZ($row['updated_date'], ($global_response_brand['response'][0]['timezone'] === null || $global_response_brand['response'][0]['timezone'] === '') ? 'Asia/Dhaka' : $global_response_brand['response'][0]['timezone'], "M d, Y h:i A")
                    ];
                }

                $count_data = CrudService::select($db_prefix . 'domain', ' WHERE domain NOT IN (:empty) ' . $sql_query, '* FROM', $params_domain);

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

    private function getDomainById(RequestContext $ctx): void
    {
        $db_prefix = $ctx->dbPrefix;
        $global_user_login = $ctx->isLoggedIn;
        $global_response_permission = $ctx->permissionResponse;
        $global_user_response = $ctx->userResponse;
        $new_csrf_token = $ctx->csrfToken;

        if ($global_user_login == true) {
            if (PermissionGuard::denyUnlessCanAccess($ctx, 'domains')) { return; }

            if (PermissionGuard::denyUnlessHas($ctx, 'domains', 'edit')) { return; }

            $request = \OwnPay\Http\Request::createFromGlobals();
            $ItemID = $request->post('ItemID', '');

            $response_brand = CrudService::select($db_prefix . 'domain', 'WHERE id = :id', '* FROM', [':id' => $ItemID]);
            if ($response_brand['status'] == true) {
                echo json_encode(['status' => 'true', 'domain' => $response_brand['response'][0]['domain'], 'istatus' => $response_brand['response'][0]['status'], 'csrf_token' => $new_csrf_token]);
            } else {
                echo json_encode(['status' => 'false', 'title' => 'Request Failed', 'message' => 'Invalid request', 'csrf_token' => $new_csrf_token]);
            }
        } else {
            echo json_encode(['status' => 'false', 'title' => 'Request Failed', 'message' => 'Invalid request', 'csrf_token' => $new_csrf_token]);
        }
    }

    private function createDomain(RequestContext $ctx): void
    {
        $db_prefix = $ctx->dbPrefix;
        $global_user_login = $ctx->isLoggedIn;
        $global_response_permission = $ctx->permissionResponse;
        $global_user_response = $ctx->userResponse;
        $new_csrf_token = $ctx->csrfToken;

        if ($global_user_login == true) {
            if (PermissionGuard::denyUnlessCanAccess($ctx, 'domains')) { return; }

            if (PermissionGuard::denyUnlessHas($ctx, 'domains', 'whitelist')) { return; }

            $request = \OwnPay\Http\Request::createFromGlobals();
            $domain_name = $request->post('domain_name', '');
            $domain_status = $request->post('domain_status', '');

            if ($domain_name == "" || $domain_status == "") {
                echo json_encode(['status' => "false", 'title' => 'Incomplete Information', 'message' => 'Please fill in all required fields before proceeding.', 'csrf_token' => $new_csrf_token]);
            } else {
                $domain_name = getDomainValue($domain_name);

                if ($domain_name === false) {
                    echo json_encode(['status' => "false", 'title' => 'Invalid Domain', 'message' => 'Please enter a valid domain or domain URL.', 'csrf_token' => $new_csrf_token]);
                } else {
                    $response = CrudService::select($db_prefix . 'domain', 'WHERE domain = :domain', '* FROM', [':domain' => $domain_name]);
                    if ($response['status'] == false) {
                        $columns = ['domain', 'status', 'created_date', 'updated_date'];
                        $values = [$domain_name, $domain_status, getCurrentDatetime('Y-m-d H:i:s'), getCurrentDatetime('Y-m-d H:i:s')];

                        CrudService::insert($db_prefix . 'domain', $columns, $values);

                        echo json_encode(['status' => 'true', 'title' => 'Domain Whitelisted', 'message' => 'The domain has been whitelisted successfully.', 'csrf_token' => $new_csrf_token]);
                    } else {
                        echo json_encode(['status' => 'false', 'title' => 'Duplicate Domain', 'message' => 'A domain with this name already exists. Please choose a different name.', 'csrf_token' => $new_csrf_token]);
                    }
                }
            }
        } else {
            echo json_encode(['status' => 'false', 'title' => 'Request Failed', 'message' => 'Invalid request', 'csrf_token' => $new_csrf_token]);
        }
    }

    private function editDomain(RequestContext $ctx): void
    {
        $db_prefix = $ctx->dbPrefix;
        $global_user_login = $ctx->isLoggedIn;
        $global_response_permission = $ctx->permissionResponse;
        $global_user_response = $ctx->userResponse;
        $new_csrf_token = $ctx->csrfToken;

        if ($global_user_login == true) {
            if (PermissionGuard::denyUnlessCanAccess($ctx, 'domains')) { return; }

            if (PermissionGuard::denyUnlessHas($ctx, 'domains', 'edit')) { return; }

            $request = \OwnPay\Http\Request::createFromGlobals();
            $domain_id = $request->post('domain_id', '');
            $domain_name = $request->post('domain_name', '');
            $domain_status = $request->post('domain_status', '');

            if ($domain_id == "" || $domain_name == "" || $domain_status == "") {
                echo json_encode(['status' => "false", 'title' => 'Incomplete Information', 'message' => 'Please fill in all required fields before proceeding.', 'csrf_token' => $new_csrf_token]);
            } else {
                $domain_name = getDomainValue($domain_name);

                if ($domain_name === false) {
                    echo json_encode(['status' => "false", 'title' => 'Invalid Domain', 'message' => 'Please enter a valid domain or domain URL.', 'csrf_token' => $new_csrf_token]);
                } else {
                    $response = CrudService::select($db_prefix . 'domain', 'WHERE id = :id', '* FROM', [':id' => $domain_id]);
                    if ($response['status'] == false) {
                        echo json_encode(['status' => 'false', 'title' => 'Request Failed', 'message' => 'Invalid request', 'csrf_token' => $new_csrf_token]);
                    } else {
                        $response = CrudService::select($db_prefix . 'domain', 'WHERE domain = :domain', '* FROM', [':domain' => $domain_name]);
                        if ($response['status'] == true) {
                            if ($response['response'][0]['id'] == $domain_id) {

                            } else {
                                echo json_encode(['status' => 'false', 'title' => 'Duplicate Domain', 'message' => 'A domain with this name already exists. Please choose a different name.', 'csrf_token' => $new_csrf_token]);
                                exit();
                            }
                        }

                        $columns = ['domain', 'status', 'updated_date'];
                        $values = [$domain_name, $domain_status, getCurrentDatetime('Y-m-d H:i:s')];
                        $condition = "id = :id";
                        $whereParams = [':id' => $domain_id];

                        CrudService::update($db_prefix . 'domain', $columns, $values, $condition, $whereParams);

                        echo json_encode(['status' => 'true', 'title' => 'Domain Updated', 'message' => 'The domain has been updated successfully.', 'csrf_token' => $new_csrf_token]);
                    }
                }
            }
        } else {
            echo json_encode(['status' => 'false', 'title' => 'Request Failed', 'message' => 'Invalid request', 'csrf_token' => $new_csrf_token]);
        }
    }

    private function deleteDomain(RequestContext $ctx): void
    {
        $db_prefix = $ctx->dbPrefix;
        $global_user_login = $ctx->isLoggedIn;
        $global_response_permission = $ctx->permissionResponse;
        $global_user_response = $ctx->userResponse;
        $new_csrf_token = $ctx->csrfToken;

        if ($global_user_login == true) {
            if (PermissionGuard::denyUnlessCanAccess($ctx, 'domains')) { return; }

            if (PermissionGuard::denyUnlessHas($ctx, 'domains', 'delete')) { return; }

            $request = \OwnPay\Http\Request::createFromGlobals();
            $ItemID = $request->post('ItemID', '');

            $response_brand = CrudService::select($db_prefix . 'domain', 'WHERE id = :id', '* FROM', [':id' => $ItemID]);
            if ($response_brand['status'] == true) {
                $condition = "id = :id";
                $whereParams = [':id' => $ItemID];

                CrudService::delete($db_prefix . 'domain', $condition, $whereParams);
            }

            echo json_encode(['status' => 'true', 'title' => 'Domain Deleted', 'message' => 'The selected domain have been deleted successfully.', 'csrf_token' => $new_csrf_token]);
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
        $new_csrf_token = $ctx->csrfToken;

        if ($global_user_login == true) {
            if (PermissionGuard::denyUnlessCanAccess($ctx, 'domains')) { return; }

            $request = \OwnPay\Http\Request::createFromGlobals();
            $actionID = $request->post('actionID', '');
            $selected_ids_json = $request->post('selected_ids', '[]');
            $selected_ids = json_decode($selected_ids_json, true);

            if (!empty($selected_ids)) {
                foreach ($selected_ids as $id) {
                    $itemID = InputSanitizer::trim($id);

                    $response_brand = CrudService::select($db_prefix . 'domain', 'WHERE id = :id', '* FROM', [':id' => $itemID]);
                    if ($response_brand['status'] == true) {
                        if ($actionID == "deleted") {
                            if (PermissionGuard::has($ctx, 'domains', 'delete')) {
                                $condition = "id = :id";
                                $whereParams = [':id' => $itemID];

                                CrudService::delete($db_prefix . 'domain', $condition, $whereParams);
                            }
                        }
                        if ($actionID == "activated") {
                            if (PermissionGuard::has($ctx, 'domains', 'edit')) {
                                $columns = ['status', 'updated_date'];
                                $values = ['active', getCurrentDatetime('Y-m-d H:i:s')];
                                $condition = "id = :id";
                                $whereParams = [':id' => $itemID];

                                CrudService::update($db_prefix . 'domain', $columns, $values, $condition, $whereParams);
                            }
                        }

                        if ($actionID == "inactive") {
                            if (PermissionGuard::has($ctx, 'domains', 'edit')) {
                                $columns = ['status', 'updated_date'];
                                $values = ['inactive', getCurrentDatetime('Y-m-d H:i:s')];
                                $condition = "id = :id";
                                $whereParams = [':id' => $itemID];

                                CrudService::update($db_prefix . 'domain', $columns, $values, $condition, $whereParams);
                            }
                        }
                    }
                }

                echo json_encode(['status' => 'true', 'title' => 'Domains ' . $actionID, 'message' => 'The selected domains have been ' . $actionID . ' successfully.', 'csrf_token' => $new_csrf_token]);
            } else {
                echo json_encode(['status' => 'false', 'title' => 'Request Failed', 'message' => 'No domains selected.', 'csrf_token' => $new_csrf_token]);
            }
        } else {
            echo json_encode(['status' => 'false', 'title' => 'Request Failed', 'message' => 'Invalid request', 'csrf_token' => $new_csrf_token]);
        }
    }
}
