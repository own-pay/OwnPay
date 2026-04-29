<?php
    if (!defined('OWNPAY_INIT')) {
        http_response_code(403);
        exit('Direct access not allowed');
    }

    if (!\OwnPay\Service\Auth\PermissionService::canAccessPage(json_decode($global_response_permission['response'][0]['permission'], true), 'sms_data', $global_user_response['response'][0]['role'])) {
        http_response_code(403);
        exit('Access denied. You need permission to perform this action. Please contact the admin.');
    }
?>

<div class="op-page-header">
    <div>
        <div class="op-page-pretitle">SMS Center</div>
        <h2 class="op-page-title">SMS Data</h2>
    </div>
    <div class="flex items-center gap-3">
        <span class="global-loaderSpinner"></span>
        <span class="<?= \OwnPay\Service\Auth\PermissionService::hasPermission(json_decode($global_response_permission['response'][0]['permission'], true), 'sms_data', 'create', $global_user_response['response'][0]['role']) ? '' : 'hidden' ?>">
            <button data-modal-target="modal-createItem" data-modal-toggle="modal-createItem" class="op-btn-primary">
                <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 me-1" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 5l0 14" /><path d="M5 12l14 0" /></svg>
                <span class="hidden sm:inline">Create SMS Data</span>
            </button>
        </span>
    </div>
</div>

<!-- Status Tabs -->
<div class="flex justify-center mb-4">
    <div class="op-card inline-block p-1">
        <div class="flex gap-1" id="statusTabs">
            <button class="op-tab active" data-type="all">All</button>
            <button class="op-tab" data-type="approved">Approved</button>
            <button class="op-tab" data-type="awaiting-review">Awaiting review</button>
            <button class="op-tab" data-type="used">Used</button>
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
                        <option value="approved">Approved</option>
                        <option value="awaiting-review">Awaiting review</option>
                        <option value="used">Used</option>
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
                    <th>Device Name</th>
                    <th>Payment Method</th>
                    <th>Type</th>
                    <th>Mobile Number</th>
                    <th>Transaction ID</th>
                    <th>Amount</th>
                    <th>Balance</th>
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
                    <?= \OwnPay\Service\Auth\PermissionService::hasPermission(json_decode($global_response_permission['response'][0]['permission'], true), 'sms_data', 'delete', $global_user_response['response'][0]['role']) ? '<option value="deleted">Delete Selected</option>' : '' ?>
                    <?= \OwnPay\Service\Auth\PermissionService::hasPermission(json_decode($global_response_permission['response'][0]['permission'], true), 'sms_data', 'edit', $global_user_response['response'][0]['role']) ? '<option value="approved">Approve Selected</option>' : '' ?>
                    <?= \OwnPay\Service\Auth\PermissionService::hasPermission(json_decode($global_response_permission['response'][0]['permission'], true), 'sms_data', 'edit', $global_user_response['response'][0]['role']) ? '<option value="awaiting-review">Awaiting Review Selected</option>' : '' ?>
                    <?= \OwnPay\Service\Auth\PermissionService::hasPermission(json_decode($global_response_permission['response'][0]['permission'], true), 'sms_data', 'edit', $global_user_response['response'][0]['role']) ? '<option value="used">Use Selected</option>' : '' ?>
                </select>
            </div>
            <div class="flex items-center justify-between p-4 border-t border-gray-200 dark:border-gray-700">
                <button type="button" class="op-btn-secondary" data-modal-hide="model-bulkAction">Close</button>
                <button type="button" class="op-btn-primary model-bulkAction-btn">Confirm</button>
            </div>
        </div>
    </div>
</div>

