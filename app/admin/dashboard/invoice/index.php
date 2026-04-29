<?php
    if (!defined('OWNPAY_INIT')) {
        http_response_code(403);
        exit('Direct access not allowed');
    }

    if (!\OwnPay\Service\Auth\PermissionService::canAccessPage(json_decode($global_response_permission['response'][0]['permission'], true), 'invoice', $global_user_response['response'][0]['role'])) {
        http_response_code(403);
        exit('Access denied. You need permission to perform this action. Please contact the admin.');
    }
?>

<div class="op-page-header">
    <div>
        <div class="op-page-pretitle">Invoices</div>
        <h2 class="op-page-title">Invoices</h2>
    </div>
    <div class="flex items-center gap-3">
        <span class="global-loaderSpinner"></span>
        <span onclick="load_content('Create Invoice','<?php echo $site_url.$path_admin ?>/invoice/create','nav-item-invoice')" class="<?= \OwnPay\Service\Auth\PermissionService::hasPermission(json_decode($global_response_permission['response'][0]['permission'], true), 'invoice', 'create', $global_user_response['response'][0]['role']) ? '' : 'hidden' ?>">
            <button class="op-btn-primary">
                <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 me-1" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 5l0 14" /><path d="M5 12l14 0" /></svg>
                <span class="hidden sm:inline">Create Invoice</span>
            </button>
        </span>
    </div>
</div>

<!-- Status Tabs -->
<div class="flex justify-center mb-4">
    <div class="op-card inline-block p-1">
        <div class="flex gap-1" id="statusTabs">
            <button class="op-tab active" data-type="all">All</button>
            <button class="op-tab" data-type="paid">Paid</button>
            <button class="op-tab" data-type="unpaid">Unpaid</button>
            <button class="op-tab" data-type="refunded">Refunded</button>
            <button class="op-tab" data-type="canceled">Canceled</button>
        </div>
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
                        <option value="paid">Paid</option>
                        <option value="unpaid">Unpaid</option>
                        <option value="refunded">Refunded</option>
                        <option value="canceled">Canceled</option>
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
                <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16v2.172a2 2 0 0 1 -.586 1.414l-4.414 4.414v7l-6 2v-8.5l-4.48 -4.928a2 2 0 0 1 -.52 -1.345v-2.227z" /></svg>
            </button>
        </div>
    </div>

    <!-- Search/Limit Bar -->
    <div class="op-card-body border-b border-gray-200 dark:border-gray-700 py-3">
        <div class="flex flex-col md:flex-row justify-between items-center gap-3">
            <div class="text-sm text-gray-500 dark:text-gray-400 flex items-center gap-2">
                Show <input type="number" min="1" max="100" class="op-input w-16 text-center show_limit" value="8"> entries
            </div>
            <div class="flex items-center gap-2">
                <div class="text-sm text-gray-500 dark:text-gray-400 flex items-center gap-2">
                    Search: <input type="text" class="op-input w-48 search_input">
                </div>
                <button class="op-btn-danger hidden bulk-action" data-modal-target="model-bulkAction" data-modal-toggle="model-bulkAction">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 me-1" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 5a2 2 0 0 1 2 -2h14a2 2 0 0 1 2 2v14a2 2 0 0 1 -2 2h-14a2 2 0 0 1 -2 -2v-14" /></svg>
                    <span id="bulkActionBTN-count">(0)</span>
                </button>
            </div>
        </div>
    </div>

    <!-- Table -->
    <div class="overflow-x-auto">
        <table class="op-table">
            <thead>
                <tr>
                    <th class="w-8"><input class="op-checkbox select-all" type="checkbox"></th>
                    <th>Customer</th>
                    <th>Items</th>
                    <th>Amount</th>
                    <th>Created Date</th>
                    <th>Status</th>
                    <th></th>
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

