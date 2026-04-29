<?php
    if (!defined('OWNPAY_INIT')) { http_response_code(403); exit('Direct access not allowed'); }
    if (!\OwnPay\Service\Auth\PermissionService::canAccessPage(json_decode($global_response_permission['response'][0]['permission'], true), 'sms_data', $global_user_response['response'][0]['role'])) { http_response_code(403); exit('Access denied.'); }
?>

<div class="op-page-header">
    <div>
        <div class="op-page-pretitle">SMS Center</div>
        <h2 class="op-page-title">SMS Templates</h2>
        <p class="text-sm mt-1 text-slate-400/50">Manage regex parsing rules for automated SMS transaction extraction.</p>
    </div>
    <div class="flex gap-2">
        <button class="op-btn op-btn-outline-secondary" onclick="load_content('SMS Center','<?php echo $site_url.$path_admin ?>/sms-center','nav-item-sms-center')">
            <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 me-1" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 6l-6 6l6 6"/></svg> Back
        </button>
        <button class="op-btn op-btn-primary" onclick="openTemplateModal()">
            <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 me-1" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5l0 14"/><path d="M5 12l14 0"/></svg> Add Template
        </button>
    </div>
</div>

<!-- Filters -->
<div class="op-card mb-4">
    <div class="p-4 flex flex-wrap items-center gap-3">
        <input type="text" id="tpl-search" class="op-input w-64" placeholder="Search templates..." onkeyup="if(event.key==='Enter') loadTemplates()">
        <select id="tpl-limit" class="op-input w-24" onchange="loadTemplates()">
            <option value="10">10</option><option value="25">25</option><option value="50">50</option><option value="all">All</option>
        </select>
        <button class="op-btn op-btn-outline-primary" onclick="loadTemplates()">
            <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10 10m-7 0a7 7 0 1 0 14 0a7 7 0 1 0 -14 0"/><path d="M21 21l-6 -6"/></svg>
        </button>
    </div>
</div>

<!-- Table -->
<div class="op-card">
    <div class="table-responsive">
        <table class="op-table">
            <thead><tr>
                <th>Provider</th><th>Sender Pattern</th><th>Type</th><th>Priority</th><th>Status</th><th>Created</th><th class="text-end">Actions</th>
            </tr></thead>
            <tbody id="tpl-tbody"></tbody>
        </table>
    </div>
    <div class="p-4 flex flex-wrap items-center justify-between gap-2">
        <div id="tpl-info" class="text-sm text-gray-500"></div>
        <div id="tpl-pagination"></div>
    </div>
</div>