<!-- Create SMS Data Modal -->
<div id="modal-createItem" tabindex="-1" class="fixed top-0 left-0 right-0 z-50 hidden w-full p-4 overflow-x-hidden overflow-y-auto md:inset-0 h-[calc(100%-1rem)] max-h-full flex items-center justify-center">
    <div class="relative w-full max-w-2xl">
        <div class="relative bg-white rounded-lg shadow-sm dark:bg-gray-800">
            <div class="flex items-center justify-between p-4 border-b border-gray-200 dark:border-gray-700">
                <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Create SMS Data</h3>
                <button type="button" class="text-gray-400 hover:text-gray-600" data-modal-hide="modal-createItem">
                    <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd"></path></svg>
                </button>
            </div>
            <div class="p-4 space-y-4">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="op-label">Device</label>
                        <select class="op-select" name="device">
                            <option value="" selected>Select a device</option>
                            <?php
                                $response_result = json_decode(\OwnPay\Service\System\CrudService::selectLegacy($db_prefix.'device',' WHERE status = :status ORDER BY 1 DESC ', '* FROM', [':status' => 'used']),true);
                                if($response_result['status'] == true){
                                    foreach($response_result['response'] as $row){
                            ?>
                                        <option value="<?php echo $row['device_id']?>"><?php echo $row['name']?></option>
                            <?php
                                    }
                                }
                            ?>
                        </select>
                    </div>
                    <div>
                        <label class="op-label">Entry Type <span class="text-red-500">*</span></label>
                        <select class="op-select" name="entry-type" onchange="itemEntryType('modal-createItem')">
                            <option value="automatic">Automatic</option>
                            <option value="manual">Manual</option>
                        </select>
                    </div>
                    <div>
                        <label class="op-label">Payment Method <span class="text-red-500">*</span></label>
                        <select class="op-select" name="sender_key" onchange="itemPaymentMethod('modal-createItem')">
                            <option value="" selected>Select a payment method</option>
                            <?php
                                $allProviders = senderWhitelist();
                                foreach($allProviders as $key => $provider) {
                            ?>
                                    <option value="<?= htmlspecialchars($key) ?>" data-currency="<?= $provider['currency'] ?>"><?= htmlspecialchars($provider['name']) ?></option>
                            <?php
                                }
                            ?>
                        </select>
                    </div>
                    <div>
                        <label class="op-label">Status <span class="text-red-500">*</span></label>
                        <select class="op-select" name="status">
                            <option value="approved" selected>Approved</option>
                            <option value="awaiting-review">Awaiting review</option>
                            <option value="used">Used</option>
                        </select>
                    </div>
                </div>

                <div class="data_automatic hidden">
                    <div>
                        <label class="op-label">Message (Copy & Paste from SMS) <span class="text-red-500">*</span></label>
                        <textarea class="op-input" rows="3"></textarea>
                    </div>
                </div>

                <div class="data_manual hidden">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="op-label">Type <span class="text-red-500">*</span></label>
                            <select class="op-select" name="type" required>
                                <option value="">Select an option</option>
                                <option value="Personal">Personal</option>
                                <option value="Merchant">Merchant</option>
                                <option value="Agent">Agent</option>
                            </select>
                        </div>
                        <div>
                            <label class="op-label">Amount <span class="text-red-500">*</span></label>
                            <div class="flex">
                                <span class="op-input-group-text">BDT</span>
                                <input type="number" class="op-input rounded-s-none" name="amount" required>
                            </div>
                        </div>
                        <div>
                            <label class="op-label">Phone Number <span class="text-red-500">*</span></label>
                            <input type="text" class="op-input" name="phone_number" required>
                        </div>
                        <div>
                            <label class="op-label">Transaction ID <span class="text-red-500">*</span></label>
                            <input type="text" class="op-input" name="transaction_id" required>
                        </div>
                    </div>
                </div>
            </div>
            <div class="flex items-center justify-between p-4 border-t border-gray-200 dark:border-gray-700">
                <button class="op-btn-secondary" data-modal-hide="modal-createItem">Cancel</button>
                <button class="op-btn-primary modal-createItem-btn">Create</button>
            </div>
        </div>
    </div>
</div>

