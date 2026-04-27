<?php
    if (!defined('OWNPAY_INIT')) {
        http_response_code(403);
        exit('Direct access not allowed');
    }

    if (!canAccessPage(json_decode($global_response_permission['response'][0]['permission'], true), 'device', $global_user_response['response'][0]['role'])) {
        http_response_code(403);
        exit('Access denied. You need permission to perform this action. Please contact the admin.');
    }

    if (!hasPermission(json_decode($global_response_permission['response'][0]['permission'], true), 'device', 'balance_verification_for', $global_user_response['response'][0]['role'])) {
        http_response_code(403);
        exit('Access denied. You need permission to perform this action. Please contact the admin.');
    }

    $params = json_decode($_POST['params'] ?? '{}', true);
    $d_id = getParam($params, 'd_id');

    if ($d_id === null) {
        http_response_code(403);
        exit('Invalid item id');
    } else {
        $d_id = clean_input($d_id);
        $response_staff = json_decode(getData($db_prefix . 'device', 'WHERE device_id = :d_id', '* FROM', [':d_id' => $d_id]), true);
        if ($response_staff['status'] != true) {
            http_response_code(403);
            exit('Direct access not allowed');
        }
    }
?>

<?php
    // Reusable modal form fragment for create/edit
    $bvProviderOptions = '';
    $allProviders = senderWhitelist();
    foreach($allProviders as $key => $provider) {
        if($provider['balance_verify'] == "true"){
            $bvProviderOptions .= '<option value="'.htmlspecialchars($key).'" data-currency="'.$provider['currency'].'">'.htmlspecialchars($provider['name']).'</option>';
        }
    }
?>

<div class="op-page-header">
    <div>
        <nav class="flex mb-1" aria-label="Breadcrumb">
            <ol class="inline-flex items-center space-x-1 text-sm text-gray-500 dark:text-gray-400">
                <li><a href="javascript:void(0)" onclick="load_content('Devices','<?php echo htmlspecialchars((string) ($site_url.$path_admin), ENT_QUOTES, 'UTF-8'); ?>/devices','nav-item-devices')" class="hover:text-primary-600">Devices</a></li>
                <li class="flex items-center"><svg class="w-3 h-3 mx-1" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M7.293 14.707a1 1 0 010-1.414L10.586 10 7.293 6.707a1 1 0 011.414-1.414l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0z" clip-rule="evenodd"></path></svg><span class="text-gray-900 dark:text-white">Balance Verification</span></li>
            </ol>
        </nav>
        <h2 class="op-page-title">Balance Verification</h2>
    </div>
    <div class="flex items-center gap-2">
        <span class="global-loaderSpinner"></span>
        <span class="<?= htmlspecialchars((string) (hasPermission(json_decode($global_response_permission['response'][0]['permission'], true), 'device', 'balance_verification_for', $global_user_response['response'][0]['role']) ? '' : 'hidden'), ENT_QUOTES, 'UTF-8'); ?>">
            <button class="op-btn-primary" data-modal-target="modal-createItem" data-modal-toggle="modal-createItem">New Verification</button>
        </span>
    </div>
</div>

