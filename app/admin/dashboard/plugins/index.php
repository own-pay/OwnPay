<?php
if (!defined('OWNPAY_INIT')) {
    http_response_code(403);
    exit('Direct access not allowed');
}

if (!canAccessPage(json_decode($global_response_permission['response'][0]['permission'], true), 'plugins', $global_user_response['response'][0]['role'])) {
    http_response_code(403);
    exit('Access denied.');
}
?>

<!-- Page Header -->
<div class="op-page-header">
    <div>
        <div class="op-page-pretitle">Manage</div>
        <h2 class="op-page-title">Plugins</h2>
    </div>
    <div class="flex items-center gap-2">
        <span class="global-loaderSpinner"></span>
        <button onclick="scanPlugins()" class="op-btn-secondary text-sm">
            <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 me-1 inline" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 11a8.1 8.1 0 0 0 -15.5 -2m-.5 -4v4h4"/><path d="M4 13a8.1 8.1 0 0 0 15.5 2m.5 4v-4h-4"/></svg>
            Scan
        </button>
        <button onclick="load_content('Install Plugin', OP_DASHBOARD_URL + '/plugins/install', 'nav-item-plugins')" class="op-btn-primary text-sm">
            <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 me-1 inline" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5l0 14"/><path d="M5 12l14 0"/></svg>
            Upload Plugin
        </button>
    </div>
</div>

<!-- Filters & List Card -->
<div class="op-card">
    <!-- Filters -->
    <div class="border-b border-gray-200 dark:border-gray-700">
        <div class="p-4 flex flex-wrap gap-3 items-center">
            <div>
                <label class="op-label text-xs">Type</label>
                <select id="filter-type" class="op-select text-sm" onchange="load_data_list(1)">
                    <option value="">All Types</option>
                    <option value="plugin">Plugin</option>
                    <option value="gateway">Gateway</option>
                    <option value="theme">Theme</option>
                </select>
            </div>
            <div>
                <label class="op-label text-xs">Status</label>
                <select id="filter-status" class="op-select text-sm" onchange="load_data_list(1)">
                    <option value="">All</option>
                    <option value="active">Active</option>
                    <option value="installed">Installed</option>
                    <option value="inactive">Inactive</option>
                </select>
            </div>
        </div>
    </div>

    <!-- Search & Limit -->
    <div class="p-4 border-b border-gray-200 dark:border-gray-700 flex flex-wrap gap-3 items-center justify-between">
        <div class="flex items-center gap-2">
            <input type="text" class="op-input text-sm search_input" placeholder="Search plugins..." onkeyup="load_data_list(1)" style="max-width: 240px;">
        </div>
        <div class="flex items-center gap-2">
            <label class="op-label text-xs mb-0">Show</label>
            <input type="number" class="op-input text-sm show_limit" value="10" min="1" max="100" style="width: 70px;" onchange="load_data_list(1)">
        </div>
    </div>

    <!-- Plugin List -->
    <div class="overflow-x-auto">
        <table class="op-table">
            <thead>
                <tr>
                    <th class="w-8"><input class="op-checkbox select-all" type="checkbox"></th>
                    <th>Plugin</th>
                    <th>Type</th>
                    <th>Version</th>
                    <th>Status</th>
                    <th class="text-right">Actions</th>
                </tr>
            </thead>
            <tbody class="table-data-list"></tbody>
        </table>
    </div>

    <div class="op-card-footer">
        <p class="table-data-list-entries text-sm text-gray-500"></p>
        <div class="table-data-list-pagination"></div>
    </div>
</div>

<!-- Settings Modal -->
<div id="plugin-settings-modal" tabindex="-1" class="hidden overflow-y-auto overflow-x-hidden fixed top-0 right-0 left-0 z-50 justify-center items-center w-full md:inset-0 h-[calc(100%-1rem)] max-h-full" data-modal-backdrop="static">
    <div class="relative p-4 w-full max-w-lg max-h-full">
        <div class="relative bg-white rounded-lg shadow dark:bg-gray-800">
            <div class="flex items-center justify-between p-4 border-b dark:border-gray-600">
                <h3 class="text-lg font-semibold text-gray-900 dark:text-white" id="settings-modal-title">Plugin Settings</h3>
                <button type="button" class="text-gray-400 bg-transparent hover:bg-gray-200 hover:text-gray-900 rounded-lg text-sm w-8 h-8 ms-auto inline-flex justify-center items-center dark:hover:bg-gray-600 dark:hover:text-white" onclick="closeSettingsModal()">
                    <svg class="w-3 h-3" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 14 14"><path stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m1 1 6 6m0 0 6 6M7 7l6-6M7 7l-6 6"/></svg>
                </button>
            </div>
            <div class="p-4" id="settings-modal-body">
                <p class="text-sm text-gray-500">Loading...</p>
            </div>
            <div class="flex items-center justify-end p-4 border-t dark:border-gray-600 gap-2">
                <button type="button" class="op-btn-secondary text-sm" onclick="closeSettingsModal()">Cancel</button>
                <button type="button" class="op-btn-primary text-sm" id="settings-save-btn" onclick="saveSettings()">Save Settings</button>
            </div>
        </div>
    </div>
