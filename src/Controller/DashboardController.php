<?php
declare(strict_types=1);

namespace AnirbanPay\Controller;

use AnirbanPay\Http\RequestContext;

class DashboardController
{
    public static function handle(string $action, RequestContext $ctx): void
    {
        $global_user_login = $ctx->isLoggedIn;
        $global_response_permission = $ctx->permissionResponse;
        $global_user_response = $ctx->userResponse;
        $new_csrf_token = $ctx->csrfToken;
        $db_prefix = $ctx->dbPrefix;
        $global_response_brand = $ctx->brandResponse;

        $request = \AnirbanPay\Http\Request::createFromGlobals();
        if ($action == "dashboard-transaction-statistics") {
            if ($global_user_login == true) {
                if (!canAccessPage(json_decode($global_response_permission['response'][0]['permission'], true), 'dashboard', $global_user_response['response'][0]['role'])) {
                    echo json_encode(['status' => 'false', 'title' => 'Access denied', 'message' => 'You need permission to perform this action. Please contact the admin.', 'csrf_token' => $new_csrf_token]);
                    exit();
                }

                $date = $request->post('date', 'this_year');
                $start = $request->post('start', '');
                $end = $request->post('end', '');

                $labels = [];
                $keys = [];

                // If user sends a custom start/end date
                if ($start || $end) {

                    // fallback to today if start or end missing
                    if (!$start)
                        $start = $end;
                    if (!$end)
                        $end = $start;

                    $start_ts = strtotime($start);
                    $end_ts = strtotime($end);

                    // loop from start to end by day
                    for ($ts = $start_ts; $ts <= $end_ts; $ts = strtotime('+1 day', $ts)) {
                        $labels[] = date('d M', $ts);       // e.g. 08 Jan
                        $keys[] = date('Y-m-d', $ts);     // use for mapping DB
                    }

                } else {

                    // Your existing switch for presets
                    switch ($date) {
                        case 'today':
                            for ($i = 6; $i >= 0; $i--) {
                                $labels[] = date('h A', strtotime("-$i hour"));
                                $keys[] = date('Y-m-d H', strtotime("-$i hour"));
                            }
                            break;

                        case 'yesterday':
                            for ($i = 0; $i < 7; $i++) {
                                $labels[] = date('h A', strtotime("yesterday +$i hour"));
                                $keys[] = date('Y-m-d H', strtotime("yesterday +$i hour"));
                            }
                            break;

                        case 'this_week':
                        case 'last_week':
                            $start = ($date === 'this_week') ? strtotime('monday this week') : strtotime('monday last week');
                            for ($i = 0; $i < 7; $i++) {
                                $labels[] = date('D', strtotime("+$i day", $start));
                                $keys[] = date('Y-m-d', strtotime("+$i day", $start));
                            }
                            break;

                        case 'this_month':
                        case 'last_month':
                            $start = ($date === 'this_month') ? strtotime(date('Y-m-01')) : strtotime('first day of last month');
                            $days = date('t', $start);
                            for ($i = 0; $i < $days; $i++) {
                                $labels[] = date('d', strtotime("+$i day", $start));
                                $keys[] = date('Y-m-d', strtotime("+$i day", $start));
                            }
                            break;

                        case 'previous_year':
                            for ($i = 11; $i >= 0; $i--) {
                                $labels[] = date('M', strtotime("-$i month", strtotime('first day of january last year')));
                                $keys[] = date('Y-m', strtotime("-$i month", strtotime('first day of january last year')));
                            }
                            break;

                        case 'this_year':
                        default:
                            for ($i = 11; $i >= 0; $i--) {
                                $labels[] = date('M', strtotime("-$i month"));
                                $keys[] = date('Y-m', strtotime("-$i month"));
                            }
                            break;
                    }

                }

                // Prepare empty arrays
                $total = array_fill(0, count($keys), 0);
                $complete = array_fill(0, count($keys), 0);
                $pending = array_fill(0, count($keys), 0);

                $keyMap = array_flip($keys);

                // Fetch transactions
                $response_transaction = json_decode(getData($db_prefix . 'transaction', ' WHERE brand_id = :brand_id AND status NOT IN ("initiated")', '* FROM', [':brand_id' => $global_response_brand['response'][0]['brand_id']]), true);

                foreach ($response_transaction['response'] as $row) {

                    if ($start || $end) {
                        // For custom date, group by day
                        $trxKey = date('Y-m-d', strtotime($row['created_date']));
                    } elseif (in_array($date, ['today', 'yesterday'])) {
                        $trxKey = date('Y-m-d H', strtotime($row['created_date']));
                    } elseif (in_array($date, ['this_week', 'last_week', 'this_month', 'last_month'])) {
                        $trxKey = date('Y-m-d', strtotime($row['created_date']));
                    } else {
                        $trxKey = date('Y-m', strtotime($row['created_date']));
                    }

                    if (isset($keyMap[$trxKey])) {
                        $i = $keyMap[$trxKey];
                        $total[$i]++;

                        if ($row['status'] === 'completed')
                            $complete[$i]++;
                        if ($row['status'] === 'pending')
                            $pending[$i]++;
                    }
                }

                echo json_encode([
                    'status' => 'true',
                    'labels' => $labels,
                    'total' => $total,
                    'complete' => $complete,
                    'pending' => $pending,
                    'csrf_token' => $new_csrf_token
                ]);
            } else {
                echo json_encode(['status' => 'false', 'title' => 'Request Failed', 'message' => 'Invalid request', 'csrf_token' => $new_csrf_token]);
            }
        }

        if ($action == "dashboard-gateway-statistics") {
            if ($global_user_login == true) {
                if (!canAccessPage(json_decode($global_response_permission['response'][0]['permission'], true), 'dashboard', $global_user_response['response'][0]['role'])) {
                    echo json_encode(['status' => 'false', 'title' => 'Access denied', 'message' => 'You need permission to perform this action. Please contact the admin.', 'csrf_token' => $new_csrf_token]);
                    exit();
                }

                $date = $request->post('date', 'this_year');
                $start = $request->post('start', '');
                $end = $request->post('end', '');

                $labels = [];
                $keys = [];

                $isCustomRange = (!empty($start) && !empty($end));

                if ($isCustomRange) {

                    $startTs = strtotime($start);
                    $endTs = strtotime($end);

                    // safety: swap if reversed
                    if ($startTs > $endTs) {
                        [$startTs, $endTs] = [$endTs, $startTs];
                    }

                    $labels = [];
                    $keys = [];

                    while ($startTs <= $endTs) {
                        $labels[] = date('d M', $startTs);   // UI label
                        $keys[] = date('Y-m-d', $startTs); // matching key
                        $startTs = strtotime('+1 day', $startTs);
                    }

                } else {
                    switch ($date) {

                        case 'today':
                            for ($i = 6; $i >= 0; $i--) {
                                $labels[] = date('h A', strtotime("-$i hour"));
                                $keys[] = date('Y-m-d H', strtotime("-$i hour"));
                            }
                            break;

                        case 'yesterday':
                            for ($i = 0; $i < 7; $i++) {
                                $labels[] = date('h A', strtotime("yesterday +$i hour"));
                                $keys[] = date('Y-m-d H', strtotime("yesterday +$i hour"));
                            }
                            break;

                        case 'this_week':
                        case 'last_week':
                            $start = ($date === 'this_week') ? strtotime('monday this week') : strtotime('monday last week');
                            for ($i = 0; $i < 7; $i++) {
                                $labels[] = date('D', strtotime("+$i day", $start));
                                $keys[] = date('Y-m-d', strtotime("+$i day", $start));
                            }
                            break;

                        case 'this_month':
                        case 'last_month':
                            $start = ($date === 'this_month') ? strtotime(date('Y-m-01')) : strtotime('first day of last month');
                            $days = date('t', $start);
                            for ($i = 0; $i < $days; $i++) {
                                $labels[] = date('d', strtotime("+$i day", $start));
                                $keys[] = date('Y-m-d', strtotime("+$i day", $start));
                            }
                            break;

                        case 'previous_year':
                            for ($i = 11; $i >= 0; $i--) {
                                $labels[] = date('M', strtotime("-$i month", strtotime('first day of january last year')));
                                $keys[] = date('Y-m', strtotime("-$i month", strtotime('first day of january last year')));
                            }
                            break;

                        case 'this_year':
                        default:
                            for ($i = 6; $i >= 0; $i--) {
                                $labels[] = date('M', strtotime("-$i month"));
                                $keys[] = date('Y-m', strtotime("-$i month"));
                            }
                            break;
                    }
                }

                $keyMap = array_flip($keys);

                // Initialize arrays for gateway data
                $gatewayData = []; // ['Stripe' => [0,0,0,...], 'PayPal' => [...]]
                $gatewayLabels = []; // slug => name

                // Get all transactions
                $response_transaction = json_decode(getData($db_prefix . 'transaction', ' WHERE brand_id = :brand_id AND status ="completed"', '* FROM', [':brand_id' => $global_response_brand['response'][0]['brand_id']]), true);

                foreach ($response_transaction['response'] as $row) {

                    // Determine key based on date range
                    if ($isCustomRange) {
                        $trxKey = date('Y-m-d', strtotime($row['created_date']));
                    } elseif (in_array($date, ['today', 'yesterday'])) {
                        $trxKey = date('Y-m-d H', strtotime($row['created_date']));
                    } elseif (in_array($date, ['this_week', 'last_week', 'this_month', 'last_month'])) {
                        $trxKey = date('Y-m-d', strtotime($row['created_date']));
                    } else {
                        $trxKey = date('Y-m', strtotime($row['created_date']));
                    }

                    if (!isset($keyMap[$trxKey]))
                        continue;
                    $i = $keyMap[$trxKey];

                    // Get gateway name
                    $gateway_id = $row['gateway_id'];
                    if (!isset($gatewayLabels[$gateway_id])) {
                        $resGateway = json_decode(getData($db_prefix . 'gateways', ' WHERE brand_id = :brand_id  AND gateway_id = :gateway_id LIMIT 1', '* FROM', [':brand_id' => $global_response_brand['response'][0]['brand_id'], ':gateway_id' => $gateway_id]), true);
                        $gatewayName = "Unknown"; // default if gateway missing
                        $gatewayColor = '#d3d3d3'; // default light grey for unknown
                        if ($resGateway['status'] && isset($resGateway['response'][0]['name']) && !empty($resGateway['response'][0]['name'])) {
                            $gatewayRow = $resGateway['response'][0];
                            if (!empty($gatewayRow['name'])) {
                                $gatewayName = $gatewayRow['name'];
                            }
                            if (!empty($gatewayRow['primary_color'])) {
                                $gatewayColor = $gatewayRow['primary_color']; // take color from DB
                            }
                        }

                        $gatewayLabels[$gateway_id] = $gatewayName;
                        $gatewayColors[$gatewayName] = $gatewayColor;
                        $gatewayData[$gatewayName] = array_fill(0, count($keys), 0);
                    }

                    $gatewayData[$gatewayLabels[$gateway_id]][$i]++;
                }

                if (empty($gatewayData)) {
                    $gatewayData['No Data'] = [1];
                    $gatewayLabels = ['No Data'];
                    $gatewayColors['No Data'] = '#f0f0f0'; // light grey
                }

                echo json_encode(['status' => 'true', 'labels' => $labels, 'keys' => $keys, 'gateway_labels' => array_values($gatewayLabels), 'data' => $gatewayData, 'colors' => array_values($gatewayColors), 'csrf_token' => $new_csrf_token]);
            } else {
                echo json_encode(['status' => 'false', 'title' => 'Request Failed', 'message' => 'Invalid request', 'csrf_token' => $new_csrf_token]);
            }
        }

        if ($action == "reports") {
            if ($global_user_login == true) {
                if (!canAccessPage(json_decode($global_response_permission['response'][0]['permission'], true), 'reports', $global_user_response['response'][0]['role'])) {
                    echo json_encode(['status' => 'false', 'title' => 'Access denied', 'message' => 'You need permission to perform this action. Please contact the admin.', 'csrf_token' => $new_csrf_token]);
                    exit();
                }

                $date = $request->post('date', 'this_year');

                $brand_id = $global_response_brand['response'][0]['brand_id'];

                $rawStart = $request->post('start', '');
                $rawEnd = $request->post('end', '');

                $start = '';
                $end = '';

                if (!empty($rawStart)) {

                    // Try Y-m-d (2026-01-07)
                    $dt = \DateTime::createFromFormat('Y-m-d', $rawStart);
                    if ($dt !== false) {
                        $start = $dt->format('Y-m-d');
                    } else {
                        // Fallback m/d/Y (01/07/2026)
                        $dt = \DateTime::createFromFormat('m/d/Y', $rawStart);
                        if ($dt !== false) {
                            $start = $dt->format('Y-m-d');
                        }
                    }
                }

                /* ---------- END DATE ---------- */
                if (!empty($rawEnd)) {

                    // Try Y-m-d (2026-01-07)
                    $dt = \DateTime::createFromFormat('Y-m-d', $rawEnd);
                    if ($dt !== false) {
                        $end = $dt->format('Y-m-d');
                    } else {
                        // Fallback m/d/Y (01/07/2026)
                        $dt = \DateTime::createFromFormat('m/d/Y', $rawEnd);
                        if ($dt !== false) {
                            $end = $dt->format('Y-m-d');
                        }
                    }
                }
                if (!empty($start) || !empty($end)) {

                    /* ---------- BOTH DATES ---------- */
                    if (!empty($start) && !empty($end)) {

                        if ($start > $end) {
                            echo json_encode([
                                'status' => 'false',
                                'title' => 'Invalid Date Range',
                                'message' => 'Start date must be earlier than end date.',
                                'csrf_token' => $new_csrf_token
                            ]);
                            exit;
                        }

                        $rangeStart = $start;
                        $rangeEnd = $end;
                    }

                    /* ---------- ONLY START DATE ---------- */ elseif (!empty($start)) {

                        $rangeStart = $start;
                        $rangeEnd = date('Y-m-d'); // today
                    }

                    /* ---------- ONLY END DATE ---------- */ else {

                        $rangeEnd = $end;
                        $rangeStart = $end; // single-day report
                    }

                    /* ---------- DISPLAY RANGE ---------- */
                    $from = date('M d, Y', strtotime($rangeStart));
                    $to = date('M d, Y', strtotime($rangeEnd));

                    /* ---------- SQL WHERE ---------- */
                    $where = "DATE(created_date) BETWEEN :rangeStart AND :rangeEnd";
                    $whereParams = [':rangeStart' => $rangeStart, ':rangeEnd' => $rangeEnd];

                    /* ---------- PREVIOUS RANGE ---------- */
                    $days = (strtotime($rangeEnd) - strtotime($rangeStart)) / 86400 + 1;

                    $prevStart = date('Y-m-d', strtotime("$rangeStart -$days days"));
                    $prevEnd = date('Y-m-d', strtotime("$rangeEnd -$days days"));

                    $prevWhere = "DATE(created_date) BETWEEN :prevStart AND :prevEnd";
                    $prevWhereParams = [':prevStart' => $prevStart, ':prevEnd' => $prevEnd];

                } else {
                    switch ($date) {

                        case 'today':
                            $from = date('M d, Y');
                            $to = $from;
                            $where = "DATE(created_date)=CURDATE()";
                            $whereParams = [];
                            $prevWhere = "DATE(created_date)=CURDATE()-INTERVAL 1 DAY";
                            $prevWhereParams = [];
                            break;

                        case 'yesterday':
                            $from = date('M d, Y', strtotime('-1 day'));
                            $to = $from;
                            $where = "DATE(created_date)=CURDATE()-INTERVAL 1 DAY";
                            $whereParams = [];
                            $prevWhere = "DATE(created_date)=CURDATE()-INTERVAL 2 DAY";
                            $prevWhereParams = [];
                            break;

                        case 'this_week':
                            $from = date('M d, Y', strtotime('monday this week'));
                            $to = date('M d, Y', strtotime('sunday this week'));
                            $where = "YEARWEEK(created_date,1)=YEARWEEK(CURDATE(),1)";
                            $whereParams = [];
                            $prevWhere = "YEARWEEK(created_date,1)=YEARWEEK(CURDATE(),1)-1";
                            $prevWhereParams = [];
                            break;

                        case 'last_week':
                            $from = date('M d, Y', strtotime('monday last week'));
                            $to = date('M d, Y', strtotime('sunday last week'));
                            $where = "YEARWEEK(created_date,1)=YEARWEEK(CURDATE(),1)-1";
                            $whereParams = [];
                            $prevWhere = "YEARWEEK(created_date,1)=YEARWEEK(CURDATE(),1)-2";
                            $prevWhereParams = [];
                            break;

                        case 'this_month':
                            $from = date('M 01, Y');
                            $to = date('M t, Y');
                            $where = "MONTH(created_date)=MONTH(CURDATE()) AND YEAR(created_date)=YEAR(CURDATE())";
                            $whereParams = [];
                            $prevWhere = "MONTH(created_date)=MONTH(CURDATE()-INTERVAL 1 MONTH)";
                            $prevWhereParams = [];
                            break;

                        case 'last_month':
                            $from = date('M 01, Y', strtotime('first day of last month'));
                            $to = date('M t, Y', strtotime('last day of last month'));
                            $where = "MONTH(created_date)=MONTH(CURDATE()-INTERVAL 1 MONTH)";
                            $whereParams = [];
                            $prevWhere = "MONTH(created_date)=MONTH(CURDATE()-INTERVAL 2 MONTH)";
                            $prevWhereParams = [];
                            break;

                        case 'previous_year':
                            $from = 'Jan 01, ' . date('Y', strtotime('-1 year'));
                            $to = 'Dec 31, ' . date('Y', strtotime('-1 year'));
                            $where = "YEAR(created_date)=YEAR(CURDATE())-1";
                            $whereParams = [];
                            $prevWhere = "YEAR(created_date)=YEAR(CURDATE())-2";
                            $prevWhereParams = [];
                            break;

                        case 'this_year':
                        default:
                            $from = 'Jan 01, ' . date('Y');
                            $to = 'Dec 31, ' . date('Y');
                            $where = "YEAR(created_date)=YEAR(CURDATE())";
                            $whereParams = [];
                            $prevWhere = "YEAR(created_date)=YEAR(CURDATE())-1";
                            $prevWhereParams = [];
                            break;
                    }
                }

                $currencyRates = [];

                $currencyRes = json_decode(getData($db_prefix . 'currency', ' WHERE brand_id = :brand_id', '* FROM', [':brand_id' => $brand_id]), true);
                if (!empty($currencyRes['response'])) {
                    foreach ($currencyRes['response'] as $c) {
                        $currencyRates[$c['code']] = (string) $c['rate'];
                    }
                }

                $global_brand_currency_code = $global_response_brand['response'][0]['currency_code'];
                $global_brand_currency_rate = "1";

                $combinedParams = array_merge([':brand_id' => $brand_id], $whereParams);
                $res = json_decode(getData($db_prefix . 'transaction', " WHERE brand_id = :brand_id AND status NOT IN ('initiated', 'expired') AND $where", '* FROM', $combinedParams), true);

                $total = 0;
                $completed = 0;
                $revenue = "0";

                foreach ($res['response'] as $row) {
                    $total++;
                    if ($row['status'] === 'completed') {
                        $completed++;

                        $txnAmount = (string) $row['amount'];
                        $txnCurrency = $row['currency'];

                        $rate = $txnCurrency === $global_brand_currency_code ? "1" : ($currencyRates[$txnCurrency] ?? "0");

                        $convertedAmount = money_mul($txnAmount, $rate);

                        $revenue = money_add($revenue, $convertedAmount);
                    }
                }

                $successRate = $total ? money_div((string) ($completed * 100), (string) $total, 2) : "0";
                $average = $completed ? money_div($revenue, (string) $completed, 2) : "0";

                $combinedPrevParams = array_merge([':brand_id' => $brand_id], $prevWhereParams);
                $prevRes = json_decode(getData($db_prefix . 'transaction', " WHERE brand_id = :brand_id AND status NOT IN ('initiated', 'expired') AND $prevWhere", '* FROM', $combinedPrevParams), true);

                $prevTotal = 0;
                $prevCompleted = 0;

                foreach ($prevRes['response'] as $row) {
                    $prevTotal++;
                    if ($row['status'] === 'completed') {
                        $prevCompleted++;
                    }
                }

                $prevSuccessRate = $prevTotal ? money_div((string) ($prevCompleted * 100), (string) $prevTotal, 2) : "0";

                $trend = bccomp($successRate, $prevSuccessRate, 2) > 0 ? 'up' : (bccomp($successRate, $prevSuccessRate, 2) < 0 ? 'down' : 'same');

                echo json_encode(['status' => 'true', 'date_range' => $from . ' – ' . $to, 'revenue' => money_round($revenue, 2), 'completed' => $completed, 'total' => $total, 'success_rate' => money_round($successRate, 2), 'prev_success_rate' => money_round($prevSuccessRate, 2), 'success_trend' => $trend, 'average' => money_round($average, 2), 'csrf_token' => $new_csrf_token]);
            } else {
                echo json_encode(['status' => 'false', 'title' => 'Request Failed', 'message' => 'Invalid request', 'csrf_token' => $new_csrf_token]);
            }
        }

    }
}
