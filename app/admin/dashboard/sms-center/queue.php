<?php
    if (!defined('OWNPAY_INIT')) { http_response_code(403); exit('Direct access not allowed'); }
    if (!\OwnPay\Service\Auth\PermissionService::canAccessPage(json_decode($global_response_permission['response'][0]['permission'], true), 'sms_data', $global_user_response['response'][0]['role'])) { http_response_code(403); exit('Access denied.'); }
?>

<div class="op-page-header">
    <div>
        <div class="op-page-pretitle">SMS Center</div>
        <h2 class="op-page-title">Unparsed SMS Queue</h2>
        <p class="text-sm mt-1 text-slate-400/50">Review SMS messages that couldn't be automatically parsed and resolve them manually.</p>
    </div>
    <div class="flex gap-2">
        <button class="op-btn op-btn-outline-secondary" onclick="load_content('SMS Center','<?php echo $site_url.$path_admin ?>/sms-center','nav-item-sms-center')">
            <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 me-1" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 6l-6 6l6 6"/></svg> Back
        </button>
    </div>
</div>

<!-- Filters -->
<div class="op-card mb-4">
    <div class="p-4 flex flex-wrap items-center gap-3">
        <input type="text" id="q-search" class="op-input w-64" placeholder="Search sender, message, trx ID..." onkeyup="if(event.key==='Enter') loadQueue()">
        <select id="q-limit" class="op-input w-24" onchange="loadQueue()">
            <option value="10">10</option><option value="25">25</option><option value="50">50</option><option value="all">All</option>
        </select>
        <button class="op-btn op-btn-outline-primary" onclick="loadQueue()">
            <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10 10m-7 0a7 7 0 1 0 14 0a7 7 0 1 0 -14 0"/><path d="M21 21l-6 -6"/></svg>
        </button>
    </div>
</div>

<!-- Table -->
<div class="op-card">
    <div class="table-responsive">
        <table class="op-table">
            <thead><tr>
                <th>Sender</th><th>Received</th><th>Message</th><th>Parse Method</th><th>Status</th><th class="text-end">Actions</th>
            </tr></thead>
            <tbody id="q-tbody"></tbody>
        </table>
    </div>
    <div class="p-4 flex flex-wrap items-center justify-between gap-2">
        <div id="q-info" class="text-sm text-gray-500"></div>
        <div id="q-pagination"></div>
    </div>
</div>

<!-- View Message Modal -->
<div id="q-view-modal" class="op-modal" style="display:none">
    <div class="op-modal-backdrop" onclick="document.getElementById('q-view-modal').style.display='none'"></div>
    <div class="op-modal-dialog" style="max-width:640px">
        <div class="op-modal-content">
            <div class="op-modal-header">
                <h5 class="op-modal-title">SMS Message</h5>
                <button class="op-modal-close" onclick="document.getElementById('q-view-modal').style.display='none'">&times;</button>
            </div>
            <div class="op-modal-body">
                <div id="q-view-sender" class="text-sm text-gray-500 mb-2"></div>
                <pre id="q-view-body" class="bg-gray-50 dark:bg-gray-800 rounded-lg p-4 text-sm font-mono whitespace-pre-wrap break-words"></pre>
            </div>
        </div>
    </div>
</div>

<!-- Resolve Modal -->
<div id="q-resolve-modal" class="op-modal" style="display:none">
    <div class="op-modal-backdrop" onclick="document.getElementById('q-resolve-modal').style.display='none'"></div>
    <div class="op-modal-dialog" style="max-width:540px">
        <div class="op-modal-content">
            <div class="op-modal-header">
                <h5 class="op-modal-title">Manually Resolve SMS</h5>
                <button class="op-modal-close" onclick="document.getElementById('q-resolve-modal').style.display='none'">&times;</button>
            </div>
            <div class="op-modal-body">
                <input type="hidden" id="q-resolve-id" value="">
                <div id="q-resolve-msg" class="bg-gray-50 dark:bg-gray-800 rounded-lg p-3 text-xs font-mono mb-4 max-h-24 overflow-y-auto"></div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="op-label">Amount <span class="text-red-500">*</span></label>
                        <input type="number" id="q-amount" class="op-input" step="0.01" placeholder="500.00">
                    </div>
                    <div>
                        <label class="op-label">Type <span class="text-red-500">*</span></label>
                        <select id="q-type" class="op-input">
                            <option value="credit">Credit (Cash In)</option>
                            <option value="debit">Debit (Cash Out)</option>
                        </select>
                    </div>
                    <div>
                        <label class="op-label">Transaction ID</label>
                        <input type="text" id="q-trxid" class="op-input" placeholder="ABC123XYZ">
                    </div>
                    <div>
                        <label class="op-label">Sender Number</label>
                        <input type="text" id="q-sender-num" class="op-input" placeholder="01712345678">
                    </div>
                </div>
            </div>
            <div class="op-modal-footer">
                <button class="op-btn op-btn-outline-secondary" onclick="document.getElementById('q-resolve-modal').style.display='none'">Cancel</button>
                <button class="op-btn op-btn-primary" onclick="resolveItem()" id="btn-resolve">Resolve</button>
            </div>
        </div>
    </div>
