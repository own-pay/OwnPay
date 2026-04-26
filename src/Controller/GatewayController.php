<?php
declare(strict_types=1);

namespace OwnPay\Controller;

use OwnPay\Http\RequestContext;
use OwnPay\Service\CrudService;
use OwnPay\Service\PermissionGuard;

class GatewayController
{
    public static function handle(string $action, RequestContext $ctx): void
    {
        $controller = new self();

        switch ($action) {
            case 'gateway-create':
                $controller->createGateway($ctx);
                break;
            case 'gateways-list':
                $controller->listGateways($ctx);
                break;
            case 'gateways-delete':
                $controller->deleteGateway($ctx);
                break;
            case 'gateway-install':
                $controller->installGateway($ctx);
                break;
            case 'gateway-uninstall':
                $controller->uninstallGateway($ctx);
                break;
        }
    }

    private function createGateway(RequestContext $ctx): void
    {
        $db_prefix = $ctx->dbPrefix;
        $site_url = $ctx->siteUrl;
        $global_user_login = $ctx->isLoggedIn;
        $global_response_permission = $ctx->permissionResponse;
        $global_user_response = $ctx->userResponse;
        $global_response_brand = $ctx->brandResponse;
        $new_csrf_token = $ctx->csrfToken;

        if ($global_user_login == true) {
            if (PermissionGuard::denyUnlessCanAccess($ctx, 'gateways')) { return; }

            if (PermissionGuard::denyUnlessHas($ctx, 'gateways', 'create')) { return; }

            $request = \OwnPay\Http\Request::createFromGlobals();

            $gateway = $request->post('gateway', '');

            if ($gateway == "") {
                echo json_encode(['status' => "false", 'title' => 'Incomplete Information', 'message' => 'Please fill in all required fields before proceeding.', 'csrf_token' => $new_csrf_token]);
            } else {
                $gatewayPath = safeModulePath($gateway, __DIR__ . '/../../app/pp-modules/pp-gateways');
                if ($gatewayPath === false) {
                    echo json_encode(['status' => 'false', 'title' => 'Request Failed', 'message' => 'Invalid request', 'csrf_token' => $new_csrf_token]);
                } else {
                    require_once $gatewayPath;

                    $slug = $gateway; // Already validated as safe slug by safeModulePath()

                    // own-pay → OwnPayGateway
                    $class = str_replace(' ', '', ucwords(str_replace('-', ' ', $slug))) . 'Gateway';

                    if (!class_exists($class)) {
                        echo json_encode(['status' => 'false', 'title' => 'Request Failed', 'message' => 'Invalid request', 'csrf_token' => $new_csrf_token]);
                    } else {
                        $gatewayObj = new $class();

                        $gatewayInfo = $gatewayObj->info();
                        $gatewayColor = $gatewayObj->color();

                        $gateway_id = generateItemID();

                        $columns = ['gateway_id', 'brand_id', 'slug', 'name', 'display', 'logo', 'currency', 'primary_color', 'text_color', 'btn_color', 'btn_text_color', 'tab', 'created_date', 'updated_date'];
                        $values = [$gateway_id, $global_response_brand['response'][0]['brand_id'], $slug, $gatewayInfo['title'], $gatewayInfo['title'], $site_url . 'app/modules/gateways/' . $gateway . '/' . $gatewayInfo['logo'], $gatewayInfo['currency'], $gatewayColor['primary_color'], $gatewayColor['text_color'], $gatewayColor['btn_color'], $gatewayColor['btn_text_color'], $gatewayInfo['tab'], getCurrentDatetime('Y-m-d H:i:s'), getCurrentDatetime('Y-m-d H:i:s')];

                        CrudService::insert($db_prefix . 'gateways', $columns, $values);

                        echo json_encode(['status' => 'true', 'title' => 'Gateway Created', 'message' => 'The gateway has been created successfully.', 'csrf_token' => $new_csrf_token]);

                    }
                }
            }
        } else {
            echo json_encode(['status' => 'false', 'title' => 'Request Failed', 'message' => 'Invalid request', 'csrf_token' => $new_csrf_token]);
        }
    }

