<?php
    if (!defined('OWNPAY_INIT')) {
        http_response_code(403);
        exit('Direct access not allowed');
    }

    if (!canAccessPage(json_decode($global_response_permission['response'][0]['permission'], true), 'device', $global_user_response['response'][0]['role'])) {
        http_response_code(403);
        exit('Access denied. You need permission to perform this action. Please contact the admin.');
    }
?>

<div class="op-page-header">
    <div>
        <div class="op-page-pretitle">SMS Center</div>
        <h2 class="op-page-title">Devices</h2>
    </div>
    <div class="flex items-center gap-2">
        <span class="global-loaderSpinner"></span>
        <span class="<?= hasPermission(json_decode($global_response_permission['response'][0]['permission'], true), 'device', 'connect', $global_user_response['response'][0]['role']) ? '' : 'hidden' ?>">
            <button class="op-btn-primary" onclick="iniConnectDeviceInfo()" data-modal-target="modal-createItem" data-modal-toggle="modal-createItem">Connect Device</button>
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
                <div><label class="op-label">Status</label><select class="op-select" id="filter-status"><option value="">All</option><option value="connected">Connected</option><option value="disconnected">Disconnected</option></select></div>
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
                <th>Device Name</th><th>Device Model</th><th>Android Level</th><th>Created Date</th><th>Last Sync</th><th></th>
            </tr></thead>
            <tbody class="table-data-list"></tbody>
        </table>
    </div>
    <div class="op-card-footer">
        <p class="text-sm text-gray-500 table-data-list-entries"></p>
        <div class="table-data-list-pagination"></div>
    </div>
</div>

<!-- Paired Companion Devices -->
<div class="op-card mt-4">
    <div class="p-4 border-b border-gray-200 dark:border-gray-700">
        <div class="flex justify-between items-center">
            <div>
                <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Paired Companion Devices</h3>
                <p class="text-xs text-gray-500 mt-0.5">Devices connected via the OwnPay Companion App</p>
            </div>
            <button class="text-xs text-blue-600 hover:text-blue-800" onclick="loadPairedDevices()">Refresh</button>
        </div>
    </div>
    <div id="paired-devices-list">
        <div class="p-8 text-center"><div class="op-spinner"></div></div>
    </div>
</div>

<!-- Connect Device Modal (Secure Pairing) -->
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
                    <?= hasPermission(json_decode($global_response_permission['response'][0]['permission'], true), 'device', 'delete', $global_user_response['response'][0]['role']) ? '<option value="deleted">Delete Selected</option>' : '' ?>
                </select>
            </div>
            <div class="flex justify-end gap-2 p-4 border-t dark:border-gray-700">
                <button type="button" class="op-btn-secondary" data-modal-hide="model-bulkAction">Close</button>
                <button type="button" class="op-btn-primary model-bulkAction-btn">Confirm</button>
            </div>
        </div>
    </div>
</div>

