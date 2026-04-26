<?php
if (!defined('OWNPAY_INIT')) {
    http_response_code(403);
    exit('Direct access not allowed');
}

if (!canAccessPage(json_decode($global_response_permission['response'][0]['permission'], true), 'dashboard', $global_user_response['response'][0]['role'])) {
    http_response_code(403);
    exit('Access denied. You need permission to perform this action. Please contact the admin.');
}
?>

<!-- Page Header -->
<div class="op-page-header">
    <div>
        <div class="op-page-pretitle">Overview</div>
        <h2 class="op-page-title">Welcome back<?php echo !empty($global_user_response['response'][0]['full_name']) ? ', ' . htmlspecialchars(explode(' ', $global_user_response['response'][0]['full_name'])[0]) : ''; ?></h2>
    </div>
    <div class="flex items-center gap-3 mt-4 sm:mt-0">
        <button class="op-btn-secondary text-sm gap-2">
            <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 3v4a1 1 0 0 0 1 1h4"/><path d="M17 21h-10a2 2 0 0 1 -2 -2v-14a2 2 0 0 1 2 -2h7l5 5v11a2 2 0 0 1 -2 2z"/><path d="M12 17v-6"/><path d="M9.5 14.5l2.5 2.5l2.5 -2.5"/></svg>
            Export
        </button>
        <button class="op-btn-primary text-sm gap-2" data-dropdown-toggle="dropdown-create-menu">
            <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5l0 14"/><path d="M5 12l14 0"/></svg>
            Create
            <svg xmlns="http://www.w3.org/2000/svg" class="w-3 h-3 ms-1" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9l6 6l6 -6"/></svg>
        </button>
        <div id="dropdown-create-menu" class="hidden z-10 bg-white divide-y divide-gray-100 rounded-lg shadow-sm w-48 dark:bg-gray-700">
            <ul class="py-2 text-sm text-gray-700 dark:text-gray-200">
                <li><a href="javascript:void(0)" onclick="load_content('Invoice','<?php echo $site_url . $path_admin ?>/invoice/create','nav-item-invoice')" class="flex items-center gap-2 px-4 py-2 hover:bg-gray-100 dark:hover:bg-gray-600"><svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 text-gray-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 3v4a1 1 0 0 0 1 1h4"/><path d="M17 21h-10a2 2 0 0 1 -2 -2v-14a2 2 0 0 1 2 -2h7l5 5v11a2 2 0 0 1 -2 2z"/><path d="M12 11v6"/><path d="M9 14h6"/></svg>New Invoice</a></li>
                <li><a href="javascript:void(0)" onclick="load_content('Payment Link','<?php echo $site_url . $path_admin ?>/payment-link/create','nav-item-payment-link')" class="flex items-center gap-2 px-4 py-2 hover:bg-gray-100 dark:hover:bg-gray-600"><svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 text-gray-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 15l6 -6"/><path d="M11 6l.463 -.536a5 5 0 0 1 7.071 7.072l-.534 .464"/><path d="M13 18l-.397 .534a5.068 5.068 0 0 1 -7.127 0a4.972 4.972 0 0 1 0 -7.071l.524 -.463"/></svg>New Payment Link</a></li>
                <li><a href="javascript:void(0)" onclick="load_content('Customers','<?php echo $site_url . $path_admin ?>/customers','nav-item-customers')" class="flex items-center gap-2 px-4 py-2 hover:bg-gray-100 dark:hover:bg-gray-600"><svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 text-gray-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M8 7a4 4 0 1 0 8 0a4 4 0 0 0 -8 0"/><path d="M6 21v-2a4 4 0 0 1 4 -4h4a4 4 0 0 1 4 4v2"/></svg>New Customer</a></li>
            </ul>
        </div>
    </div>
</div>

