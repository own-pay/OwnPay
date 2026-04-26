<?php
declare(strict_types=1);

namespace OwnPay\Controller;

use OwnPay\Http\RequestContext;
use OwnPay\Service\CrudService;
use OwnPay\Service\DevicePairingService;
use OwnPay\Service\InputSanitizer;
use OwnPay\Service\PermissionGuard;
use OwnPay\Repository\PairedDeviceRepository;

class DeviceController
{
    public static function handle(string $action, RequestContext $ctx): void
    {
        $global_response_brand = $ctx->brandResponse;
        $new_csrf_token = $ctx->csrfToken;
        $db_prefix = $ctx->dbPrefix;
        $op_admin = $ctx->isAdmin();

        $request = \OwnPay\Http\Request::createFromGlobals();

        // Inline extracted code from adapter.php
        if ($action == "device-list") {
            if ($ctx->isLoggedIn) {
                if (PermissionGuard::denyUnlessCanAccess($ctx, 'device')) { return; }

                $search_input = $request->post('search_input', '');
                $show_limit_raw = $request->post('show_limit', '5');

                /* Filters */
                $filter_status = $request->post('filter_status', '');
                $filter_start = $request->post('filter_start', '');
                $filter_end = $request->post('filter_end', '');

                $where = [];
                $params_device = [':status_used' => 'used'];

                if ($filter_start !== '') {
                    $where[] = "created_date >= :filter_start";
                    $params_device[':filter_start'] = "{$filter_start} 00:00:00";
                }

                if ($filter_end !== '') {
                    $where[] = "created_date <= :filter_end";
                    $params_device[':filter_end'] = "{$filter_end} 23:59:59";
                }

                if ($filter_status !== '') {
                    if ($filter_status === 'connected') {
                        $where[] = "updated_date >= (NOW() - INTERVAL 6 MINUTE)";
                    } elseif ($filter_status === 'disconnected') {
                        $where[] = "updated_date < (NOW() - INTERVAL 6 MINUTE)";
                    }
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
                    $sql_query .= " AND ( name LIKE :search OR model LIKE :search OR android_level LIKE :search )";
                    $params_device[':search'] = "%$search_input%";
                }

                $sql_limit = '';
                if ($pag['isAll']) {

                } else {
                    $sql_limit = " LIMIT $offset, $show_limit_val";
                }

                $response_result = CrudService::select($db_prefix . 'device', ' WHERE ' . $where_sql . ' status = :status_used ' . $sql_query . ' ORDER BY 1 DESC ' . $sql_limit, '* FROM', $params_device);
                if ($response_result['status'] == true) {
                    $response = [];

                    foreach ($response_result['response'] as $row) {
                        $response[] = [
                            "id" => $row['device_id'],
                            "name" => $row['name'],
                            "model" => $row['model'],
                            "android_level" => $row['android_level'],
                            "status" => $row['status'],
                            "created_date" => convertUTCtoUserTZ($row['created_date'], ($global_response_brand['response'][0]['timezone'] === null || $global_response_brand['response'][0]['timezone'] === '') ? 'Asia/Dhaka' : $global_response_brand['response'][0]['timezone'], "M d, Y h:i A"),
                            "updated_date" => convertUTCtoUserTZ($row['updated_date'], ($global_response_brand['response'][0]['timezone'] === null || $global_response_brand['response'][0]['timezone'] === '') ? 'Asia/Dhaka' : $global_response_brand['response'][0]['timezone'], "M d, Y h:i A"),
                            "last_sync" => empty($row['last_sync']) ? '' : convertUTCtoUserTZ($row['last_sync'], ($global_response_brand['response'][0]['timezone'] === null || $global_response_brand['response'][0]['timezone'] === '') ? 'Asia/Dhaka' : $global_response_brand['response'][0]['timezone'], "M d, Y h:i A")
                        ];
                    }

                    $count_data = CrudService::select($db_prefix . 'device', ' WHERE ' . $where_sql . ' status = :status_used ' . $sql_query, '* FROM', $params_device);


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

        if ($action == "device-delete") {
            if ($ctx->isLoggedIn) {
                if (PermissionGuard::denyUnlessCanAccess($ctx, 'device')) { return; }
                if (PermissionGuard::denyUnlessHas($ctx, 'device', 'delete')) { return; }

                $ItemID = $request->post('ItemID', '');
                $params_item = [':id' => $ItemID];

                $response_brand = CrudService::select($db_prefix . 'device', 'WHERE device_id = :id', '* FROM', $params_item);
                if ($response_brand['status'] == true) {
                    $condition = "device_id = :id";
                    $whereParams = [':id' => $ItemID];

                    CrudService::delete($db_prefix . 'device', $condition, $whereParams);

                    $condition = "device_id = :id";

                    CrudService::delete($db_prefix . 'balance_verification', $condition, $whereParams);
                }

                echo json_encode(['status' => 'true', 'title' => 'Device Deleted', 'message' => 'The selected device have been deleted successfully.', 'csrf_token' => $new_csrf_token]);
            } else {
                echo json_encode(['status' => 'false', 'title' => 'Request Failed', 'message' => 'Invalid request', 'csrf_token' => $new_csrf_token]);
            }
        }

        if ($action == "device-bulk-action") {
            if ($ctx->isLoggedIn) {
                if (PermissionGuard::denyUnlessCanAccess($ctx, 'device')) { return; }

                $actionID = $request->post('actionID', '');
                $selected_ids_json = $request->post('selected_ids', '[]');
                $selected_ids = json_decode($selected_ids_json, true);

                if (!empty($selected_ids)) {
                    foreach ($selected_ids as $id) {
                        $itemID = InputSanitizer::trim($id);
                        $params_item = [':id' => $itemID];

                        $response_brand = CrudService::select($db_prefix . 'device', 'WHERE device_id = :id', '* FROM', $params_item);
                        if ($response_brand['status'] == true) {
                            if ($actionID == "deleted") {
                                if (PermissionGuard::has($ctx, 'device', 'delete')) {

                                    $condition = "device_id = :id";
                                    $whereParams = [':id' => $itemID];

                                    CrudService::delete($db_prefix . 'device', $condition, $whereParams);

                                    $condition = "device_id = :id";

                                    CrudService::delete($db_prefix . 'balance_verification', $condition, $whereParams);
                                }
                            }
                        }
                    }

                    echo json_encode(['status' => 'true', 'title' => 'Devices ' . $actionID, 'message' => 'The selected devices have been ' . $actionID . ' successfully.', 'csrf_token' => $new_csrf_token]);
                } else {
                    echo json_encode(['status' => 'false', 'title' => 'Request Failed', 'message' => 'No devices selected.', 'csrf_token' => $new_csrf_token]);
                }
            } else {
                echo json_encode(['status' => 'false', 'title' => 'Request Failed', 'message' => 'Invalid request', 'csrf_token' => $new_csrf_token]);
            }
        }

        if ($action == "device-connect-info") {
            if ($ctx->isLoggedIn) {
                if (PermissionGuard::denyUnlessCanAccess($ctx, 'device')) { return; }
                if (PermissionGuard::denyUnlessHas($ctx, 'device', 'connect')) { return; }

                $otp = generateItemID();
                $params_dev = [':status' => 'processing', ':d_id' => $op_admin];

                $response_brand = CrudService::select($db_prefix . 'device', 'WHERE status = :status AND d_id = :d_id', '* FROM', $params_dev);
                if ($response_brand['status'] == true) {
                    $columns = ['otp', 'updated_date'];
                    $values = [$otp, getCurrentDatetime('Y-m-d H:i:s')];
                    $condition = "id = :id";
                    $whereParams = [':id' => $response_brand['response'][0]['id']];

                    CrudService::update($db_prefix . 'device', $columns, $values, $condition, $whereParams);

                    echo json_encode(['status' => 'true', 'otp' => $otp, 'csrf_token' => $new_csrf_token]);
                } else {
                    $device_id = generateItemID();

                    $columns = ['d_id', 'device_id', 'otp', 'created_date', 'updated_date'];
                    $values = [$op_admin, $device_id, $otp, getCurrentDatetime('Y-m-d H:i:s'), getCurrentDatetime('Y-m-d H:i:s')];

                    CrudService::insert($db_prefix . 'device', $columns, $values);

                    echo json_encode(['status' => 'true', 'otp' => $otp, 'csrf_token' => $new_csrf_token]);
                }
            } else {
                echo json_encode(['status' => 'false', 'title' => 'Request Failed', 'message' => 'Invalid request', 'csrf_token' => $new_csrf_token]);
            }
        }

        // ── New Secure Pairing System ────────────────────────────────────

        if ($action == "device-pair-generate") {
            if ($ctx->isLoggedIn) {
                if (PermissionGuard::denyUnlessCanAccess($ctx, 'device')) { return; }
                if (PermissionGuard::denyUnlessHas($ctx, 'device', 'connect')) { return; }

                $brandId = (int) ($global_response_brand['response'][0]['brand_id'] ?? 0);
                $adminId = (int) ($ctx->user['a_id'] ?? $ctx->user['id'] ?? 0);

                $pairingService = new DevicePairingService();
                $result = $pairingService->generatePairingOtp($brandId, $adminId);

                if (isset($result['error'])) {
                    echo json_encode(['status' => 'false', 'title' => 'Rate Limited', 'message' => $result['error'], 'csrf_token' => $new_csrf_token]);
                } else {
                    // Generate server-side QR code
                    $siteUrl = $global_response_brand['response'][0]['site_url'] ?? ($_SERVER['REQUEST_SCHEME'] . '://' . $_SERVER['HTTP_HOST']);
                    $qrPayload = json_encode(['url' => rtrim($siteUrl, '/'), 'otp' => $result['otp']]);
                    $qrDataUri = '';
                    try {
                        $qrOptions = new \chillerlan\QRCode\QROptions([
                            'outputType'   => \chillerlan\QRCode\QRCode::OUTPUT_MARKUP_SVG,
                            'eccLevel'     => \chillerlan\QRCode\QRCode::ECC_M,
                            'svgUseCssProperties' => false,
                        ]);
                        $qr = new \chillerlan\QRCode\QRCode($qrOptions);
                        $svgRaw = $qr->render($qrPayload);
                        $qrDataUri = 'data:image/svg+xml;base64,' . base64_encode($svgRaw);
                    } catch (\Throwable $e) {
                        // QR generation failed — non-critical, OTP still works manually
                    }

                    echo json_encode([
                        'status'     => 'true',
                        'otp'        => $result['otp'],
                        'expires_in' => $result['expires_in'],
                        'qr_data'    => $qrDataUri,
                        'csrf_token' => $new_csrf_token,
                    ]);
                }
            } else {
                echo json_encode(['status' => 'false', 'title' => 'Request Failed', 'message' => 'Invalid request', 'csrf_token' => $new_csrf_token]);
            }
        }

        if ($action == "device-paired-list") {
            if ($ctx->isLoggedIn) {
                if (PermissionGuard::denyUnlessCanAccess($ctx, 'device')) { return; }

                $brandId = (int) ($global_response_brand['response'][0]['brand_id'] ?? 0);

                $repo = new PairedDeviceRepository();
                $data = $repo->listByBrand($brandId, 50, 0);

                $devices = array_map(function ($d) use ($global_response_brand) {
                    $tz = $global_response_brand['response'][0]['timezone'] ?? 'Asia/Dhaka';
                    return [
                        'device_uuid' => $d['device_uuid'],
                        'device_name' => $d['device_name'],
                        'platform'    => $d['platform'],
                        'app_version' => $d['app_version'],
                        'last_seen'   => $d['last_seen_at'] ? convertUTCtoUserTZ($d['last_seen_at'], $tz, "M d, Y h:i A") : 'Never',
                        'status'      => $d['revoked_at'] ? 'revoked' : 'active',
                        'created_at'  => convertUTCtoUserTZ($d['created_at'], $tz, "M d, Y h:i A"),
                    ];
                }, $data['devices']);

                echo json_encode(['status' => 'true', 'response' => $devices, 'total' => $data['total'], 'csrf_token' => $new_csrf_token]);
            } else {
                echo json_encode(['status' => 'false', 'title' => 'Request Failed', 'message' => 'Invalid request', 'csrf_token' => $new_csrf_token]);
            }
        }

        if ($action == "device-revoke") {
            if ($ctx->isLoggedIn) {
                if (PermissionGuard::denyUnlessCanAccess($ctx, 'device')) { return; }
                if (PermissionGuard::denyUnlessHas($ctx, 'device', 'delete')) { return; }

                $deviceUuid = $request->post('device_uuid', '');
                if ($deviceUuid === '') {
                    echo json_encode(['status' => 'false', 'title' => 'Invalid Request', 'message' => 'Device UUID is required.', 'csrf_token' => $new_csrf_token]);
                    return;
                }

                $repo = new PairedDeviceRepository();
                $repo->revoke($deviceUuid);

                echo json_encode(['status' => 'true', 'title' => 'Device Revoked', 'message' => 'The device has been revoked and will no longer be able to connect.', 'csrf_token' => $new_csrf_token]);
            } else {
                echo json_encode(['status' => 'false', 'title' => 'Request Failed', 'message' => 'Invalid request', 'csrf_token' => $new_csrf_token]);
            }
        }

    }
}