<!-- Edit SMS Data Modal -->
<div id="modal-editItem" tabindex="-1" class="fixed top-0 left-0 right-0 z-50 hidden w-full p-4 overflow-x-hidden overflow-y-auto md:inset-0 h-[calc(100%-1rem)] max-h-full flex items-center justify-center">
    <div class="relative w-full max-w-2xl">
        <div class="relative bg-white rounded-lg shadow-sm dark:bg-gray-800">
            <div class="flex items-center justify-between p-4 border-b border-gray-200 dark:border-gray-700">
                <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Edit SMS Data</h3>
                <button type="button" class="text-gray-400 hover:text-gray-600" data-modal-hide="modal-editItem">
                    <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd"></path></svg>
                </button>
            </div>
            <div class="p-4 space-y-4">
                <input type="hidden" name="item-id">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="op-label">Device</label>
                        <select class="op-select" name="device">
                            <option value="" selected>Select a device</option>
                            <?php
                                $response_result = json_decode(\OwnPay\Service\System\CrudService::selectLegacy($db_prefix.'device',' WHERE status = :status ORDER BY 1 DESC ', '* FROM', [':status' => 'used']),true);
                                if($response_result['status'] == true){
                                    foreach($response_result['response'] as $row){
                            ?>
                                        <option value="<?php echo $row['device_id']?>"><?php echo $row['name']?></option>
                            <?php
                                    }
                                }
                            ?>
                        </select>
                    </div>
                    <div class="hidden">
                        <label class="op-label">Entry Type <span class="text-red-500">*</span></label>
                        <select class="op-select" name="entry-type" onchange="itemEntryType('modal-editItem')">
                            <option value="automatic">Automatic</option>
                            <option value="manual">Manual</option>
                        </select>
                    </div>
                    <div>
                        <label class="op-label">Payment Method <span class="text-red-500">*</span></label>
                        <select class="op-select" name="sender_key" onchange="itemPaymentMethod('modal-editItem')">
                            <option value="" selected>Select a payment method</option>
                            <?php
                                $allProviders = senderWhitelist();
                                foreach($allProviders as $key => $provider) {
                            ?>
                                    <option value="<?= htmlspecialchars($key) ?>" data-currency="<?= $provider['currency'] ?>"><?= htmlspecialchars($provider['name']) ?></option>
                            <?php
                                }
                            ?>
                        </select>
                    </div>
                    <div>
                        <label class="op-label">Status <span class="text-red-500">*</span></label>
                        <select class="op-select" name="status">
                            <option value="approved" selected>Approved</option>
                            <option value="awaiting-review">Awaiting review</option>
                            <option value="used">Used</option>
                        </select>
                    </div>
                </div>

                <div class="data_automatic hidden">
                    <div class="space-y-3">
                        <div>
                            <label class="op-label">Message (Copy & Paste from SMS) <span class="text-red-500">*</span></label>
                            <textarea class="op-input" rows="3"></textarea>
                        </div>
                        <div class="reason-block hidden">
                            <label class="op-label">Reason</label>
                            <div class="p-3 bg-blue-50 dark:bg-blue-900/30 rounded-lg text-sm text-blue-700 dark:text-blue-300">
                                <span class="reason"></span>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="data_manual hidden">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="op-label">Type <span class="text-red-500">*</span></label>
                            <select class="op-select" name="type" required>
                                <option value="">Select an option</option>
                                <option value="Personal">Personal</option>
                                <option value="Merchant">Merchant</option>
                                <option value="Agent">Agent</option>
                            </select>
                        </div>
                        <div>
                            <label class="op-label">Amount <span class="text-red-500">*</span></label>
                            <div class="flex">
                                <span class="op-input-group-text">BDT</span>
                                <input type="number" class="op-input rounded-s-none" name="amount" required>
                            </div>
                        </div>
                        <div>
                            <label class="op-label">Phone Number <span class="text-red-500">*</span></label>
                            <input type="text" class="op-input" name="phone_number" required>
                        </div>
                        <div>
                            <label class="op-label">Transaction ID <span class="text-red-500">*</span></label>
                            <input type="text" class="op-input" name="transaction_id" required>
                        </div>
                    </div>
                </div>
            </div>
            <div class="flex items-center justify-between p-4 border-t border-gray-200 dark:border-gray-700">
                <button class="op-btn-secondary" data-modal-hide="modal-editItem">Cancel</button>
                <button class="op-btn-primary modal-editItem-btn">Save Changes</button>
            </div>
        </div>
    </div>
