<?php
declare(strict_types=1);
bcscale(8);

define('ANIRBANPAY_INIT', true);

if (date_default_timezone_get() !== 'UTC') {
    date_default_timezone_set('UTC');
}

if (file_exists(__DIR__ . '/app/core/functions.php')) {
    if (isset($ap_functions_loaded)) {

    } else {
        require __DIR__ . '/app/core/functions.php';

        if (isset($ap_functions_loaded)) {

        } else {
            if (file_exists(__DIR__ . '/errors/404.php')) {
                http_response_code(404);
                require __DIR__ . '/errors/404.php';
                exit();
            } else {
                http_response_code(403);
                exit('Direct access not allowed');
            }
        }
    }
} else {
    if (file_exists(__DIR__ . '/errors/404.php')) {
        http_response_code(404);
        require __DIR__ . '/errors/404.php';
        exit();
    } else {
        http_response_code(403);
        exit('Direct access not allowed');
    }
}

/*
|--------------------------------------------------------------------------
| SOA Service Layer Bootstrap (Phase 2.0)
|--------------------------------------------------------------------------
| Loads the Composer autoloader and initializes the SOA Database singleton.
| This makes all AnirbanPay\Service\* and AnirbanPay\Repository\* classes
| available to the legacy runtime without breaking the existing flow.
*/
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';

    try {
        \AnirbanPay\Bootstrap::init();
    } catch (\Throwable $e) {
        // SOA bootstrap failure must not crash the legacy app.
        // Log the error and continue with legacy-only mode.
        error_log('[AnirbanPay] SOA Bootstrap failed: ' . $e->getMessage());
    }
}

if (file_exists(__DIR__ . '/app/core/adapter.php')) {
    if (isset($ap_adapter_loaded)) {

    } else {
        require __DIR__ . '/app/core/adapter.php';

        if (isset($ap_adapter_loaded)) {

        } else {
            if (file_exists(__DIR__ . '/errors/404.php')) {
                http_response_code(404);
                require __DIR__ . '/errors/404.php';
                exit();
            } else {
                http_response_code(403);
                exit('Direct access not allowed');
            }
        }
    }
} else {
    if (file_exists(__DIR__ . '/errors/404.php')) {
        http_response_code(404);
        require __DIR__ . '/errors/404.php';
        exit();
    } else {
        http_response_code(403);
        exit('Direct access not allowed');
    }

}

/*
|--------------------------------------------------------------------------
| Basic Security Headers
|--------------------------------------------------------------------------
*/
header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('X-XSS-Protection: 1; mode=block'); // SEC-12 fix
header('Permissions-Policy: camera=(), microphone=(), geolocation=()'); // SEC-12 fix
// Generate a cryptographic nonce per request (kept for backwards compat with nonce="" attributes in existing script tags)
$csp_nonce = base64_encode(random_bytes(16));
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'nonce-{$csp_nonce}' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com; style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://fonts.googleapis.com https://cdn.tailwindcss.com; font-src 'self' data: https://fonts.gstatic.com; img-src 'self' data: https:; connect-src 'self'; frame-ancestors 'self';");
if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains'); // SEC-12 fix
}

/*
|--------------------------------------------------------------------------
| Get Requested Page
|--------------------------------------------------------------------------
*/
$page = $_GET['page'] ?? '';
$page = trim($page, '/');

/*
|--------------------------------------------------------------------------
| SECURITY: Prevent traversal & illegal chars
|--------------------------------------------------------------------------
*/
if (strpos($page, '..') !== false || !preg_match('/^[a-zA-Z0-9\/_-]+$/', $page)) {
    $page = 'homepageRedirect';
}

/*
|--------------------------------------------------------------------------
| Explode path for dynamic values
|--------------------------------------------------------------------------
*/
$segments = explode('/', $page);

/*
|--------------------------------------------------------------------------
| Example:
| /payment/2134124123
|--------------------------------------------------------------------------
*/
$route = $segments[0] ?? '';
$param1 = $segments[1] ?? null;