<!-- Bulk Action Modal -->
<div id="model-bulkAction" tabindex="-1" class="fixed top-0 left-0 right-0 z-50 hidden w-full p-4 overflow-x-hidden overflow-y-auto md:inset-0 h-[calc(100%-1rem)] max-h-full flex items-center justify-center">
    <div class="relative w-full max-w-md">
        <div class="relative bg-white rounded-lg shadow-sm dark:bg-gray-800">
            <div class="flex items-center justify-between p-4 border-b border-gray-200 dark:border-gray-700">
                <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Action for Selected Items</h3>
                <button type="button" class="text-gray-400 hover:text-gray-600" data-modal-hide="model-bulkAction">
                    <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd"></path></svg>
                </button>
            </div>
            <div class="p-4">
                <label class="op-label">Action <span class="text-red-500">*</span></label>
                <select class="op-select" id="model-bulkActionID">
                    <option value="" selected>Select a Action</option>
                    <?= \OwnPay\Service\Auth\PermissionService::hasPermission(json_decode($global_response_permission['response'][0]['permission'], true), 'invoice', 'delete', $global_user_response['response'][0]['role']) ? '<option value="deleted">Delete Selected</option>' : '' ?>
                </select>
            </div>
            <div class="flex items-center justify-between p-4 border-t border-gray-200 dark:border-gray-700">
                <button type="button" class="op-btn-secondary" data-modal-hide="model-bulkAction">Close</button>
                <button type="button" class="op-btn-primary model-bulkAction-btn">Confirm</button>
            </div>
        </div>
    </div>
</div>

