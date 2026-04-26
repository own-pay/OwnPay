<?php
    if (!defined('OWNPAY_INIT')) {
        http_response_code(403);
        exit('Direct access not allowed');
    }

    if (!canAccessPage(json_decode($global_response_permission['response'][0]['permission'], true), 'payment_link', $global_user_response['response'][0]['role'])) {
        http_response_code(403);
        exit('Access denied. You need permission to perform this action. Please contact the admin.');
    }
?>

<div class="op-page-header">
    <div>
        <div class="op-page-pretitle">Payment Link</div>
        <h2 class="op-page-title">Payment Link</h2>
    </div>
    <div class="flex items-center gap-3">
        <span class="global-loaderSpinner"></span>
        <button data-modal-target="modal-createItem" data-modal-toggle="modal-createItem" class="op-btn-secondary">
            <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 me-1" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 15l6 -6" /><path d="M11 6l.463 -.536a5 5 0 0 1 7.071 7.072l-.534 .464" /><path d="M13 18l-.397 .534a5.068 5.068 0 0 1 -7.127 0a4.972 4.972 0 0 1 0 -7.071l.524 -.463" /></svg>
            <span class="hidden sm:inline">Default Link</span>
        </button>
        <span onclick="load_content('Create Payment Link','<?php echo $site_url.$path_admin ?>/payment-link/create','nav-item-payment-link')" class="<?= hasPermission(json_decode($global_response_permission['response'][0]['permission'], true), 'payment_link', 'create', $global_user_response['response'][0]['role']) ? '' : 'hidden' ?>">
            <button class="op-btn-primary">
                <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 me-1" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 5l0 14" /><path d="M5 12l14 0" /></svg>
                <span class="hidden sm:inline">Create Payment Link</span>
            </button>
        </span>
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
                        <option value="inactive">Inactive</option>
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
                    <th>Product</th>
                    <th>Quantity</th>
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
                    <?= hasPermission(json_decode($global_response_permission['response'][0]['permission'], true), 'payment_link', 'delete', $global_user_response['response'][0]['role']) ? '<option value="deleted">Delete Selected</option>' : '' ?>
                    <?= hasPermission(json_decode($global_response_permission['response'][0]['permission'], true), 'payment_link', 'edit', $global_user_response['response'][0]['role']) ? '<option value="activated">Active Selected</option>' : '' ?>
                    <?= hasPermission(json_decode($global_response_permission['response'][0]['permission'], true), 'payment_link', 'edit', $global_user_response['response'][0]['role']) ? '<option value="inactivated">Inactive Selected</option>' : '' ?>
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
                <input type="hidden" name="action" value="paymentLink-manageStatus">
                <input type="hidden" name="csrf_token" value="<?= $csrf_token; ?>">
                <div class="flex items-center justify-between p-4 border-b border-gray-200 dark:border-gray-700">
                    <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Manage Status</h3>
                    <button type="button" class="text-gray-400 hover:text-gray-600" data-modal-hide="model-manageStatus">
                        <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd"></path></svg>
                    </button>
                </div>
                <div class="p-4">
                    <input type="hidden" name="paymentLink-id">
                    <label class="op-label">Status <span class="text-red-500">*</span></label>
                    <select class="op-select" name="status" id="paymentLink-status">
                        <?= hasPermission(json_decode($global_response_permission['response'][0]['permission'], true), 'payment_link', 'edit', $global_user_response['response'][0]['role']) ? '<option value="active">Active</option>' : '' ?>
                        <?= hasPermission(json_decode($global_response_permission['response'][0]['permission'], true), 'payment_link', 'edit', $global_user_response['response'][0]['role']) ? '<option value="inactive">Inactive</option>' : '' ?>
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

