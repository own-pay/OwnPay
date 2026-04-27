<?php
    if (!defined('OWNPAY_INIT')) { http_response_code(403); exit('Direct access not allowed'); }
    if (!canAccessPage(json_decode($global_response_permission['response'][0]['permission'], true), 'brands', $global_user_response['response'][0]['role'])) { http_response_code(403); exit('Access denied.'); }
    if (!hasPermission(json_decode($global_response_permission['response'][0]['permission'], true), 'brands', 'edit', $global_user_response['response'][0]['role'])) { http_response_code(403); exit('Access denied.'); }

    $params = json_decode($_POST['params'] ?? '{}', true);
    $b_id = getParam($params, 'b_id');
    if ($b_id === null) { http_response_code(403); exit('Invalid brand id'); }

    $b_id = clean_input($b_id);
    $response_brands = json_decode(getData($db_prefix.'brands','WHERE brand_id = :brand_id', '* FROM', [':brand_id' => $b_id]),true);
    if($response_brands['status'] == true){
        if($response_brands['response'][0]['id'] == 1){ http_response_code(403); exit("You can't edit default brand"); }
    } else { http_response_code(403); exit('Direct access not allowed'); }
?>

<div class="op-page-header">
    <div>
        <nav class="flex mb-1" aria-label="Breadcrumb">
            <ol class="inline-flex items-center space-x-1 text-sm text-gray-500 dark:text-gray-400">
                <li><a href="javascript:void(0)" onclick="load_content('All Brands','<?php echo htmlspecialchars((string) ($site_url.$path_admin), ENT_QUOTES, 'UTF-8'); ?>/brands','nav-item-brands')" class="hover:text-primary-600">All Brands</a></li>
                <li class="flex items-center"><svg class="w-3 h-3 mx-1" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M7.293 14.707a1 1 0 010-1.414L10.586 10 7.293 6.707a1 1 0 011.414-1.414l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0z" clip-rule="evenodd"></path></svg><span class="text-gray-900 dark:text-white">Edit Brand</span></li>
            </ol>
        </nav>
        <h2 class="op-page-title">Edit Brand</h2>
    </div>
</div>

<form class="form-edit-brand">
    <input type="hidden" name="action" value="edit-brand">
    <input type="hidden" name="b_id" value="<?= htmlspecialchars((string) ($b_id), ENT_QUOTES, 'UTF-8'); ?>">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) ($csrf_token), ENT_QUOTES, 'UTF-8'); ?>">
    <div class="op-card">
        <div class="p-4">
            <label class="op-label">Brand Name <span class="text-red-500">*</span></label>
            <input type="text" class="op-input" name="brand-name" placeholder="Brand Name" value="<?php echo htmlspecialchars((string) ($response_brands['response'][0]['identify_name']), ENT_QUOTES, 'UTF-8'); ?>" required>
        </div>
    </div>
    <div class="text-end pt-3"><button class="op-btn-primary btn-edit-brand" type="submit">Save Changes</button></div>
</form>

<script nonce="<?= htmlspecialchars((string) ($csp_nonce ?? ''), ENT_QUOTES, 'UTF-8'); ?>" data-cfasync="false">
    window.OP_DASHBOARD_URL = '<?php echo htmlspecialchars((string) ($site_url.$path_admin), ENT_QUOTES, 'UTF-8'); ?>/dashboard';
    document.querySelector('.form-edit-brand').addEventListener('submit', function(e){
        e.preventDefault();
        var btnEl = document.querySelector('.btn-edit-brand'); var btn = btnEl.innerHTML;
        btnEl.innerHTML = '<div class="op-spinner" style="width:16px;height:16px;border-width:2px"></div>';
        fetch('<?php echo htmlspecialchars((string) ($site_url), ENT_QUOTES, 'UTF-8'); ?>dashboard', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: new URLSearchParams(new FormData(this)).toString() })
            .then(r => r.json()).then(response => {
                apRotateCsrf(response.csrf_token); btnEl.innerHTML = btn;
                if(response.status === 'true'){ apToast('success', response.title, response.message); location.reload(); }
                else { apToast('error', response.title, response.message); }
            }).catch(err => apToastError());
    });
</script>