<div class="op-card">
    <!-- Filter -->
    <div class="border-b border-gray-200 dark:border-gray-700">
        <div class="filter-tab-data hidden p-4">
            <div class="flex justify-between items-center mb-3">
                <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Filters</h3>
                <button class="text-sm text-red-500 hover:text-red-700" onclick="filter_hide_show_reset('filter-tab-data')">Reset</button>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                <div><label class="op-label">Status</label><select class="op-select" id="filter-status"><option value="">All</option><option value="active">Active</option><option value="inactive">Inactive</option></select></div>
                <div><label class="op-label">Created From</label><input type="date" class="op-input" id="filter-created-from"></div>
                <div><label class="op-label">Created Until</label><input type="date" class="op-input" id="filter-created-until"></div>
            </div>
        </div>
        <div class="flex justify-end items-center h-12 px-4">
            <svg onclick="filter_hide_show('filter-tab-data')" class="w-5 h-5 cursor-pointer text-gray-500 hover:text-gray-700 dark:text-gray-400" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16v2.172a2 2 0 0 1 -.586 1.414l-4.414 4.414v7l-6 2v-8.5l-4.48 -4.928a2 2 0 0 1 -.52 -1.345v-2.227z" /></svg>
        </div>
    </div>

    <!-- Search & Limit -->
    <div class="p-4 border-b border-gray-200 dark:border-gray-700">
        <div class="flex flex-col sm:flex-row justify-between gap-3">
            <div class="flex items-center gap-2 text-sm text-gray-500">Show <input type="number" min="1" max="100" class="op-input w-16 text-center show_limit" value="8"> entries</div>
            <div class="flex items-center gap-2">
                <div class="flex items-center gap-2 text-sm text-gray-500">Search: <input type="text" class="op-input w-48 search_input"></div>
                <button class="op-btn-danger bulk-action hidden" data-modal-target="model-bulkAction" data-modal-toggle="model-bulkAction"><span id="bulkActionBTN-count">(0)</span></button>
            </div>
        </div>
    </div>

    <!-- Table -->
    <div class="overflow-x-auto">
        <table class="op-table">
            <thead><tr>
                <th class="w-8"><input class="op-checkbox select-all" type="checkbox"></th>
                <th>Sim Slot</th><th>Payment Method</th><th>Method Type</th><th>Current Balance</th><th>Created Date</th><th>Status</th><th></th>
            </tr></thead>
            <tbody class="table-data-list"></tbody>
        </table>
    </div>
    <div class="op-card-footer">
        <p class="text-sm text-gray-500 table-data-list-entries"></p>
        <div class="table-data-list-pagination"></div>
    </div>
</div>

<!-- Bulk Action Modal -->
<div id="model-bulkAction" tabindex="-1" class="hidden overflow-y-auto overflow-x-hidden fixed top-0 right-0 left-0 z-50 justify-center items-center w-full md:inset-0 h-[calc(100%-1rem)] max-h-full" data-modal-backdrop="static">
    <div class="relative p-4 w-full max-w-md max-h-full">
        <div class="relative bg-white rounded-lg shadow-sm dark:bg-gray-800">
            <div class="flex items-center justify-between p-4 border-b dark:border-gray-700">
                <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Action for Selected Items</h3>
                <button type="button" class="op-modal-close" data-modal-hide="model-bulkAction"><svg class="w-3 h-3" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 14 14"><path stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m1 1 6 6m0 0 6 6M7 7l6-6M7 7l-6 6"/></svg></button>
            </div>
            <div class="p-4">
                <label class="op-label">Action <span class="text-red-500">*</span></label>
                <select class="op-select" id="model-bulkActionID">
                    <option value="" selected>Select a Action</option>
                    <?= htmlspecialchars((string) (hasPermission(json_decode($global_response_permission['response'][0]['permission'], true), 'device', 'balance_verification_for', $global_user_response['response'][0]['role']) ? '<option value="deleted">Delete Selected</option><option value="activated">Active Selected</option><option value="inactivated">Inactive Selected</option>' : ''), ENT_QUOTES, 'UTF-8'); ?>
                </select>
            </div>
            <div class="flex justify-end gap-2 p-4 border-t dark:border-gray-700">
                <button type="button" class="op-btn-secondary" data-modal-hide="model-bulkAction">Close</button>
                <button type="button" class="op-btn-primary model-bulkAction-btn">Confirm</button>
            </div>
        </div>
    </div>
</div>