<!-- KPI Cards -->
<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
    <!-- Total Revenue -->
    <div class="op-stat-card">
        <div class="flex items-center justify-between mb-3">
            <div class="op-stat-title">Total Revenue</div>
            <div class="w-8 h-8 rounded-lg flex items-center justify-center bg-indigo-500/10">
                <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 text-indigo-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16.7 8a3 3 0 0 0 -2.7 -2h-4a3 3 0 0 0 0 6h4a3 3 0 0 1 0 6h-4a3 3 0 0 1 -2.7 -2"/><path d="M12 3v3m0 12v3"/></svg>
            </div>
        </div>
        <div class="op-stat-value tabular-nums">
            <?php
            $total_revenue = 0;
            $response_dashboard_info = json_decode(getData($db_prefix . 'transaction', ' WHERE brand_id = "' . $global_response_brand['response'][0]['brand_id'] . '" AND status = "completed"', 'SUM(amount) as total FROM'), true);
            if ($response_dashboard_info['status'] == true && !empty($response_dashboard_info['response'][0]['total'])) {
                $total_revenue = $response_dashboard_info['response'][0]['total'];
            }
            echo ($global_brand_currency_symbol ?? '$') . number_format($total_revenue, 2);
            ?>
        </div>
        <div id="chart-total-payment" class="mt-3" style="min-height: 35px;"></div>
    </div>

    <!-- Pending Payments -->
    <div class="op-stat-card">
        <div class="flex items-center justify-between mb-3">
            <div class="op-stat-title">Pending Payments</div>
            <div class="w-8 h-8 rounded-lg flex items-center justify-center bg-amber-500/10">
                <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 text-amber-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 12m-9 0a9 9 0 1 0 18 0a9 9 0 1 0 -18 0"/><path d="M12 7v5l3 3"/></svg>
            </div>
        </div>
        <div class="op-stat-value tabular-nums">
            <?php
            $count = 0;
            $response_dashboard_info = json_decode(getData($db_prefix . 'transaction', ' WHERE brand_id = "' . $global_response_brand['response'][0]['brand_id'] . '" AND status = "pending"'), true);
            if ($response_dashboard_info['status'] == true) {
                $count = count($response_dashboard_info['response']);
            }
            echo number_format($count, 0);
            ?>
        </div>
        <div id="chart-pending-payment" class="mt-3" style="min-height: 35px;"></div>
    </div>

    <!-- Success Rate -->
    <div class="op-stat-card">
        <div class="flex items-center justify-between mb-3">
            <div class="op-stat-title">Success Rate</div>
            <div class="w-8 h-8 rounded-lg flex items-center justify-center bg-emerald-500/10">
                <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 text-emerald-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12l5 5l10 -10"/></svg>
            </div>
        </div>
        <div class="op-stat-value tabular-nums">
            <?php
            $total = 0; $completed = 0;
            $response_all = json_decode(getData($db_prefix . 'transaction', ' WHERE brand_id = "' . $global_response_brand['response'][0]['brand_id'] . '" AND status NOT IN ("initiated")'), true);
            if ($response_all['status'] == true) { $total = count($response_all['response']); }
            $response_completed = json_decode(getData($db_prefix . 'transaction', ' WHERE brand_id = "' . $global_response_brand['response'][0]['brand_id'] . '" AND status = "completed"'), true);
            if ($response_completed['status'] == true) { $completed = count($response_completed['response']); }
            $rate = $total > 0 ? round(($completed / $total) * 100, 1) : 0;
            echo $rate . '%';
            ?>
        </div>
        <div id="chart-unpaid-invoice" class="mt-3" style="min-height: 35px;"></div>
    </div>

    <!-- Active Gateways -->
    <div class="op-stat-card">
        <div class="flex items-center justify-between mb-3">
            <div class="op-stat-title">Active Gateways</div>
            <div class="w-8 h-8 rounded-lg flex items-center justify-center bg-blue-500/10">
                <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 text-blue-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 8v-3a1 1 0 0 0 -1 -1h-10a2 2 0 0 0 0 4h12a1 1 0 0 1 1 1v3m0 4v3a1 1 0 0 1 -1 1h-12a2 2 0 0 1 -2 -2v-12"/><path d="M20 12v4h-4a2 2 0 0 1 0 -4h4"/></svg>
            </div>
        </div>
        <div class="op-stat-value tabular-nums">
            <?php
            $count = 0;
            $response_dashboard_info = json_decode(getData($db_prefix . 'gateway', ' WHERE brand_id = "' . $global_response_brand['response'][0]['brand_id'] . '" AND status = "active"'), true);
            if ($response_dashboard_info['status'] == true) {
                $count = count($response_dashboard_info['response']);
            }
            echo number_format($count, 0);
            ?>
        </div>
        <div id="chart-customer" class="mt-3" style="min-height: 35px;"></div>
    </div>