</div>

<script nonce="<?= $csp_nonce ?? '' ?>" data-cfasync="false">
window.OP_DASHBOARD_URL = '<?php echo $site_url.$path_admin ?>/dashboard';
var qCurrentPage = 1;
var qCachedItems = {};

function escH(s) { var d = document.createElement('div'); d.textContent = s || ''; return d.innerHTML; }

function loadQueue(page) {
    qCurrentPage = page || 1;
    var tbody = document.getElementById('q-tbody');
    tbody.innerHTML = apSkeletonRows(6);

    opFetch('sms-queue-list', {
        search_input: document.getElementById('q-search').value,
        show_limit: document.getElementById('q-limit').value,
        page: qCurrentPage
    }).then(function(res) {
        if (res.status === 'true' && res.response && res.response.length > 0) {
            qCachedItems = {};
            var html = '';
            res.response.forEach(function(item) {
                qCachedItems[item.id] = item;
                var methodBadge = '<span class="op-badge-secondary">' + escH(item.parse_method) + '</span>';
                var statusBadge = '<span class="op-badge-warning">' + escH(item.status) + '</span>';
                html += '<tr>' +
                    '<td><span class="font-medium text-gray-900 dark:text-white">' + escH(item.sender) + '</span></td>' +
                    '<td class="text-sm text-gray-500">' + escH(item.received_at) + '</td>' +
                    '<td><span class="text-xs text-gray-600 dark:text-gray-400 cursor-pointer hover:text-primary-600" onclick="viewMessage(' + item.id + ')" title="Click to view full message">' + escH(item.raw_message) + '</span></td>' +
                    '<td>' + methodBadge + '</td>' +
                    '<td>' + statusBadge + '</td>' +
                    '<td class="text-end">' +
                        '<button class="op-btn op-btn-sm op-btn-outline-primary me-1" onclick="reprocessItem(' + item.id + ')" title="Reprocess"><svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 11a8.1 8.1 0 0 0 -15.5 -2m-.5 -4v4h4"/><path d="M4 13a8.1 8.1 0 0 0 15.5 2m.5 4v-4h-4"/></svg></button>' +
                        '<button class="op-btn op-btn-sm op-btn-outline-success" onclick="openResolveModal(' + item.id + ')" title="Resolve Manually"><svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12l5 5l10 -10"/></svg></button>' +
                    '</td></tr>';
            });
            tbody.innerHTML = html;
            document.getElementById('q-info').innerHTML = res.datatableInfo || '';
            document.getElementById('q-pagination').innerHTML = res.pagination || '';
        } else {
            tbody.innerHTML = '<tr><td colspan="6">' + apEmptyState('Queue Empty', 'All SMS messages have been successfully parsed. Nothing needs review.') + '</td></tr>';
            document.getElementById('q-info').innerHTML = '';
            document.getElementById('q-pagination').innerHTML = '';
        }
    }).catch(function(e) { apToastError(e); });
}

function viewMessage(id) {
    var item = qCachedItems[id];
    if (!item) return;
    document.getElementById('q-view-sender').textContent = 'From: ' + item.sender + ' • ' + item.received_at;
    document.getElementById('q-view-body').textContent = item.raw_message_full || item.raw_message;
    document.getElementById('q-view-modal').style.display = '';
}

function reprocessItem(id) {
    if (!confirm('Re-run the parsing engine on this SMS?')) return;
    var restore = apGlobalLoading();
    opFetch('sms-queue-reprocess', { ItemID: id }).then(function(res) {
        restore();
        apHandleResponse(res, function() { loadQueue(qCurrentPage); });
    }).catch(function(e) { restore(); apToastError(e); });
}

function openResolveModal(id) {
    var item = qCachedItems[id];
    if (!item) return;
    document.getElementById('q-resolve-id').value = id;
    document.getElementById('q-resolve-msg').textContent = item.raw_message_full || item.raw_message;
    document.getElementById('q-amount').value = item.parsed_amount || '';
    document.getElementById('q-type').value = item.parsed_type || 'credit';
    document.getElementById('q-trxid').value = item.parsed_trx_id || '';
    document.getElementById('q-sender-num').value = item.parsed_sender || '';
    document.getElementById('q-resolve-modal').style.display = '';
}

function resolveItem() {
    var restore = apBtnLoading('#btn-resolve');
    opFetch('sms-queue-resolve', {
        ItemID: document.getElementById('q-resolve-id').value,
        amount: document.getElementById('q-amount').value,
        type: document.getElementById('q-type').value,
        trx_id: document.getElementById('q-trxid').value,
        sender_number: document.getElementById('q-sender-num').value
    }).then(function(res) {
        restore();
        apHandleResponse(res, function() {
            document.getElementById('q-resolve-modal').style.display = 'none';
            loadQueue(qCurrentPage);
        });
    }).catch(function(e) { restore(); apToastError(e); });
}

// Initial load
loadQueue();
</script>