<!-- Manage Status Modal -->
<div id="model-manageStatus" tabindex="-1" class="fixed top-0 left-0 right-0 z-50 hidden w-full p-4 overflow-x-hidden overflow-y-auto md:inset-0 h-[calc(100%-1rem)] max-h-full flex items-center justify-center">
    <div class="relative w-full max-w-md">
        <div class="relative bg-white rounded-lg shadow-sm dark:bg-gray-800">
            <form action="" class="form-manageStatus">
                <input type="hidden" name="action" value="invoice-manageStatus">
                <input type="hidden" name="csrf_token" value="<?= $csrf_token; ?>">
                <div class="flex items-center justify-between p-4 border-b border-gray-200 dark:border-gray-700">
                    <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Manage Status</h3>
                    <button type="button" class="text-gray-400 hover:text-gray-600" data-modal-hide="model-manageStatus">
                        <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd"></path></svg>
                    </button>
                </div>
                <div class="p-4">
                    <input type="hidden" name="invoice-id">
                    <label class="op-label">Status <span class="text-red-500">*</span></label>
                    <select class="op-select" name="status" id="invoice-status">
                        <?= \OwnPay\Service\Auth\PermissionService::hasPermission(json_decode($global_response_permission['response'][0]['permission'], true), 'invoice', 'edit', $global_user_response['response'][0]['role']) ? '<option value="paid">Paid</option>' : '' ?>
                        <?= \OwnPay\Service\Auth\PermissionService::hasPermission(json_decode($global_response_permission['response'][0]['permission'], true), 'invoice', 'edit', $global_user_response['response'][0]['role']) ? '<option value="unpaid">Unpaid</option>' : '' ?>
                        <?= \OwnPay\Service\Auth\PermissionService::hasPermission(json_decode($global_response_permission['response'][0]['permission'], true), 'invoice', 'edit', $global_user_response['response'][0]['role']) ? '<option value="refunded">Refund</option>' : '' ?>
                        <?= \OwnPay\Service\Auth\PermissionService::hasPermission(json_decode($global_response_permission['response'][0]['permission'], true), 'invoice', 'edit', $global_user_response['response'][0]['role']) ? '<option value="canceled">Cancel</option>' : '' ?>
                    </select>
                </div>
                <div class="flex items-center justify-between p-4 border-t border-gray-200 dark:border-gray-700">
                    <button type="button" class="op-btn-secondary" data-modal-hide="model-manageStatus">Close</button>
                    <button class="op-btn-primary btn-manageStatus">Confirm</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script nonce="<?= $csp_nonce ?? '' ?>" data-cfasync="false">
    window.OP_DASHBOARD_URL = '<?php echo $site_url.$path_admin ?>/dashboard';
    let currentPage = 1;

    document.querySelector('.form-manageStatus').addEventListener('submit', function(e) {
        e.preventDefault();
        var btnEl = document.querySelector('.btn-manageStatus');
        var btn = btnEl.innerHTML;
        btnEl.innerHTML = '<div class="op-spinner" style="width:16px;height:16px;border-width:2px"></div>';

        var formData = new URLSearchParams(new FormData(this)).toString();
        fetch('<?php echo $site_url.$path_admin ?>/dashboard', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: formData })
            .then(r => r.json())
            .then(response => {
                apRotateCsrf(response.csrf_token);
                closeAllModals();
                btnEl.innerHTML = btn;
                response.status === 'true' ? (apToast('success', response.title, response.message), load_data_list()) : apToast('error', response.title, response.message);
            }).catch(err => apToastError());
    });

    document.querySelector('.model-bulkAction-btn').addEventListener('click', function() {
        var my_action_confirmation_btn = document.querySelector("#my-action-confirmation-btn")?.value || '';
        var actionID = document.getElementById("model-bulkActionID").value;
        if(actionID === ""){ apToast('error', 'Action Required', "You haven't selected any action."); return; }
        const selectedRows = Array.from(document.querySelectorAll('.rowCheckbox:checked')).map(cb => cb.closest('tr').dataset.id);
        if(my_action_confirmation_btn !== ""){
            document.querySelector('.global-loaderSpinner').innerHTML = '<div class="op-spinner"></div>';
            opFetch('invoice-bulk-action', { actionID, selected_ids: JSON.stringify(selectedRows) })
                .then(response => {
                    closeAllModals(); document.querySelector("#my-action-confirmation-btn").value = ''; document.getElementById("model-bulkActionID").selectedIndex = 0; document.querySelector('.global-loaderSpinner').innerHTML = '';
                    if (response.status === 'true') { document.querySelectorAll('.select-all').forEach(cb => cb.checked = false); document.querySelector('.bulk-action').classList.add('hidden'); apToast('success', response.title, response.message); load_data_list(1); }
                    else { apToast('error', response.title, response.message); }
                }).catch(err => apToastError());
        } else { show_action_confirmation_tab('model-bulkAction-btn', 'Confirm Action', 'Confirm', 'btn-danger'); }
    });

    function initCheckboxTable() {
        const selectAll = document.querySelector('.select-all');
        const rowCheckboxes = document.querySelectorAll('.rowCheckbox');
        const bulkActionBTN = document.querySelector('.bulk-action');
        function updateSelection() { const selected = document.querySelectorAll('.rowCheckbox:checked'); document.getElementById("bulkActionBTN-count").innerHTML = `(${selected.length})`; bulkActionBTN.classList.toggle('hidden', selected.length === 0); }
        selectAll.addEventListener('change', () => { rowCheckboxes.forEach(cb => cb.checked = selectAll.checked); updateSelection(); });
        rowCheckboxes.forEach(cb => { cb.addEventListener('change', () => { selectAll.checked = rowCheckboxes.length === document.querySelectorAll('.rowCheckbox:checked').length; updateSelection(); }); });
    }

    function deleteItem(ItemID){
        var my_action_confirmation_btn = document.querySelector("#my-action-confirmation-btn")?.value || '';
        var btnClass = 'btnDeleteItem-'+ItemID;
        if(my_action_confirmation_btn !== ""){
            var btnEl = document.querySelector('#model-my-action-confirmation-btn'); var btn = btnEl.innerHTML;
            btnEl.innerHTML = '<div class="op-spinner" style="width:16px;height:16px;border-width:2px"></div>';
            opFetch('invoice-delete', { ItemID }).then(response => {
                closeAllModals(); document.querySelector("#my-action-confirmation-btn").value = ''; btnEl.innerHTML = btn;
                response.status === 'true' ? (apToast('success', response.title, response.message), load_data_list(1)) : apToast('error', response.title, response.message);
            }).catch(err => apToastError());
        } else { show_action_confirmation_tab(btnClass, 'Delete Invoice', 'Delete', 'btn-danger'); }
    }

    function load_data_list(page = 1){
        currentPage = page;
        var search_input = document.querySelector('.search_input').value;
        var show_limit = document.querySelector('.show_limit').value;
        var tabType = document.querySelector('#statusTabs .op-tab.active')?.dataset.type;
        var filter_status = document.getElementById('filter-status').value;
        var filter_start = document.getElementById('filter-created-from').value;
        var filter_end = document.getElementById('filter-created-until').value;

        document.querySelector(".table-data-list").innerHTML = apSkeletonRows(5);

        opFetch('invoice-list', { search_input, show_limit, tabType, page, filter_status, filter_start, filter_end })
            .then(res => {
                let html = '';
                if (res.status === 'true') {
                    let allowEdit = <?= \OwnPay\Service\Auth\PermissionService::hasPermission(json_decode($global_response_permission['response'][0]['permission'], true), 'invoice', 'edit', $global_user_response['response'][0]['role']) ? 'true' : 'false' ?>;
                    let allowDelete = <?= \OwnPay\Service\Auth\PermissionService::hasPermission(json_decode($global_response_permission['response'][0]['permission'], true), 'invoice', 'delete', $global_user_response['response'][0]['role']) ? 'true' : 'false' ?>;

                    res.response.forEach(item => {
                        let badgeClass = 'op-badge-gray';
                        if (item.status === 'paid') badgeClass = 'op-badge-success';
                        if (item.status === 'unpaid') badgeClass = 'op-badge-warning';
                        if (item.status === 'refunded') badgeClass = 'op-badge-info';
                        if (item.status === 'canceled') badgeClass = 'op-badge-danger';

                        let editAttr = allowEdit ? `class="cursor-pointer" onclick="load_content('Edit Invoice','<?php echo $site_url.$path_admin ?>/invoice/edit?i_id=${item.id}','nav-item-invoice')"` : '';
                        let deleteAttr = allowDelete ? `onclick="deleteItem('${item.id}')"` : '';
                        let changeStatusAttr = allowEdit ? `onclick="openChangeStatusModel('${item.id}', '${item.status}')"` : '';

                        html += `
                            <tr data-id="${item.id}">
                                <td><input class="op-checkbox rowCheckbox" type="checkbox"></td>
                                <td ${editAttr}>
                                    <div class="font-medium text-gray-900 dark:text-white">${apEscapeHtml(item.name)}</div>
                                    <div class="text-sm text-gray-500 dark:text-gray-400">${item.email && item.email.trim() !== '' ? apEscapeHtml(item.email) : apEscapeHtml(item.mobile)}</div>
                                </td>
                                <td ${editAttr}>${apEscapeHtml(item.items)}</td>
                                <td ${editAttr}>${apEscapeHtml(item.amount)}</td>
                                <td ${editAttr}>${apEscapeHtml(item.created_date)}</td>
                                <td ${editAttr}><span class="${badgeClass}">${item.status.charAt(0).toUpperCase() + item.status.slice(1)}</span></td>
                                <td class="text-end">
                                    <button id="dropdownBtn-${item.id}" data-dropdown-toggle="dropdown-${item.id}" class="op-btn-secondary text-xs">Actions</button>
                                    <div id="dropdown-${item.id}" class="z-10 hidden bg-white divide-y divide-gray-100 rounded-lg shadow-lg w-44 dark:bg-gray-700">
                                        <ul class="py-1 text-sm text-gray-700 dark:text-gray-200">
                                            <li><a href="javascript:void(0)" onclick="copyContent('<?php echo $site_url.$path_invoice ?>/${item.id}', 'Copied!', 'Invoice URL copied.')" class="block px-4 py-2 hover:bg-gray-100 dark:hover:bg-gray-600">Copy Link</a></li>
                                            ${allowEdit ? `<li><a href="javascript:void(0)" ${editAttr} class="block px-4 py-2 hover:bg-gray-100 dark:hover:bg-gray-600">Edit</a></li>` : ''}
                                            ${allowDelete ? `<li><a href="javascript:void(0)" ${deleteAttr} class="btnDeleteItem-${item.id} block px-4 py-2 hover:bg-gray-100 dark:hover:bg-gray-600 text-red-500">Delete</a></li>` : ''}
                                            ${allowEdit ? `<li><a href="javascript:void(0)" ${changeStatusAttr} class="block px-4 py-2 hover:bg-gray-100 dark:hover:bg-gray-600">Change Status</a></li>` : ''}
                                        </ul>
                                    </div>
                                </td>
                            </tr>
                        `;
                    });

                    if (html === '') {
                        html = `<tr><td colspan="7">${apEmptyState(res.title, res.message)}</td></tr>`;
                    }

                    document.querySelector(".table-data-list").innerHTML = html;
                    initCheckboxTable();
                    if (typeof initFlowbite === 'function') initFlowbite();
                    document.querySelector(".table-data-list-entries").innerHTML = res.datatableInfo;
                    document.querySelector(".table-data-list-pagination").innerHTML = res.pagination;
                } else {
                    document.querySelector(".table-data-list").innerHTML = `<tr><td colspan="7">${apEmptyState(res.title, res.message)}</td></tr>`;
                    document.querySelector(".table-data-list-entries").innerHTML = 'Showing <strong>0 to 0</strong> of <strong>0 entries</strong>';
                    document.querySelector(".table-data-list-pagination").innerHTML = '';
                }
            }).catch(err => {
                document.querySelector(".table-data-list").innerHTML = '<tr><td colspan="999" class="py-16 text-center"><div class="flex flex-col items-center justify-center"><div class="w-16 h-16 mb-3 rounded-full bg-red-50 dark:bg-red-900/20 flex items-center justify-center"><svg xmlns="http://www.w3.org/2000/svg" class="w-8 h-8 text-red-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M12 9v4m0 4h.01M5.07 19h13.86c1.54 0 2.5-1.67 1.73-3L13.73 4.99c-.77-1.33-2.69-1.33-3.46 0L3.34 16c-.77 1.33.19 3 1.73 3z"/></svg></div><h3 class="text-base font-semibold text-gray-900 dark:text-white mb-1">Connection Error</h3><p class="text-sm text-gray-500 dark:text-gray-400">Unable to load data. Please check your connection and try again.</p></div></td></tr>';
                apToastError(err);
            });
    }

    document.addEventListener('click', function(e) { const btn = e.target.closest('.table-data-list-pagination button[data-page]'); if (btn) load_data_list(parseInt(btn.dataset.page)); });
    load_data_list(1);

    function filter_hide_show_reset(className) { const c = document.querySelector('.'+className); if(!c) return; c.querySelectorAll('input').forEach(i=>i.value=''); c.querySelectorAll('select').forEach(s=>s.selectedIndex=0); load_data_list(1); }
    document.querySelectorAll('.filter-tab-data input, .filter-tab-data select, .search_input, .show_limit').forEach(el => { el.addEventListener('change', () => load_data_list(1)); });
    document.querySelectorAll('#statusTabs .op-tab').forEach(btn => { btn.addEventListener('click', function() { document.querySelectorAll('#statusTabs .op-tab').forEach(b => b.classList.remove('active')); this.classList.add('active'); load_data_list(1); }); });

    function openChangeStatusModel(itemID, itemStatus){
        const modal = document.getElementById("model-manageStatus");
        modal.querySelector('input[name="invoice-id"]').value = itemID || '';
        const select = modal.querySelector('#invoice-status');
        [...select.options].forEach(opt => { opt.selected = (opt.value == itemStatus); });
        const m = new Modal(document.getElementById('model-manageStatus'));
        m.show();
    }
</script>