</div>

<!-- Charts Row -->
<div class="grid grid-cols-1 lg:grid-cols-3 gap-4 mb-6">
    <!-- Transaction Volume (2/3 width) -->
    <div class="op-card lg:col-span-2">
        <div class="op-card-header">
            <div>
                <h3 class="op-card-title">Volume (30 Days)</h3>
                <p class="text-xs mt-0.5 text-slate-400/60">Gross transaction volume</p>
            </div>
            <div class="flex items-center gap-2">
                <span class="dashboard-transaction-statistics-loading"></span>
                <div class="relative">
                    <button data-dropdown-toggle="filterDropdown-transaction-statistics"
                        class="op-icon-btn">
                        <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h16v2.172a2 2 0 0 1 -.586 1.414l-4.414 4.414v7l-6 2v-8.5l-4.48 -4.928a2 2 0 0 1 -.52 -1.345v-2.227z" /></svg>
                    </button>

                    <div id="filterDropdown-transaction-statistics" class="hidden z-50 mt-2 w-72 rounded-xl p-4 shadow-lg op-card">
                        <label class="op-label">Filter By</label>
                        <select class="op-select mb-3" id="dateFilter-transaction-statistics" onchange="handleFilterChangeTransactionStatistics(this.value)">
                            <option value="today">Today</option>
                            <option value="yesterday">Yesterday</option>
                            <option value="this_week">This week</option>
                            <option value="last_week">Last week</option>
                            <option value="this_month">This month</option>
                            <option value="last_month">Last month</option>
                            <option value="this_year" selected>This year</option>
                            <option value="previous_year">Previous year</option>
                            <option value="custom">Custom Range</option>
                        </select>
                        <div id="customRange-transaction-statistics" class="hidden">
                            <label class="op-label">Start Date</label>
                            <input type="date" id="startDate-transaction-statistics" class="op-input mb-2">
                            <label class="op-label">End Date</label>
                            <input type="date" id="endDate-transaction-statistics" class="op-input mb-3">
                            <button class="op-btn-primary w-full" onclick="applyCustomRangeTransactionStatistics()">Apply</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="op-card-body">
            <div id="chart-transaction-statistics" style="height: 280px; min-height: 280px;"></div>
        </div>
    </div>

    <!-- Gateway Distribution (1/3 width) -->
    <div class="op-card">
        <div class="op-card-header">
            <h3 class="op-card-title">Gateway Distribution</h3>
            <div class="flex items-center gap-2">
                <span class="dashboard-gateway-statistics-loading"></span>
                <div class="relative">
                    <button data-dropdown-toggle="filterDropdown-gateway-statistics"
                        class="op-icon-btn">
                        <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h16v2.172a2 2 0 0 1 -.586 1.414l-4.414 4.414v7l-6 2v-8.5l-4.48 -4.928a2 2 0 0 1 -.52 -1.345v-2.227z" /></svg>
                    </button>

                    <div id="filterDropdown-gateway-statistics" class="hidden z-50 mt-2 w-72 rounded-xl p-4 shadow-lg op-card">
                        <label class="op-label">Filter By</label>
                        <select class="op-select mb-3" id="dateFilter-gateway-statistics" onchange="handleFilterChangeGatewayStatistics(this.value)">
                            <option value="today">Today</option>
                            <option value="yesterday">Yesterday</option>
                            <option value="this_week">This week</option>
                            <option value="last_week">Last week</option>
                            <option value="this_month">This month</option>
                            <option value="last_month">Last month</option>
                            <option value="this_year" selected>This year</option>
                            <option value="previous_year">Previous year</option>
                            <option value="custom">Custom Range</option>
                        </select>
                        <div id="customRange-gateway-statistics" class="hidden">
                            <label class="op-label">Start Date</label>
                            <input type="date" id="startDate-gateway-statistics" class="op-input mb-2">
                            <label class="op-label">End Date</label>
                            <input type="date" id="endDate-gateway-statistics" class="op-input mb-3">
                            <button class="op-btn-primary w-full" onclick="applyCustomRangeGatewayStatistics()">Apply</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="op-card-body">
            <div id="chart-gateway-statistics" style="height: 280px;"></div>
        </div>
    </div>
