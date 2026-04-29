<?php
    if (!defined('OWNPAY_INIT')) {
        http_response_code(403);
        exit('Direct access not allowed');
    }

    if (!\OwnPay\Service\Auth\PermissionService::canAccessPage(json_decode($global_response_permission['response'][0]['permission'], true), 'reports', $global_user_response['response'][0]['role'])) {
        http_response_code(403);
        exit('Access denied. You need permission to perform this action. Please contact the admin.');
    }
?>

<div class="op-page-header">
    <div>
        <div class="op-page-pretitle">Reports</div>
        <h2 class="op-page-title">Reports</h2>
    </div>
    <div class="flex items-center gap-3">
        <div class="reports-loading w-4 h-4 flex-shrink-0 flex items-center justify-center"></div>
        <select class="op-select w-auto" id="report-date" onchange="load_reports()">
            <option value="today">Today</option>
            <option value="yesterday">Yesterday</option>
            <option value="this_week">This week</option>
            <option value="last_week">Last week</option>
            <option value="this_month">This month</option>
            <option value="last_month">Last month</option>
            <option value="this_year" selected>This year</option>
            <option value="previous_year">Previous year</option>
        </select>
        <button id="custom-range-btn" type="button" data-drawer-target="custom-date-range-drawer" data-drawer-show="custom-date-range-drawer" data-drawer-placement="right" aria-controls="custom-date-range-drawer" class="op-btn-primary">
            <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 me-1" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 10a2 2 0 1 0 4 0a2 2 0 0 0 -4 0" /><path d="M6 4v4" /><path d="M6 12v8" /><path d="M10 16a2 2 0 1 0 4 0a2 2 0 0 0 -4 0" /><path d="M12 4v10" /><path d="M12 18v2" /><path d="M16 7a2 2 0 1 0 4 0a2 2 0 0 0 -4 0" /><path d="M18 4v1" /><path d="M18 9v11" /></svg>
            <span class="hidden sm:inline">Custom Range</span>
        </button>
    </div>
</div>

<!-- Date range info -->
<div class="op-card mb-4">
    <div class="op-card-body flex justify-between items-center">
        <p class="text-gray-500 dark:text-gray-400">
            <strong class="text-primary-600 dark:text-primary-400">Financial Report</strong>
            <span id="financial-date-range"></span>
        </p>
    </div>
</div>

<!-- Stat Cards -->
<div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
    <div class="op-card">
        <div class="op-card-body">
            <p class="text-sm text-gray-500 dark:text-gray-400 mb-2">Revenue</p>
            <h1 class="text-2xl font-bold text-gray-900 dark:text-white mb-2" id="revenue-amount"><?php echo $global_brand_currency_symbol;?>0.00</h1>
            <div class="flex items-center text-green-500">
                <span id="revenue-count">0 payments completed</span>
            </div>
        </div>
    </div>

    <div class="op-card">
        <div class="op-card-body">
            <p class="text-sm text-gray-500 dark:text-gray-400 mb-2">Success Rate</p>
            <h1 class="text-2xl font-bold text-gray-900 dark:text-white mb-2" id="success-rate">0%</h1>
            <div class="flex items-center" id="success-rate-indicator">
                <span id="success-rate-text">0 total transactions</span>
            </div>
        </div>
    </div>

    <div class="op-card">
        <div class="op-card-body">
            <p class="text-sm text-gray-500 dark:text-gray-400 mb-2">Average Transaction</p>
            <h1 class="text-2xl font-bold text-gray-900 dark:text-white mb-2" id="avg-transaction"><?php echo $global_brand_currency_symbol;?>0.00</h1>
            <div class="flex items-center text-blue-500">
                <span>Average payment amount</span>
            </div>
        </div>
    </div>
</div>