</div>

<!-- Confirm Delete Modal -->
<div id="plugin-delete-modal" tabindex="-1" class="hidden overflow-y-auto overflow-x-hidden fixed top-0 right-0 left-0 z-50 justify-center items-center w-full md:inset-0 h-[calc(100%-1rem)] max-h-full" data-modal-backdrop="static">
    <div class="relative p-4 w-full max-w-md max-h-full">
        <div class="relative bg-white rounded-lg shadow dark:bg-gray-800">
            <div class="p-6 text-center">
                <svg class="mx-auto mb-4 text-red-500 w-12 h-12" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" /></svg>
                <h3 class="mb-2 text-lg font-semibold text-gray-900 dark:text-white">Delete Plugin</h3>
                <p class="mb-5 text-sm text-gray-500 dark:text-gray-400">This will uninstall the plugin, rollback its migrations, and delete all files. This action cannot be undone.</p>
                <p class="mb-5 text-sm font-medium text-gray-700 dark:text-gray-300" id="delete-plugin-name"></p>
                <button type="button" class="op-btn-danger text-sm me-2" id="confirm-delete-btn">Yes, Delete</button>
                <button type="button" class="op-btn-secondary text-sm" onclick="closeDeleteModal()">Cancel</button>
            </div>
        </div>
    </div>
</div>

