<?php
declare(strict_types=1);

namespace AnirbanPay\Controller;

use AnirbanPay\Http\RequestContext;

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

        $request = \AnirbanPay\Http\Request::createFromGlobals();

        // Inline extracted code from adapter.php
        if ($action == "currency-list") {
            if ($global_user_login == true) {
                if (!canAccessPage(json_decode($global_response_permission['response'][0]['permission'], true), 'brand_settings', $global_user_response['response'][0]['role'])) {
                    echo json_encode(['status' => 'false', 'title' => 'Access denied', 'message' => 'You need permission to perform this action. Please contact the admin.', 'csrf_token' => $new_csrf_token]);
                    exit();
                }

                if (!hasPermission(json_decode($global_response_permission['response'][0]['permission'], true), 'currency_settings', 'view', $global_user_response['response'][0]['role'])) {
                    echo json_encode(['status' => 'false', 'title' => 'Access denied', 'message' => 'You need permission to perform this action. Please contact the admin.', 'csrf_token' => $new_csrf_token]);
                    exit();
                }

                $search_input = $request->post('search_input', '');
                $show_limit = $request->post('show_limit', '5');


                $page = max(1, (int) $request->post('page', '1'));
                $show_limit = ($request->post('show_limit') == '') ? 999999 : (int) $request->post('show_limit');
                $offset = ($page - 1) * $show_limit;

                $sql_query = '';
                $params_curr_list = [':brand_id' => $global_response_brand['response'][0]['brand_id'], ':global_code' => $global_brand_currency_code];

                if ($search_input !== '') {
                    $sql_query .= " AND ( code LIKE :search OR symbol LIKE :search )";
                    $params_curr_list[':search'] = "%$search_input%";
                }

                $sql_limit = '';
                if ($show_limit == 'all') {

                } else {
                    $sql_limit = " LIMIT $offset, $show_limit";
                }

                $response_result = json_decode(getData($db_prefix . 'currency', ' WHERE brand_id = :brand_id ' . $sql_query . ' ORDER BY (code = :global_code) DESC, id ASC ' . $sql_limit, '* FROM', $params_curr_list), true);
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
                            "created_date" => convertUTCtoUserTZ($row['created_date'], ($global_response_brand['response'][0]['timezone'] === '--' || $global_response_brand['response'][0]['timezone'] === '') ? 'Asia/Dhaka' : $global_response_brand['response'][0]['timezone'], "M d, Y h:i A"),
                            "updated_date" => convertUTCtoUserTZ($row['updated_date'], ($global_response_brand['response'][0]['timezone'] === '--' || $global_response_brand['response'][0]['timezone'] === '') ? 'Asia/Dhaka' : $global_response_brand['response'][0]['timezone'], "M d, Y h:i A")
                        ];
                    }

                    $count_data = json_decode(getData($db_prefix . 'currency', ' WHERE brand_id = :brand_id ' . $sql_query, '* FROM', $params_curr_list), true);

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

        if ($action == "currency-edit") {
            if ($global_user_login == true) {
                if (!canAccessPage(json_decode($global_response_permission['response'][0]['permission'], true), 'brand_settings', $global_user_response['response'][0]['role'])) {
                    echo json_encode(['status' => 'false', 'title' => 'Access denied', 'message' => 'You need permission to perform this action. Please contact the admin.', 'csrf_token' => $new_csrf_token]);
                    exit();
                }

                if (!hasPermission(json_decode($global_response_permission['response'][0]['permission'], true), 'currency_settings', 'edit', $global_user_response['response'][0]['role'])) {
                    echo json_encode(['status' => 'false', 'title' => 'Access denied', 'message' => 'You need permission to perform this action. Please contact the admin.', 'csrf_token' => $new_csrf_token]);
                    exit();
                }

                $currency_id = $request->post('currency_id', '');
                $currency_symbol = $request->post('currency_symbol', '');
                $currency_rate = $request->post('currency_rate', '');

                if ($currency_id == "" || $currency_symbol == "" || $currency_rate == "") {
                    echo json_encode(['status' => "false", 'title' => 'Incomplete Information', 'message' => 'Please fill in all required fields before proceeding.', 'csrf_token' => $new_csrf_token]);
                } else {
                    $response = json_decode(getData($db_prefix . 'currency', 'WHERE brand_id = :brand_id AND id = :id', '* FROM', [':brand_id' => $global_response_brand['response'][0]['brand_id'], ':id' => $currency_id]), true);
                    if ($response['status'] == true) {
                        $columns = ['symbol', 'rate', 'updated_date'];
                        $values = [$currency_symbol, money_sanitize($currency_rate), getCurrentDatetime('Y-m-d H:i:s')];
                        $condition = "id = :id";
                        $whereParams = [':id' => $currency_id];

                        updateData($db_prefix . 'currency', $columns, $values, $condition, $whereParams);

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
                if (!canAccessPage(json_decode($global_response_permission['response'][0]['permission'], true), 'brand_settings', $global_user_response['response'][0]['role'])) {
                    echo json_encode(['status' => 'false', 'title' => 'Access denied', 'message' => 'You need permission to perform this action. Please contact the admin.', 'csrf_token' => $new_csrf_token]);
                    exit();
                }

                if (!hasPermission(json_decode($global_response_permission['response'][0]['permission'], true), 'currency_settings', 'edit', $global_user_response['response'][0]['role'])) {
                    echo json_encode(['status' => 'false', 'title' => 'Access denied', 'message' => 'You need permission to perform this action. Please contact the admin.', 'csrf_token' => $new_csrf_token]);
                    exit();
                }

                $ItemID = $request->post('ItemID', '');

                $response_brand = json_decode(getData($db_prefix . 'currency', 'WHERE id = :ItemID AND brand_id = :brand_id', '* FROM', [':ItemID' => $ItemID, ':brand_id' => $global_response_brand['response'][0]['brand_id']]), true);
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
                if (!canAccessPage(json_decode($global_response_permission['response'][0]['permission'], true), 'brand_settings', $global_user_response['response'][0]['role'])) {
                    echo json_encode(['status' => 'false', 'title' => 'Access denied', 'message' => 'You need permission to perform this action. Please contact the admin.', 'csrf_token' => $new_csrf_token]);
                    exit();
                }

                if (!hasPermission(json_decode($global_response_permission['response'][0]['permission'], true), 'currency_settings', 'import', $global_user_response['response'][0]['role'])) {
                    echo json_encode(['status' => 'false', 'title' => 'Access denied', 'message' => 'You need permission to perform this action. Please contact the admin.', 'csrf_token' => $new_csrf_token]);
                    exit();
                }

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
                    $response = json_decode(getData($db_prefix . 'currency', 'WHERE brand_id = :brand_id AND code = :code', '* FROM', [':brand_id' => $global_response_brand['response'][0]['brand_id'], ':code' => $code]), true);
                    if ($response['status'] == false) {
                        $columns = ['brand_id', 'code', 'symbol', 'rate', 'created_date', 'updated_date'];
                        $values = [$global_response_brand['response'][0]['brand_id'], $code, $details['symbol_native'], '0', getCurrentDatetime('Y-m-d H:i:s'), getCurrentDatetime('Y-m-d H:i:s')];

                        insertData($db_prefix . 'currency', $columns, $values);
                    }
                }

                echo json_encode(['status' => 'true', 'title' => 'Currencies Imported', 'message' => 'All currency data has been imported successfully.', 'csrf_token' => $new_csrf_token]);
            } else {
                echo json_encode(['status' => 'false', 'title' => 'Request Failed', 'message' => 'Invalid request', 'csrf_token' => $new_csrf_token]);
            }
        }

        if ($action == "currency-rateSync") {
            if ($global_user_login == true) {
                if (!canAccessPage(json_decode($global_response_permission['response'][0]['permission'], true), 'brand_settings', $global_user_response['response'][0]['role'])) {
                    echo json_encode(['status' => 'false', 'title' => 'Access denied', 'message' => 'You need permission to perform this action. Please contact the admin.', 'csrf_token' => $new_csrf_token]);
                    exit();
                }

                if (!hasPermission(json_decode($global_response_permission['response'][0]['permission'], true), 'currency_settings', 'sync_rate', $global_user_response['response'][0]['role'])) {
                    echo json_encode(['status' => 'false', 'title' => 'Access denied', 'message' => 'You need permission to perform this action. Please contact the admin.', 'csrf_token' => $new_csrf_token]);
                    exit();
                }

                $ItemID = $request->post('ItemID', '');

                $response_brand = json_decode(getData($db_prefix . 'currency', 'WHERE id = :ItemID AND brand_id = :brand_id', '* FROM', [':ItemID' => $ItemID, ':brand_id' => $global_response_brand['response'][0]['brand_id']]), true);
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

                            updateData($db_prefix . 'currency', $columns, $values, $condition, $whereParams);

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
                if (!canAccessPage(json_decode($global_response_permission['response'][0]['permission'], true), 'brand_settings', $global_user_response['response'][0]['role'])) {
                    echo json_encode(['status' => 'false', 'title' => 'Access denied', 'message' => 'You need permission to perform this action. Please contact the admin.', 'csrf_token' => $new_csrf_token]);
                    exit();
                }

                if (!hasPermission(json_decode($global_response_permission['response'][0]['permission'], true), 'currency_settings', 'sync_rate', $global_user_response['response'][0]['role'])) {
                    echo json_encode(['status' => 'false', 'title' => 'Access denied', 'message' => 'You need permission to perform this action. Please contact the admin.', 'csrf_token' => $new_csrf_token]);
                    exit();
                }

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

                    updateData($db_prefix . 'currency', $columns, $values, $condition, $whereParams_bulk);
                }

                echo json_encode(['status' => 'true', 'title' => 'Currencies Rate Updated', 'message' => 'The selected currencies rate have been updated successfully.', 'csrf_token' => $new_csrf_token]);
            } else {
                echo json_encode(['status' => 'false', 'title' => 'Request Failed', 'message' => 'Invalid request', 'csrf_token' => $new_csrf_token]);
            }
        }

    }
}