<!-- Create Balance Verification Modal -->
<div id="modal-createItem" tabindex="-1" class="hidden overflow-y-auto overflow-x-hidden fixed top-0 right-0 left-0 z-50 justify-center items-center w-full md:inset-0 h-[calc(100%-1rem)] max-h-full" data-modal-backdrop="static">
    <div class="relative p-4 w-full max-w-lg max-h-full">
        <div class="relative bg-white rounded-lg shadow-sm dark:bg-gray-800">
            <div class="flex items-center justify-between p-4 border-b dark:border-gray-700">
                <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Create Balance Verification</h3>
                <button type="button" class="op-modal-close" data-modal-hide="modal-createItem"><svg class="w-3 h-3" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 14 14"><path stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m1 1 6 6m0 0 6 6M7 7l6-6M7 7l-6 6"/></svg></button>
            </div>
            <div class="p-4">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                    <div><label class="op-label">Payment Method <span class="text-red-500">*</span></label><select class="op-select" name="sender_key" onchange="paymentChangedCreate(this)"><?= htmlspecialchars((string) ($bvProviderOptions), ENT_QUOTES, 'UTF-8'); ?></select></div>
                    <div><label class="op-label">Payment Type <span class="text-red-500">*</span></label><select class="op-select" name="payment-type"><option value="Personal">Personal</option><option value="Agent">Agent</option><option value="Merchant">Merchant</option></select></div>
                    <div><label class="op-label">Sim Slot <span class="text-red-500">*</span></label><select class="op-select" name="simslot"><option value="Any">Any</option><option value="Sim1">Sim 1</option><option value="Sim2">Sim 2</option></select></div>
                    <div><label class="op-label">Current Balance <span class="text-red-500">*</span></label><div class="flex"><span class="inline-flex items-center px-3 text-sm text-gray-500 bg-gray-100 border border-e-0 border-gray-300 rounded-s-lg dark:bg-gray-600 dark:text-gray-400 dark:border-gray-600 payment-method-currency">BDT</span><input type="text" class="op-input rounded-s-none" name="current-balance" value="0"></div></div>
                </div>
                <label class="op-label">Status <span class="text-red-500">*</span></label>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                    <label class="flex items-start gap-3 p-3 border rounded-lg cursor-pointer has-[:checked]:border-primary-500 has-[:checked]:bg-primary-50 dark:border-gray-600 dark:has-[:checked]:bg-primary-900/20"><input type="radio" name="balance-verification-status" value="active" class="mt-1 w-4 h-4 text-primary-600" checked><div><div class="font-medium text-sm text-gray-900 dark:text-white">Active</div><div class="text-xs text-gray-500">Automatically verify incoming SMS by matching with balance.</div></div></label>
                    <label class="flex items-start gap-3 p-3 border rounded-lg cursor-pointer has-[:checked]:border-primary-500 has-[:checked]:bg-primary-50 dark:border-gray-600 dark:has-[:checked]:bg-primary-900/20"><input type="radio" name="balance-verification-status" value="inactive" class="mt-1 w-4 h-4 text-primary-600"><div><div class="font-medium text-sm text-gray-900 dark:text-white">Inactive</div><div class="text-xs text-gray-500">Incoming SMS will not be checked against balance.</div></div></label>
                </div>
            </div>
            <div class="flex justify-end gap-2 p-4 border-t dark:border-gray-700">
                <button type="button" class="op-btn-secondary" data-modal-hide="modal-createItem">Cancel</button>
                <button type="button" class="op-btn-primary modal-createItem-btn">Create</button>
            </div>
        </div>
    </div>
</div>