</div>

<!-- Bottom Row: Recent Transactions + Quick Actions -->
<div class="grid grid-cols-1 lg:grid-cols-3 gap-4 mb-6">
    <!-- Recent Transactions (2/3 width) -->
    <div class="op-card lg:col-span-2">
        <div class="op-card-header">
            <h3 class="op-card-title">Recent Transactions</h3>
            <a href="#" onclick="load_content('Transaction','<?php echo $site_url . $path_admin ?>/transaction','nav-item-transaction'); return false;"
                class="text-sm font-medium text-indigo-400">View all →</a>
        </div>
        <div class="overflow-x-auto">
            <table class="op-table">
                <thead>
                    <tr>
                        <th>Amount</th>
                        <th>Status</th>
                        <th>Customer</th>
                        <th>Gateway</th>
                        <th>Date</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $response_recent = json_decode(getData($db_prefix . 'transaction', ' WHERE brand_id = "' . $global_response_brand['response'][0]['brand_id'] . '" AND status NOT IN ("initiated") ORDER BY id DESC LIMIT 5', '* FROM'), true);
                    if ($response_recent['status'] == true) {
                        foreach ($response_recent['response'] as $row) {
                            $statusClass = match($row['status']) {
                                'completed' => 'op-badge-success',
                                'pending' => 'op-badge-warning',
                                'failed' => 'op-badge-danger',
                                'expired' => 'op-badge-info',
                                default => 'op-badge'
                            };
                            $customerName = !empty($row['customer_name']) ? htmlspecialchars($row['customer_name']) : 'N/A';
                            $gateway = !empty($row['gateway']) ? htmlspecialchars($row['gateway']) : 'N/A';
                            $date = !empty($row['created_date']) ? date('M d, h:i A', strtotime($row['created_date'])) : 'N/A';
                            ?>
                            <tr>
                                <td class="font-semibold text-white tabular-nums"><?php echo ($global_brand_currency_symbol ?? '$') . number_format($row['amount'] ?? 0, 2); ?></td>
                                <td><span class="<?php echo $statusClass; ?>"><?php echo ucfirst($row['status']); ?></span></td>
                                <td><?php echo $customerName; ?></td>
                                <td><?php echo $gateway; ?></td>
                                <td class="text-xs"><?php echo $date; ?></td>
                            </tr>
                            <?php
                        }
                    } else {
                        echo '<tr><td colspan="5" class="text-center py-8 text-slate-400/50">No transactions yet</td></tr>';
                    }
                    ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Quick Actions (1/3 width) -->
    <div class="op-card">
        <div class="op-card-header">
            <h3 class="op-card-title">Quick Actions</h3>
        </div>
        <div class="op-card-body space-y-3">
            <div class="op-quick-action" onclick="load_content('Invoice','<?php echo $site_url . $path_admin ?>/invoice','nav-item-invoice')">
                <div class="op-quick-action-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 3v4a1 1 0 0 0 1 1h4"/><path d="M17 21h-10a2 2 0 0 1 -2 -2v-14a2 2 0 0 1 2 -2h7l5 5v11a2 2 0 0 1 -2 2z"/><path d="M12 11v6"/><path d="M9 14h6"/></svg>
                </div>
                <div>
                    <div class="text-sm font-medium text-white">New Invoice</div>
                    <div class="text-xs text-slate-400/50">Create a new invoice</div>
                </div>
                <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 ml-auto text-slate-400/30" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 6l6 6l-6 6"/></svg>
            </div>

            <div class="op-quick-action" onclick="load_content('Payment Link','<?php echo $site_url . $path_admin ?>/payment-link','nav-item-payment-link')">
                <div class="op-quick-action-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 15l6 -6"/><path d="M11 6l.463 -.536a5 5 0 0 1 7.071 7.072l-.534 .464"/><path d="M13 18l-.397 .534a5.068 5.068 0 0 1 -7.127 0a4.972 4.972 0 0 1 0 -7.071l.524 -.463"/></svg>
                </div>
                <div>
                    <div class="text-sm font-medium text-white">Create Payment Link</div>
                    <div class="text-xs text-slate-400/50">Generate a shareable link</div>
                </div>
                <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 ml-auto text-slate-400/30" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 6l6 6l-6 6"/></svg>
            </div>

            <div class="op-quick-action" onclick="load_content('Gateways','<?php echo $site_url . $path_admin ?>/gateways','nav-item-gateways')">
                <div class="op-quick-action-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 8v-3a1 1 0 0 0 -1 -1h-10a2 2 0 0 0 0 4h12a1 1 0 0 1 1 1v3m0 4v3a1 1 0 0 1 -1 1h-12a2 2 0 0 1 -2 -2v-12"/><path d="M20 12v4h-4a2 2 0 0 1 0 -4h4"/></svg>
                </div>
                <div>
                    <div class="text-sm font-medium text-white">Manage Gateways</div>
                    <div class="text-xs text-slate-400/50">Configure payment gateways</div>
                </div>
                <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 ml-auto text-slate-400/30" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 6l6 6l-6 6"/></svg>
            </div>

            <div class="op-quick-action" onclick="load_content('Reports','<?php echo $site_url . $path_admin ?>/reports','nav-item-reports')">
                <div class="op-quick-action-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3v18h18"/><path d="M7 16l4 -4l4 4l6 -6"/></svg>
                </div>
                <div>
                    <div class="text-sm font-medium text-white">View Reports</div>
                    <div class="text-xs text-slate-400/50">Analytics & revenue reports</div>
                </div>
                <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 ml-auto text-slate-400/30" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 6l6 6l-6 6"/></svg>
            </div>
        </div>
    </div>
