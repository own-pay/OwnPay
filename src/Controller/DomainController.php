<?php
declare(strict_types=1);

namespace AnirbanPay\Controller;

use AnirbanPay\Http\RequestContext;

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
            if (!canAccessPage(json_decode($global_response_permission['response'][0]['permission'], true), 'domains', $global_user_response['response'][0]['role'])) {
                echo json_encode(['status' => 'false', 'title' => 'Access denied', 'message' => 'You need permission to perform this action. Please contact the admin.', 'csrf_token' => $new_csrf_token]);
                exit();
            }

            $request = \AnirbanPay\Http\Request::createFromGlobals();
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

            $page = max(1, (int) $request->post('page', '1'));
            $show_limit_val = ($request->post('show_limit') == '') ? 999999 : (int) $request->post('show_limit');
            $offset = ($page - 1) * $show_limit_val;
            $show_limit = $show_limit_val;

            $sql_query = '';

            if ($search_input !== '') {
                $sql_query .= " AND ( domain LIKE :search )";
                $params_domain[':search'] = "%$search_input%";
            }

            $sql_limit = '';
            if ($show_limit == 'all') {

            } else {
                $sql_limit = " LIMIT $offset, $show_limit";
            }

            $response_result = json_decode(getData($db_prefix . 'domain', ' WHERE ' . $where_sql . ' status NOT IN (:empty) ' . $sql_query . ' ORDER BY 1 DESC ' . $sql_limit, '* FROM', $params_domain), true);
            if ($response_result['status'] == true) {
                $response = [];

                foreach ($response_result['response'] as $row) {
                    $response[] = [
                        "id" => $row['id'],
                        "domain" => $row['domain'],
                        "status" => $row['status'],
                        "created_date" => convertUTCtoUserTZ($row['created_date'], ($global_response_brand['response'][0]['timezone'] === '--' || $global_response_brand['response'][0]['timezone'] === '') ? 'Asia/Dhaka' : $global_response_brand['response'][0]['timezone'], "M d, Y h:i A"),
                        "updated_date" => convertUTCtoUserTZ($row['updated_date'], ($global_response_brand['response'][0]['timezone'] === '--' || $global_response_brand['response'][0]['timezone'] === '') ? 'Asia/Dhaka' : $global_response_brand['response'][0]['timezone'], "M d, Y h:i A")
                    ];
                }

                $count_data = json_decode(getData($db_prefix . 'domain', ' WHERE domain NOT IN (:empty) ' . $sql_query, '* FROM', $params_domain), true);

                $total_records = count($count_data['response'] ?? []);
                $total_pages = ceil($total_records / $show_limit);

                $pagination = '<ul class="pagination m-0 ms-auto">';

                $pagination .= '<li class="page-item' . ($page <= 1 ? ' disabled' : '') . '">
                        <button class="page-link" ' . ($page > 1 ? 'data-page="' . ($page - 1) . '"' : '') . '>
                            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="icon icon-1">
                                <path d="M15 6l-6 6l6 6"></path>
                            </svg>
                        </button>
                    </li>';

                for ($i = 1; $i <= $total_pages; $i++) {
                    $pagination .= '<li class="page-item' . ($i == $page ? ' active' : '') . '">
                            <button class="page-link" data-page="' . $i . '">' . $i . '</button>
                        </li>';
                }

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

    private function getDomainById(RequestContext $ctx): void
    {
        $db_prefix = $ctx->dbPrefix;
        $global_user_login = $ctx->isLoggedIn;
        $global_response_permission = $ctx->permissionResponse;
        $global_user_response = $ctx->userResponse;
        $new_csrf_token = $ctx->csrfToken;

        if ($global_user_login == true) {
            if (!canAccessPage(json_decode($global_response_permission['response'][0]['permission'], true), 'domains', $global_user_response['response'][0]['role'])) {
                echo json_encode(['status' => 'false', 'title' => 'Access denied', 'message' => 'You need permission to perform this action. Please contact the admin.', 'csrf_token' => $new_csrf_token]);
                exit();
            }

            if (!hasPermission(json_decode($global_response_permission['response'][0]['permission'], true), 'domains', 'edit', $global_user_response['response'][0]['role'])) {
                echo json_encode(['status' => 'false', 'title' => 'Access denied', 'message' => 'You need permission to perform this action. Please contact the admin.', 'csrf_token' => $new_csrf_token]);
                exit();
            }

            $request = \AnirbanPay\Http\Request::createFromGlobals();
            $ItemID = $request->post('ItemID', '');

            $response_brand = json_decode(getData($db_prefix . 'domain', 'WHERE id = :id', '* FROM', [':id' => $ItemID]), true);
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
            if (!canAccessPage(json_decode($global_response_permission['response'][0]['permission'], true), 'domains', $global_user_response['response'][0]['role'])) {
                echo json_encode(['status' => 'false', 'title' => 'Access denied', 'message' => 'You need permission to perform this action. Please contact the admin.', 'csrf_token' => $new_csrf_token]);
                exit();
            }

            if (!hasPermission(json_decode($global_response_permission['response'][0]['permission'], true), 'domains', 'whitelist', $global_user_response['response'][0]['role'])) {
                echo json_encode(['status' => 'false', 'title' => 'Access denied', 'message' => 'You need permission to perform this action. Please contact the admin.', 'csrf_token' => $new_csrf_token]);
                exit();
            }

            $request = \AnirbanPay\Http\Request::createFromGlobals();
            $domain_name = $request->post('domain_name', '');
            $domain_status = $request->post('domain_status', '');

            if ($domain_name == "" || $domain_status == "") {
                echo json_encode(['status' => "false", 'title' => 'Incomplete Information', 'message' => 'Please fill in all required fields before proceeding.', 'csrf_token' => $new_csrf_token]);
            } else {
                $domain_name = getDomainValue($domain_name);

                if ($domain_name === false) {
                    echo json_encode(['status' => "false", 'title' => 'Invalid Domain', 'message' => 'Please enter a valid domain or domain URL.', 'csrf_token' => $new_csrf_token]);
                } else {
                    $response = json_decode(getData($db_prefix . 'domain', 'WHERE domain = :domain', '* FROM', [':domain' => $domain_name]), true);
                    if ($response['status'] == false) {
                        $columns = ['domain', 'status', 'created_date', 'updated_date'];
                        $values = [$domain_name, $domain_status, getCurrentDatetime('Y-m-d H:i:s'), getCurrentDatetime('Y-m-d H:i:s')];

                        insertData($db_prefix . 'domain', $columns, $values);

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
            if (!canAccessPage(json_decode($global_response_permission['response'][0]['permission'], true), 'domains', $global_user_response['response'][0]['role'])) {
                echo json_encode(['status' => 'false', 'title' => 'Access denied', 'message' => 'You need permission to perform this action. Please contact the admin.', 'csrf_token' => $new_csrf_token]);
                exit();
            }

            if (!hasPermission(json_decode($global_response_permission['response'][0]['permission'], true), 'domains', 'edit', $global_user_response['response'][0]['role'])) {
                echo json_encode(['status' => 'false', 'title' => 'Access denied', 'message' => 'You need permission to perform this action. Please contact the admin.', 'csrf_token' => $new_csrf_token]);
                exit();
            }

            $request = \AnirbanPay\Http\Request::createFromGlobals();
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
                    $response = json_decode(getData($db_prefix . 'domain', 'WHERE id = :id', '* FROM', [':id' => $domain_id]), true);
                    if ($response['status'] == false) {
                        echo json_encode(['status' => 'false', 'title' => 'Request Failed', 'message' => 'Invalid request', 'csrf_token' => $new_csrf_token]);
                    } else {
                        $response = json_decode(getData($db_prefix . 'domain', 'WHERE domain = :domain', '* FROM', [':domain' => $domain_name]), true);
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

                        updateData($db_prefix . 'domain', $columns, $values, $condition, $whereParams);

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
            if (!canAccessPage(json_decode($global_response_permission['response'][0]['permission'], true), 'domains', $global_user_response['response'][0]['role'])) {
                echo json_encode(['status' => 'false', 'title' => 'Access denied', 'message' => 'You need permission to perform this action. Please contact the admin.', 'csrf_token' => $new_csrf_token]);
                exit();
            }

            if (!hasPermission(json_decode($global_response_permission['response'][0]['permission'], true), 'domains', 'delete', $global_user_response['response'][0]['role'])) {
                echo json_encode(['status' => 'false', 'title' => 'Access denied', 'message' => 'You need permission to perform this action. Please contact the admin.', 'csrf_token' => $new_csrf_token]);
                exit();
            }

            $request = \AnirbanPay\Http\Request::createFromGlobals();
            $ItemID = $request->post('ItemID', '');

            $response_brand = json_decode(getData($db_prefix . 'domain', 'WHERE id = :id', '* FROM', [':id' => $ItemID]), true);
            if ($response_brand['status'] == true) {
                $condition = "id = :id";
                $whereParams = [':id' => $ItemID];

                deleteData($db_prefix . 'domain', $condition, $whereParams);
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
            if (!canAccessPage(json_decode($global_response_permission['response'][0]['permission'], true), 'domains', $global_user_response['response'][0]['role'])) {
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

                    $response_brand = json_decode(getData($db_prefix . 'domain', 'WHERE id = :id', '* FROM', [':id' => $itemID]), true);
                    if ($response_brand['status'] == true) {
                        if ($actionID == "deleted") {
                            if (hasPermission(json_decode($global_response_permission['response'][0]['permission'], true), 'domains', 'delete', $global_user_response['response'][0]['role'])) {
                                $condition = "id = :id";
                                $whereParams = [':id' => $itemID];

                                deleteData($db_prefix . 'domain', $condition, $whereParams);
                            }
                        }
                        if ($actionID == "activated") {
                            if (hasPermission(json_decode($global_response_permission['response'][0]['permission'], true), 'domains', 'edit', $global_user_response['response'][0]['role'])) {
                                $columns = ['status', 'updated_date'];
                                $values = ['active', getCurrentDatetime('Y-m-d H:i:s')];
                                $condition = "id = :id";
                                $whereParams = [':id' => $itemID];

                                updateData($db_prefix . 'domain', $columns, $values, $condition, $whereParams);
                            }
                        }

                        if ($actionID == "inactive") {
                            if (hasPermission(json_decode($global_response_permission['response'][0]['permission'], true), 'domains', 'edit', $global_user_response['response'][0]['role'])) {
                                $columns = ['status', 'updated_date'];
                                $values = ['inactive', getCurrentDatetime('Y-m-d H:i:s')];
                                $condition = "id = :id";
                                $whereParams = [':id' => $itemID];

                                updateData($db_prefix . 'domain', $columns, $values, $condition, $whereParams);
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