    private function listGateways(RequestContext $ctx): void
    {
        $db_prefix = $ctx->dbPrefix;
        $global_user_login = $ctx->isLoggedIn;
        $global_response_permission = $ctx->permissionResponse;
        $global_user_response = $ctx->userResponse;
        $global_response_brand = $ctx->brandResponse;
        $new_csrf_token = $ctx->csrfToken;

        if ($global_user_login == true) {
            if (PermissionGuard::denyUnlessCanAccess($ctx, 'gateways')) { return; }

            $request = \OwnPay\Http\Request::createFromGlobals();

            $search_input = $request->post('search_input', '');
            $show_limit = $request->post('show_limit', '5');

            $tabType = $request->post('tabType', '');

            /* Filters */
            $filter_status = $request->post('filter_status', '');
            $filter_start = $request->post('filter_start', '');
            $filter_end = $request->post('filter_end', '');

            $where = [];
            $params_gw = [':brand_id' => $global_response_brand['response'][0]['brand_id']];

            if ($tabType !== "all") {
                $where[] = "tab = :tab_type";
                $params_gw[':tab_type'] = $tabType;
            }

            if ($filter_start !== '') {
                $where[] = "created_date >= :filter_start";
                $params_gw[':filter_start'] = "{$filter_start} 00:00:00";
            }

            if ($filter_end !== '') {
                $where[] = "created_date <= :filter_end";
                $params_gw[':filter_end'] = "{$filter_end} 23:59:59";
            }

            if ($filter_status !== '') {
                $where[] = "status = :filter_status";
                $params_gw[':filter_status'] = $filter_status;
            }

            $where_sql = $where ? implode(' AND ', $where) . ' AND ' : '';
            /* Filters */

            $pag = \OwnPay\Service\PaginationService::resolve($request->post('page', '1'), $request->post('show_limit'));
            $page = $pag['page'];
            $show_limit_val = $pag['perPage'];
            $offset = $pag['offset'];

            $sql_query = '';

            if ($search_input !== '') {
                $sql_query .= " AND ( name LIKE :search OR display LIKE :search )";
                $params_gw[':search'] = "%$search_input%";
            }

            $sql_limit = '';
            if ($pag['isAll']) {

            } else {
                $sql_limit = " LIMIT $offset, $show_limit_val";
            }

            $response_result = CrudService::select($db_prefix . 'gateways', ' WHERE ' . $where_sql . ' brand_id = :brand_id ' . $sql_query . ' ORDER BY 1 DESC ' . $sql_limit, '* FROM', $params_gw);
            if ($response_result['status'] == true) {
                $response = [];

                foreach ($response_result['response'] as $row) {
                    $response[] = [
                        "id" => $row['gateway_id'],
                        "name" => $row['name'],
                        "display" => $row['display'],
                        "currency" => $row['currency'],
                        "status" => $row['status']
                    ];
                }

                $count_data = CrudService::select($db_prefix . 'gateways', ' WHERE ' . $where_sql . ' brand_id = :brand_id ' . $sql_query, '* FROM', $params_gw);

                $total_records = count($count_data['response'] ?? []);
                $pagHtml = \OwnPay\Service\PaginationService::render($page, $total_records, $show_limit_val, $offset);
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

    private function deleteGateway(RequestContext $ctx): void
    {
        $db_prefix = $ctx->dbPrefix;
        $global_user_login = $ctx->isLoggedIn;
        $global_response_permission = $ctx->permissionResponse;
        $global_user_response = $ctx->userResponse;
        $global_response_brand = $ctx->brandResponse;
        $new_csrf_token = $ctx->csrfToken;

        if ($global_user_login == true) {
            if (PermissionGuard::denyUnlessCanAccess($ctx, 'gateways')) { return; }

            if (PermissionGuard::denyUnlessHas($ctx, 'gateways', 'delete')) { return; }

            $request = \OwnPay\Http\Request::createFromGlobals();

            $ItemID = $request->post('ItemID', '');
            $params_item = [':gw_id' => $ItemID, ':brand_id' => $global_response_brand['response'][0]['brand_id']];

            $response_brand = CrudService::select($db_prefix . 'gateways', 'WHERE gateway_id = :gw_id AND brand_id = :brand_id', '* FROM', $params_item);
            if ($response_brand['status'] == true) {
                $condition = "gateway_id = :gw_id";
                $whereParams = [':gw_id' => $ItemID];

                CrudService::delete($db_prefix . 'gateways', $condition, $whereParams);

                $condition = "gateway_id = :gw_id";
                $whereParams = [':gw_id' => $ItemID];

                CrudService::delete($db_prefix . 'gateways_parameter', $condition, $whereParams);
            }

            echo json_encode(['status' => 'true', 'title' => 'Gateway Deleted', 'message' => 'The selected gateway have been deleted successfully.', 'csrf_token' => $new_csrf_token]);
        } else {
            echo json_encode(['status' => 'false', 'title' => 'Request Failed', 'message' => 'Invalid request', 'csrf_token' => $new_csrf_token]);
        }
    }

    private function installGateway(RequestContext $ctx): void
    {
        $global_user_login = $ctx->isLoggedIn;
        $global_response_permission = $ctx->permissionResponse;
        $global_user_response = $ctx->userResponse;
        $new_csrf_token = $ctx->csrfToken;

        if ($global_user_login == true) {
            if (PermissionGuard::denyUnlessCanAccess($ctx, 'gateways')) { return; }

            if (PermissionGuard::denyUnlessHas($ctx, 'gateways', 'create')) { return; }

            if (!isset($_FILES['plugin_zip']) || $_FILES['plugin_zip']['error'] !== UPLOAD_ERR_OK) {
                echo json_encode(['status' => 'false', 'title' => 'Upload Failed', 'message' => 'Please select a valid ZIP file.', 'csrf_token' => $new_csrf_token]);
                exit();
            }

            $result = \OwnPay\Service\PluginManager::install('gateway', $_FILES['plugin_zip']);

            echo json_encode([
                'status' => $result['status'] ? 'true' : 'false',
                'title' => $result['status'] ? 'Gateway Installed' : 'Installation Failed',
                'message' => $result['message'],
                'csrf_token' => $new_csrf_token
            ]);
        } else {
            echo json_encode(['status' => 'false', 'title' => 'Request Failed', 'message' => 'Invalid request', 'csrf_token' => $new_csrf_token]);
        }
    }

    private function uninstallGateway(RequestContext $ctx): void
    {
        $global_user_login = $ctx->isLoggedIn;
        $global_response_permission = $ctx->permissionResponse;
        $global_user_response = $ctx->userResponse;
        $new_csrf_token = $ctx->csrfToken;

        if ($global_user_login == true) {
            if (PermissionGuard::denyUnlessCanAccess($ctx, 'gateways')) { return; }

            if (PermissionGuard::denyUnlessHas($ctx, 'gateways', 'delete')) { return; }

            $request = \OwnPay\Http\Request::createFromGlobals();

            $slug = $request->post('slug', '');
            if ($slug === '') {
                echo json_encode(['status' => 'false', 'title' => 'Incomplete Information', 'message' => 'Please specify the gateway to uninstall.', 'csrf_token' => $new_csrf_token]);
                exit();
            }

            $result = \OwnPay\Service\PluginManager::uninstall('gateway', $slug);

            echo json_encode([
                'status' => $result['status'] ? 'true' : 'false',
                'title' => $result['status'] ? 'Gateway Uninstalled' : 'Uninstall Failed',
                'message' => $result['message'],
                'csrf_token' => $new_csrf_token
            ]);
        } else {
            echo json_encode(['status' => 'false', 'title' => 'Request Failed', 'message' => 'Invalid request', 'csrf_token' => $new_csrf_token]);
        }
    }
}
