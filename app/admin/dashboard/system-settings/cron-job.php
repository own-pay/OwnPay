<?php
if (!defined('OWNPAY_INIT')) { http_response_code(403); exit('Direct access not allowed'); }
if (!canAccessPage(json_decode($global_response_permission['response'][0]['permission'], true), 'system_settings', $global_user_response['response'][0]['role'])) { http_response_code(403); exit('Access denied.'); }
if (!hasPermission(json_decode($global_response_permission['response'][0]['permission'], true), 'system_settings', 'manage_cron', $global_user_response['response'][0]['role'])) { http_response_code(403); exit('Access denied.'); }
?>
<div class="op-page-header"><div>
    <nav class="flex mb-1"><ol class="inline-flex items-center space-x-1 text-sm text-gray-500"><li><a href="javascript:void(0)" onclick="load_content('Settings','<?php echo $site_url.$path_admin ?>/settings?tab=system','nav-item-settings')" class="hover:text-primary-600">Settings</a></li><li class="flex items-center"><svg class="w-3 h-3 mx-1" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M7.293 14.707a1 1 0 010-1.414L10.586 10 7.293 6.707a1 1 0 011.414-1.414l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0z" clip-rule="evenodd"/></svg><span class="text-gray-900 dark:text-white">Cron Job</span></li></ol></nav>
    <h2 class="op-page-title">Cron Job</h2>
</div></div>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    <div><h3 class="font-semibold text-gray-900 dark:text-white">Command-Line Cron</h3><p class="text-sm text-gray-500 mt-1">Use it to automate background tasks.</p></div>
    <div class="lg:col-span-2">
        <div class="op-card"><div class="p-4">
            <label class="op-label">Cron Command</label>
            <div class="flex">
                <input type="text" class="op-input rounded-e-none" id="cron-command" readonly value="curl -s <?php echo $site_url.$path_cron?>/<?= get_env('cron-job'); ?> >/dev/null 2>&1">
                <button class="px-3 border border-s-0 border-gray-300 bg-gray-50 hover:bg-gray-100 rounded-e-lg dark:bg-gray-700 dark:border-gray-600 cron-command-copy" type="button" title="Copy">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"/></svg>
                </button>
                <button class="px-3 border border-s-0 border-gray-300 bg-gray-50 hover:bg-gray-100 rounded-e-lg dark:bg-gray-700 dark:border-gray-600 cron-command-generate" type="button" title="Generate New Cron Key">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                </button>
            </div>
            <p class="text-xs text-gray-500 mt-1">Copy your current cron command or generate a new key with a single click.</p>
        </div></div>
    </div>
</div>

<script nonce="<?= $csp_nonce ?? '' ?>" data-cfasync="false">
window.OP_DASHBOARD_URL='<?php echo $site_url.$path_admin ?>/dashboard';
document.querySelector('.cron-command-copy').addEventListener('click',function(){const v=document.getElementById('cron-command').value;navigator.clipboard.writeText(v).then(()=>apToast('success','Copied!','Cron command copied to clipboard.')).catch(()=>apToast('error','Failed!','Unable to copy. Please try manually.'));});
document.querySelector('.cron-command-generate').addEventListener('click',function(){
    var my=document.querySelector("#my-action-confirmation-btn")?.value||'';
    var btnClass='cron-command-generate';
    if(my!==""){
        var btnEl=document.querySelector('.'+btnClass),btn=btnEl.innerHTML;
        btnEl.innerHTML='<div class="op-spinner" style="width:16px;height:16px;border-width:2px"></div>';
        opFetch('cron-job-command-generate',{}).then(res=>{
            closeAllModals();document.querySelector("#my-action-confirmation-btn").value='';btnEl.innerHTML=btn;
            if(res.status==='true'){apToast('success',res.title,res.message);document.getElementById('cron-command').value='curl -s <?php echo $site_url?>cron/'+res.cron_command+' >/dev/null 2>&1';}
            else{apToast('error',res.title,res.message);}
        }).catch(err=>apToastError());
    }else{show_action_confirmation_tab(btnClass,'Generate Cron Command','Generate','btn-primary');}
});
</script>