<!-- Connect Device Modal (Secure Pairing) -->
<div id="modal-createItem" tabindex="-1" class="hidden overflow-y-auto overflow-x-hidden fixed top-0 right-0 left-0 z-50 justify-center items-center w-full md:inset-0 h-[calc(100%-1rem)] max-h-full" data-modal-backdrop="static">
    <div class="relative p-4 w-full max-w-md max-h-full">
        <div class="relative bg-white rounded-lg shadow-sm dark:bg-gray-800">
            <div class="flex items-center justify-between p-4 border-b dark:border-gray-700">
                <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Pair Mobile Device</h3>
                <button type="button" class="op-modal-close" data-modal-hide="modal-createItem"><svg class="w-3 h-3" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 14 14"><path stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m1 1 6 6m0 0 6 6M7 7l6-6M7 7l-6 6"/></svg></button>
            </div>
            <div class="p-4 space-y-4">
                <div class="p-3 text-sm text-blue-800 rounded-lg bg-blue-50 dark:bg-gray-700 dark:text-blue-400">
                    Open the <strong>OwnPay Companion App</strong> on your phone, tap <strong>"Pair Device"</strong>, and scan the QR code below.
                </div>
                <div class="text-center" id="pair-qr-container">
                    <div class="inline-block op-card p-3">
                        <div class="op-spinner"></div>
                    </div>
                </div>
                <div class="text-center" id="pair-countdown-container" style="display:none;">
                    <span class="text-sm text-gray-500 dark:text-gray-400">Expires in <strong id="pair-countdown">5:00</strong></span>
                </div>
                <p class="text-sm text-gray-600 dark:text-gray-400">Or enter the code manually in the app:</p>
                <div>
                    <label class="op-label">Server URL</label>
                    <div class="flex">
                        <input type="text" class="op-input rounded-e-none" id="pair-base-url" value="<?php echo $site_url ?>" readonly>
                        <button type="button" class="op-btn-secondary rounded-s-none" onclick="copyContent(document.getElementById('pair-base-url').value, 'Copied!', 'URL copied.')">Copy</button>
                    </div>
                </div>
                <div>
                    <label class="op-label">Pairing Code</label>
                    <div class="flex">
                        <input type="text" class="op-input rounded-e-none font-mono text-lg tracking-widest text-center" id="pair-otp" readonly placeholder="------">
                        <button type="button" class="op-btn-secondary rounded-s-none" onclick="copyContent(document.getElementById('pair-otp').value, 'Copied!', 'Code copied.')">Copy</button>
                    </div>
                </div>
            </div>
            <div class="flex justify-between p-4 border-t dark:border-gray-700">
                <button type="button" class="op-btn-secondary text-sm" onclick="iniConnectDeviceInfo()">Regenerate</button>
                <button type="button" class="op-btn-secondary" data-modal-hide="modal-createItem">Close</button>
            </div>
        </div>
    </div>
</div>