/*
|--------------------------------------------------------------------------
| Router
|--------------------------------------------------------------------------
*/

if (!file_exists(__DIR__ . '/.maintenance')) {
    if (file_exists(__DIR__ . '/ap-config.php')) {
        if (isset($requriemntnoneedchecked) && $requriemntnoneedchecked === true) {
            switch ($route) {
                case '404':
                    if (file_exists(__DIR__ . '/errors/404.php')) {
                        http_response_code(404);
                        require __DIR__ . '/errors/404.php';
                    } else {
                        http_response_code(403);
                        exit('Direct access not allowed');
                    }
                    break;

                case 'health':
                    header('Content-Type: application/json');
                    try {
                        $healthController = new \AnirbanPay\Http\Controller\HealthController();
                        $healthController->index();
                    } catch (\Throwable $e) {
                        http_response_code(503);
                        echo json_encode(['status' => 'error', 'message' => 'Health check failed.']);
                    }
                    exit;

                case 'login':
                case 'forgot':
                case '2fa':
                    if (file_exists(__DIR__ . '/app/admin/' . $route . '.php')) {
                        require __DIR__ . '/app/admin/' . $route . '.php';
                    } else {
                        if (file_exists(__DIR__ . '/errors/404.php')) {
                            http_response_code(404);
                            require __DIR__ . '/errors/404.php';
                        } else {
                            http_response_code(403);
                            exit('Direct access not allowed');
                        }
                    }
                    break;

                case 'ipn':
                    \AnirbanPay\Controller\Frontend\IpnController::handle(compact('db_prefix', 'site_url', 'path_payment', 'path_invoice', 'path_payment_link', 'route', 'segments', 'param1', 'param2'));
                    break;

                case 'api':
                    \AnirbanPay\Controller\Frontend\ApiController::handle(compact('db_prefix', 'site_url', 'path_payment', 'path_invoice', 'path_payment_link', 'route', 'segments', 'param1', 'param2'));
                    break;

                case $path_payment:
                    \AnirbanPay\Controller\Frontend\LegacyCheckoutController::handle(compact('db_prefix', 'site_url', 'path_payment', 'path_invoice', 'path_payment_link', 'route', 'segments', 'param1', 'param2'));
                    break;

                case $path_invoice:
                    \AnirbanPay\Controller\Frontend\InvoiceCheckoutController::handle(compact('db_prefix', 'site_url', 'path_payment', 'path_invoice', 'path_payment_link', 'route', 'segments', 'param1', 'param2'));
                    break;

                case $path_payment_link:
                    \AnirbanPay\Controller\Frontend\PaymentLinkCheckoutController::handle(compact('db_prefix', 'site_url', 'path_payment', 'path_invoice', 'path_payment_link', 'route', 'segments', 'param1', 'param2'));
                    break;

                case $path_admin:
                    $_GET['page_name'] = $param1;

                    if (file_exists(__DIR__ . '/app/admin/index.php')) {
                        require __DIR__ . '/app/admin/index.php';
                    } else {
                        if (file_exists(__DIR__ . '/errors/404.php')) {
                            http_response_code(404);
                            require __DIR__ . '/errors/404.php';
                        } else {
                            http_response_code(403);
                            exit('Direct access not allowed');
                        }
                    }
                    break;

                case $path_cron:
                    if ($param1 == "") {
                        if (file_exists(__DIR__ . '/errors/404.php')) {
                            http_response_code(404);
                            require __DIR__ . '/errors/404.php';
                        } else {
                            http_response_code(403);
                            exit('Direct access not allowed');
                        }
                    } else {
                        if (escape_string($param1) == get_env('cron-job')) {
                            $lockFile = __DIR__ . '/media/storage/cron.lock';
                            $maxLockTime = 60 * 10;

                            header('Content-Type: application/json');

                            echo json_encode(['status' => 'true', "message" => "Cron run executed."]);

                            if (file_exists($lockFile) && (time() - filemtime($lockFile)) < $maxLockTime) {
                                exit;
                            }

                            file_put_contents($lockFile, time());

                            set_env('last-cron-invocation', getCurrentDatetime('Y-m-d H:i:s'));

                            //auto system update
                            //auto system update
                            $automatic_update = get_env('system-settings-automatic_update') === '--' || (get_env('system-settings-automatic_update') === '') ? '' : get_env('system-settings-automatic_update');

                            if ($automatic_update == "yes") {
                                if (strtotime(getCurrentDatetime('Y-m-d H:i:s')) - strtotime(get_env('last-auto-update-check') ?: getCurrentDatetime('Y-m-d H:i:s')) >= 10 * 3600) {
                                    set_env('last-auto-update-check', getCurrentDatetime('Y-m-d H:i:s'));

                                    $manifest = json_decode(\AnirbanPay\Service\HttpClient::get('https://updates.AnirbanPay.com/manifest.json') ?? '', true);

                                    $current_code = $AnirbanPay_current_version['version_code'];
                                    $current_name = $AnirbanPay_current_version['version_name'];

                                    if (get_env('system-settings-update_channel') == "" || get_env('system-settings-update_channel') == "--" || get_env('system-settings-update_channel') == "stable") {
                                        $update_channel = 'stable';
                                    } else {
                                        $update_channel = 'beta';
                                    }

                                    $channel_data = $manifest['channels'][$update_channel] ?? null;

                                    $update_available = false;
                                    $latest_name = null;
                                    $latest_code = null;

                                    if ($channel_data) {
                                        $latest_name = $channel_data['latest_version_name'];
                                        $latest_code = $channel_data['latest_version_code'];

                                        if (version_compare($latest_code, $current_code, '>')) {
                                            $update_available = true;
                                        }
                                    }

                                    if ($update_available == true) {
                                        do_action('system.update.available', [
                                            'current_version_name' => $current_name,
                                            'current_version_code' => $current_code,
                                            'latest_version_name' => $latest_name,
                                            'latest_version_code' => $latest_code,
                                        ]);

                                        set_env('last-update-version-name', $latest_name);
                                        set_env('last-update-version', $latest_code);
                                    } else {
                                        set_env('last-update-version-name', $current_name);
                                        set_env('last-update-version', $current_code);
                                    }
                                } else {
                                    if (get_env('last-auto-update-check') == "--" || get_env('last-auto-update-check') == "") {
                                        set_env('last-auto-update-check', getCurrentDatetime('Y-m-d H:i:s'));
                                    }
                                }
                            }
                            //auto system update
                            //auto system update


                            //verify pending against sms data
                            //verify pending against sms data
                            $response_pending_transaciton = json_decode(getData($db_prefix . 'transaction', 'WHERE status = :status AND sender_key NOT IN (:null_dash, :null_empty) ORDER BY 1 DESC', '* FROM', [':status' => 'pending', ':null_dash' => '--', ':null_empty' => '']), true);
                            $all_transactions = [];
                            foreach ($response_pending_transaciton['response'] as $row) {
                                $params = [':sender_key' => $row['sender_key'], ':type' => $row['sender_type'], ':trx_id' => $row['trx_id'], ':status' => 'approved'];

                                $response_pending_SMSTransaction = json_decode(getData($db_prefix . 'sms_data', 'WHERE sender_key = :sender_key AND type = :type AND trx_id = :trx_id AND status = :status', '* FROM', $params), true);
                                if ($response_pending_SMSTransaction['status'] == true) {

                                    $response_brand = json_decode(getData($db_prefix . 'brands', ' WHERE brand_id = :brand_id', '* FROM', [':brand_id' => $row['brand_id']]), true);
                                    if ($response_brand['status'] == true) {
                                        if (verifyPaymentTolerance($row['local_net_amount'], $response_pending_SMSTransaction['response'][0]['amount'], $response_brand['response'][0]['payment_tolerance'])) {
                                            $columns = ['status', 'updated_date'];
                                            $values = ['used', getCurrentDatetime('Y-m-d H:i:s')];
                                            $condition = 'id = :where_id';
                                            $whereParams = [':where_id' => $response_pending_SMSTransaction['response'][0]['id']];

                                            updateData($db_prefix . 'sms_data', $columns, $values, $condition, $whereParams);

                                            $columns = ['status', 'sender', 'trx_id', 'updated_date'];
                                            $values = ['completed', $response_pending_SMSTransaction['response'][0]['number'], $row['trx_id'], getCurrentDatetime('Y-m-d H:i:s')];
                                            $condition = 'id = :where_id';
                                            $whereParams = [':where_id' => $row['id']];

                                            updateData($db_prefix . 'transaction', $columns, $values, $condition, $whereParams);


                                            $metadata = json_decode($row['metadata'], true) ?: [];

                                            $response_gateway = json_decode(getData($db_prefix . 'gateways', ' WHERE brand_id = :brand_id AND gateway_id = :gateway_id', '* FROM', [':brand_id' => $response_brand['response'][0]['brand_id'], ':gateway_id' => $row['gateway_id']]), true);

                                            $gateway = $response_gateway['response'][0]['display'] ?? '';

                                            $customer_info = json_decode($row['customer_info'], true) ?: [];

                                            $net = money_sub(money_add($row['amount'], $row['processing_fee']), $row['discount_amount']);

                                            $all_transactions[] = [
                                                "ap_id" => $row['ref'],
                                                "full_name" => $customer_info['name'] ?? 'N/A',
                                                "email_address" => $customer_info['email'] ?? 'N/A',
                                                "mobile_number" => $customer_info['mobile'] ?? 'N/A',
                                                "gateway" => $gateway,
                                                "amount" => money_round($row['amount']),
                                                "fee" => money_round($row['processing_fee']),
                                                "discount_amount" => money_round($row['discount_amount']),
                                                "total" => money_round($net),
                                                "local_net_amount" => money_round($row['local_net_amount']),
                                                "currency" => $row['currency'],
                                                "local_currency" => $row['local_currency'],
                                                "metadata" => $metadata, // ← AS-IS
                                                "sender" => $response_pending_SMSTransaction['response'][0]['number'],
                                                "transaction_id" => $row['trx_id'],
                                                "status" => $row['status'],
                                                "date" => convertUTCtoUserTZ($row['created_date'], ($response_brand['response'][0]['timezone'] === '--' || $response_brand['response'][0]['timezone'] === '') ? 'Asia/Dhaka' : $response_brand['response'][0]['timezone'], "M d, Y h:i A")
                                            ];

                                            if ($row['webhook_url'] == "" || $row['webhook_url'] == "--") {

                                            } else {
                                                $ipnData = [
                                                    "ap_id" => $row['ref'],
                                                    "full_name" => $customer_info['name'] ?? 'N/A',
                                                    "email_address" => $customer_info['email'] ?? 'N/A',
                                                    "mobile_number" => $customer_info['mobile'] ?? 'N/A',
                                                    "gateway" => $gateway,
                                                    "amount" => money_round($row['amount']),
                                                    "fee" => money_round($row['processing_fee']),
                                                    "discount_amount" => money_round($row['discount_amount']),
                                                    "total" => money_round($net),
                                                    "local_net_amount" => money_round($row['local_net_amount']),
                                                    "currency" => $row['currency'],
                                                    "local_currency" => $row['local_currency'],
                                                    "metadata" => $metadata, // ← AS-IS
                                                    "sender" => $response_pending_SMSTransaction['response'][0]['number'],
                                                    "transaction_id" => $row['trx_id'],
                                                    "status" => $row['status'],
                                                    "date" => convertUTCtoUserTZ($row['created_date'], ($response_brand['response'][0]['timezone'] === '--' || $response_brand['response'][0]['timezone'] === '') ? 'Asia/Dhaka' : $response_brand['response'][0]['timezone'], "M d, Y h:i A")
                                                ];

                                                $payload = json_encode($ipnData, JSON_UNESCAPED_UNICODE);

                                                $columns = ['ref', 'brand_id', 'payload', 'url', 'created_date', 'updated_date'];
                                                $values = [$row['ref'], $row['brand_id'], $payload, $row['webhook_url'], getCurrentDatetime('Y-m-d H:i:s'), getCurrentDatetime('Y-m-d H:i:s')];

                                                insertData($db_prefix . 'webhook_log', $columns, $values);
                                            }
                                        }
                                    }
                                }
                            }

                            if (!empty($all_transactions)) {
                                do_action('transactions.updated', $all_transactions);
                            }
                            //verify pending against sms data
                            //verify pending against sms data


                            //auto update currency
                            //auto update currency
                            $response_currency_auto_update = json_decode(getData($db_prefix . 'brands', 'WHERE autoExchange = "enabled"'), true);

                            $multiHandle = curl_multi_init();
                            $curlHandles = [];
                            $brandMap = [];

                            foreach ($response_currency_auto_update['response'] as $row) {
                                if (strtotime(getCurrentDatetime('Y-m-d H:i:s')) - strtotime(get_env('last-auto-exchange', $row['brand_id']) ?: getCurrentDatetime('Y-m-d H:i:s')) >= 5 * 3600) {
                                    set_env('last-auto-exchange', getCurrentDatetime('Y-m-d H:i:s'), $row['brand_id']);

                                    $url = 'https://cdn.jsdelivr.net/npm/@fawazahmed0/currency-api@latest/v1/currencies/' . strtolower($row['currency_code']) . '.json';

                                    $ch = curl_init($url);
                                    curl_setopt_array($ch, [
                                        CURLOPT_RETURNTRANSFER => true,
                                        CURLOPT_TIMEOUT => 10,
                                        CURLOPT_SSL_VERIFYPEER => true, // SEC-04 fix
                                        CURLOPT_SSL_VERIFYHOST => 2
                                    ]);

                                    curl_multi_add_handle($multiHandle, $ch);
                                    $curlHandles[] = $ch;
                                    $brandMap[(int) $ch] = $row;
                                } else {
                                    if (get_env('last-auto-exchange', $row['brand_id']) == "--" || get_env('last-auto-exchange', $row['brand_id']) == "") {
                                        set_env('last-auto-exchange', getCurrentDatetime('Y-m-d H:i:s'), $row['brand_id']);
                                    }
                                }
                            }

                            $running = null;
                            do {
                                curl_multi_exec($multiHandle, $running);
                                curl_multi_select($multiHandle);
                            } while ($running > 0);

                            foreach ($curlHandles as $ch) {
                                $row = $brandMap[(int) $ch];
                                $response = curl_multi_getcontent($ch);
                                curl_multi_remove_handle($multiHandle, $ch);


                                if (!$response)
                                    continue;

                                $data = json_decode($response, true);
                                if (!isset($data[strtolower($row['currency_code'])]))
                                    continue;

                                $rates = $data[strtolower($row['currency_code'])];

                                foreach ($rates as $currency => $rate) {
                                    if ($currency === strtolower($row['currency_code']))
                                        continue;
                                    if ($rate <= 0)
                                        continue;

                                    $converted = number_format(1 / $rate, 4);
                                    $columns = ['rate', 'updated_date'];
                                    $values = [$converted, getCurrentDatetime('Y-m-d H:i:s')];
                                    $condition = 'brand_id = :where_brand_id AND code = :where_code';
                                    $whereParams = [':where_brand_id' => $row['brand_id'], ':where_code' => $currency];
                                    updateData($db_prefix . 'currency', $columns, $values, $condition, $whereParams);
                                }
                            }

                            curl_multi_close($multiHandle);
                            //auto update currency
                            //auto update currency


                            //balance verification
                            //balance verification
                            $response_balance_verification = json_decode(getData($db_prefix . 'balance_verification', 'WHERE status = "active"'), true);
                            foreach ($response_balance_verification['response'] as $row) {
                                reconcileByLongestChain($row['device_id'], $row['sender_key'], $row['type']);
                            }
                            //balance verification
                            //balance verification


                            //webhook pending
                            //webhook pending
                            $limit = get_env('geneal-application-settings-webhook_attempts_limit');
                            $limit = ($limit === '' || $limit === '--') ? 1 : (int) $limit;

                            $response = json_decode(getData($db_prefix . 'webhook_log', 'WHERE status = :status AND attempts < :limit ORDER BY id ASC LIMIT 15', '* FROM', [':status' => 'pending', ':limit' => $limit]), true);

                            $jobs = [];

                            foreach ($response['response'] as $row) {
                                updateData($db_prefix . 'webhook_log', ['attempts', 'updated_date'], [$row['attempts'] + 1, getCurrentDatetime('Y-m-d H:i:s')], 'id = :where_id', [':where_id' => $row['id']]);

                                $jobs[] = [
                                    'id' => $row['id'],
                                    'url' => $row['url'],
                                    'payload' => json_decode($row['payload'], true),
                                    'attempts' => $row['attempts'] + 1
                                ];
                            }

                            $results = sendIPNMulti($jobs);

                            foreach ($jobs as $job) {
                                $code = $results[$job['id']] ?? 0;
                                $status = ($code === 200) ? 'completed' : 'pending';

                                if ($job['attempts'] >= $limit && $code !== 200) {
                                    $status = 'canceled';
                                }

                                updateData($db_prefix . 'webhook_log', ['status', 'http_code', 'updated_date'], [$status, $code, getCurrentDatetime('Y-m-d H:i:s')], 'id = :where_id', [':where_id' => $job['id']]);
                            }
                            //webhook pending
                            //webhook pending

                            // Run structured cron jobs (rate_limit_cleanup, key_expiry)
                            try {
                                $cronRunner = new \AnirbanPay\Cron\CronJobRunner();
                                $cronRunner->run('rate_limit_cleanup');
                                $cronRunner->run('key_expiry');
                            } catch (\Throwable $e) {
                                error_log('[Cron] CronJobRunner error: ' . $e->getMessage());
                            }

                            unlink($lockFile);
                        } else {
                            if (file_exists(__DIR__ . '/errors/404.php')) {
                                http_response_code(404);
                                require __DIR__ . '/errors/404.php';
                            } else {
                                http_response_code(403);
                                exit('Direct access not allowed');
                            }
                        }
                    }
                    break;

                case 'homepageRedirect':
                    if ($path_homepageRedirect == "") {
                        echo '<script nonce="' . $csp_nonce . '">location.href="login";</script>';
                    } else {
                        echo '<script nonce="' . $csp_nonce . '">location.href="https://' . $path_homepageRedirect . '";</script>';
                    }
                    break;
                default:
                    if (file_exists(__DIR__ . '/errors/404.php')) {
                        http_response_code(404);
                        require __DIR__ . '/errors/404.php';
                    } else {
                        http_response_code(403);
                        exit('Direct access not allowed');
                    }
                    break;
            }
        } else {
            if (file_exists(__DIR__ . '/errors/requirement.php')) {
                require __DIR__ . '/errors/requirement.php';
            } else {
                if (file_exists(__DIR__ . '/errors/404.php')) {
                    http_response_code(404);
                    require __DIR__ . '/errors/404.php';
                } else {
                    http_response_code(403);
                    exit('Direct access not allowed');
                }
            }
        }
    } else {
        if (file_exists(__DIR__ . '/app/install/index.php')) {
            require __DIR__ . '/app/install/index.php';
        } else {
            if (file_exists(__DIR__ . '/errors/404.php')) {
                http_response_code(404);
                require __DIR__ . '/errors/404.php';
            } else {
                http_response_code(403);
                exit('Direct access not allowed');
            }
        }
    }
} else {
    if (file_exists(__DIR__ . '/errors/maintenance.php')) {
        require __DIR__ . '/errors/maintenance.php';
    } else {
        if (file_exists(__DIR__ . '/errors/404.php')) {
            http_response_code(404);
            require __DIR__ . '/errors/404.php';
        } else {
            http_response_code(403);
            exit('Direct access not allowed');
        }
    }
}