<!-- Edit Balance Verification Modal -->
<div id="modal-editItem" tabindex="-1" class="hidden overflow-y-auto overflow-x-hidden fixed top-0 right-0 left-0 z-50 justify-center items-center w-full md:inset-0 h-[calc(100%-1rem)] max-h-full" data-modal-backdrop="static">
    <div class="relative p-4 w-full max-w-lg max-h-full">
        <div class="relative bg-white rounded-lg shadow-sm dark:bg-gray-800">
            <div class="flex items-center justify-between p-4 border-b dark:border-gray-700">
                <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Edit Balance Verification</h3>
                <button type="button" class="op-modal-close" data-modal-hide="modal-editItem"><svg class="w-3 h-3" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 14 14"><path stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m1 1 6 6m0 0 6 6M7 7l6-6M7 7l-6 6"/></svg></button>
            </div>
            <div class="p-4">
                <input type="hidden" name="itemID">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                    <div><label class="op-label">Payment Method <span class="text-red-500">*</span></label><select class="op-select" name="sender_key" onchange="paymentChangedCreate2(this)"><?= htmlspecialchars((string) ($bvProviderOptions), ENT_QUOTES, 'UTF-8'); ?></select></div>
                    <div><label class="op-label">Payment Type <span class="text-red-500">*</span></label><select class="op-select" name="payment-type"><option value="Personal">Personal</option><option value="Agent">Agent</option><option value="Merchant">Merchant</option></select></div>
                    <div><label class="op-label">Sim Slot <span class="text-red-500">*</span></label><select class="op-select" name="simslot"><option value="Any">Any</option><option value="Sim1">Sim 1</option><option value="Sim2">Sim 2</option></select></div>
                    <div><label class="op-label">Current Balance <span class="text-red-500">*</span></label><div class="flex"><span class="inline-flex items-center px-3 text-sm text-gray-500 bg-gray-100 border border-e-0 border-gray-300 rounded-s-lg dark:bg-gray-600 dark:text-gray-400 dark:border-gray-600 payment-method-currency">BDT</span><input type="text" class="op-input rounded-s-none" name="current-balance" value="0"></div></div>
                </div>
                <label class="op-label">Status <span class="text-red-500">*</span></label>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                    <label class="flex items-start gap-3 p-3 border rounded-lg cursor-pointer has-[:checked]:border-primary-500 has-[:checked]:bg-primary-50 dark:border-gray-600 dark:has-[:checked]:bg-primary-900/20"><input type="radio" name="balance-verification-status" value="active" class="mt-1 w-4 h-4 text-primary-600" checked><div><div class="font-medium text-sm text-gray-900 dark:text-white">Active</div><div class="text-xs text-gray-500">Automatically verify incoming SMS by matching with balance.</div></div></label>
                    <label class="flex items-start gap-3 p-3 border rounded-lg cursor-pointer has-[:checked]:border-primary-500 has-[:checked]:bg-primary-50 dark:border-gray-600 dark:has-[:checked]:bg-primary-900/20"><input type="radio" name="balance-verification-status" value="inactive" class="mt-1 w-4 h-4 text-primary-600"><div><div class="font-medium text-sm text-gray-900 dark:text-white">Inactive</div><div class="text-xs text-gray-500">Incoming SMS will not be checked against balance.</div></div></label>
                </div>
            </div>
            <div class="flex justify-end gap-2 p-4 border-t dark:border-gray-700">
                <button type="button" class="op-btn-secondary" data-modal-hide="modal-editItem">Cancel</button>
                <button type="button" class="op-btn-primary modal-editItem-btn">Save Changes</button>
            </div>
        </div>
    </div>
</div>

