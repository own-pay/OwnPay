<?php
declare(strict_types=1);

namespace AnirbanPay\Controller;

use AnirbanPay\Http\RequestContext;

class AddonController
{

    public static function handle(string $action, ?RequestContext $ctx = null): void
    {
        $ctx ??= $GLOBALS['requestContext'] ?? throw new \RuntimeException('RequestContext not available');
        $controller = new self();
        $request = \AnirbanPay\Http\Request::createFromGlobals();

        switch ($action) {
            case 'addons-create':
                $controller->create($request, $ctx);
                break;
            case 'addons-list':
                $controller->list($request, $ctx);
                break;
            case 'addons-delete':
                $controller->delete($request, $ctx);
                break;
            case 'addons-bulk-action':
                $controller->bulkAction($request, $ctx);
                break;
            case 'addon-setting-update':
                $controller->update($request, $ctx);
                break;
            case 'addon-configuration-update':
                $controller->configurationUpdate($request, $ctx);
                break;
        }
    }

    private function create(\AnirbanPay\Http\Request $request, RequestContext $ctx): void
    {
        $db_prefix = $ctx->dbPrefix;
        $global_user_login = $ctx->isLoggedIn;
        $global_response_permission = $ctx->permissionResponse;
        $global_user_response = $ctx->userResponse;
        $new_csrf_token = $ctx->csrfToken;

        if ($global_user_login == true) {
            if (!canAccessPage(json_decode($global_response_permission['response'][0]['permission'], true), 'addons', $global_user_response['response'][0]['role'])) {
                echo json_encode(['status' => 'false', 'title' => 'Access denied', 'message' => 'You need permission to perform this action. Please contact the admin.', 'csrf_token' => $new_csrf_token]);
                exit();
            }

            if (!hasPermission(json_decode($global_response_permission['response'][0]['permission'], true), 'addons', 'create', $global_user_response['response'][0]['role'])) {
                echo json_encode(['status' => 'false', 'title' => 'Access denied', 'message' => 'You need permission to perform this action. Please contact the admin.', 'csrf_token' => $new_csrf_token]);
                exit();
            }

            $addon = $request->post('addon', '');

            if ($addon == "") {
                echo json_encode(['status' => "false", 'title' => 'Incomplete Information', 'message' => 'Please fill in all required fields before proceeding.', 'csrf_token' => $new_csrf_token]);
            } else {
                if (!file_exists(__DIR__ . '/../../app/modules/addons/' . $addon . '/class.php')) {
                    // Assuming pp-modules/pp-addons is roughly meant to refer to app/modules/addons based on standard path
                    if (!file_exists(__DIR__ . '/../../app/modules/pp-addons/' . $addon . '/class.php')) {
                        echo json_encode(['status' => 'false', 'title' => 'Request Failed', 'message' => 'Invalid request', 'csrf_token' => $new_csrf_token]);
                    } else {
                        require_once __DIR__ . '/../../app/modules/pp-addons/' . $addon . '/class.php';
                        $slug = basename(__DIR__ . '/../../app/modules/pp-addons/' . $addon);
                        $this->instantiateAddon($slug, $ctx);
                    }
                } else {
                    require_once __DIR__ . '/../../app/modules/addons/' . $addon . '/class.php';
                    $slug = basename(__DIR__ . '/../../app/modules/addons/' . $addon);
                    $this->instantiateAddon($slug, $ctx);
                }
            }
        } else {
            echo json_encode(['status' => 'false', 'title' => 'Request Failed', 'message' => 'Invalid request', 'csrf_token' => $new_csrf_token]);
        }
    }