<script nonce="<?= $csp_nonce ?? '' ?>" data-cfasync="false">
    window.OP_DASHBOARD_URL = '<?php echo $site_url.$path_admin ?>/dashboard';
    let pairCountdownInterval = null;

    function iniConnectDeviceInfo(){
        const qrContainer = document.getElementById('pair-qr-container');
        const cdContainer = document.getElementById('pair-countdown-container');
        const otpField = document.getElementById('pair-otp');
        qrContainer.innerHTML = '<div class="inline-block op-card p-3"><div class="op-spinner"></div></div>';
        cdContainer.style.display = 'none';
        otpField.value = '';
        if (pairCountdownInterval) clearInterval(pairCountdownInterval);

        opFetch('device-pair-generate', {}).then(response => {
            if (response.status === 'true') {
                otpField.value = response.otp;
                // Show server-side QR — use DOM API to avoid XSS via string interpolation
                if (response.qr_data) {
                    const img = document.createElement('img');
                    img.setAttribute('src', response.qr_data);
                    img.setAttribute('alt', 'QR Code');
                    img.style.width = '200px';
                    img.style.height = '200px';
                    img.className = 'dark:invert';
                    const wrapper = document.createElement('div');
                    wrapper.className = 'inline-block op-card p-3';
                    wrapper.appendChild(img);
                    qrContainer.innerHTML = '';
                    qrContainer.appendChild(wrapper);
                } else {
                    qrContainer.innerHTML = '<div class="inline-block op-card p-3 text-sm text-gray-500">QR unavailable. Use manual code.</div>';
                }
                // Start countdown
                let remaining = response.expires_in || 300;
                cdContainer.style.display = 'block';
                pairCountdownInterval = setInterval(() => {
                    remaining--;
                    const m = Math.floor(remaining / 60);
                    const s = remaining % 60;
                    document.getElementById('pair-countdown').textContent = m + ':' + String(s).padStart(2, '0');
                    if (remaining <= 0) {
                        clearInterval(pairCountdownInterval);
                        document.getElementById('pair-countdown').textContent = 'Expired';
                        qrContainer.innerHTML = '<div class="inline-block op-card p-6 text-sm text-red-500">Code expired. Click Regenerate.</div>';
                    }
                }, 1000);
            } else { apToast('error', response.title, response.message); }
        }).catch(err => apToastError());
    }

    document.querySelector('.model-bulkAction-btn').addEventListener('click', function(){
        var my_action_confirmation_btn = document.querySelector("#my-action-confirmation-btn")?.value || '';
        var actionID = document.getElementById("model-bulkActionID").value;
        if(actionID === ""){ apToast('error', 'Action Required', "You haven't selected any action."); return; }
        const selectedRows = Array.from(document.querySelectorAll('.rowCheckbox:checked')).map(cb => cb.closest('tr').dataset.id);
        if(my_action_confirmation_btn !== ""){
            document.querySelector('.global-loaderSpinner').innerHTML = '<div class="op-spinner"></div>';
            opFetch('device-bulk-action', { actionID, selected_ids: JSON.stringify(selectedRows) }).then(response => {
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
            opFetch('device-delete', { ItemID }).then(response => {
                closeAllModals(); document.querySelector("#my-action-confirmation-btn").value = ''; btnEl.innerHTML = btn;
                response.status === 'true' ? (apToast('success', response.title, response.message), load_data_list(1)) : apToast('error', response.title, response.message);
            }).catch(err => apToastError());
        } else { show_action_confirmation_tab(btnClass, 'Delete Device', 'Delete', 'btn-danger'); }
    }

    function revokeDevice(deviceUuid){
        var my_action_confirmation_btn = document.querySelector("#my-action-confirmation-btn")?.value || '';
        var btnClass = 'btnRevoke-'+deviceUuid.replace(/-/g,'');
        if(my_action_confirmation_btn !== ""){
            document.querySelector('.global-loaderSpinner').innerHTML = '<div class="op-spinner"></div>';
            opFetch('device-revoke', { device_uuid: deviceUuid }).then(response => {
                closeAllModals(); document.querySelector("#my-action-confirmation-btn").value = ''; document.querySelector('.global-loaderSpinner').innerHTML = '';
                if(response.status === 'true'){ apToast('success', response.title, response.message); loadPairedDevices(); }
                else { apToast('error', response.title, response.message); }
            }).catch(err => apToastError());
        } else { show_action_confirmation_tab(btnClass, 'Revoke Device', 'Revoke', 'btn-danger'); }
    }

    function loadPairedDevices(){
        const container = document.getElementById('paired-devices-list');
        if (!container) return;
        container.innerHTML = '<div class="p-8 text-center"><div class="op-spinner"></div></div>';
        opFetch('device-paired-list', {}).then(res => {
            if (res.status === 'true' && res.response.length > 0) {
                let html = '';
                let allowRevoke = <?= hasPermission(json_decode($global_response_permission['response'][0]['permission'], true), 'device', 'delete', $global_user_response['response'][0]['role']) ? 'true' : 'false' ?>;
                res.response.forEach(d => {
                    const statusBadge = d.status === 'active'
                        ? '<span class="px-2 py-0.5 text-xs font-medium rounded-full bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-400">Active</span>'
                        : '<span class="px-2 py-0.5 text-xs font-medium rounded-full bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-400">Revoked</span>';
                    const platformIcon = d.platform === 'ios' ? '🍎' : '🤖';
                    const revokeBtn = (allowRevoke && d.status === 'active')
                        ? `<button class="text-xs text-red-500 hover:text-red-700 btnRevoke-${d.device_uuid.replace(/-/g,'')}" onclick="revokeDevice('${d.device_uuid}')">Revoke</button>`
                        : '';
                    html += `<div class="flex items-center justify-between p-3 border-b border-gray-100 dark:border-gray-700 last:border-0">
                        <div class="flex items-center gap-3">
                            <span class="text-xl">${platformIcon}</span>
                            <div>
                                <p class="text-sm font-medium text-gray-900 dark:text-white">${d.device_name || 'Unknown Device'}</p>
                                <p class="text-xs text-gray-500">v${d.app_version || '?'} · Last seen: ${d.last_seen} · ${d.created_at}</p>
                            </div>
                        </div>
                        <div class="flex items-center gap-2">${statusBadge} ${revokeBtn}</div>
                    </div>`;
                });
                container.innerHTML = html;
            } else {
                container.innerHTML = '<div class="p-8 text-center text-sm text-gray-500">No paired devices yet.</div>';
            }
        }).catch(err => { container.innerHTML = '<div class="p-8 text-center text-sm text-red-500">Failed to load paired devices.</div>'; });
    }

    function load_data_list(page = 1){
        currentPage = page;
        var search_input = document.querySelector('.search_input').value;
        var show_limit = document.querySelector('.show_limit').value;
        var filter_status = document.getElementById('filter-status').value;
        var filter_start = document.getElementById('filter-created-from').value;
        var filter_end = document.getElementById('filter-created-until').value;

        document.querySelector(".table-data-list").innerHTML = apSkeletonRows(5);

        opFetch('device-list', { search_input, show_limit, page, filter_status, filter_start, filter_end }).then(res => {
            let html = '';
            if (res.status === 'true') {
                let allowBV = <?= hasPermission(json_decode($global_response_permission['response'][0]['permission'], true), 'device', 'balance_verification_for', $global_user_response['response'][0]['role']) ? 'true' : 'false' ?>;
                let allowDelete = <?= hasPermission(json_decode($global_response_permission['response'][0]['permission'], true), 'device', 'delete', $global_user_response['response'][0]['role']) ? 'true' : 'false' ?>;

                res.response.forEach(item => {
                    let redirectBV = allowBV ? `onclick="load_content('Balance Verification','<?php echo $site_url.$path_admin ?>/devices/balance-verification?d_id=${item.id}','nav-item-devices')"` : '';
                    let redirectDelete = allowDelete ? `onclick="deleteItem('${item.id}')"` : '';

                    html += `<tr data-id="${item.id}">
                        <td><input class="op-checkbox rowCheckbox" type="checkbox"></td>
                        <td>${item.name}</td><td>${item.model}</td><td>${item.android_level}</td><td>${item.created_date}</td><td>${item.last_sync}</td>
                        <td class="text-end">
                            <button class="op-btn-secondary text-xs" data-dropdown-toggle="dropdown-${item.id}">Actions</button>
                            <div id="dropdown-${item.id}" class="hidden z-10 bg-white divide-y divide-gray-100 rounded-lg shadow-sm w-44 dark:bg-gray-700">
                                <ul class="py-2 text-sm text-gray-700 dark:text-gray-200">
                                    <li class="${allowBV ? '' : 'hidden'}"><a href="javascript:void(0)" ${redirectBV} class="block px-4 py-2 hover:bg-gray-100 dark:hover:bg-gray-600">Balance Verification</a></li>
                                    <li class="${allowDelete ? '' : 'hidden'}"><a href="javascript:void(0)" ${redirectDelete} class="block px-4 py-2 hover:bg-gray-100 dark:hover:bg-gray-600 text-red-500 btnDeleteItem-${item.id}">Delete</a></li>
                                </ul>
                            </div>
                        </td>
                    </tr>`;
                });

                if (html === '') {
                    html = `<tr><td colspan="7">${apEmptyState(res.title, res.message)}</td></tr>`;
                }

                document.querySelector(".table-data-list").innerHTML = html;
                initCheckboxTable();
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

    document.addEventListener('click', function(e){ if(e.target.closest('.table-data-list-pagination button')){ load_data_list(parseInt(e.target.closest('button').dataset.page)); } });
    load_data_list(1);
    loadPairedDevices();

    function filter_hide_show_reset(className) {
        const c = document.querySelector('.' + className); if (!c) return;
        c.querySelectorAll('input').forEach(i => i.value = '');
        c.querySelectorAll('select').forEach(s => s.selectedIndex = 0);
        load_data_list(1);
    }
    document.querySelectorAll('.filter-tab-data input, .filter-tab-data select, .search_input, .show_limit').forEach(el => el.addEventListener('change', () => load_data_list(1)));
</script>
