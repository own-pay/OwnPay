<?php
if (!defined('OWNPAY_INIT')) { http_response_code(403); exit('Direct access not allowed'); }
if (!canAccessPage(json_decode($global_response_permission['response'][0]['permission'], true), 'system_settings', $global_user_response['response'][0]['role'])) { http_response_code(403); exit('Access denied.'); }
if (!hasPermission(json_decode($global_response_permission['response'][0]['permission'], true), 'system_settings', 'manage_import', $global_user_response['response'][0]['role'])) { http_response_code(403); exit('Access denied.'); }
?>
<div class="op-page-header"><div>
    <nav class="flex mb-1"><ol class="inline-flex items-center space-x-1 text-sm text-gray-500"><li><a href="javascript:void(0)" onclick="load_content('Settings','<?php echo $site_url.$path_admin ?>/settings?tab=system','nav-item-settings')" class="hover:text-primary-600">Settings</a></li><li class="flex items-center"><svg class="w-3 h-3 mx-1" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M7.293 14.707a1 1 0 010-1.414L10.586 10 7.293 6.707a1 1 0 011.414-1.414l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0z" clip-rule="evenodd"/></svg><span class="text-gray-900 dark:text-white">Import</span></li></ol></nav>
    <h2 class="op-page-title">Import</h2>
</div></div>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    <div><h3 class="font-semibold text-gray-900 dark:text-white">Import</h3><p class="text-sm text-gray-500 mt-1">Import themes, add-ons, payment gateways, or any other modules</p></div>
    <div class="lg:col-span-2">
        <div class="op-card"><div class="p-4">
            <form class="form-import" enctype="multipart/form-data">
                <input type="hidden" name="action" value="system-settings-import">
                <input type="hidden" name="csrf_token" value="<?= $csrf_token; ?>">
                <label class="op-label">Upload ZIP File</label>
                <input type="file" name="zip_file" class="op-input" accept=".zip" required>
                <p class="text-xs text-gray-500 mt-1">Select a ZIP file from your device and upload it.</p>
                <button class="op-btn-primary btn-save-changes mt-3" type="submit">Upload</button>
            </form>
        </div></div>
    </div>
</div>

<script nonce="<?= $csp_nonce ?? '' ?>" data-cfasync="false">
window.OP_DASHBOARD_URL='<?php echo $site_url.$path_admin ?>/dashboard';
document.querySelector('.form-import').addEventListener('submit',function(e){e.preventDefault();let fd=new FormData(this);var btnEl=document.querySelector('.btn-save-changes'),btn=btnEl.innerHTML;btnEl.innerHTML='<div class="op-spinner" style="width:16px;height:16px;border-width:2px"></div>';fetch('<?php echo $site_url.$path_admin ?>/dashboard',{method:'POST',body:fd}).then(r=>r.json()).then(res=>{apRotateCsrf(res.csrf_token);btnEl.innerHTML=btn;if(res.status==='true'){document.querySelector('.form-import').reset();apToast('success',res.title,res.message);}else{apToast('error',res.title,res.message);}}).catch(err=>apToastError());});
</script>