<script nonce="<?= $csp_nonce ?? '' ?>" data-cfasync="false">
    window.OP_DASHBOARD_URL = '<?php echo $site_url . $path_admin ?>/dashboard';
    var _settingsSlug = '';
    var _deleteSlug = '';

    function escapeHtml(text) {
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(text));
        return div.textContent;
    }

    function statusBadge(status) {
        var classes = {
            'active': 'op-badge-success',
            'installed': 'op-badge-warning',
            'inactive': 'op-badge-secondary'
        };
        var span = document.createElement('span');
        span.className = classes[status] || 'op-badge-secondary';
        span.textContent = status;
        return span.outerHTML;
    }

    function buildRow(row) {
        var tr = document.createElement('tr');
        tr.className = 'op-table-row';

        var tdCheck = document.createElement('td');
        var cb = document.createElement('input');
        cb.className = 'op-checkbox row-check';
        cb.type = 'checkbox';
        cb.value = row.slug;
        tdCheck.appendChild(cb);
        tr.appendChild(tdCheck);

        var tdName = document.createElement('td');
        var nameDiv = document.createElement('div');
        nameDiv.className = 'font-medium text-gray-900 dark:text-white text-sm';
        nameDiv.textContent = row.name;
        var slugDiv = document.createElement('div');
        slugDiv.className = 'text-xs text-gray-500';
        slugDiv.textContent = row.slug;
        tdName.appendChild(nameDiv);
        tdName.appendChild(slugDiv);
        tr.appendChild(tdName);

        var tdType = document.createElement('td');
        var typeSpan = document.createElement('span');
        typeSpan.className = 'text-xs font-medium text-gray-600 dark:text-gray-400 capitalize';
        typeSpan.textContent = row.type;
        tdType.appendChild(typeSpan);
        tr.appendChild(tdType);

        var tdVer = document.createElement('td');
        tdVer.className = 'text-sm text-gray-600 dark:text-gray-400';
        tdVer.textContent = row.version;
        tr.appendChild(tdVer);

        var tdStatus = document.createElement('td');
        var badge = document.createElement('span');
        var badgeClasses = { 'active': 'op-badge-success', 'installed': 'op-badge-warning', 'inactive': 'op-badge-secondary' };
        badge.className = badgeClasses[row.status] || 'op-badge-secondary';
        badge.textContent = row.status;
        tdStatus.appendChild(badge);
        tr.appendChild(tdStatus);

        var tdActions = document.createElement('td');
        tdActions.className = 'text-right';

        if (row.status === 'active') {
            var btnDeact = document.createElement('button');
            btnDeact.className = 'text-yellow-600 hover:text-yellow-800 dark:text-yellow-400 text-xs font-medium me-3';
            btnDeact.textContent = 'Deactivate';
            btnDeact.addEventListener('click', function() { deactivatePlugin(row.slug); });
            tdActions.appendChild(btnDeact);
        } else {
            var btnAct = document.createElement('button');
            btnAct.className = 'text-green-600 hover:text-green-800 dark:text-green-400 text-xs font-medium me-3';
            btnAct.textContent = 'Activate';
            btnAct.addEventListener('click', function() { activatePlugin(row.slug); });
            tdActions.appendChild(btnAct);
        }

        var btnSet = document.createElement('button');
        btnSet.className = 'text-blue-600 hover:text-blue-800 dark:text-blue-400 text-xs font-medium me-3';
        btnSet.textContent = 'Settings';
        btnSet.addEventListener('click', function() { openSettings(row.slug, row.name); });
        tdActions.appendChild(btnSet);

        var btnDel = document.createElement('button');
        btnDel.className = 'text-red-600 hover:text-red-800 dark:text-red-400 text-xs font-medium';
        btnDel.textContent = 'Delete';
        btnDel.addEventListener('click', function() { openDeleteModal(row.slug, row.name); });
        tdActions.appendChild(btnDel);

        tr.appendChild(tdActions);
        return tr;
    }

    function load_data_list(page) {
        page = page || 1;
        var search_input = document.querySelector('.search_input').value;
        var show_limit = document.querySelector('.show_limit').value;
        var filter_type = document.getElementById('filter-type').value;
        var filter_status = document.getElementById('filter-status').value;

        opFetch('plugins-list', { search_input: search_input, show_limit: show_limit, page: page, filter_type: filter_type, filter_status: filter_status }).then(function(res) {
            apRotateCsrf(res.csrf_token);
            var tbody = document.querySelector('.table-data-list');
            tbody.replaceChildren();

            if (res.status === 'true' && res.response.length > 0) {
                res.response.forEach(function(row) {
                    tbody.appendChild(buildRow(row));
                });

                var p = res.pagination;
                document.querySelector('.table-data-list-entries').textContent = 'Showing ' + ((p.page - 1) * p.limit + 1) + '-' + Math.min(p.page * p.limit, p.total) + ' of ' + p.total;

                var pagDiv = document.querySelector('.table-data-list-pagination');
                pagDiv.replaceChildren();
                for (var i = 1; i <= p.total_pages; i++) {
                    (function(pg) {
                        var btn = document.createElement('button');
                        btn.className = 'px-3 py-1 text-sm rounded me-1 ' + (pg === p.page ? 'bg-blue-600 text-white' : 'bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300 hover:bg-gray-200 dark:hover:bg-gray-600');
                        btn.textContent = pg;
                        btn.addEventListener('click', function() { load_data_list(pg); });
                        pagDiv.appendChild(btn);
                    })(i);
                }
            } else {
                var emptyTr = document.createElement('tr');
                var emptyTd = document.createElement('td');
                emptyTd.setAttribute('colspan', '6');
                emptyTd.className = 'text-center py-8 text-gray-500 dark:text-gray-400';
                emptyTd.textContent = 'No plugins found. Click "Scan" to discover plugins or "Upload Plugin" to install one.';
                emptyTr.appendChild(emptyTd);
                tbody.appendChild(emptyTr);
                document.querySelector('.table-data-list-entries').textContent = '';
                document.querySelector('.table-data-list-pagination').replaceChildren();
            }
        }).catch(function() { apToastError(); });
    }

    function activatePlugin(slug) {
        opFetch('plugins-activate', { slug: slug }).then(function(res) {
            apRotateCsrf(res.csrf_token);
            res.status === 'true' ? (apToast('success', res.title, res.message), load_data_list(1)) : apToast('error', res.title, res.message);
        }).catch(function() { apToastError(); });
    }

    function deactivatePlugin(slug) {
        opFetch('plugins-deactivate', { slug: slug }).then(function(res) {
            apRotateCsrf(res.csrf_token);
            res.status === 'true' ? (apToast('success', res.title, res.message), load_data_list(1)) : apToast('error', res.title, res.message);
        }).catch(function() { apToastError(); });
    }

    function openDeleteModal(slug, name) {
        _deleteSlug = slug;
        document.getElementById('delete-plugin-name').textContent = name + ' (' + slug + ')';
        document.getElementById('confirm-delete-btn').onclick = function() { confirmDelete(); };
        document.getElementById('plugin-delete-modal').classList.remove('hidden');
        document.getElementById('plugin-delete-modal').classList.add('flex');
    }

    function closeDeleteModal() {
        document.getElementById('plugin-delete-modal').classList.add('hidden');
        document.getElementById('plugin-delete-modal').classList.remove('flex');
        _deleteSlug = '';
    }

    function confirmDelete() {
        if (!_deleteSlug) return;
        closeDeleteModal();
        opFetch('plugins-delete', { slug: _deleteSlug }).then(function(res) {
            apRotateCsrf(res.csrf_token);
            res.status === 'true' ? (apToast('success', res.title, res.message), load_data_list(1)) : apToast('error', res.title, res.message);
        }).catch(function() { apToastError(); });
    }

    function openSettings(slug, name) {
        _settingsSlug = slug;
        document.getElementById('settings-modal-title').textContent = name + ' Settings';
        document.getElementById('settings-modal-body').textContent = 'Loading...';
        document.getElementById('plugin-settings-modal').classList.remove('hidden');
        document.getElementById('plugin-settings-modal').classList.add('flex');

        opFetch('plugins-settings-get', { slug: slug }).then(function(res) {
            apRotateCsrf(res.csrf_token);
            var body = document.getElementById('settings-modal-body');
            body.replaceChildren();

            if (res.status === 'true') {
                if (res.fields.length === 0) {
                    var p = document.createElement('p');
                    p.className = 'text-sm text-gray-500 dark:text-gray-400';
                    p.textContent = 'This plugin has no configurable settings.';
                    body.appendChild(p);
                    document.getElementById('settings-save-btn').style.display = 'none';
                } else {
                    document.getElementById('settings-save-btn').style.display = '';
                    res.fields.forEach(function(f) {
                        var wrapper = document.createElement('div');
                        wrapper.className = 'mb-4';

                        var label = document.createElement('label');
                        label.className = 'op-label text-sm';
                        label.textContent = f.label || f.name;
                        wrapper.appendChild(label);

                        var input;
                        if (f.type === 'select' && f.options) {
                            input = document.createElement('select');
                            input.className = 'op-select text-sm';
                            for (var k in f.options) {
                                var opt = document.createElement('option');
                                opt.value = k;
                                opt.textContent = f.options[k];
                                if (f.value === k) opt.selected = true;
                                input.appendChild(opt);
                            }
                        } else if (f.type === 'textarea') {
                            input = document.createElement('textarea');
                            input.className = 'op-input text-sm';
                            input.rows = 3;
                            input.textContent = f.value || '';
                        } else {
                            input = document.createElement('input');
                            input.type = f.type || 'text';
                            input.className = 'op-input text-sm';
                            input.value = f.value || '';
                        }
                        input.id = 'field_' + f.name;
                        wrapper.appendChild(input);
                        body.appendChild(wrapper);
                    });
                }
            } else {
                var err = document.createElement('p');
                err.className = 'text-sm text-red-500';
                err.textContent = res.message;
                body.appendChild(err);
            }
        }).catch(function() {
            var body = document.getElementById('settings-modal-body');
            body.replaceChildren();
            var err = document.createElement('p');
            err.className = 'text-sm text-red-500';
            err.textContent = 'Failed to load settings.';
            body.appendChild(err);
        });
    }

    function closeSettingsModal() {
        document.getElementById('plugin-settings-modal').classList.add('hidden');
        document.getElementById('plugin-settings-modal').classList.remove('flex');
        _settingsSlug = '';
    }

    function saveSettings() {
        if (!_settingsSlug) return;
        var data = { slug: _settingsSlug };
        var inputs = document.querySelectorAll('#settings-modal-body input, #settings-modal-body select, #settings-modal-body textarea');
        inputs.forEach(function(el) {
            if (el.id) data[el.id] = el.value;
        });

        opFetch('plugins-settings-save', data).then(function(res) {
            apRotateCsrf(res.csrf_token);
            res.status === 'true' ? (apToast('success', res.title, res.message), closeSettingsModal()) : apToast('error', res.title, res.message);
        }).catch(function() { apToastError(); });
    }

    function scanPlugins() {
        opFetch('plugins-scan', {}).then(function(res) {
            apRotateCsrf(res.csrf_token);
            res.status === 'true' ? (apToast('success', res.title, res.message), load_data_list(1)) : apToast('error', res.title, res.message);
        }).catch(function() { apToastError(); });
    }

    load_data_list(1);
</script>