<!-- Template Modal -->
<div id="tpl-modal" class="op-modal" style="display:none">
    <div class="op-modal-backdrop" onclick="closeTemplateModal()"></div>
    <div class="op-modal-dialog" style="max-width:720px">
        <div class="op-modal-content">
            <div class="op-modal-header">
                <h5 class="op-modal-title" id="tpl-modal-title">Add Template</h5>
                <button class="op-modal-close" onclick="closeTemplateModal()">&times;</button>
            </div>
            <div class="op-modal-body">
                <input type="hidden" id="tpl-edit-id" value="">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="op-label">Provider Name <span class="text-red-500">*</span></label>
                        <input type="text" id="tpl-provider" class="op-input" placeholder="e.g. bKash, Nagad">
                    </div>
                    <div>
                        <label class="op-label">Sender Pattern <span class="text-red-500">*</span></label>
                        <input type="text" id="tpl-sender" class="op-input" placeholder="e.g. bKash, 16216">
                    </div>
                    <div>
                        <label class="op-label">Transaction Type</label>
                        <select id="tpl-type" class="op-input">
                            <option value="credit">Credit (Cash In)</option>
                            <option value="debit">Debit (Cash Out)</option>
                        </select>
                    </div>
                    <div>
                        <label class="op-label">Priority</label>
                        <input type="number" id="tpl-priority" class="op-input" value="100" min="1" max="999">
                    </div>
                    <div class="md:col-span-2">
                        <label class="op-label">Regex Pattern <span class="text-red-500">*</span></label>
                        <textarea id="tpl-regex" class="op-input font-mono text-xs" rows="3" placeholder="/(?P<amount>[\d,.]+)\s*Tk.*TrxID\s*(?P<trxid>[A-Z0-9]+)/i"></textarea>
                        <p class="text-xs text-gray-400 mt-1">Use named groups: <code class="text-violet-500">(?P&lt;amount&gt;...)</code>, <code class="text-violet-500">(?P&lt;trxid&gt;...)</code>, <code class="text-violet-500">(?P&lt;sender&gt;...)</code>, <code class="text-violet-500">(?P&lt;balance&gt;...)</code></p>
                    </div>
                    <div class="md:col-span-2">
                        <label class="op-label">Description</label>
                        <textarea id="tpl-desc" class="op-input" rows="2" placeholder="Optional notes about this template"></textarea>
                    </div>
                    <div>
                        <label class="flex items-center gap-2 cursor-pointer">
                            <input type="checkbox" id="tpl-active" checked class="op-checkbox">
                            <span class="text-sm text-gray-700 dark:text-gray-300">Active</span>
                        </label>
                    </div>
                </div>

                <!-- Regex Tester -->
                <div class="mt-6 pt-4 border-t border-gray-200 dark:border-gray-700">
                    <h6 class="text-sm font-semibold text-gray-900 dark:text-white mb-3 flex items-center gap-2">
                        <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 text-violet-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 3v4a1 1 0 0 0 1 1h4"/><path d="M17 21h-10a2 2 0 0 1 -2 -2v-14a2 2 0 0 1 2 -2h7l5 5v11a2 2 0 0 1 -2 2z"/><path d="M10 13l-1 2l1 2"/><path d="M14 13l1 2l-1 2"/></svg>
                        Regex Tester
                    </h6>
                    <textarea id="tpl-sample" class="op-input font-mono text-xs" rows="3" placeholder="Paste a sample SMS message here to test your regex..."></textarea>
                    <button class="op-btn op-btn-outline-primary mt-2" onclick="testRegex()" id="btn-test-regex">
                        <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 me-1" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 13a8 8 0 0 1 7 7a6 6 0 0 0 3 -5a9 9 0 0 0 6 -8a3 3 0 0 0 -3 -3a9 9 0 0 0 -8 6a6 6 0 0 0 -5 3"/><path d="M7 14a6 6 0 0 0 -3 6a6 6 0 0 0 6 -3"/><circle cx="15" cy="9" r="1"/></svg> Test Regex
                    </button>
                    <div id="tpl-test-result" class="mt-3" style="display:none"></div>
                </div>
            </div>
            <div class="op-modal-footer">
                <button class="op-btn op-btn-outline-secondary" onclick="closeTemplateModal()">Cancel</button>
                <button class="op-btn op-btn-primary" onclick="saveTemplate()" id="btn-save-tpl">Save Template</button>
            </div>
        </div>
    </div>
</div>

<script nonce="<?= $csp_nonce ?? '' ?>" data-cfasync="false">
window.OP_DASHBOARD_URL = '<?php echo $site_url.$path_admin ?>/dashboard';
var tplCurrentPage = 1;

