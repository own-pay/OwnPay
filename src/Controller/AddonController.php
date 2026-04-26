<?php
declare(strict_types=1);

namespace OwnPay\Controller;

use OwnPay\Http\RequestContext;
use OwnPay\Service\CrudService;
use OwnPay\Service\InputSanitizer;
use OwnPay\Service\PermissionGuard;

class AddonController
{

    public static function handle(string $action, ?RequestContext $ctx = null): void
    {
        $ctx ??= $GLOBALS['requestContext'] ?? throw new \RuntimeException('RequestContext not available');
        $controller = new self();
        $request = \OwnPay\Http\Request::createFromGlobals();

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

    private function create(\OwnPay\Http\Request $request, RequestContext $ctx): void
    {
        $db_prefix = $ctx->dbPrefix;
        $global_user_login = $ctx->isLoggedIn;
        $global_response_permission = $ctx->permissionResponse;
        $global_user_response = $ctx->userResponse;
        $new_csrf_token = $ctx->csrfToken;

        if ($global_user_login == true) {
            if (!PermissionGuard::canAccess($ctx, 'addons') || !PermissionGuard::has($ctx, 'addons', 'create')) {
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

        // own-pay → OwnPayTheme
        $class = str_replace(' ', '', ucwords(str_replace('-', ' ', $slug))) . 'Addon';

        if (!class_exists($class)) {
            echo json_encode(['status' => 'false', 'title' => 'Request Failed', 'message' => 'Invalid request', 'csrf_token' => $new_csrf_token]);
        } else {
            $addonObj = new $class();

            $addonInfo = $addonObj->info();

            $addon_id = generateItemID();

            $columns = ['addon_id', 'slug', 'name', 'created_date', 'updated_date'];
            $values = [$addon_id, $slug, $addonInfo['title'], getCurrentDatetime('Y-m-d H:i:s'), getCurrentDatetime('Y-m-d H:i:s')];

            CrudService::insert($db_prefix . 'addon', $columns, $values);

            echo json_encode(['status' => 'true', 'title' => 'Addon Created', 'message' => 'The addon has been created successfully.', 'csrf_token' => $new_csrf_token]);

        }
    }


    private function list(\OwnPay\Http\Request $request, RequestContext $ctx): void
    {
        $db_prefix = $ctx->dbPrefix;
        $global_user_login = $ctx->isLoggedIn;
        $global_response_permission = $ctx->permissionResponse;
        $global_user_response = $ctx->userResponse;
        $new_csrf_token = $ctx->csrfToken;

        if ($global_user_login == true) {
            if (!PermissionGuard::canAccess($ctx, 'addons')) {
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

            $pag = \OwnPay\Service\PaginationService::resolve($request->post('page', 1, false), $request->post('show_limit', '', false));
            $page = $pag['page'];
            $show_limit_val = $pag['perPage'];
            $offset = $pag['offset'];

            $sql_query = '';

            if ($search_input !== '') {
                $sql_query .= " AND ( name LIKE :search )";
                $params_addon[':search'] = "%$search_input%";
            }

            $sql_limit = '';
            if ($pag['isAll']) {

            } else {
                $sql_limit = " LIMIT $offset, $show_limit_val";
            }

            $response_result = CrudService::select($db_prefix . 'addon', ' WHERE ' . $where_sql . ' status NOT IN (:no_status) ' . $sql_query . ' ORDER BY 1 DESC ' . $sql_limit, '* FROM', $params_addon);
            if ($response_result['status'] == true) {
                $response = [];

                foreach ($response_result['response'] as $row) {
                    $response[] = [
                        "id" => $row['addon_id'],
                        "name" => $row['name'],
                        "status" => $row['status']
                    ];
                }

                $count_data = CrudService::select($db_prefix . 'addon', ' WHERE ' . $where_sql . ' status NOT IN (:no_status) ' . $sql_query, '* FROM', $params_addon);

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

    private function delete(\OwnPay\Http\Request $request, RequestContext $ctx): void
    {
        $db_prefix = $ctx->dbPrefix;
        $global_user_login = $ctx->isLoggedIn;
        $global_response_permission = $ctx->permissionResponse;
        $global_user_response = $ctx->userResponse;
        $new_csrf_token = $ctx->csrfToken;

        if ($global_user_login == true) {
            if (!PermissionGuard::canAccess($ctx, 'addons') || !PermissionGuard::has($ctx, 'addons', 'delete')) {
                echo json_encode(['status' => 'false', 'title' => 'Access denied', 'message' => 'You need permission to perform this action. Please contact the admin.', 'csrf_token' => $new_csrf_token]);
                exit();
            }

            $ItemID = $request->post('ItemID', '');
            $params_item = [':id' => $ItemID];

            $response_brand = CrudService::select($db_prefix . 'addon', 'WHERE addon_id = :id', '* FROM', $params_item);
            if ($response_brand['status'] == true) {
                $condition = "addon_id = :id";
                $whereParams = [':id' => $ItemID];

                CrudService::delete($db_prefix . 'addon', $condition, $whereParams);

                $condition = "addon_id = :id";
                $whereParams = [':id' => $ItemID];

                CrudService::delete($db_prefix . 'addon_parameter', $condition, $whereParams);
            }

            echo json_encode(['status' => 'true', 'title' => 'Addon Deleted', 'message' => 'The selected addon have been deleted successfully.', 'csrf_token' => $new_csrf_token]);
        } else {
            echo json_encode(['status' => 'false', 'title' => 'Request Failed', 'message' => 'Invalid request', 'csrf_token' => $new_csrf_token]);
        }
    }

    private function bulkAction(\OwnPay\Http\Request $request, RequestContext $ctx): void
    {
        $db_prefix = $ctx->dbPrefix;
        $global_user_login = $ctx->isLoggedIn;
        $global_response_permission = $ctx->permissionResponse;
        $global_user_response = $ctx->userResponse;
        $new_csrf_token = $ctx->csrfToken;

        if ($global_user_login == true) {
            if (!PermissionGuard::canAccess($ctx, 'addons')) {
                echo json_encode(['status' => 'false', 'title' => 'Access denied', 'message' => 'You need permission to perform this action. Please contact the admin.', 'csrf_token' => $new_csrf_token]);
                exit();
            }

            $actionID = $request->post('actionID', '');
            $selected_ids_json = $request->post('selected_ids', '[]', false);
            $selected_ids = json_decode($selected_ids_json, true);

            if (!empty($selected_ids)) {
                foreach ($selected_ids as $id) {
                    $itemID = InputSanitizer::trim($id);
                    $params_item = [':id' => $itemID];

                    $response_brand = CrudService::select($db_prefix . 'addon', 'WHERE addon_id = :id', '* FROM', $params_item);
                    if ($response_brand['status'] == true) {
                        if ($actionID == "deleted") {
                            if (PermissionGuard::has($ctx, 'addons', 'delete')) {

                                $condition = "addon_id = :id";
                                $whereParams = [':id' => $itemID];

                                CrudService::delete($db_prefix . 'addon', $condition, $whereParams);

                                $condition = "addon_id = :id";
                                $whereParams = [':id' => $itemID];

                                CrudService::delete($db_prefix . 'addon_parameter', $condition, $whereParams);
                            }
                        }

                        if ($actionID == "activated") {
                            if (PermissionGuard::has($ctx, 'addons', 'edit')) {

                                $columns = ['status', 'updated_date'];
                                $values = ['active', getCurrentDatetime('Y-m-d H:i:s')];
                                $condition = "addon_id = :id";
                                $whereParams = [':id' => $itemID];

                                CrudService::update($db_prefix . 'addon', $columns, $values, $condition, $whereParams);

                            }
                        }

                        if ($actionID == "inactivated") {
                            if (PermissionGuard::has($ctx, 'addons', 'edit')) {

                                $columns = ['status', 'updated_date'];
                                $values = ['inactive', getCurrentDatetime('Y-m-d H:i:s')];
                                $condition = "addon_id = :id";
                                $whereParams = [':id' => $itemID];

                                CrudService::update($db_prefix . 'addon', $columns, $values, $condition, $whereParams);

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

    private function update(\OwnPay\Http\Request $request, RequestContext $ctx): void
    {
        $db_prefix = $ctx->dbPrefix;
        $global_user_login = $ctx->isLoggedIn;
        $global_response_permission = $ctx->permissionResponse;
        $global_user_response = $ctx->userResponse;
        $new_csrf_token = $ctx->csrfToken;

        if ($global_user_login == true) {
            if (!PermissionGuard::canAccess($ctx, 'addons') || !PermissionGuard::has($ctx, 'addons', 'edit')) {
                echo json_encode(['status' => 'false', 'title' => 'Access denied', 'message' => 'You need permission to perform this action. Please contact the admin.', 'csrf_token' => $new_csrf_token]);
                exit();
            }

            $addon_id = $request->post('addon-id', '');
            $status = $request->post('status', '');

            if ($addon_id == "" || $status == "") {
                echo json_encode(['status' => "false", 'title' => 'Incomplete Information', 'message' => 'Please fill in all required fields before proceeding.', 'csrf_token' => $new_csrf_token]);
            } else {
                $params_item = [':id' => $addon_id];
                $response = CrudService::select($db_prefix . 'addon', 'WHERE addon_id = :id', '* FROM', $params_item);
                if ($response['status'] == true) {
                    $columns = ['status', 'updated_date'];
                    $values = [$status, getCurrentDatetime('Y-m-d H:i:s')];
                    $condition = "addon_id = :id";
                    $whereParams = [':id' => $addon_id];

                    CrudService::update($db_prefix . 'addon', $columns, $values, $condition, $whereParams);

                    echo json_encode(['status' => 'true', 'title' => 'Addon Updated', 'message' => 'The addon has been updated successfully.', 'csrf_token' => $new_csrf_token]);
                } else {
                    echo json_encode(['status' => 'false', 'title' => 'Request Failed', 'message' => 'Invalid Addon ID', 'csrf_token' => $new_csrf_token]);
                }
            }
        } else {
            echo json_encode(['status' => 'false', 'title' => 'Request Failed', 'message' => 'Invalid request', 'csrf_token' => $new_csrf_token]);
        }
    }

    private function configurationUpdate(\OwnPay\Http\Request $request, RequestContext $ctx): void
    {
        $db_prefix = $ctx->dbPrefix;
        $global_user_login = $ctx->isLoggedIn;
        $global_response_permission = $ctx->permissionResponse;
        $global_user_response = $ctx->userResponse;
        $new_csrf_token = $ctx->csrfToken;
        $site_url = $ctx->siteUrl;

        if ($global_user_login == true) {
            if (!PermissionGuard::canAccess($ctx, 'addons') || !PermissionGuard::has($ctx, 'addons', 'edit')) {
                echo json_encode(['status' => 'false', 'title' => 'Access denied', 'message' => 'You need permission to perform this action. Please contact the admin.', 'csrf_token' => $new_csrf_token]);
                exit();
            }

            $addon_id = $request->post('addon-id', '');

            if ($addon_id == "") {
                echo json_encode(['status' => "false", 'title' => 'Incomplete Information', 'message' => 'Please fill in all required fields before proceeding.', 'csrf_token' => $new_csrf_token]);
            } else {
                $params_item = [':id' => $addon_id];
                $response = CrudService::select($db_prefix . 'addon', 'WHERE addon_id = :id', '* FROM', $params_item);
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
                        $response_optionValue = CrudService::select($db_prefix . 'addon_parameter', 'WHERE addon_id = :id AND option_name = :opt_name', '* FROM', $params_opt);

                        if (isset($response_optionValue['response'][0]['value'])) {
                            $columns = ['value', 'updated_date'];
                            $values = [($optionValue == "") ? null : $optionValue, getCurrentDatetime('Y-m-d H:i:s')];
                            $condition = "id = :id";
                            $whereParams = [':id' => $response_optionValue['response'][0]['id']];

                            CrudService::update($db_prefix . 'addon_parameter', $columns, $values, $condition, $whereParams);
                        } else {
                            $columns = ['addon_id', 'option_name', 'value', 'created_date', 'updated_date'];
                            $values = [$addon_id, $optionName, ($optionValue == "") ? null : $optionValue, getCurrentDatetime('Y-m-d H:i:s'), getCurrentDatetime('Y-m-d H:i:s')];

                            CrudService::insert($db_prefix . 'addon_parameter', $columns, $values);
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