</div>

<script nonce="<?= $csp_nonce ?? '' ?>" data-cfasync="false">
    window.OP_DASHBOARD_URL = '<?php echo $site_url.$path_admin ?>/dashboard';

    // Bulk action handler
    document.querySelector('.model-bulkAction-btn').addEventListener('click', function() {
        var my_action_confirmation_btn = document.querySelector("#my-action-confirmation-btn")?.value || '';
        var actionID = document.getElementById("model-bulkActionID").value;

        if(actionID === ""){
            apToast('error', 'Action Required', "You haven't selected any action.");
        } else {
            const selectedRows = Array.from(document.querySelectorAll('.rowCheckbox:checked')).map(cb => cb.closest('tr').dataset.id);
            if(my_action_confirmation_btn !== ""){
                document.querySelector('.global-loaderSpinner').innerHTML = '<div class="op-spinner"></div>';
                opFetch('sms-data-bulk-action', { actionID, selected_ids: JSON.stringify(selectedRows) })
                    .then(response => {
                        closeAllModals();
                        document.querySelector("#my-action-confirmation-btn").value = '';
                        document.getElementById("model-bulkActionID").selectedIndex = 0;
                        document.querySelector('.global-loaderSpinner').innerHTML = '';
                        if (response.status === 'true') {
                            document.querySelectorAll('.select-all').forEach(cb => cb.checked = false);
                            document.querySelector('.bulk-action').classList.add('hidden');
                            apToast('success', response.title, response.message);
                            load_data_list(1);
                        } else {
                            apToast('error', response.title, response.message);
                        }
                    })
                    .catch(err => apToastError());
            } else {
                show_action_confirmation_tab('model-bulkAction-btn', 'Confirm Action', 'Confirm', 'btn-danger');
            }
        }
    });

    function initCheckboxTable() {
        const selectAll = document.querySelector('.select-all');
        const rowCheckboxes = document.querySelectorAll('.rowCheckbox');
        const bulkActionBTN = document.querySelector('.bulk-action');
        function updateSelection() {
            const selected = document.querySelectorAll('.rowCheckbox:checked');
            document.getElementById("bulkActionBTN-count").innerHTML = `(${selected.length})`;
            bulkActionBTN.classList.toggle('hidden', selected.length === 0);
        }
        selectAll.addEventListener('change', () => { rowCheckboxes.forEach(cb => cb.checked = selectAll.checked); updateSelection(); });
        rowCheckboxes.forEach(cb => { cb.addEventListener('change', () => { selectAll.checked = rowCheckboxes.length === document.querySelectorAll('.rowCheckbox:checked').length; updateSelection(); }); });
    }

    function deleteItem(ItemID){
        var my_action_confirmation_btn = document.querySelector("#my-action-confirmation-btn")?.value || '';
        var btnClass = 'btnDeleteItem-'+ItemID;
        if(my_action_confirmation_btn !== ""){
            var btnEl = document.querySelector('#model-my-action-confirmation-btn');
            var btn = btnEl.innerHTML;
            btnEl.innerHTML = '<div class="op-spinner" style="width:16px;height:16px;border-width:2px"></div>';
            opFetch('sms-data-delete', { ItemID })
                .then(response => {
                    closeAllModals(); document.querySelector("#my-action-confirmation-btn").value = ''; btnEl.innerHTML = btn;
                    response.status === 'true' ? (apToast('success', response.title, response.message), load_data_list(1)) : apToast('error', response.title, response.message);
                }).catch(err => apToastError());
        } else { show_action_confirmation_tab(btnClass, 'Delete SMS Data', 'Delete', 'btn-danger'); }
    }

    function load_data_list(page = 1){
        currentPage = page;
        var search_input = document.querySelector('.search_input').value;
        var show_limit = document.querySelector('.show_limit').value;
        var tabType = document.querySelector('#statusTabs .op-tab.active')?.dataset.type;
        var filter_status = document.getElementById('filter-status').value;
        var filter_start = document.getElementById('filter-created-from').value;
        var filter_end = document.getElementById('filter-created-until').value;

        document.querySelector(".table-data-list").innerHTML = apSkeletonRows(9);

        opFetch('sms-data-list', { search_input, show_limit, tabType, page, filter_status, filter_start, filter_end })
            .then(res => {
                let html = '';
                if (res.status === 'true') {
                    let allowEdit = <?= \OwnPay\Service\Auth\PermissionService::hasPermission(json_decode($global_response_permission['response'][0]['permission'], true), 'sms_data', 'edit', $global_user_response['response'][0]['role']) ? 'true' : 'false' ?>;
                    let allowDelete = <?= \OwnPay\Service\Auth\PermissionService::hasPermission(json_decode($global_response_permission['response'][0]['permission'], true), 'sms_data', 'delete', $global_user_response['response'][0]['role']) ? 'true' : 'false' ?>;

                    res.response.forEach(item => {
                        let badgeClass = 'op-badge-gray';
                        if (item.status === 'approved') badgeClass = 'op-badge-success';
                        if (item.status === 'awaiting-review') badgeClass = 'op-badge-warning';
                        if (item.status === 'used') badgeClass = 'op-badge-info';

                        let editAttr = allowEdit ? `class="cursor-pointer" onclick="openEditModel('${item.id}')"` : '';
                        let deleteAttr = allowDelete ? `onclick="deleteItem('${item.id}')"` : '';

                        html += `
                            <tr data-id="${item.id}">
                                <td><input class="op-checkbox rowCheckbox" type="checkbox"></td>
                                <td ${editAttr}>${item.device}</td>
                                <td ${editAttr}>${item.payment_method}</td>
                                <td ${editAttr}>${item.type}</td>
                                <td ${editAttr}>${item.mobileNumber}</td>
                                <td ${editAttr}>${item.transaction_id}</td>
                                <td ${editAttr}>${item.amount}</td>
                                <td ${editAttr}>${item.balance}</td>
                                <td ${editAttr}>${item.created_date}</td>
                                <td ${editAttr}><span class="${badgeClass}">${item.status.charAt(0).toUpperCase() + item.status.slice(1)}</span></td>
                                <td class="text-end">
                                    <button id="dropdownBtn-${item.id}" data-dropdown-toggle="dropdown-${item.id}" class="op-btn-secondary text-xs">Actions</button>
                                    <div id="dropdown-${item.id}" class="z-10 hidden bg-white divide-y divide-gray-100 rounded-lg shadow-lg w-36 dark:bg-gray-700">
                                        <ul class="py-1 text-sm text-gray-700 dark:text-gray-200">
                                            ${allowEdit ? `<li><a href="javascript:void(0)" onclick="openEditModel('${item.id}')" class="block px-4 py-2 hover:bg-gray-100 dark:hover:bg-gray-600">Edit</a></li>` : ''}
                                            ${allowDelete ? `<li><a href="javascript:void(0)" ${deleteAttr} class="btnDeleteItem-${item.id} block px-4 py-2 hover:bg-gray-100 dark:hover:bg-gray-600 text-red-500">Delete</a></li>` : ''}
                                        </ul>
                                    </div>
                                </td>
                            </tr>
                        `;
                    });

                    if (html === '') {
                        html = `<tr><td colspan="11">${apEmptyState(res.title, res.message)}</td></tr>`;
                    }

                    document.querySelector(".table-data-list").innerHTML = html;
                    initCheckboxTable();
                    if (typeof initFlowbite === 'function') initFlowbite();
                    document.querySelector(".table-data-list-entries").innerHTML = res.datatableInfo;
                    document.querySelector(".table-data-list-pagination").innerHTML = res.pagination;
                } else {
                    document.querySelector(".table-data-list").innerHTML = `<tr><td colspan="11">${apEmptyState(res.title, res.message)}</td></tr>`;
                    document.querySelector(".table-data-list-entries").innerHTML = 'Showing <strong>0 to 0</strong> of <strong>0 entries</strong>';
                    document.querySelector(".table-data-list-pagination").innerHTML = '';
                }
            }).catch(err => {
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

    document.querySelectorAll('#statusTabs .op-tab').forEach(btn => {
        btn.addEventListener('click', function() {
            document.querySelectorAll('#statusTabs .op-tab').forEach(b => b.classList.remove('active'));
            this.classList.add('active');
            load_data_list(1);
        });
    });

    function itemEntryType(modelID){
        const modal = document.getElementById(modelID);
        const entry_type = modal.querySelector('select[name="entry-type"]').value;
        itemPaymentMethod(modelID);
        if(entry_type == "automatic"){
            modal.querySelector('.data_automatic').classList.remove('hidden');
            modal.querySelector('.data_manual').classList.add('hidden');
        } else {
            modal.querySelector('.data_automatic').classList.add('hidden');
            modal.querySelector('.data_manual').classList.remove('hidden');
        }
    }

    itemEntryType('modal-createItem');

    function itemPaymentMethod(modelID){
        const modal = document.getElementById(modelID);
        const select = modal.querySelector('select[name="sender_key"]');
        const currency = select.selectedOptions[0]?.dataset.currency ?? '';
        modal.querySelector('.input-group-text').innerHTML = currency;
    }

    function openEditModel(itemID){
        document.querySelector('.global-loaderSpinner').innerHTML = '<div class="op-spinner"></div>';
        opFetch('sms-data-info-byID', { ItemID: itemID })
            .then(response => {
                document.querySelector('.global-loaderSpinner').innerHTML = '';
                if (response.status === 'true') {
                    const modal = document.getElementById("modal-editItem");
                    modal.querySelector('input[name="item-id"]').value = itemID || '';
                    modal.querySelector('textarea').value = response.message ?? '';
                    modal.querySelector('input[name="amount"]').value = response.amount || '';
                    modal.querySelector('input[name="phone_number"]').value = response.number || '';
                    modal.querySelector('input[name="transaction_id"]').value = response.trx_id || '';

                    if(response.istatus == "awaiting-review" || response.istatus == "error"){
                        modal.querySelector('.reason-block').classList.remove('hidden');
                        modal.querySelector('.reason').innerHTML = response.reason || '';
                    } else {
                        modal.querySelector('.reason-block').classList.add('hidden');
                    }

                    ['type','status','sender_key','entry-type','device'].forEach(name => {
                        const el = modal.querySelector(`select[name="${name}"]`);
                        if(el) { el.value = response[name === 'entry-type' ? 'entry_type' : name === 'sender_key' ? 'sender_key' : name === 'device' ? 'device_id' : name === 'status' ? 'istatus' : name] || ''; el.dispatchEvent(new Event('change', { bubbles: true })); }
                    });

                    const m = new Modal(document.getElementById('modal-editItem'));
                    m.show();
                } else {
                    apToast('error', response.title, response.message);
                }
            }).catch(err => apToastError());
    }

    // Create handler
    document.querySelector('.modal-createItem-btn').addEventListener('click', function() {
        const modal = document.getElementById("modal-createItem");
        var device = modal.querySelector('select[name="device"]').value;
        var entry_type = modal.querySelector('select[name="entry-type"]').value;
        var sender_key = modal.querySelector('select[name="sender_key"]').value;
        var status = modal.querySelector('select[name="status"]').value;
        var message = modal.querySelector('textarea').value;
        var type = modal.querySelector('select[name="type"]').value;
        var amount = modal.querySelector('input[name="amount"]').value;
        var phone_number = modal.querySelector('input[name="phone_number"]').value;
        var transaction_id = modal.querySelector('input[name="transaction_id"]').value;
        var currency = modal.querySelector('.input-group-text').innerHTML;

        if(!sender_key || !status) { apToast('error', 'Incomplete Information', 'Please fill in all required fields.'); return; }
        if(entry_type === "automatic" && !message) { apToast('error', 'Incomplete Information', 'Please enter the SMS message.'); return; }
        if(entry_type === "manual" && (!type || !amount || !phone_number || !transaction_id)) { apToast('error', 'Incomplete Information', 'Please fill in all required fields.'); return; }

        var btnEl = this; var btn = btnEl.innerHTML;
        btnEl.innerHTML = '<div class="op-spinner" style="width:16px;height:16px;border-width:2px"></div>';

        opFetch('sms-data-create', { device, entry_type, currency, sender_key, status, message, type, amount, phone_number, transaction_id })
            .then(response => {
                closeAllModals();
                modal.querySelectorAll('input[type="text"], input[type="number"]').forEach(i => i.value = '');
                modal.querySelectorAll('select').forEach(s => { s.selectedIndex = 0; s.dispatchEvent(new Event('change')); });
                var ta = modal.querySelector('textarea'); if(ta) ta.value = '';
                btnEl.innerHTML = btn;
                response.status === 'true' ? (apToast('success', response.title, response.message), load_data_list(1)) : apToast('error', response.title, response.message);
            }).catch(err => apToastError());
    });

    // Edit handler
    document.querySelector('.modal-editItem-btn').addEventListener('click', function() {
        const modal = document.getElementById("modal-editItem");
        var itemid = modal.querySelector('input[name="item-id"]').value;
        var device = modal.querySelector('select[name="device"]').value;
        var entry_type = modal.querySelector('select[name="entry-type"]').value;
        var sender_key = modal.querySelector('select[name="sender_key"]').value;
        var status = modal.querySelector('select[name="status"]').value;
        var message = modal.querySelector('textarea').value;
        var type = modal.querySelector('select[name="type"]').value;
        var amount = modal.querySelector('input[name="amount"]').value;
        var phone_number = modal.querySelector('input[name="phone_number"]').value;
        var transaction_id = modal.querySelector('input[name="transaction_id"]').value;
        var currency = modal.querySelector('.input-group-text').innerHTML;

        if(!sender_key || !status) { apToast('error', 'Incomplete Information', 'Please fill in all required fields.'); return; }
        if(entry_type === "automatic" && !message) { apToast('error', 'Incomplete Information', 'Please enter the SMS message.'); return; }
        if(entry_type === "manual" && (!type || !amount || !phone_number || !transaction_id)) { apToast('error', 'Incomplete Information', 'Please fill in all required fields.'); return; }

        var btnEl = this; var btn = btnEl.innerHTML;
        btnEl.innerHTML = '<div class="op-spinner" style="width:16px;height:16px;border-width:2px"></div>';

        opFetch('sms-data-edit', { itemid, device, entry_type, currency, sender_key, status, message, type, amount, phone_number, transaction_id })
            .then(response => {
                closeAllModals();
                modal.querySelectorAll('input[type="text"], input[type="number"]').forEach(i => i.value = '');
                modal.querySelectorAll('select').forEach(s => { s.selectedIndex = 0; s.dispatchEvent(new Event('change')); });
                var ta = modal.querySelector('textarea'); if(ta) ta.value = '';
                btnEl.innerHTML = btn;
                response.status === 'true' ? (apToast('success', response.title, response.message), load_data_list(1)) : apToast('error', response.title, response.message);
            }).catch(err => apToastError());
    });
</script>