<!-- Default Link Modal -->
<div id="modal-createItem" tabindex="-1" class="fixed top-0 left-0 right-0 z-50 hidden w-full p-4 overflow-x-hidden overflow-y-auto md:inset-0 h-[calc(100%-1rem)] max-h-full flex items-center justify-center">
    <div class="relative w-full max-w-md">
        <div class="relative bg-white rounded-lg shadow-sm dark:bg-gray-800">
            <div class="flex items-center justify-between p-4 border-b border-gray-200 dark:border-gray-700">
                <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Default Link</h3>
                <button type="button" class="text-gray-400 hover:text-gray-600" data-modal-hide="modal-createItem">
                    <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd"></path></svg>
                </button>
            </div>
            <div class="p-4 space-y-4">
                <div>
                    <label class="op-label">Currency <span class="text-red-500">*</span></label>
                    <?php
                        $defaultCurrency = get_env('payment-link-default-currency', $global_response_brand['response'][0]['brand_id']);
                        $activeCurrency = empty($defaultCurrency) ? $global_brand_currency_code : $defaultCurrency;
                    ?>
                    <select class="op-select DefaultCurrency" onchange="DefaultchangeCurrency()">
                        <?php
                            $response_brand = json_decode(getData($db_prefix . 'currency', 'WHERE brand_id ="'.$global_response_brand['response'][0]['brand_id'].'" ORDER BY 1 DESC'), true);
                            if ($response_brand['status'] == true) {
                                foreach ($response_brand['response'] as $row) {
                        ?>
                                    <option value="<?php echo $row['code'] ?>" <?php echo ($activeCurrency === $row['code']) ? 'selected' : '';?>><?php echo $row['code'] ?></option>
                        <?php
                                }
                            }
                        ?>
                    </select>
                </div>

                <div>
                    <label class="op-label">Payment Link URL <span class="text-red-500">*</span></label>
                    <div class="flex">
                        <input type="text" class="op-input rounded-e-none DefaultPaymentLink" readonly value="<?php echo $site_url?>payment-link/default/<?php echo $global_response_brand['response'][0]['brand_id']?>">
                        <button class="inline-flex items-center px-3 text-sm text-gray-500 bg-gray-100 border border-s-0 border-gray-300 rounded-e-lg hover:bg-gray-200 dark:bg-gray-600 dark:text-gray-400 dark:border-gray-600 dark:hover:bg-gray-500" type="button" onclick="copyDefaultPaymentLink()">
                            <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M7 9.667a2.667 2.667 0 0 1 2.667 -2.667h8.666a2.667 2.667 0 0 1 2.667 2.667v8.666a2.667 2.667 0 0 1 -2.667 2.667h-8.666a2.667 2.667 0 0 1 -2.667 -2.667l0 -8.666" /><path d="M4.012 16.737a2.005 2.005 0 0 1 -1.012 -1.737v-10c0 -1.1 .9 -2 2 -2h10c.75 0 1.158 .385 1.5 1" /></svg>
                        </button>
                    </div>
                </div>

                <div class="text-center">
                    <div class="op-card inline-block p-3">
                        <div id="qrcode"></div>
                    </div>
                    <p class="mt-2 text-sm text-gray-500 dark:text-gray-400 DefaultPaymentLinkText">Scan to access payment link for <?php echo $global_brand_currency_code?></p>

                    <div class="flex justify-center gap-2 mt-3">
                        <button type="button" class="op-btn-primary" onclick="downloadQR('png')">Download PNG</button>
                        <button type="button" class="op-btn-secondary" onclick="downloadQR('svg')">Download SVG</button>
                    </div>
                </div>
            </div>
            <div class="flex items-center justify-end p-4 border-t border-gray-200 dark:border-gray-700">
                <button class="op-btn-secondary" data-modal-hide="modal-createItem">Cancel</button>
            </div>
        </div>
    </div>
</div>