<!-- Custom Date Range Drawer -->
<div id="custom-date-range-drawer" tabindex="-1" aria-labelledby="drawer-right-label" class="fixed top-0 right-0 z-50 h-screen p-4 overflow-y-auto w-80 bg-white dark:bg-gray-800 transition-transform translate-x-full border-l border-gray-200 dark:border-gray-700">
    <div class="flex items-center justify-between mb-4">
        <h5 id="drawer-right-label" class="text-lg font-semibold text-gray-900 dark:text-white">Custom Date Range</h5>
        <button type="button" data-drawer-hide="custom-date-range-drawer" aria-controls="custom-date-range-drawer" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300">
            <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd"></path></svg>
        </button>
    </div>
    <div class="flex flex-col h-[calc(100%-60px)]">
        <div class="flex-1 space-y-4">
            <div>
                <label class="op-label">Start Date <span class="text-red-500">*</span></label>
                <input type="date" class="op-input" id="custom-date-range-offcanvas-start-date">
            </div>
            <div>
                <label class="op-label">End Date <span class="text-red-500">*</span></label>
                <input type="date" class="op-input" id="custom-date-range-offcanvas-end-date">
            </div>
        </div>
        <div class="border-t border-gray-200 dark:border-gray-700 pt-4 mt-4 flex gap-2">
            <button type="button" data-drawer-hide="custom-date-range-drawer" aria-controls="custom-date-range-drawer" class="op-btn-secondary w-1/2">Cancel</button>
            <button id="custom-date-range-offcanvas-applyDateFilter" type="button" class="op-btn-primary w-1/2">Apply Filter</button>
        </div>
    </div>
</div>

<script nonce="<?= $csp_nonce ?? '' ?>" data-cfasync="false">
    window.OP_DASHBOARD_URL = '<?php echo $site_url.$path_admin ?>/dashboard';

    function load_reports(){
        var date = document.getElementById('report-date').value;
        const start = document.getElementById('custom-date-range-offcanvas-start-date').value;
        const end   = document.getElementById('custom-date-range-offcanvas-end-date').value;

        document.querySelector(".reports-loading").innerHTML = '<div class="op-spinner" style="width:16px;height:16px;border-width:2px"></div>';
      
        opFetch('reports', { date: date, start: start, end: end })
            .then(res => {
                document.querySelector(".reports-loading").innerHTML = '';
                document.getElementById('custom-date-range-offcanvas-start-date').value = '';
                document.getElementById('custom-date-range-offcanvas-end-date').value = '';

                if (res.status === 'true') {
                    document.getElementById('financial-date-range').innerText = res.date_range;
                    document.getElementById('revenue-amount').innerText = '<?php echo $global_brand_currency_symbol;?>' + res.revenue;
                    document.getElementById('revenue-count').innerText = res.completed + ' payments completed';
                    document.getElementById('success-rate').innerText = res.success_rate + '%';

                    let indicator = document.getElementById('success-rate-indicator');
                    let text = document.getElementById('success-rate-text');

                    indicator.classList.remove('text-green-500','text-red-500','text-gray-500');

                    if (res.success_trend === 'up') {
                        indicator.classList.add('text-green-500');
                        text.innerText = `Improved from ${res.prev_success_rate}%`;
                    } else if (res.success_trend === 'down') {
                        indicator.classList.add('text-red-500');
                        text.innerText = `Dropped from ${res.prev_success_rate}%`;
                    } else {
                        indicator.classList.add('text-gray-500');
                        text.innerText = `No change from ${res.prev_success_rate}%`;
                    }

                    document.getElementById('avg-transaction').innerText = '<?php echo $global_brand_currency_symbol;?>' + res.average;
                } else {
                    APToast.show({ title: res.title, description: res.message, type: 'error', timeout: 6000 });
                }
            })
            .catch(err => apToastError());
    }

    load_reports();

    document.getElementById('custom-date-range-offcanvas-applyDateFilter').addEventListener('click', function () {
        load_reports();
        document.getElementById('custom-date-range-drawer').classList.add('translate-x-full');
    });
</script>
