<?php
    if (!defined('OWNPAY_INIT')) { http_response_code(403); exit('Direct access not allowed'); }
    if (!canAccessPage(json_decode($global_response_permission['response'][0]['permission'], true), 'sms_data', $global_user_response['response'][0]['role'])) { http_response_code(403); exit('Access denied.'); }
?>

<div class="op-page-header">
    <div>
        <div class="op-page-pretitle">Automation</div>
        <h2 class="op-page-title">SMS Center</h2>
        <p class="text-sm mt-1 text-slate-400/50">Manage SMS data and connected devices.</p>
    </div>
</div>

<div class="grid grid-cols-1 md:grid-cols-2 gap-4">

    <!-- SMS Data Card -->
    <div class="op-card p-5 cursor-pointer hover:shadow-md transition-shadow"
         onclick="load_content('SMS Data','<?php echo $site_url.$path_admin ?>/sms-data','nav-item-sms-center')">
        <div class="flex items-start gap-4">
            <div class="w-12 h-12 rounded-lg bg-primary-50 dark:bg-primary-900/20 flex items-center justify-center flex-shrink-0">
                <svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6 text-primary-600 dark:text-primary-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M8 9h8"/><path d="M8 13h6"/><path d="M9 18h-3a3 3 0 0 1 -3 -3v-8a3 3 0 0 1 3 -3h12a3 3 0 0 1 3 3v8a3 3 0 0 1 -3 3h-3l-3 3l-3 -3z"/></svg>
            </div>
            <div>
                <h3 class="text-base font-semibold text-gray-900 dark:text-white">SMS Data <span class="sms-count-badge text-xs font-normal"></span></h3>
                <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">View and manage incoming SMS transaction data, approve or review entries.</p>
            </div>
            <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 text-gray-400 flex-shrink-0 ms-auto mt-1" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 6l6 6l-6 6"/></svg>
        </div>
    </div>

    <!-- Devices Card -->
    <?php if (canAccessPage(json_decode($global_response_permission['response'][0]['permission'], true), 'device', $global_user_response['response'][0]['role'])): ?>
    <div class="op-card p-5 cursor-pointer hover:shadow-md transition-shadow"
         onclick="load_content('Devices','<?php echo $site_url.$path_admin ?>/devices','nav-item-sms-center')">
        <div class="flex items-start gap-4">
            <div class="w-12 h-12 rounded-lg bg-emerald-50 dark:bg-emerald-900/20 flex items-center justify-center flex-shrink-0">
                <svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6 text-emerald-600 dark:text-emerald-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M6 5a2 2 0 0 1 2 -2h8a2 2 0 0 1 2 2v14a2 2 0 0 1 -2 2h-8a2 2 0 0 1 -2 -2v-14z"/><path d="M11 4h2"/><path d="M12 17v.01"/></svg>
            </div>
            <div>
                <h3 class="text-base font-semibold text-gray-900 dark:text-white">Devices <span class="device-count-badge text-xs font-normal"></span></h3>
                <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Connect and manage mobile devices for SMS-based payment automation.</p>
            </div>
            <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 text-gray-400 flex-shrink-0 ms-auto mt-1" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 6l6 6l-6 6"/></svg>
        </div>
    </div>
    <?php endif; ?>

</div>

<script nonce="<?= $csp_nonce ?? '' ?>" data-cfasync="false">
    window.OP_DASHBOARD_URL = '<?php echo $site_url.$path_admin ?>/dashboard';

    // Fetch SMS data count
    opFetch('sms-data-list', { show_limit: 1, page: 1, status_filter: 'awaiting-review' }).then(function(res) {
        var badge = document.querySelector('.sms-count-badge');
        if (!badge) return;
        if (res.status === 'true' && res.datatableInfo) {
            var match = res.datatableInfo.match(/of\s*<strong>(\d+)/);
            if (match && parseInt(match[1]) > 0) {
                badge.className = 'sms-count-badge text-xs font-normal op-badge-warning';
                badge.textContent = match[1] + ' pending';
            }
        }
    }).catch(function(){});

    // Fetch device count
    opFetch('device-list', { show_limit: 1, page: 1 }).then(function(res) {
        var badge = document.querySelector('.device-count-badge');
        if (!badge) return;
        if (res.status === 'true' && res.datatableInfo) {
            var match = res.datatableInfo.match(/of\s*<strong>(\d+)/);
            if (match && parseInt(match[1]) > 0) {
                badge.className = 'device-count-badge text-xs font-normal op-badge-success';
                badge.textContent = match[1] + ' connected';
            }
        }
    }).catch(function(){});
</script>