function loadTemplates(page) {
    tplCurrentPage = page || 1;
    var tbody = document.getElementById('tpl-tbody');
    tbody.innerHTML = apSkeletonRows(7);

    opFetch('sms-template-list', {
        search_input: document.getElementById('tpl-search').value,
        show_limit: document.getElementById('tpl-limit').value,
        page: tplCurrentPage
    }).then(function(res) {
        if (res.status === 'true' && res.response && res.response.length > 0) {
            var html = '';
            res.response.forEach(function(t) {
                var statusBadge = t.is_active == 1
                    ? '<span class="op-badge-success">Active</span>'
                    : '<span class="op-badge-secondary">Inactive</span>';
                html += '<tr>' +
                    '<td><span class="font-medium text-gray-900 dark:text-white">' + escH(t.provider_name) + '</span></td>' +
                    '<td><code class="text-xs bg-gray-100 dark:bg-gray-800 px-1.5 py-0.5 rounded">' + escH(t.sender_pattern) + '</code></td>' +
                    '<td><span class="op-badge-' + (t.transaction_type === 'credit' ? 'success' : 'warning') + '">' + escH(t.transaction_type) + '</span></td>' +
                    '<td>' + t.priority + '</td>' +
                    '<td>' + statusBadge + '</td>' +
                    '<td class="text-sm text-gray-500">' + escH(t.created_at) + '</td>' +
                    '<td class="text-end">' +
                        '<button class="op-btn op-btn-sm op-btn-outline-primary me-1" onclick="editTemplate(' + t.id + ')" title="Edit"><svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M7 7h-1a2 2 0 0 0 -2 2v9a2 2 0 0 0 2 2h9a2 2 0 0 0 2 -2v-1"/><path d="M20.385 6.585a2.1 2.1 0 0 0 -2.97 -2.97l-8.415 8.385v3h3l8.385 -8.415z"/></svg></button>' +
                        '<button class="op-btn op-btn-sm op-btn-outline-danger" onclick="deleteTemplate(' + t.id + ', \'' + escH(t.provider_name).replace(/'/g, "\\'") + '\')" title="Delete"><svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 7l16 0"/><path d="M10 11l0 6"/><path d="M14 11l0 6"/><path d="M5 7l1 12a2 2 0 0 0 2 2h8a2 2 0 0 0 2 -2l1 -12"/><path d="M9 7v-3a1 1 0 0 1 1 -1h4a1 1 0 0 1 1 1v3"/></svg></button>' +
                    '</td></tr>';
            });
            tbody.innerHTML = html;
            document.getElementById('tpl-info').innerHTML = res.datatableInfo || '';
            document.getElementById('tpl-pagination').innerHTML = res.pagination || '';
        } else {
            tbody.innerHTML = '<tr><td colspan="7">' + apEmptyState(res.title || 'No Templates', res.message || 'Create your first SMS parsing template to get started.') + '</td></tr>';
            document.getElementById('tpl-info').innerHTML = '';
            document.getElementById('tpl-pagination').innerHTML = '';
        }
    }).catch(function(e) { apToastError(e); });
}

function escH(s) { var d = document.createElement('div'); d.textContent = s || ''; return d.innerHTML; }

function openTemplateModal(title) {
    document.getElementById('tpl-modal-title').textContent = title || 'Add Template';
    document.getElementById('tpl-edit-id').value = '';
    document.getElementById('tpl-provider').value = '';
    document.getElementById('tpl-sender').value = '';
    document.getElementById('tpl-regex').value = '';
    document.getElementById('tpl-type').value = 'credit';
    document.getElementById('tpl-priority').value = '100';
    document.getElementById('tpl-active').checked = true;
    document.getElementById('tpl-desc').value = '';
    document.getElementById('tpl-sample').value = '';
    document.getElementById('tpl-test-result').style.display = 'none';
    document.getElementById('tpl-modal').style.display = '';
}

function closeTemplateModal() { document.getElementById('tpl-modal').style.display = 'none'; }

function editTemplate(id) {
    var restore = apGlobalLoading();
    opFetch('sms-template-info-byID', { ItemID: id }).then(function(res) {
        restore();
        if (res.status === 'true') {
            document.getElementById('tpl-modal-title').textContent = 'Edit Template';
            document.getElementById('tpl-edit-id').value = res.id;
            document.getElementById('tpl-provider').value = res.provider_name;
            document.getElementById('tpl-sender').value = res.sender_pattern;
            document.getElementById('tpl-regex').value = res.regex_pattern;
            document.getElementById('tpl-type').value = res.transaction_type;
            document.getElementById('tpl-priority').value = res.priority;
            document.getElementById('tpl-active').checked = res.is_active == 1;
            document.getElementById('tpl-desc').value = res.description || '';
            document.getElementById('tpl-test-result').style.display = 'none';
            document.getElementById('tpl-modal').style.display = '';
        } else { apToastError(res.title, res.message); }
    }).catch(function(e) { restore(); apToastError(e); });
}

function saveTemplate() {
    var editId = document.getElementById('tpl-edit-id').value;
    var action = editId ? 'sms-template-edit' : 'sms-template-create';
    var payload = {
        provider_name: document.getElementById('tpl-provider').value,
        sender_pattern: document.getElementById('tpl-sender').value,
        regex_pattern: document.getElementById('tpl-regex').value,
        transaction_type: document.getElementById('tpl-type').value,
        priority: document.getElementById('tpl-priority').value,
        is_active: document.getElementById('tpl-active').checked ? 1 : 0,
        description: document.getElementById('tpl-desc').value,
    };
    if (editId) payload.itemid = editId;

    var restore = apBtnLoading('#btn-save-tpl');
    opFetch(action, payload).then(function(res) {
        restore();
        apHandleResponse(res, function() { closeTemplateModal(); loadTemplates(tplCurrentPage); });
    }).catch(function(e) { restore(); apToastError(e); });
}

function deleteTemplate(id, name) {
    if (!confirm('Delete template "' + name + '"? This cannot be undone.')) return;
    opFetch('sms-template-delete', { ItemID: id }).then(function(res) {
        apHandleResponse(res, function() { loadTemplates(tplCurrentPage); });
    }).catch(function(e) { apToastError(e); });
}

function testRegex() {
    var regex = document.getElementById('tpl-regex').value;
    var sample = document.getElementById('tpl-sample').value;
    var type = document.getElementById('tpl-type').value;
    var resultDiv = document.getElementById('tpl-test-result');

    if (!regex || !sample) { apToast('error', 'Missing', 'Enter both regex and sample text.'); return; }

    var restore = apBtnLoading('#btn-test-regex');
    opFetch('sms-template-test-regex', { regex_pattern: regex, sample_text: sample, transaction_type: type }).then(function(res) {
        restore();
        resultDiv.style.display = '';
        if (res.status === 'true' && res.matched) {
            var captures = res.raw_captures || {};
            var parsed = res.parsed_result || {};
            var html = '<div class="rounded-lg border border-emerald-200 dark:border-emerald-800 bg-emerald-50 dark:bg-emerald-900/20 p-3">' +
                '<div class="flex items-center gap-2 mb-2"><svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 text-emerald-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 12m-9 0a9 9 0 1 0 18 0a9 9 0 1 0 -18 0"/><path d="M9 12l2 2l4 -4"/></svg>' +
                '<span class="font-semibold text-emerald-700 dark:text-emerald-300">Match Found!</span></div>';
            if (Object.keys(captures).length > 0) {
                html += '<div class="grid grid-cols-2 gap-2 text-sm">';
                for (var k in captures) { html += '<div class="font-mono text-xs"><span class="text-gray-500">' + escH(k) + ':</span> <span class="font-semibold text-gray-900 dark:text-white">' + escH(captures[k]) + '</span></div>'; }
                html += '</div>';
            }
            html += '</div>';
            resultDiv.innerHTML = html;
        } else {
            resultDiv.innerHTML = '<div class="rounded-lg border border-red-200 dark:border-red-800 bg-red-50 dark:bg-red-900/20 p-3">' +
                '<div class="flex items-center gap-2"><svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 text-red-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 12m-9 0a9 9 0 1 0 18 0a9 9 0 1 0 -18 0"/><path d="M12 9v4"/><path d="M12 16v.01"/></svg>' +
                '<span class="font-semibold text-red-700 dark:text-red-300">No Match</span></div>' +
                '<p class="text-sm text-red-600 dark:text-red-400 mt-1">The regex did not match the sample text. Check your pattern and named groups.</p></div>';
        }
    }).catch(function(e) { restore(); apToastError(e); });
}

// Initial load
loadTemplates();
</script>