    private function instantiateAddon($slug, RequestContext $ctx): void
    {
        $db_prefix = $ctx->dbPrefix;
        $new_csrf_token = $ctx->csrfToken;

        // twenty-six → TwentySixTheme
        $class = str_replace(' ', '', ucwords(str_replace('-', ' ', $slug))) . 'Addon';

        if (!class_exists($class)) {
            echo json_encode(['status' => 'false', 'title' => 'Request Failed', 'message' => 'Invalid request', 'csrf_token' => $new_csrf_token]);
        } else {
            $addonObj = new $class();

            $addonInfo = $addonObj->info();

            $addon_id = generateItemID();

            $columns = ['addon_id', 'slug', 'name', 'created_date', 'updated_date'];
            $values = [$addon_id, $slug, $addonInfo['title'], getCurrentDatetime('Y-m-d H:i:s'), getCurrentDatetime('Y-m-d H:i:s')];

            insertData($db_prefix . 'addon', $columns, $values);

            echo json_encode(['status' => 'true', 'title' => 'Addon Created', 'message' => 'The addon has been created successfully.', 'csrf_token' => $new_csrf_token]);

        }
    }


    private function list(\AnirbanPay\Http\Request $request, RequestContext $ctx): void
    {
        $db_prefix = $ctx->dbPrefix;
        $global_user_login = $ctx->isLoggedIn;
        $global_response_permission = $ctx->permissionResponse;
        $global_user_response = $ctx->userResponse;
        $new_csrf_token = $ctx->csrfToken;

        if ($global_user_login == true) {
            if (!canAccessPage(json_decode($global_response_permission['response'][0]['permission'], true), 'addons', $global_user_response['response'][0]['role'])) {
                echo json_encode(['status' => 'false', 'title' => 'Access denied', 'message' => 'You need permission to perform this action. Please contact the admin.', 'csrf_token' => $new_csrf_token]);
                exit();
            }

            $search_input = $request->post('search_input', '');
            $show_limit = (string) $request->post('show_limit', 5);

            /* Filters */
            $filter_status = $request->post('filter_status', '');
            $filter_start = $request->post('filter_start', '');
            $filter_end = $request->post('filter_end', '');

            $where = [];
            $params_addon = [':no_status' => '--'];

            if ($filter_start !== '') {
                $where[] = "created_date >= :filter_start";
                $params_addon[':filter_start'] = "{$filter_start} 00:00:00";
            }

            if ($filter_end !== '') {
                $where[] = "created_date <= :filter_end";
                $params_addon[':filter_end'] = "{$filter_end} 23:59:59";
            }

            if ($filter_status !== '') {
                $where[] = "status = :filter_status";
                $params_addon[':filter_status'] = $filter_status;
            }

            $where_sql = $where ? implode(' AND ', $where) . ' AND ' : '';
            /* Filters */

            $page = max(1, intval($request->post('page', 1, false)));
            $show_limit_val = ($request->post('show_limit', '', false) == '') ? 999999 : intval($request->post('show_limit', '', false));
            $offset = ($page - 1) * $show_limit_val;

            $sql_query = '';

            if ($search_input !== '') {
                $sql_query .= " AND ( name LIKE :search )";
                $params_addon[':search'] = "%$search_input%";
            }

            $sql_limit = '';
            if ($show_limit_val == 'all') {

            } else {
                $sql_limit = " LIMIT $offset, $show_limit_val";
            }

            $response_result = json_decode(getData($db_prefix . 'addon', ' WHERE ' . $where_sql . ' status NOT IN (:no_status) ' . $sql_query . ' ORDER BY 1 DESC ' . $sql_limit, '* FROM', $params_addon), true);
            if ($response_result['status'] == true) {
                $response = [];

                foreach ($response_result['response'] as $row) {
                    $response[] = [
                        "id" => $row['addon_id'],
                        "name" => $row['name'],
                        "status" => $row['status']
                    ];
                }

                $count_data = json_decode(getData($db_prefix . 'addon', ' WHERE ' . $where_sql . ' status NOT IN (:no_status) ' . $sql_query, '* FROM', $params_addon), true);

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

    private function delete(\AnirbanPay\Http\Request $request, RequestContext $ctx): void
    {
        $db_prefix = $ctx->dbPrefix;
        $global_user_login = $ctx->isLoggedIn;
        $global_response_permission = $ctx->permissionResponse;
        $global_user_response = $ctx->userResponse;
        $new_csrf_token = $ctx->csrfToken;

        if ($global_user_login == true) {
            if (!canAccessPage(json_decode($global_response_permission['response'][0]['permission'], true), 'addons', $global_user_response['response'][0]['role'])) {
                echo json_encode(['status' => 'false', 'title' => 'Access denied', 'message' => 'You need permission to perform this action. Please contact the admin.', 'csrf_token' => $new_csrf_token]);
                exit();
            }

            if (!hasPermission(json_decode($global_response_permission['response'][0]['permission'], true), 'addons', 'delete', $global_user_response['response'][0]['role'])) {
                echo json_encode(['status' => 'false', 'title' => 'Access denied', 'message' => 'You need permission to perform this action. Please contact the admin.', 'csrf_token' => $new_csrf_token]);
                exit();
            }

            $ItemID = $request->post('ItemID', '');
            $params_item = [':id' => $ItemID];

            $response_brand = json_decode(getData($db_prefix . 'addon', 'WHERE addon_id = :id', '* FROM', $params_item), true);
            if ($response_brand['status'] == true) {
                $condition = "addon_id = :id";
                $whereParams = [':id' => $ItemID];

                deleteData($db_prefix . 'addon', $condition, $whereParams);

                $condition = "addon_id = :id";
                $whereParams = [':id' => $ItemID];

                deleteData($db_prefix . 'addon_parameter', $condition, $whereParams);
            }

            echo json_encode(['status' => 'true', 'title' => 'Addon Deleted', 'message' => 'The selected addon have been deleted successfully.', 'csrf_token' => $new_csrf_token]);
        } else {
            echo json_encode(['status' => 'false', 'title' => 'Request Failed', 'message' => 'Invalid request', 'csrf_token' => $new_csrf_token]);
        }
    }

    private function bulkAction(\AnirbanPay\Http\Request $request, RequestContext $ctx): void
    {
        $db_prefix = $ctx->dbPrefix;
        $global_user_login = $ctx->isLoggedIn;
        $global_response_permission = $ctx->permissionResponse;
        $global_user_response = $ctx->userResponse;
        $new_csrf_token = $ctx->csrfToken;

        if ($global_user_login == true) {
            if (!canAccessPage(json_decode($global_response_permission['response'][0]['permission'], true), 'addons', $global_user_response['response'][0]['role'])) {
                echo json_encode(['status' => 'false', 'title' => 'Access denied', 'message' => 'You need permission to perform this action. Please contact the admin.', 'csrf_token' => $new_csrf_token]);
                exit();
            }

            $actionID = $request->post('actionID', '');
            $selected_ids_json = $request->post('selected_ids', '[]', false);
            $selected_ids = json_decode($selected_ids_json, true);

            if (!empty($selected_ids)) {
                foreach ($selected_ids as $id) {
                    $itemID = escape_string($id);
                    $params_item = [':id' => $itemID];

                    $response_brand = json_decode(getData($db_prefix . 'addon', 'WHERE addon_id = :id', '* FROM', $params_item), true);
                    if ($response_brand['status'] == true) {
                        if ($actionID == "deleted") {
                            if (hasPermission(json_decode($global_response_permission['response'][0]['permission'], true), 'addons', 'delete', $global_user_response['response'][0]['role'])) {

                                $condition = "addon_id = :id";
                                $whereParams = [':id' => $itemID];

                                deleteData($db_prefix . 'addon', $condition, $whereParams);

                                $condition = "addon_id = :id";
                                $whereParams = [':id' => $itemID];

                                deleteData($db_prefix . 'addon_parameter', $condition, $whereParams);
                            }
                        }

                        if ($actionID == "activated") {
                            if (hasPermission(json_decode($global_response_permission['response'][0]['permission'], true), 'addons', 'edit', $global_user_response['response'][0]['role'])) {

                                $columns = ['status', 'updated_date'];
                                $values = ['active', getCurrentDatetime('Y-m-d H:i:s')];
                                $condition = "addon_id = :id";
                                $whereParams = [':id' => $itemID];

                                updateData($db_prefix . 'addon', $columns, $values, $condition, $whereParams);

                            }
                        }

                        if ($actionID == "inactivated") {
                            if (hasPermission(json_decode($global_response_permission['response'][0]['permission'], true), 'addons', 'edit', $global_user_response['response'][0]['role'])) {

                                $columns = ['status', 'updated_date'];
                                $values = ['inactive', getCurrentDatetime('Y-m-d H:i:s')];
                                $condition = "addon_id = :id";
                                $whereParams = [':id' => $itemID];

                                updateData($db_prefix . 'addon', $columns, $values, $condition, $whereParams);

                            }
                        }
                    }
                }

                echo json_encode(['status' => 'true', 'title' => 'Addons ' . $actionID, 'message' => 'The selected addons have been ' . $actionID . ' successfully.', 'csrf_token' => $new_csrf_token]);
            } else {
                echo json_encode(['status' => 'false', 'title' => 'Request Failed', 'message' => 'No addons selected.', 'csrf_token' => $new_csrf_token]);
            }
        } else {
            echo json_encode(['status' => 'false', 'title' => 'Request Failed', 'message' => 'Invalid request', 'csrf_token' => $new_csrf_token]);
        }
    }

    private function update(\AnirbanPay\Http\Request $request, RequestContext $ctx): void
    {
        $db_prefix = $ctx->dbPrefix;
        $global_user_login = $ctx->isLoggedIn;
        $global_response_permission = $ctx->permissionResponse;
        $global_user_response = $ctx->userResponse;
        $new_csrf_token = $ctx->csrfToken;

        if ($global_user_login == true) {
            if (!canAccessPage(json_decode($global_response_permission['response'][0]['permission'], true), 'addons', $global_user_response['response'][0]['role'])) {
                echo json_encode(['status' => 'false', 'title' => 'Access denied', 'message' => 'You need permission to perform this action. Please contact the admin.', 'csrf_token' => $new_csrf_token]);
                exit();
            }

            if (!hasPermission(json_decode($global_response_permission['response'][0]['permission'], true), 'addons', 'edit', $global_user_response['response'][0]['role'])) {
                echo json_encode(['status' => 'false', 'title' => 'Access denied', 'message' => 'You need permission to perform this action. Please contact the admin.', 'csrf_token' => $new_csrf_token]);
                exit();
            }

            $addon_id = $request->post('addon-id', '');
            $status = $request->post('status', '');

            if ($addon_id == "" || $status == "") {
                echo json_encode(['status' => "false", 'title' => 'Incomplete Information', 'message' => 'Please fill in all required fields before proceeding.', 'csrf_token' => $new_csrf_token]);
            } else {
                $params_item = [':id' => $addon_id];
                $response = json_decode(getData($db_prefix . 'addon', 'WHERE addon_id = :id', '* FROM', $params_item), true);
                if ($response['status'] == true) {
                    $columns = ['status', 'updated_date'];
                    $values = [$status, getCurrentDatetime('Y-m-d H:i:s')];
                    $condition = "addon_id = :id";
                    $whereParams = [':id' => $addon_id];

                    updateData($db_prefix . 'addon', $columns, $values, $condition, $whereParams);

                    echo json_encode(['status' => 'true', 'title' => 'Addon Updated', 'message' => 'The addon has been updated successfully.', 'csrf_token' => $new_csrf_token]);
                } else {
                    echo json_encode(['status' => 'false', 'title' => 'Request Failed', 'message' => 'Invalid Addon ID', 'csrf_token' => $new_csrf_token]);
                }
            }
        } else {
            echo json_encode(['status' => 'false', 'title' => 'Request Failed', 'message' => 'Invalid request', 'csrf_token' => $new_csrf_token]);
        }
    }

    private function configurationUpdate(\AnirbanPay\Http\Request $request, RequestContext $ctx): void
    {
        $db_prefix = $ctx->dbPrefix;
        $global_user_login = $ctx->isLoggedIn;
        $global_response_permission = $ctx->permissionResponse;
        $global_user_response = $ctx->userResponse;
        $new_csrf_token = $ctx->csrfToken;
        $site_url = $ctx->siteUrl;

        if ($global_user_login == true) {
            if (!canAccessPage(json_decode($global_response_permission['response'][0]['permission'], true), 'addons', $global_user_response['response'][0]['role'])) {
                echo json_encode(['status' => 'false', 'title' => 'Access denied', 'message' => 'You need permission to perform this action. Please contact the admin.', 'csrf_token' => $new_csrf_token]);
                exit();
            }

            if (!hasPermission(json_decode($global_response_permission['response'][0]['permission'], true), 'addons', 'edit', $global_user_response['response'][0]['role'])) {
                echo json_encode(['status' => 'false', 'title' => 'Access denied', 'message' => 'You need permission to perform this action. Please contact the admin.', 'csrf_token' => $new_csrf_token]);
                exit();
            }

            $addon_id = $request->post('addon-id', '');

            if ($addon_id == "") {
                echo json_encode(['status' => "false", 'title' => 'Incomplete Information', 'message' => 'Please fill in all required fields before proceeding.', 'csrf_token' => $new_csrf_token]);
            } else {
                $params_item = [':id' => $addon_id];
                $response = json_decode(getData($db_prefix . 'addon', 'WHERE addon_id = :id', '* FROM', $params_item), true);
                if ($response['status'] == true) {
                    $configData = [];

                    foreach ($request->postAll(false) as $key => $value) {
                        // Handle multi-select (array)
                        if (is_array($value)) {
                            $value = json_encode($value);
                        }

                        $configData[$key] = $value;
                    }

                    foreach ($_FILES as $key => $file) {
                        if (empty($file['name']))
                            continue;

                        $max_file_size = 5 * 1024 * 1024;

                        $mediaUpload = json_decode(uploadImage($_FILES[$key] ?? null, $max_file_size), true);
                        if ($mediaUpload['status'] == true) {
                            $configData[$key] = $site_url . 'media/storage/' . $mediaUpload['file'];
                        }
                    }

                    foreach ($configData as $optionName => $optionValue) {
                        $params_opt = [':id' => $addon_id, ':opt_name' => $optionName];
                        $response_optionValue = json_decode(getData($db_prefix . 'addon_parameter', 'WHERE addon_id = :id AND option_name = :opt_name', '* FROM', $params_opt), true);

                        if (isset($response_optionValue['response'][0]['value'])) {
                            $columns = ['value', 'updated_date'];
                            $values = [($optionValue == "") ? '--' : $optionValue, getCurrentDatetime('Y-m-d H:i:s')];
                            $condition = "id = :id";
                            $whereParams = [':id' => $response_optionValue['response'][0]['id']];

                            updateData($db_prefix . 'addon_parameter', $columns, $values, $condition, $whereParams);
                        } else {
                            $columns = ['addon_id', 'option_name', 'value', 'created_date', 'updated_date'];
                            $values = [$addon_id, $optionName, ($optionValue == "") ? '--' : $optionValue, getCurrentDatetime('Y-m-d H:i:s'), getCurrentDatetime('Y-m-d H:i:s')];

                            insertData($db_prefix . 'addon_parameter', $columns, $values);
                        }
                    }

                    echo json_encode(['status' => 'true', 'title' => 'Addon Updated', 'message' => 'The addon configuration has been updated successfully.', 'csrf_token' => $new_csrf_token]);
                } else {
                    echo json_encode(['status' => 'false', 'title' => 'Request Failed', 'message' => 'Invalid Addon ID', 'csrf_token' => $new_csrf_token]);
                }
            }
        } else {
            echo json_encode(['status' => 'false', 'title' => 'Request Failed', 'message' => 'Invalid request', 'csrf_token' => $new_csrf_token]);
        }
    }
}
