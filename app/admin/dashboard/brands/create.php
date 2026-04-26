<?php
    if (!defined('OWNPAY_INIT')) { http_response_code(403); exit('Direct access not allowed'); }
    if (!canAccessPage(json_decode($global_response_permission['response'][0]['permission'], true), 'brands', $global_user_response['response'][0]['role'])) { http_response_code(403); exit('Access denied.'); }
    if (!hasPermission(json_decode($global_response_permission['response'][0]['permission'], true), 'brands', 'create', $global_user_response['response'][0]['role'])) { http_response_code(403); exit('Access denied.'); }
?>

<div class="op-page-header">
    <div>
        <nav class="flex mb-1" aria-label="Breadcrumb">
            <ol class="inline-flex items-center space-x-1 text-sm text-gray-500 dark:text-gray-400">
                <li><a href="javascript:void(0)" onclick="load_content('All Brands','<?php echo $site_url.$path_admin ?>/brands','nav-item-brands')" class="hover:text-primary-600">All Brands</a></li>
                <li class="flex items-center"><svg class="w-3 h-3 mx-1" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M7.293 14.707a1 1 0 010-1.414L10.586 10 7.293 6.707a1 1 0 011.414-1.414l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0z" clip-rule="evenodd"></path></svg><span class="text-gray-900 dark:text-white">Create Brand</span></li>
            </ol>
        </nav>
        <h2 class="op-page-title">Create Brand</h2>
    </div>
</div>

<form class="form-create-brand">
    <input type="hidden" name="action" value="create-new-brand">
    <input type="hidden" name="csrf_token" value="<?= $csrf_token; ?>">
    <div class="op-card">
        <div class="p-4">
            <label class="op-label">Brand Name <span class="text-red-500">*</span></label>
            <input type="text" class="op-input" name="brand-name" placeholder="Brand Name" required>
        </div>
    </div>
    <div class="text-end pt-3"><button class="op-btn-primary btn-create-brand" type="submit">Create</button></div>
</form>

<script nonce="<?= $csp_nonce ?? '' ?>" data-cfasync="false">
    window.OP_DASHBOARD_URL = '<?php echo $site_url.$path_admin ?>/dashboard';
    document.querySelector('.form-create-brand').addEventListener('submit', function(e){
        e.preventDefault();
        var btnEl = document.querySelector('.btn-create-brand'); var btn = btnEl.innerHTML;
        btnEl.innerHTML = '<div class="op-spinner" style="width:16px;height:16px;border-width:2px"></div>';
        fetch('<?php echo $site_url ?>dashboard', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: new URLSearchParams(new FormData(this)).toString() })
            .then(r => r.json()).then(response => {
                apRotateCsrf(response.csrf_token); btnEl.innerHTML = btn;
                if(response.status === 'true'){ apToast('success', response.title, response.message); location.href = "<?php echo $site_url.$path_admin ?>/brands"; }
                else { apToast('error', response.title, response.message); }
            }).catch(err => apToastError());
    });
</script>
