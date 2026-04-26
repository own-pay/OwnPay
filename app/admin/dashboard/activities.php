<?php
if (!defined('OWNPAY_INIT')) {
    http_response_code(403);
    exit('Direct access not allowed');
}
?>

<div class="op-page-header">
    <div>
        <div class="op-page-pretitle">Audit</div>
        <h2 class="op-page-title">Activity Log</h2>
    </div>
</div>

<div class="op-card">
    <!-- Filter Bar -->
    <div class="border-b border-gray-200 dark:border-gray-700">
        <div class="filter-tab-data hidden p-4">
            <div class="flex justify-between items-center mb-3">
                <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Filters</h3>
                <button class="text-sm text-red-500 hover:underline cursor-pointer" onclick="filter_hide_show_reset('filter-tab-data')">Reset</button>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                <div>
                    <label class="op-label">Status</label>
                    <select class="op-select" id="filter-status">
                        <option value="">All</option>
                        <option value="active">Active</option>
                        <option value="expired">Expired</option>
                    </select>
                </div>
                <div>
                    <label class="op-label">Created From</label>
                    <input type="date" class="op-input" id="filter-created-from">
                </div>
                <div>
                    <label class="op-label">Created Until</label>
                    <input type="date" class="op-input" id="filter-created-until">
                </div>
            </div>
        </div>
        <div class="flex justify-end items-center h-12 px-4">
            <button onclick="filter_hide_show('filter-tab-data')" class="p-2 text-gray-500 rounded-lg hover:bg-gray-100 dark:text-gray-400 dark:hover:bg-gray-700">
                <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h16v2.172a2 2 0 0 1 -.586 1.414l-4.414 4.414v7l-6 2v-8.5l-4.48 -4.928a2 2 0 0 1 -.52 -1.345v-2.227z" /></svg>
            </button>
        </div>
    </div>

    <!-- Search/Limit Bar -->
    <div class="op-card-body border-b border-gray-200 dark:border-gray-700 py-3">
        <div class="flex flex-col md:flex-row justify-between items-center gap-3">
            <div class="text-sm text-gray-500 dark:text-gray-400 flex items-center gap-2">
                Show <input type="number" min="1" max="100" class="op-input w-16 text-center show_limit" value="8"> entries
            </div>
            <div class="text-sm text-gray-500 dark:text-gray-400 flex items-center gap-2">
                Search: <input type="text" class="op-input w-48 search_input">
            </div>
        </div>
    </div>

    <!-- Table -->
    <div class="overflow-x-auto">
        <table class="op-table">
            <thead>
                <tr>
                    <th>Browser</th>
                    <th>Device</th>
                    <th>IP</th>
                    <th>Date</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody class="table-data-list"></tbody>
        </table>
    </div>

    <!-- Footer -->
    <div class="op-card-body border-t border-gray-200 dark:border-gray-700">
        <div class="flex flex-col sm:flex-row justify-between items-center gap-2">
            <p class="text-sm text-gray-500 dark:text-gray-400 table-data-list-entries"></p>
            <div class="table-data-list-pagination"></div>
        </div>
    </div>
</div>

<script nonce="<?= $csp_nonce ?? '' ?>" data-cfasync="false">
    window.OP_DASHBOARD_URL = '<?php echo $site_url.$path_admin ?>/dashboard';

    function load_data_list(page = 1){
        currentPage = page;

        var search_input = document.querySelector('.search_input').value;
        var show_limit = document.querySelector('.show_limit').value;
        var filter_status = document.getElementById('filter-status').value;
        var filter_start = document.getElementById('filter-created-from').value;
        var filter_end = document.getElementById('filter-created-until').value;

        document.querySelector(".table-data-list").innerHTML = apSkeletonRows(5);

        opFetch('activities-list', { search_input, show_limit, page, filter_status, filter_start, filter_end })
            .then(res => {
                let html = '';
                if (res.status === 'true') {
                    res.response.forEach(item => {
                        let badgeClass = 'op-badge-gray';
                        if (item.status === 'active') badgeClass = 'op-badge-success';
                        if (item.status === 'expired') badgeClass = 'op-badge-info';

                        let bgStyle = '';
                        if (item.isequal === 'matched') bgStyle = 'style="border-left: 2px solid #7c3aed; background-color: rgba(124,58,237,0.05);"';

                        html += `
                            <tr ${bgStyle}>
                                <td class="text-gray-500 dark:text-gray-400">${item.browser}</td>
                                <td>${item.device}</td>
                                <td>${item.ip}</td>
                                <td>${item.created_date}</td>
                                <td><span class="${badgeClass}">${item.status.charAt(0).toUpperCase() + item.status.slice(1)}</span></td>
                            </tr>
                        `;
                    });
                    document.querySelector(".table-data-list").innerHTML = html;
                    document.querySelector(".table-data-list-entries").innerHTML = res.datatableInfo;
                    document.querySelector(".table-data-list-pagination").innerHTML = res.pagination;
                } else {
                    document.querySelector(".table-data-list").innerHTML = `<tr><td colspan="5">${apEmptyState(res.title, res.message)}</td></tr>`;
                    document.querySelector(".table-data-list-entries").innerHTML = 'Showing <strong>0 to 0</strong> of <strong>0 entries</strong>';
                    document.querySelector(".table-data-list-pagination").innerHTML = '';
                }
            })
            .catch(err => {
                document.querySelector(".table-data-list").innerHTML = '<tr><td colspan="999" class="py-16 text-center"><div class="flex flex-col items-center justify-center"><div class="w-16 h-16 mb-3 rounded-full bg-red-50 dark:bg-red-900/20 flex items-center justify-center"><svg xmlns="http://www.w3.org/2000/svg" class="w-8 h-8 text-red-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M12 9v4m0 4h.01M5.07 19h13.86c1.54 0 2.5-1.67 1.73-3L13.73 4.99c-.77-1.33-2.69-1.33-3.46 0L3.34 16c-.77 1.33.19 3 1.73 3z"/></svg></div><h3 class="text-base font-semibold text-gray-900 dark:text-white mb-1">Connection Error</h3><p class="text-sm text-gray-500 dark:text-gray-400">Unable to load data. Please check your connection and try again.</p></div></td></tr>';
                apToastError(err);
            });
    }

    document.addEventListener('click', function(e) {
        const btn = e.target.closest('.table-data-list-pagination button[data-page]');
        if (btn) load_data_list(parseInt(btn.dataset.page));
    });

    load_data_list(1);

    function filter_hide_show_reset(className) {
        const container = document.querySelector('.' + className);
        if (!container) return;
        container.querySelectorAll('input').forEach(input => input.value = '');
        container.querySelectorAll('select').forEach(select => select.selectedIndex = 0);
        load_data_list(1);
    }

    document.querySelectorAll('.filter-tab-data input, .filter-tab-data select, .search_input, .show_limit').forEach(el => {
        el.addEventListener('change', () => load_data_list(1));
    });
</script>