<script nonce="<?= $csp_nonce ?? '' ?>" data-cfasync="false">
    window.OP_DASHBOARD_URL = '<?php echo $site_url.$path_admin ?>/dashboard';
    let currentPage = 1;

    function copyDefaultPaymentLink(){ copyContent(document.querySelector(".DefaultPaymentLink").value, 'Copied!', 'Payment Link copied successfully.'); }

    function DefaultchangeCurrency(){
        var DefaultCurrency = document.querySelector(".DefaultCurrency").value;
        document.querySelector(".DefaultPaymentLink").value = '<?php echo $site_url?>payment-link/default/<?php echo $global_response_brand['response'][0]['brand_id']?>';
        const text = '<?php echo $site_url?>payment-link/default/<?php echo $global_response_brand['response'][0]['brand_id']?>';
        document.getElementById('qrcode').innerHTML = "";
        qr = new QRCode(document.getElementById("qrcode"), { text: text, width: 200, height: 200 });

        opFetch('paymentLink-defaultLinkCurrency', { DefaultCurrency })
            .then(response => { if (response.status !== 'true') apToast('error', response.title, response.message); })
            .catch(err => apToastError());
        document.querySelector(".DefaultPaymentLinkText").innerHTML = 'Scan to access payment link for '+DefaultCurrency;
    }

    function downloadQR(type) {
        const qrContainer = document.getElementById('qrcode');
        const canvas = qrContainer.querySelector('canvas');
        const img = qrContainer.querySelector('img');

        if (type === 'png') {
            let dataURL = canvas ? canvas.toDataURL("image/png") : (img ? img.src : null);
            if (!dataURL) { alert('QR code not found'); return; }
            const a = document.createElement('a'); a.href = dataURL; a.download = 'payment-link-qr.png'; document.body.appendChild(a); a.click(); document.body.removeChild(a);
        }
        if (type === 'svg') {
            if (!canvas) { alert('SVG download requires canvas QR'); return; }
            const svg = `<svg xmlns="http://www.w3.org/2000/svg" width="${canvas.width}" height="${canvas.height}"><image href="${canvas.toDataURL('image/png')}" width="${canvas.width}" height="${canvas.height}" /></svg>`;
            const blob = new Blob([svg], { type: 'image/svg+xml;charset=utf-8;' }); const url = URL.createObjectURL(blob);
            const a = document.createElement('a'); a.href = url; a.download = 'payment-link-qr.svg'; document.body.appendChild(a); a.click(); document.body.removeChild(a); URL.revokeObjectURL(url);
        }
    }

    document.querySelector('.form-manageStatus').addEventListener('submit', function(e) {
        e.preventDefault();
        var btnEl = document.querySelector('.btn-manageStatus'); var btn = btnEl.innerHTML;
        btnEl.innerHTML = '<div class="op-spinner" style="width:16px;height:16px;border-width:2px"></div>';
        var formData = new URLSearchParams(new FormData(this)).toString();
        fetch('<?php echo $site_url.$path_admin ?>/dashboard', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: formData })
            .then(r => r.json()).then(response => {
                apRotateCsrf(response.csrf_token); closeAllModals(); btnEl.innerHTML = btn;
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
            opFetch('paymentLink-bulk-action', { actionID, selected_ids: JSON.stringify(selectedRows) })
                .then(response => {
                    closeAllModals(); document.querySelector("#my-action-confirmation-btn").value = ''; document.getElementById("model-bulkActionID").selectedIndex = 0; document.querySelector('.global-loaderSpinner').innerHTML = '';
                    if (response.status === 'true') { document.querySelectorAll('.select-all').forEach(cb => cb.checked = false); document.querySelector('.bulk-action').classList.add('hidden'); apToast('success', response.title, response.message); load_data_list(1); }
                    else { apToast('error', response.title, response.message); }
                }).catch(err => apToastError());
        } else { show_action_confirmation_tab('model-bulkAction-btn', 'Confirm Action', 'Confirm', 'btn-danger'); }
    });

    function initCheckboxTable() {
        const selectAll = document.querySelector('.select-all'); const rowCheckboxes = document.querySelectorAll('.rowCheckbox'); const bulkActionBTN = document.querySelector('.bulk-action');
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
            opFetch('paymentLink-delete', { ItemID }).then(response => {
                closeAllModals(); document.querySelector("#my-action-confirmation-btn").value = ''; btnEl.innerHTML = btn;
                response.status === 'true' ? (apToast('success', response.title, response.message), load_data_list(1)) : apToast('error', response.title, response.message);
            }).catch(err => apToastError());
        } else { show_action_confirmation_tab(btnClass, 'Delete Payment Link', 'Delete', 'btn-danger'); }
    }

    function load_data_list(page = 1){
        currentPage = page;
        var search_input = document.querySelector('.search_input').value;
        var show_limit = document.querySelector('.show_limit').value;
        var filter_status = document.getElementById('filter-status').value;
        var filter_start = document.getElementById('filter-created-from').value;
        var filter_end = document.getElementById('filter-created-until').value;

        document.querySelector(".table-data-list").innerHTML = apSkeletonRows(7);

        opFetch('paymentLink-list', { search_input, show_limit, page, filter_status, filter_start, filter_end })
            .then(res => {
                let html = '';
                if (res.status === 'true') {
                    let allowEdit = <?= hasPermission(json_decode($global_response_permission['response'][0]['permission'], true), 'payment_link', 'edit', $global_user_response['response'][0]['role']) ? 'true' : 'false' ?>;
                    let allowDelete = <?= hasPermission(json_decode($global_response_permission['response'][0]['permission'], true), 'payment_link', 'delete', $global_user_response['response'][0]['role']) ? 'true' : 'false' ?>;

                    res.response.forEach(item => {
                        let badgeClass = 'op-badge-gray';
                        if (item.status === 'active') badgeClass = 'op-badge-success';
                        if (item.status === 'inactive') badgeClass = 'op-badge-danger';

                        let editAttr = allowEdit ? `class="cursor-pointer" onclick="load_content('Edit Payment Link','<?php echo $site_url.$path_admin ?>/payment-link/edit?p_id=${item.id}','nav-item-payment-link')"` : '';
                        let deleteAttr = allowDelete ? `onclick="deleteItem('${item.id}')"` : '';
                        let changeStatusAttr = allowEdit ? `onclick="openChangeStatusModel('${item.id}', '${item.status}')"` : '';

                        html += `
                            <tr data-id="${item.id}">
                                <td><input class="op-checkbox rowCheckbox" type="checkbox"></td>
                                <td ${editAttr}>
                                    <div class="font-medium text-gray-900 dark:text-white">${apEscapeHtml(item.title)}</div>
                                    <div class="text-sm text-gray-500 dark:text-gray-400">${apEscapeHtml(item.description)}</div>
                                </td>
                                <td ${editAttr}>${apEscapeHtml(item.quantity)}</td>
                                <td ${editAttr}>${apEscapeHtml(item.amount)}</td>
                                <td ${editAttr}>${apEscapeHtml(item.created_date)}</td>
                                <td ${editAttr}><span class="${badgeClass}">${item.status.charAt(0).toUpperCase() + item.status.slice(1)}</span></td>
                                <td class="text-end">
                                    <button id="dropdownBtn-${item.id}" data-dropdown-toggle="dropdown-${item.id}" class="op-btn-secondary text-xs">Actions</button>
                                    <div id="dropdown-${item.id}" class="z-10 hidden bg-white divide-y divide-gray-100 rounded-lg shadow-lg w-44 dark:bg-gray-700">
                                        <ul class="py-1 text-sm text-gray-700 dark:text-gray-200">
                                            <li><a href="javascript:void(0)" onclick="copyContent('<?php echo $site_url.$path_payment_link ?>/${item.id}', 'Copied!', 'Payment Link copied.')" class="block px-4 py-2 hover:bg-gray-100 dark:hover:bg-gray-600">Copy Link</a></li>
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
                DefaultchangeCurrency();
            }).catch(err => {
                document.querySelector(".table-data-list").innerHTML = '<tr><td colspan="999" class="py-16 text-center"><div class="flex flex-col items-center justify-center"><div class="w-16 h-16 mb-3 rounded-full bg-red-50 dark:bg-red-900/20 flex items-center justify-center"><svg xmlns="http://www.w3.org/2000/svg" class="w-8 h-8 text-red-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M12 9v4m0 4h.01M5.07 19h13.86c1.54 0 2.5-1.67 1.73-3L13.73 4.99c-.77-1.33-2.69-1.33-3.46 0L3.34 16c-.77 1.33.19 3 1.73 3z"/></svg></div><h3 class="text-base font-semibold text-gray-900 dark:text-white mb-1">Connection Error</h3><p class="text-sm text-gray-500 dark:text-gray-400">Unable to load data. Please check your connection and try again.</p></div></td></tr>';
                apToastError(err);
            });
    }

    document.addEventListener('click', function(e) { const btn = e.target.closest('.table-data-list-pagination button[data-page]'); if (btn) load_data_list(parseInt(btn.dataset.page)); });
    load_data_list(1);

    function filter_hide_show_reset(className) { const c = document.querySelector('.'+className); if(!c) return; c.querySelectorAll('input').forEach(i=>i.value=''); c.querySelectorAll('select').forEach(s=>s.selectedIndex=0); load_data_list(1); }
    document.querySelectorAll('.filter-tab-data input, .filter-tab-data select, .search_input, .show_limit').forEach(el => { el.addEventListener('change', () => load_data_list(1)); });

    function openChangeStatusModel(itemID, itemStatus){
        const modal = document.getElementById("model-manageStatus");
        modal.querySelector('input[name="paymentLink-id"]').value = itemID || '';
        const select = modal.querySelector('#paymentLink-status');
        [...select.options].forEach(opt => { opt.selected = (opt.value == itemStatus); });
        const m = new Modal(document.getElementById('model-manageStatus'));
        m.show();
    }
</script>