<script nonce="<?= htmlspecialchars((string) ($csp_nonce ?? ''), ENT_QUOTES, 'UTF-8'); ?>" data-cfasync="false">
    window.OP_DASHBOARD_URL = '<?php echo htmlspecialchars((string) ($site_url.$path_admin), ENT_QUOTES, 'UTF-8'); ?>/dashboard';

    function updateItem(ItemID){
        var balance = document.getElementById('tr-data'+ItemID)?.value;
        var btnEl = document.querySelector('#model-my-action-confirmation-btn'); if(btnEl){ var btn = btnEl.innerHTML; btnEl.innerHTML = '<div class="op-spinner" style="width:16px;height:16px;border-width:2px"></div>'; }
        opFetch('balance-verification-iupdate', { ItemID, balance }).then(response => {
            closeAllModals(); if(btnEl) btnEl.innerHTML = btn;
            response.status === 'true' ? apToast('success', response.title, response.message) : apToast('error', response.title, response.message);
        }).catch(err => apToastError());
    }

    document.querySelector('.model-bulkAction-btn').addEventListener('click', function(){
        var my_action_confirmation_btn = document.querySelector("#my-action-confirmation-btn")?.value || '';
        var actionID = document.getElementById("model-bulkActionID").value;
        if(actionID === ""){ apToast('error', 'Action Required', "You haven't selected any action."); return; }
        const selectedRows = Array.from(document.querySelectorAll('.rowCheckbox:checked')).map(cb => cb.closest('tr').dataset.id);
        if(my_action_confirmation_btn !== ""){
            document.querySelector('.global-loaderSpinner').innerHTML = '<div class="op-spinner"></div>';
            opFetch('balance-verification-bulk-action', { actionID, selected_ids: JSON.stringify(selectedRows) }).then(response => {
                closeAllModals(); document.querySelector("#my-action-confirmation-btn").value = ''; document.getElementById("model-bulkActionID").selectedIndex = 0; document.querySelector('.global-loaderSpinner').innerHTML = '';
                if(response.status === 'true'){ document.querySelectorAll('.select-all').forEach(cb => cb.checked = false); document.querySelector('.bulk-action').classList.add('hidden'); apToast('success', response.title, response.message); load_data_list(1); }
                else { apToast('error', response.title, response.message); }
            }).catch(err => apToastError());
        } else { show_action_confirmation_tab('model-bulkAction-btn', 'Confirm Action', 'Confirm', 'btn-danger'); }
    });

    function initCheckboxTable() {
        const selectAll = document.querySelector('.select-all');
        const rowCheckboxes = document.querySelectorAll('.rowCheckbox');
        const bulkActionBTN = document.querySelector('.bulk-action');
        function updateSelection() {
            const selected = document.querySelectorAll('.rowCheckbox:checked');
            document.getElementById("bulkActionBTN-count").innerHTML = `(${selected.length})`;
            selected.length > 0 ? bulkActionBTN.classList.remove('hidden') : bulkActionBTN.classList.add('hidden');
        }
        selectAll.addEventListener('change', () => { rowCheckboxes.forEach(cb => cb.checked = selectAll.checked); updateSelection(); });
        rowCheckboxes.forEach(cb => cb.addEventListener('change', () => { selectAll.checked = rowCheckboxes.length === document.querySelectorAll('.rowCheckbox:checked').length; updateSelection(); }));
    }

    function deleteItem(ItemID){
        var my_action_confirmation_btn = document.querySelector("#my-action-confirmation-btn")?.value || '';
        var btnClass = 'btnDeleteItem-'+ItemID;
        if(my_action_confirmation_btn !== ""){
            var btnEl = document.querySelector('#model-my-action-confirmation-btn'); var btn = btnEl.innerHTML;
            btnEl.innerHTML = '<div class="op-spinner" style="width:16px;height:16px;border-width:2px"></div>';
            opFetch('balance-verification-delete', { ItemID }).then(response => {
                closeAllModals(); document.querySelector("#my-action-confirmation-btn").value = ''; btnEl.innerHTML = btn;
                response.status === 'true' ? (apToast('success', response.title, response.message), load_data_list(1)) : apToast('error', response.title, response.message);
            }).catch(err => apToastError());
        } else { show_action_confirmation_tab(btnClass, 'Delete Balance Verification', 'Delete', 'btn-danger'); }
    }

    function load_data_list(page = 1){
        currentPage = page;
        var search_input = document.querySelector('.search_input').value;
        var show_limit = document.querySelector('.show_limit').value;
        var filter_status = document.getElementById('filter-status').value;
        var filter_start = document.getElementById('filter-created-from').value;
        var filter_end = document.getElementById('filter-created-until').value;

        document.querySelector(".table-data-list").innerHTML = apSkeletonRows(5);

        opFetch('balance-verification-list', { d_id: "<?php echo htmlspecialchars((string) ($d_id), ENT_QUOTES, 'UTF-8'); ?>", search_input, show_limit, page, filter_status, filter_start, filter_end }).then(res => {
            let html = '';
            if (res.status === 'true') {
                let allowEdit = <?= htmlspecialchars((string) (hasPermission(json_decode($global_response_permission['response'][0]['permission'], true), 'device', 'balance_verification_for', $global_user_response['response'][0]['role']) ? 'true' : 'false'), ENT_QUOTES, 'UTF-8'); ?>;
                let allowDelete = <?= htmlspecialchars((string) (hasPermission(json_decode($global_response_permission['response'][0]['permission'], true), 'device', 'balance_verification_for', $global_user_response['response'][0]['role']) ? 'true' : 'false'), ENT_QUOTES, 'UTF-8'); ?>;

                res.response.forEach(item => {
                    let redirectEdit = allowEdit ? `style="cursor:pointer;" onclick="openEditModel('${item.id}')"` : '';
                    let redirectDelete = allowDelete ? `onclick="deleteItem('${item.id}')"` : '';
                    let badge = item.status === 'active' ? 'op-badge-success' : (item.status === 'inactive' ? 'op-badge-danger' : 'op-badge-secondary');

                    html += `<tr data-id="${item.id}">
                        <td><input class="op-checkbox rowCheckbox" type="checkbox"></td>
                        <td ${redirectEdit}>${item.simslot}</td>
                        <td ${redirectEdit}>${item.payment_method}</td>
                        <td ${redirectEdit}>${item.payment_type}</td>
                        <td><input type="text" value="${item.current_balance}" class="op-input w-32" id="tr-data${item.id}" onchange="updateItem('${item.id}')"></td>
                        <td ${redirectEdit}>${item.created_date}</td>
                        <td ${redirectEdit}><span class="${badge}">${item.status.charAt(0).toUpperCase() + item.status.slice(1)}</span></td>
                        <td class="text-end">
                            <button class="op-btn-secondary text-xs" data-dropdown-toggle="dropdown-${item.id}">Actions</button>
                            <div id="dropdown-${item.id}" class="hidden z-10 bg-white divide-y divide-gray-100 rounded-lg shadow-sm w-36 dark:bg-gray-700">
                                <ul class="py-2 text-sm text-gray-700 dark:text-gray-200">
                                    <li class="${allowEdit ? '' : 'hidden'}"><a href="javascript:void(0)" ${redirectEdit} class="block px-4 py-2 hover:bg-gray-100 dark:hover:bg-gray-600">Edit</a></li>
                                    <li class="${allowDelete ? '' : 'hidden'}"><a href="javascript:void(0)" ${redirectDelete} class="block px-4 py-2 hover:bg-gray-100 dark:hover:bg-gray-600 text-red-500 btnDeleteItem-${item.id}">Delete</a></li>
                                </ul>
                            </div>
                        </td>
                    </tr>`;
                });
                document.querySelector(".table-data-list").innerHTML = html;
                initCheckboxTable();
                document.querySelector(".table-data-list-entries").innerHTML = res.datatableInfo;
                document.querySelector(".table-data-list-pagination").innerHTML = res.pagination;
            } else {
                document.querySelector(".table-data-list").innerHTML = `<tr><td colspan="8">${apEmptyState(res.title, res.message)}</td></tr>`;
                document.querySelector(".table-data-list-entries").innerHTML = 'Showing <strong>0 to 0</strong> of <strong>0 entries</strong>';
                document.querySelector(".table-data-list-pagination").innerHTML = '';
            }
        }).catch(err => {
                document.querySelector(".table-data-list").innerHTML = '<tr><td colspan="999" class="py-16 text-center"><div class="flex flex-col items-center justify-center"><div class="w-16 h-16 mb-3 rounded-full bg-red-50 dark:bg-red-900/20 flex items-center justify-center"><svg xmlns="http://www.w3.org/2000/svg" class="w-8 h-8 text-red-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M12 9v4m0 4h.01M5.07 19h13.86c1.54 0 2.5-1.67 1.73-3L13.73 4.99c-.77-1.33-2.69-1.33-3.46 0L3.34 16c-.77 1.33.19 3 1.73 3z"/></svg></div><h3 class="text-base font-semibold text-gray-900 dark:text-white mb-1">Connection Error</h3><p class="text-sm text-gray-500 dark:text-gray-400">Unable to load data. Please check your connection and try again.</p></div></td></tr>';
                apToastError(err);
            });
    }

    document.addEventListener('click', function(e){ if(e.target.closest('.table-data-list-pagination button')){ load_data_list(parseInt(e.target.closest('button').dataset.page)); } });
    load_data_list(1);

    function filter_hide_show_reset(className) { const c = document.querySelector('.' + className); if (!c) return; c.querySelectorAll('input').forEach(i => i.value = ''); c.querySelectorAll('select').forEach(s => s.selectedIndex = 0); load_data_list(1); }
    document.querySelectorAll('.filter-tab-data input, .filter-tab-data select, .search_input, .show_limit').forEach(el => el.addEventListener('change', () => load_data_list(1)));

    function openEditModel(itemID){
        document.querySelector('.global-loaderSpinner').innerHTML = '<div class="op-spinner"></div>';
        opFetch('balance-verification-info-byID', { ItemID: itemID }).then(response => {
            document.querySelector('.global-loaderSpinner').innerHTML = '';
            if (response.status === 'true') {
                const modal = document.getElementById("modal-editItem");
                modal.querySelector('input[name="itemID"]').value = itemID;
                const sk = modal.querySelector('select[name="sender_key"]'); sk.value = response.sender_key || ''; sk.dispatchEvent(new Event('change'));
                const pt = modal.querySelector('select[name="payment-type"]'); pt.value = response.type || ''; pt.dispatchEvent(new Event('change'));
                const ss = modal.querySelector('select[name="simslot"]'); ss.value = response.simslot || ''; ss.dispatchEvent(new Event('change'));
                modal.querySelector('input[name="current-balance"]').value = response.current_balance || '';
                modal.querySelectorAll('input[name="balance-verification-status"]').forEach(input => input.checked = input.value === response.istatus);
                new Modal(modal).show();
            } else { apToast('error', response.title, response.message); }
        }).catch(err => apToastError());
    }

    function paymentChangedCreate(select){ document.getElementById("modal-createItem").querySelector('.payment-method-currency').textContent = select.options[select.selectedIndex].dataset.currency; }
    function paymentChangedCreate2(select){ document.getElementById("modal-editItem").querySelector('.payment-method-currency').textContent = select.options[select.selectedIndex].dataset.currency; }

    document.querySelector('.modal-createItem-btn').addEventListener('click', function(){
        const modal = document.getElementById("modal-createItem");
        var sender_key = modal.querySelector('select[name="sender_key"]').value;
        var payment_type = modal.querySelector('select[name="payment-type"]').value;
        var simslot = modal.querySelector('select[name="simslot"]').value;
        var current_balance = modal.querySelector('input[name="current-balance"]').value;
        var statusInput = modal.querySelector('input[name="balance-verification-status"]:checked');
        var balance_verification_status = statusInput ? statusInput.value : "";

        if(!sender_key || !payment_type || !simslot || !current_balance){ apToast('error','Incomplete Information','Please fill in all required fields.'); return; }

        var btnEl = this; var btn = btnEl.innerHTML;
        btnEl.innerHTML = '<div class="op-spinner" style="width:16px;height:16px;border-width:2px"></div>';

        opFetch('balance-verification-create', { d_id: "<?php echo htmlspecialchars((string) ($d_id), ENT_QUOTES, 'UTF-8'); ?>", sender_key, payment_type, simslot, current_balance, balance_verification_status }).then(response => {
            closeAllModals();
            modal.querySelectorAll('input[type="text"]').forEach(i => i.value = '');
            modal.querySelector('input[name="current-balance"]').value = '0';
            modal.querySelectorAll('select').forEach(s => s.selectedIndex = 0);
            modal.querySelectorAll('input[name="balance-verification-status"]').forEach((r,i) => r.checked = (i===0));
            btnEl.innerHTML = btn;
            response.status === 'true' ? (apToast('success', response.title, response.message), load_data_list(1)) : apToast('error', response.title, response.message);
        }).catch(err => apToastError());
    });

    document.querySelector('.modal-editItem-btn').addEventListener('click', function(){
        const modal = document.getElementById("modal-editItem");
        var itemID = modal.querySelector('input[name="itemID"]').value;
        var sender_key = modal.querySelector('select[name="sender_key"]').value;
        var payment_type = modal.querySelector('select[name="payment-type"]').value;
        var simslot = modal.querySelector('select[name="simslot"]').value;
        var current_balance = modal.querySelector('input[name="current-balance"]').value;
        var statusInput = modal.querySelector('input[name="balance-verification-status"]:checked');
        var balance_verification_status = statusInput ? statusInput.value : "";

        if(!sender_key || !payment_type || !simslot || !current_balance){ apToast('error','Incomplete Information','Please fill in all required fields.'); return; }

        var btnEl = this; var btn = btnEl.innerHTML;
        btnEl.innerHTML = '<div class="op-spinner" style="width:16px;height:16px;border-width:2px"></div>';

        opFetch('balance-verification-update', { d_id: "<?php echo htmlspecialchars((string) ($d_id), ENT_QUOTES, 'UTF-8'); ?>", itemID, sender_key, payment_type, simslot, current_balance, balance_verification_status }).then(response => {
            closeAllModals();
            modal.querySelectorAll('input[type="text"]').forEach(i => i.value = '');
            modal.querySelectorAll('select').forEach(s => s.selectedIndex = 0);
            modal.querySelectorAll('input[name="balance-verification-status"]').forEach((r,i) => r.checked = (i===0));
            btnEl.innerHTML = btn;
            response.status === 'true' ? (apToast('success', response.title, response.message), load_data_list(1)) : apToast('error', response.title, response.message);
        }).catch(err => apToastError());
    });
</script>
