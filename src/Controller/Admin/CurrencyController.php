<?php
declare(strict_types=1);

namespace OwnPay\Controller\Admin;

use OwnPay\Http\RequestContext;
use OwnPay\Service\System\CrudService;
use OwnPay\Service\Auth\PermissionGuard;

class CurrencyController
{
    public static function handle(string $action, ?RequestContext $ctx = null): void
    {
        $ctx ??= $GLOBALS['requestContext'] ?? throw new \RuntimeException('RequestContext not available');
        $global_user_login = $ctx->isLoggedIn;
        $global_response_permission = $ctx->permissionResponse;
        $global_user_response = $ctx->userResponse;
        $new_csrf_token = $ctx->csrfToken;
        $db_prefix = $ctx->dbPrefix;
        $global_response_brand = $ctx->brandResponse;
        $global_brand_currency_code = $ctx->currencyCode;

        $request = \OwnPay\Http\Request::createFromGlobals();

        // Inline extracted code from adapter.php
        if ($action == "currency-list") {
            if ($global_user_login == true) {
                if (PermissionGuard::denyUnlessCanAccess($ctx, 'brand_settings')) { return; }

                if (PermissionGuard::denyUnlessHas($ctx, 'currency_settings', 'view')) { return; }

                $search_input = $request->post('search_input', '');
                $show_limit = $request->post('show_limit', '5');


                $pag = \OwnPay\Service\System\PaginationService::resolve($request->post('page', '1'), $request->post('show_limit'));
                $page = $pag['page'];
                $show_limit = $pag['perPage'];
                $offset = $pag['offset'];

                $sql_query = '';
                $params_curr_list = [':brand_id' => $global_response_brand['response'][0]['brand_id'], ':global_code' => $global_brand_currency_code];

                if ($search_input !== '') {
                    $sql_query .= " AND ( code LIKE :search OR symbol LIKE :search )";
                    $params_curr_list[':search'] = "%$search_input%";
                }

                $sql_limit = '';
                if ($pag['isAll']) {

                } else {
                    $sql_limit = " LIMIT $offset, $show_limit";
                }

                $response_result = CrudService::select($db_prefix . 'currency', ' WHERE brand_id = :brand_id ' . $sql_query . ' ORDER BY (code = :global_code) DESC, id ASC ' . $sql_limit, '* FROM', $params_curr_list);
                if ($response_result['status'] == true) {
                    $response = [];

                    foreach ($response_result['response'] as $row) {
                        if ($global_brand_currency_code == $row['code']) {
                            $rate = '1.00 ' . $global_brand_currency_code . ' = 1.00 ' . $row['code'];
                        } else {
                            $rate = '1.00 ' . $row['code'] . ' = ' . money_round($row['rate'], 4) . ' ' . $global_brand_currency_code;
                        }

                        if ($global_brand_currency_code == $row['code']) {
                            $default = 'true';
                        } else {
                            $default = 'false';
                        }

                        $response[] = [
                            "default" => $default,
                            "id" => $row['id'],
                            "code" => $row['code'],
                            "symbol" => $row['symbol'],
                            "rate" => $rate,
                            "created_date" => convertUTCtoUserTZ($row['created_date'], ($global_response_brand['response'][0]['timezone'] === null || $global_response_brand['response'][0]['timezone'] === '') ? 'Asia/Dhaka' : $global_response_brand['response'][0]['timezone'], "M d, Y h:i A"),
                            "updated_date" => convertUTCtoUserTZ($row['updated_date'], ($global_response_brand['response'][0]['timezone'] === null || $global_response_brand['response'][0]['timezone'] === '') ? 'Asia/Dhaka' : $global_response_brand['response'][0]['timezone'], "M d, Y h:i A")
                        ];
                    }

                    $count_data = CrudService::select($db_prefix . 'currency', ' WHERE brand_id = :brand_id ' . $sql_query, '* FROM', $params_curr_list);

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

        if ($action == "currency-edit") {
            if ($global_user_login == true) {
                if (PermissionGuard::denyUnlessCanAccess($ctx, 'brand_settings')) { return; }

                if (PermissionGuard::denyUnlessHas($ctx, 'currency_settings', 'edit')) { return; }

                $currency_id = $request->post('currency_id', '');
                $currency_symbol = $request->post('currency_symbol', '');
                $currency_rate = $request->post('currency_rate', '');

                if ($currency_id == "" || $currency_symbol == "" || $currency_rate == "") {
                    echo json_encode(['status' => "false", 'title' => 'Incomplete Information', 'message' => 'Please fill in all required fields before proceeding.', 'csrf_token' => $new_csrf_token]);
                } else {
                    $response = CrudService::select($db_prefix . 'currency', 'WHERE brand_id = :brand_id AND id = :id', '* FROM', [':brand_id' => $global_response_brand['response'][0]['brand_id'], ':id' => $currency_id]);
                    if ($response['status'] == true) {
                        $columns = ['symbol', 'rate', 'updated_date'];
                        $values = [$currency_symbol, money_sanitize($currency_rate), getCurrentDatetime('Y-m-d H:i:s')];
                        $condition = "id = :id";
                        $whereParams = [':id' => $currency_id];

                        CrudService::update($db_prefix . 'currency', $columns, $values, $condition, $whereParams);

                        echo json_encode(['status' => 'true', 'title' => 'Currency Updated', 'message' => 'The currency has been updated successfully.', 'csrf_token' => $new_csrf_token]);

                    } else {
                        echo json_encode(['status' => 'false', 'title' => 'Request Failed', 'message' => 'Invalid Currency ID', 'csrf_token' => $new_csrf_token]);
                    }
                }
            } else {
                echo json_encode(['status' => 'false', 'title' => 'Request Failed', 'message' => 'Invalid request', 'csrf_token' => $new_csrf_token]);
            }
        }

        if ($action == "currency-info-byID") {
            if ($global_user_login == true) {
                if (PermissionGuard::denyUnlessCanAccess($ctx, 'brand_settings')) { return; }

                if (PermissionGuard::denyUnlessHas($ctx, 'currency_settings', 'edit')) { return; }

                $ItemID = $request->post('ItemID', '');

                $response_brand = CrudService::select($db_prefix . 'currency', 'WHERE id = :ItemID AND brand_id = :brand_id', '* FROM', [':ItemID' => $ItemID, ':brand_id' => $global_response_brand['response'][0]['brand_id']]);
                if ($response_brand['status'] == true) {
                    echo json_encode(['status' => 'true', 'code' => $response_brand['response'][0]['code'], 'symbol' => $response_brand['response'][0]['symbol'], 'rate' => money_sanitize($response_brand['response'][0]['rate']), 'csrf_token' => $new_csrf_token]);
                } else {
                    echo json_encode(['status' => 'false', 'title' => 'Request Failed', 'message' => 'Invalid request', 'csrf_token' => $new_csrf_token]);
                }
            } else {
                echo json_encode(['status' => 'false', 'title' => 'Request Failed', 'message' => 'Invalid request', 'csrf_token' => $new_csrf_token]);
            }
        }

        if ($action == "currency-bulkImport") {
            if ($global_user_login == true) {
                if (PermissionGuard::denyUnlessCanAccess($ctx, 'brand_settings')) { return; }

                if (PermissionGuard::denyUnlessHas($ctx, 'currency_settings', 'import')) { return; }

                $url = "https://gist.githubusercontent.com/ksafranski/2973986/raw/";

                // Initialize cURL
                $ch = curl_init($url);
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => 10,
                    CURLOPT_SSL_VERIFYPEER => true, // SEC-04 fix: enforce TLS certificate verification
                    CURLOPT_SSL_VERIFYHOST => 2,
                ]);

                // Execute cURL
                $response = curl_exec($ch);


                // Decode JSON into associative array
                $currencies = json_decode($response, true);

                // Check if JSON decoded successfully
                if ($currencies === null) {
                    echo json_encode(['status' => 'false', 'title' => 'Request Failed', 'message' => 'Invalid request', 'csrf_token' => $new_csrf_token]);
                    exit();
                }

                // Loop through each currency
                foreach ($currencies as $code => $details) {
                    $response = CrudService::select($db_prefix . 'currency', 'WHERE brand_id = :brand_id AND code = :code', '* FROM', [':brand_id' => $global_response_brand['response'][0]['brand_id'], ':code' => $code]);
                    if ($response['status'] == false) {
                        $columns = ['brand_id', 'code', 'symbol', 'rate', 'created_date', 'updated_date'];
                        $values = [$global_response_brand['response'][0]['brand_id'], $code, $details['symbol_native'], '0', getCurrentDatetime('Y-m-d H:i:s'), getCurrentDatetime('Y-m-d H:i:s')];

                        CrudService::insert($db_prefix . 'currency', $columns, $values);
                    }
                }

                echo json_encode(['status' => 'true', 'title' => 'Currencies Imported', 'message' => 'All currency data has been imported successfully.', 'csrf_token' => $new_csrf_token]);
            } else {
                echo json_encode(['status' => 'false', 'title' => 'Request Failed', 'message' => 'Invalid request', 'csrf_token' => $new_csrf_token]);
            }
        }

        if ($action == "currency-rateSync") {
            if ($global_user_login == true) {
                if (PermissionGuard::denyUnlessCanAccess($ctx, 'brand_settings')) { return; }

                if (PermissionGuard::denyUnlessHas($ctx, 'currency_settings', 'sync_rate')) { return; }

                $ItemID = $request->post('ItemID', '');

                $response_brand = CrudService::select($db_prefix . 'currency', 'WHERE id = :ItemID AND brand_id = :brand_id', '* FROM', [':ItemID' => $ItemID, ':brand_id' => $global_response_brand['response'][0]['brand_id']]);
                if ($response_brand['status'] == true) {


                    $url = 'https://cdn.jsdelivr.net/npm/@fawazahmed0/currency-api@latest/v1/currencies/' . strtolower($global_brand_currency_code) . '.json';

                    $ch = curl_init($url);
                    curl_setopt_array($ch, [
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_TIMEOUT => 10,
                        CURLOPT_SSL_VERIFYPEER => true, // SEC-04 fix
                        CURLOPT_SSL_VERIFYHOST => 2,
                    ]);

                    $response = curl_exec($ch);


                    $data = json_decode($response, true);

                    if (!isset($data[strtolower($global_brand_currency_code)])) {
                        echo json_encode(['status' => 'false', 'title' => 'Request Failed', 'message' => 'Invalid default currency', 'csrf_token' => $new_csrf_token]);
                        exit();
                    }

                    $rates = $data[strtolower($global_brand_currency_code)];


                    foreach ($rates as $currency => $rate) {

                        if ($currency === strtolower($global_brand_currency_code)) {
                            continue;
                        }

                        if ($rate <= 0) {
                            continue;
                        }

                        if (strtolower($response_brand['response'][0]['code']) == $currency) {
                            $columns = ['rate', 'updated_date'];
                            $values = [money_div(1, money_sanitize(sprintf('%.14f', $rate))), getCurrentDatetime('Y-m-d H:i:s')];

                            $condition = 'brand_id = :brand_id AND id = :ItemID';
                            $whereParams = [':brand_id' => $global_response_brand['response'][0]['brand_id'], ':ItemID' => $ItemID];

                            CrudService::update($db_prefix . 'currency', $columns, $values, $condition, $whereParams);

                            break;
                        }
                    }
                }

                echo json_encode(['status' => 'true', 'title' => 'Currency Rate Updated', 'message' => 'The selected currency rate have been updated successfully.', 'csrf_token' => $new_csrf_token]);
            } else {
                echo json_encode(['status' => 'false', 'title' => 'Request Failed', 'message' => 'Invalid request', 'csrf_token' => $new_csrf_token]);
            }
        }

        if ($action == "currency-bulk-rateSync") {
            if ($global_user_login == true) {
                if (PermissionGuard::denyUnlessCanAccess($ctx, 'brand_settings')) { return; }

                if (PermissionGuard::denyUnlessHas($ctx, 'currency_settings', 'sync_rate')) { return; }

                $url = 'https://cdn.jsdelivr.net/npm/@fawazahmed0/currency-api@latest/v1/currencies/' . strtolower($global_brand_currency_code) . '.json';

                $ch = curl_init($url);
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => 10,
                    CURLOPT_SSL_VERIFYPEER => true, // SEC-04 fix
                    CURLOPT_SSL_VERIFYHOST => 2,
                ]);

                $response = curl_exec($ch);


                $data = json_decode($response, true);

                if (!isset($data[strtolower($global_brand_currency_code)])) {
                    echo json_encode(['status' => 'false', 'title' => 'Request Failed', 'message' => 'Invalid default currency', 'csrf_token' => $new_csrf_token]);
                    exit();
                }

                $rates = $data[strtolower($global_brand_currency_code)];

                foreach ($rates as $currency => $rate) {

                    if ($currency === strtolower($global_brand_currency_code)) {
                        continue;
                    }

                    if ($rate <= 0) {
                        continue;
                    }

                    $columns = ['rate', 'updated_date'];
                    $values = [money_div(1, money_sanitize(sprintf('%.14f', $rate))), getCurrentDatetime('Y-m-d H:i:s')];

                    $condition = 'brand_id = :brand_id AND code = :currency';
                    $whereParams_bulk = [':brand_id' => $global_response_brand['response'][0]['brand_id'], ':currency' => $currency];

                    CrudService::update($db_prefix . 'currency', $columns, $values, $condition, $whereParams_bulk);
                }

                echo json_encode(['status' => 'true', 'title' => 'Currencies Rate Updated', 'message' => 'The selected currencies rate have been updated successfully.', 'csrf_token' => $new_csrf_token]);
            } else {
                echo json_encode(['status' => 'false', 'title' => 'Request Failed', 'message' => 'Invalid request', 'csrf_token' => $new_csrf_token]);
            }
        }

    }
}