</div>

<script nonce="<?= $csp_nonce ?? '' ?>">
    <?php
    // Customer sparkline data (last 30 days)
    $labels = [];
    for ($i = 29; $i >= 0; $i--) { $labels[date('Y-m-d', strtotime("-$i days"))] = 0; }
    $response_dashboard_info = json_decode(getData($db_prefix . 'customer', ' WHERE brand_id = "' . $global_response_brand['response'][0]['brand_id'] . '" AND created_date >= DATE_SUB(CURDATE(), INTERVAL 29 DAY) GROUP BY DATE(created_date)', 'DATE(created_date) as day, COUNT(*) as total FROM'), true);
    if (isset($response_dashboard_info['response'])) { foreach ($response_dashboard_info['response'] as $row) { if (isset($labels[$row['day']])) $labels[$row['day']] = (int) $row['total']; } }
    $chartLabelsCustomer = json_encode(array_keys($labels)); $chartDataCustomer = json_encode(array_values($labels));

    // Unpaid Invoice sparkline data
    $labels = [];
    for ($i = 29; $i >= 0; $i--) { $labels[date('Y-m-d', strtotime("-$i days"))] = 0; }
    $response_dashboard_info = json_decode(getData($db_prefix . 'invoice', ' WHERE brand_id = "' . $global_response_brand['response'][0]['brand_id'] . '" AND status = "unpaid" AND created_date >= DATE_SUB(CURDATE(), INTERVAL 29 DAY) GROUP BY DATE(created_date)', 'DATE(created_date) as day, COUNT(*) as total FROM'), true);
    if (isset($response_dashboard_info['response'])) { foreach ($response_dashboard_info['response'] as $row) { if (isset($labels[$row['day']])) $labels[$row['day']] = (int) $row['total']; } }
    $chartLabelsInvoice = json_encode(array_keys($labels)); $chartDataInvoice = json_encode(array_values($labels));

    // Pending Payment sparkline data
    $labels = [];
    for ($i = 29; $i >= 0; $i--) { $labels[date('Y-m-d', strtotime("-$i days"))] = 0; }
    $response_dashboard_info = json_decode(getData($db_prefix . 'transaction', ' WHERE brand_id = "' . $global_response_brand['response'][0]['brand_id'] . '" AND status = "pending" AND created_date >= DATE_SUB(CURDATE(), INTERVAL 29 DAY) GROUP BY DATE(created_date)', 'DATE(created_date) as day, COUNT(*) as total FROM'), true);
    if (isset($response_dashboard_info['response'])) { foreach ($response_dashboard_info['response'] as $row) { if (isset($labels[$row['day']])) $labels[$row['day']] = (int) $row['total']; } }
    $chartLabelsPending = json_encode(array_keys($labels)); $chartDataPending = json_encode(array_values($labels));

    // Total Payment sparkline data
    $labels = [];
    for ($i = 29; $i >= 0; $i--) { $labels[date('Y-m-d', strtotime("-$i days"))] = 0; }
    $response_dashboard_info = json_decode(getData($db_prefix . 'transaction', ' WHERE brand_id = "' . $global_response_brand['response'][0]['brand_id'] . '" AND status NOT IN ("initiated", "expired") AND created_date >= DATE_SUB(CURDATE(), INTERVAL 29 DAY) GROUP BY DATE(created_date)', 'DATE(created_date) as day, COUNT(*) as total FROM'), true);
    if (isset($response_dashboard_info['response'])) { foreach ($response_dashboard_info['response'] as $row) { if (isset($labels[$row['day']])) $labels[$row['day']] = (int) $row['total']; } }
    $chartLabelsTotal = json_encode(array_keys($labels)); $chartDataTotal = json_encode(array_values($labels));
    ?>

    function createSparkline(elId, data, labels, color) {
        if (!window.ApexCharts || !document.getElementById(elId)) return;
        new ApexCharts(document.getElementById(elId), {
            chart: { type: "area", fontFamily: "inherit", height: 40, sparkline: { enabled: true }, animations: { enabled: false } },
            dataLabels: { enabled: false },
            fill: { type: "solid", opacity: 0.08 },
            stroke: { width: 2, curve: "smooth", lineCap: "round" },
            series: [{ name: "", data: data }],
            tooltip: { theme: "dark" },
            grid: { strokeDashArray: 4 },
            xaxis: { type: "datetime", labels: { show: false }, axisBorder: { show: false }, tooltip: { enabled: false } },
            yaxis: { labels: { show: false } },
            labels: labels,
            colors: [color],
            legend: { show: false }
        }).render();
    }

    createSparkline("chart-total-payment", <?= $chartDataTotal ?>, <?= $chartLabelsTotal ?>, "#818cf8");
    createSparkline("chart-pending-payment", <?= $chartDataPending ?>, <?= $chartLabelsPending ?>, "#fbbf24");
    createSparkline("chart-unpaid-invoice", <?= $chartDataInvoice ?>, <?= $chartLabelsInvoice ?>, "#34d399");
    createSparkline("chart-customer", <?= $chartDataCustomer ?>, <?= $chartLabelsCustomer ?>, "#60a5fa");

    // --- Transaction Statistics ---
    function load_dashboard_transaction_statistics() {
        const el = document.getElementById('filterDropdown-transaction-statistics');
        if (el) el.classList.add('hidden');

        var csrf_token_default = apGetCsrf();
        var date = document.getElementById('dateFilter-transaction-statistics').value;
        var start = document.getElementById('startDate-transaction-statistics')?.value || '';
        var end = document.getElementById('endDate-transaction-statistics')?.value || '';

        document.querySelector(".dashboard-transaction-statistics-loading").innerHTML = '<div class="op-spinner" style="width:16px;height:16px;border-width:2px"></div>';

        const params = new URLSearchParams();
        params.append('action', 'dashboard-transaction-statistics');
        params.append('csrf_token', csrf_token_default);
        params.append('date', date);
        if (start) params.append('start', start);
        if (end) params.append('end', end);

        fetch('<?php echo $site_url . $path_admin ?>/dashboard', { method: 'POST', body: params })
            .then(response => response.json())
            .then(res => {
                document.querySelector(".dashboard-transaction-statistics-loading").innerHTML = '';
                apSetCsrf(res.csrf_token);

                if (res.status === 'true') {
                    if (chartTransactionStatistics) chartTransactionStatistics.destroy();

                    chartTransactionStatistics = new ApexCharts(
                        document.getElementById("chart-transaction-statistics"),
                        {
                            chart: { type: "area", height: 265, fontFamily: "inherit", toolbar: { show: false }, animations: { enabled: true }, background: 'transparent' },
                            stroke: { width: 2, curve: "smooth", lineCap: "round" },
                            fill: { type: "gradient", gradient: { shadeIntensity: 1, opacityFrom: 0.2, opacityTo: 0.02, stops: [0, 100] } },
                            series: [
                                { name: "Total", data: res.total },
                                { name: "Complete", data: res.complete },
                                { name: "Pending", data: res.pending }
                            ],
                            xaxis: { type: "category", categories: res.labels, labels: { padding: 0, style: { colors: 'rgba(148,163,184,0.5)', fontSize: '11px' } }, axisBorder: { show: false }, axisTicks: { show: false } },
                            yaxis: { labels: { padding: 4, style: { colors: 'rgba(148,163,184,0.5)', fontSize: '11px' } } },
                            grid: { strokeDashArray: 4, borderColor: 'rgba(255,255,255,0.04)', padding: { top: -20, right: 0, left: -4, bottom: -4 } },
                            tooltip: { theme: "dark" },
                            legend: { show: true, position: "bottom", labels: { colors: 'rgba(148,163,184,0.7)' } },
                            colors: ["#818cf8", "#34d399", "#fbbf24"]
                        }
                    );
                    chartTransactionStatistics.render();
                    load_dashboard_gateway_statistics();
                } else {
                    APToast.show({ title: res.title, description: res.message, type: 'error', timeout: 6000 });
                }
            })
            .catch(() => {
                APToast.show({ title: 'Something Wrong!', description: 'For further assistance, please contact our support team.', type: 'error', timeout: 6000 });
            });
    }

    load_dashboard_transaction_statistics();

    function handleFilterChangeTransactionStatistics(value) {
        const custom = document.getElementById('customRange-transaction-statistics');
        if (value === 'custom') { custom.classList.remove('hidden'); }
        else { custom.classList.add('hidden'); load_dashboard_transaction_statistics(); }
    }

    function applyCustomRangeTransactionStatistics() {
        const start = document.getElementById('startDate-transaction-statistics').value;
        const end = document.getElementById('endDate-transaction-statistics').value;
        if (!start && !end) {
            APToast.show({ title: 'Action required', description: 'Please select at least one date', type: 'warning', timeout: 6000 });
        } else { load_dashboard_transaction_statistics(); }
    }

    // --- Gateway Statistics ---
    function load_dashboard_gateway_statistics() {
        const el = document.getElementById('filterDropdown-gateway-statistics');
        if (el) el.classList.add('hidden');

        var csrf_token_default = apGetCsrf();
        var date = document.getElementById('dateFilter-gateway-statistics').value;
        var start = document.getElementById('startDate-gateway-statistics')?.value || '';
        var end = document.getElementById('endDate-gateway-statistics')?.value || '';

        document.querySelector(".dashboard-gateway-statistics-loading").innerHTML = '<div class="op-spinner" style="width:16px;height:16px;border-width:2px"></div>';

        const params = new URLSearchParams();
        params.append('action', 'dashboard-gateway-statistics');
        params.append('csrf_token', csrf_token_default);
        params.append('date', date);
        if (start) params.append('start', start);
        if (end) params.append('end', end);

        fetch('<?php echo $site_url . $path_admin ?>/dashboard', { method: 'POST', body: params })
            .then(response => response.json())
            .then(res => {
                document.querySelector(".dashboard-gateway-statistics-loading").innerHTML = '';
                apSetCsrf(res.csrf_token);

                if (res.status === 'true') {
                    if (chartGatewayStatistics) chartGatewayStatistics.destroy();
                    const data = res.gateway_labels.map(label => res.data[label] ? res.data[label].reduce((a, b) => a + b, 0) : 0);

                    chartGatewayStatistics = new ApexCharts(
                        document.getElementById("chart-gateway-statistics"),
                        {
                            chart: { type: "donut", height: 270, fontFamily: "inherit", sparkline: { enabled: true }, animations: { enabled: true }, background: 'transparent' },
                            series: data,
                            labels: res.gateway_labels,
                            colors: res.colors,
                            tooltip: { theme: "dark", fillSeriesColor: false },
                            plotOptions: {
                                pie: {
                                    donut: {
                                        size: '72%',
                                        labels: {
                                            show: true,
                                            name: { show: true, color: 'rgba(148,163,184,0.7)', fontSize: '12px' },
                                            value: { show: true, color: '#e2e8f0', fontSize: '20px', fontWeight: 700 },
                                            total: { show: true, label: 'Gateways', color: 'rgba(148,163,184,0.5)', fontSize: '12px',
                                                formatter: function(w) { return w.globals.seriesTotals.reduce((a, b) => a + b, 0); }
                                            }
                                        }
                                    }
                                }
                            },
                            grid: { strokeDashArray: 4 },
                            legend: { show: true, position: "bottom", offsetY: 8, labels: { colors: 'rgba(148,163,184,0.7)' } },
                            stroke: { width: 2, colors: ['#0f172a'] }
                        }
                    );
                    chartGatewayStatistics.render();
                } else {
                    APToast.show({ title: res.title, description: res.message, type: 'error', timeout: 6000 });
                }
            })
            .catch(() => {
                APToast.show({ title: 'Something Wrong!', description: 'For further assistance, please contact our support team.', type: 'error', timeout: 6000 });
            });
    }

    function handleFilterChangeGatewayStatistics(value) {
        const custom = document.getElementById('customRange-gateway-statistics');
        if (value === 'custom') { custom.classList.remove('hidden'); }
        else { custom.classList.add('hidden'); load_dashboard_gateway_statistics(); }
    }

    function applyCustomRangeGatewayStatistics() {
        const start = document.getElementById('startDate-gateway-statistics').value;
        const end = document.getElementById('endDate-gateway-statistics').value;
        if (!start && !end) {
            APToast.show({ title: 'Action required', description: 'Please select at least one date', type: 'warning', timeout: 6000 });
        } else { load_dashboard_gateway_statistics(); }
    }

    function toggleFilter(id) {
        const el = document.getElementById(id);
        el.classList.toggle('hidden');
    }
</script>
