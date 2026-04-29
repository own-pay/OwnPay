<?php
if (!defined('OWNPAY_INIT')) { http_response_code(403); exit('Direct access not allowed'); }
if (!\OwnPay\Service\Auth\PermissionService::canAccessPage(json_decode($global_response_permission['response'][0]['permission'], true), 'brand_settings', $global_user_response['response'][0]['role'])) { http_response_code(403); exit('Access denied.'); }
if (!\OwnPay\Service\Auth\PermissionService::hasPermission(json_decode($global_response_permission['response'][0]['permission'], true), 'currency_settings', 'view', $global_user_response['response'][0]['role'])) { http_response_code(403); exit('Access denied.'); }
$allowSync = \OwnPay\Service\Auth\PermissionService::hasPermission(json_decode($global_response_permission['response'][0]['permission'], true), 'currency_settings', 'sync_rate', $global_user_response['response'][0]['role']);
$allowEdit = \OwnPay\Service\Auth\PermissionService::hasPermission(json_decode($global_response_permission['response'][0]['permission'], true), 'currency_settings', 'edit', $global_user_response['response'][0]['role']);
$allowImport = \OwnPay\Service\Auth\PermissionService::hasPermission(json_decode($global_response_permission['response'][0]['permission'], true), 'currency_settings', 'import', $global_user_response['response'][0]['role']);
?>
<div class="op-page-header"><div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
    <div>
        <nav class="flex mb-1"><ol class="inline-flex items-center space-x-1 text-sm text-gray-500"><li><a href="javascript:void(0)" onclick="load_content('Settings','<?php echo $site_url.$path_admin ?>/settings','nav-item-settings')" class="hover:text-primary-600">Settings</a></li><li class="flex items-center"><svg class="w-3 h-3 mx-1" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M7.293 14.707a1 1 0 010-1.414L10.586 10 7.293 6.707a1 1 0 011.414-1.414l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0z" clip-rule="evenodd"/></svg><span class="text-gray-900 dark:text-white">Currency Settings</span></li></ol></nav>
        <h2 class="op-page-title">Currency Settings</h2>
    </div>
    <div class="flex items-center gap-2">
        <span class="global-loaderSpinner"></span>
        <?php if ($allowSync): ?><button onclick="BulksyncRate()" class="btnbulksyncRate op-btn-secondary text-sm">Sync Rates</button><?php endif; ?>
        <?php if ($allowImport): ?><button onclick="BulkImportCurrency()" class="btnImportCurrency op-btn-primary text-sm">Import Currency</button><?php endif; ?>
    </div>
</div></div>

<div class="op-card">
    <div class="p-4 border-b border-gray-200 dark:border-gray-700"><div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
        <div class="flex items-center gap-2 text-sm text-gray-600 dark:text-gray-400">Show <input type="number" min="1" max="100" class="op-input w-16 text-center show_limit" value="8"> entries</div>
        <div class="flex items-center gap-2 text-sm text-gray-600 dark:text-gray-400">Search: <input type="text" class="op-input w-48 search_input"></div>
    </div></div>
    <div class="overflow-x-auto"><table class="op-table w-full text-sm">
        <thead><tr><th class="p-3">Code</th><th class="p-3">Symbol</th><th class="p-3">Rate</th><th class="p-3">Last Sync</th><th class="p-3"></th></tr></thead>
        <tbody class="table-data-list"></tbody>
    </table></div>
    <div class="p-4 border-t border-gray-200 dark:border-gray-700"><div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2">
        <p class="text-sm text-gray-600 dark:text-gray-400 table-data-list-entries"></p>
        <div class="table-data-list-pagination"></div>
    </div></div>
</div>

<!-- Edit Currency Modal (Flowbite) -->
<div id="modal-editItem" tabindex="-1" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-gray-900/50">
    <div class="relative w-full max-w-lg p-4"><div class="bg-white rounded-lg shadow dark:bg-gray-800">
        <div class="flex items-center justify-between p-4 border-b dark:border-gray-700"><h3 class="text-lg font-semibold dark:text-white">Edit Currency</h3><button type="button" onclick="document.getElementById('modal-editItem').classList.add('hidden')" class="text-gray-400 hover:text-gray-900 dark:hover:text-white"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg></button></div>
        <div class="p-4">
            <input type="hidden" name="currency-id">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div><label class="op-label">Code <span class="text-red-500">*</span></label><input type="text" class="op-input" name="currency-code" placeholder="Currency Code" readonly></div>
                <div><label class="op-label">Symbol <span class="text-red-500">*</span></label><input type="text" class="op-input" name="currency-symbol" placeholder="Currency Symbol"></div>
                <div class="md:col-span-2"><label class="op-label">Rate <span class="text-red-500">*</span></label>
                    <div class="flex"><span class="inline-flex items-center px-3 text-sm bg-gray-200 border border-e-0 border-gray-300 rounded-s-lg dark:bg-gray-600 dark:text-gray-400 dark:border-gray-600 currency-codeex"></span><input type="text" class="op-input rounded-none" name="currency-rate" placeholder="Currency Rate"><span class="inline-flex items-center px-3 text-sm bg-gray-200 border border-s-0 border-gray-300 rounded-e-lg dark:bg-gray-600 dark:text-gray-400 dark:border-gray-600"><?= $global_brand_currency_code ?></span></div>
                </div>
            </div>
        </div>
        <div class="flex justify-end gap-2 p-4 border-t dark:border-gray-700"><button type="button" onclick="document.getElementById('modal-editItem').classList.add('hidden')" class="op-btn-secondary">Cancel</button><button type="button" class="op-btn-primary modal-editItem-btn">Save Changes</button></div>
    </div></div>
</div>

<script nonce="<?= $csp_nonce ?? '' ?>" data-cfasync="false">
window.OP_DASHBOARD_URL='<?php echo $site_url.$path_admin ?>/dashboard';
function confirmAction(btnClass,title,label,cls,cb){var my=document.querySelector("#my-action-confirmation-btn")?.value||'';if(my!==""){cb();}else{show_action_confirmation_tab(btnClass,title,label,cls);}}

function BulkImportCurrency(){confirmAction('btnImportCurrency','Import Currency','Confirm','btn-primary',function(){
    var btnEl=document.querySelector('#model-my-action-confirmation-btn'),btn=btnEl.innerHTML;
    btnEl.innerHTML='<div class="op-spinner" style="width:16px;height:16px;border-width:2px"></div>';
    opFetch('currency-bulkImport',{}).then(res=>{closeAllModals();document.querySelector("#my-action-confirmation-btn").value='';btnEl.textContent=btn;if(res.status==='true'){apToast('success',res.title,res.message);load_data_list(1);}else{apToast('error',res.title,res.message);}}).catch(err=>{btnEl.textContent=btn;apToastError();});
});}

function BulksyncRate(){confirmAction('btnbulksyncRate','Sync Rate','Confirm','btn-primary',function(){
    var btnEl=document.querySelector('#model-my-action-confirmation-btn'),btn=btnEl.innerHTML;
    btnEl.innerHTML='<div class="op-spinner" style="width:16px;height:16px;border-width:2px"></div>';
    opFetch('currency-bulk-rateSync',{}).then(res=>{closeAllModals();document.querySelector("#my-action-confirmation-btn").value='';btnEl.textContent=btn;if(res.status==='true'){apToast('success',res.title,res.message);load_data_list(1);}else{apToast('error',res.title,res.message);}}).catch(err=>{btnEl.textContent=btn;apToastError();});
});}

function syncRate(ItemID){confirmAction('btnsyncRate-'+ItemID,'Sync Rate','Confirm','btn-primary',function(){
    var btnEl=document.querySelector('#model-my-action-confirmation-btn'),btn=btnEl.innerHTML;
    btnEl.innerHTML='<div class="op-spinner" style="width:16px;height:16px;border-width:2px"></div>';
    opFetch('currency-rateSync',{ItemID}).then(res=>{closeAllModals();document.querySelector("#my-action-confirmation-btn").value='';btnEl.textContent=btn;if(res.status==='true'){apToast('success',res.title,res.message);load_data_list(1);}else{apToast('error',res.title,res.message);}}).catch(err=>{btnEl.textContent=btn;apToastError();});
});}

function load_data_list(page=1){
    currentPage=page;var search=document.querySelector('.search_input').value,limit=document.querySelector('.show_limit').value;
    document.querySelector(".table-data-list").innerHTML=apSkeletonRows(5);
    opFetch('currency-list',{search_input:search,show_limit:limit,page}).then(res=>{
        let html='';
        if(res.status==='true'){
            res.response.forEach(item=>{
                let allowSync=<?= $allowSync?'true':'false' ?>,allowEdit=<?= $allowEdit?'true':'false' ?>;
                let border=item.default==="true"?'class="bg-primary-50 dark:bg-primary-900/10 border-l-2 border-primary-500"':'';
                html+=`<tr ${border} data-id="${apEscapeHtml(item.id)}"><td class="p-3">${apEscapeHtml(item.code)}</td><td class="p-3">${apEscapeHtml(item.symbol)}</td><td class="p-3">${apEscapeHtml(item.rate)}</td><td class="p-3">${apEscapeHtml(item.updated_date)}</td><td class="p-3 text-end"><div class="op-dropdown"><button class="op-btn-secondary text-xs" onclick="this.nextElementSibling.classList.toggle('hidden')">Actions ▾</button><div class="hidden absolute right-0 mt-1 w-40 bg-white rounded-lg shadow-lg border dark:bg-gray-800 dark:border-gray-700 z-50">${allowSync?`<a href="javascript:void(0)" onclick="syncRate('${apEscapeHtml(item.id)}')" class="btnsyncRate-${apEscapeHtml(item.id)} block px-3 py-2 text-sm hover:bg-gray-100 dark:hover:bg-gray-700">Sync Rate</a>`:''}${allowEdit?`<a href="javascript:void(0)" onclick="openEditModel('${apEscapeHtml(item.id)}')" class="block px-3 py-2 text-sm hover:bg-gray-100 dark:hover:bg-gray-700">Edit Currency</a>`:''}</div></div></td></tr>`;
            });
            document.querySelector(".table-data-list").innerHTML=html;
            document.querySelector(".table-data-list-entries").innerHTML=res.datatableInfo;
            document.querySelector(".table-data-list-pagination").innerHTML=res.pagination;
        }else{
            document.querySelector(".table-data-list").innerHTML=`<tr><td colspan="5">${apEmptyState(res.title,res.message)}</td></tr>`;
            document.querySelector(".table-data-list-entries").innerHTML='Showing <strong>0 to 0</strong> of <strong>0 entries</strong>';
            document.querySelector(".table-data-list-pagination").innerHTML='';
        }
    }).catch(err=>{document.querySelector(".table-data-list").innerHTML='<tr><td colspan="999" class="py-16 text-center"><div class="flex flex-col items-center justify-center"><div class="w-16 h-16 mb-3 rounded-full bg-red-50 dark:bg-red-900/20 flex items-center justify-center"><svg xmlns="http://www.w3.org/2000/svg" class="w-8 h-8 text-red-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M12 9v4m0 4h.01M5.07 19h13.86c1.54 0 2.5-1.67 1.73-3L13.73 4.99c-.77-1.33-2.69-1.33-3.46 0L3.34 16c-.77 1.33.19 3 1.73 3z"/></svg></div><h3 class="text-base font-semibold text-gray-900 dark:text-white mb-1">Connection Error</h3><p class="text-sm text-gray-500 dark:text-gray-400">Unable to load data. Please check your connection and try again.</p></div></td></tr>';apToastError(err);});
}
document.addEventListener('click',function(e){if(e.target.matches('[data-page]')){load_data_list(parseInt(e.target.dataset.page));}});
load_data_list(1);
document.querySelectorAll('.search_input,.show_limit').forEach(el=>{el.addEventListener('change',()=>load_data_list(1));});

function openEditModel(itemID){
    document.querySelector('.global-loaderSpinner').innerHTML='<div class="op-spinner"></div>';
    opFetch('currency-info-byID',{ItemID:itemID}).then(res=>{
        document.querySelector('.global-loaderSpinner').innerHTML='';
        if(res.status==='true'){var m=document.getElementById('modal-editItem');m.querySelector('input[name="currency-id"]').value=itemID;m.querySelector('input[name="currency-code"]').value=res.code||'';m.querySelector('input[name="currency-symbol"]').value=res.symbol||'';m.querySelector('input[name="currency-rate"]').value=res.rate||'';m.querySelector('.currency-codeex').innerHTML='1 '+res.code+' = ';m.classList.remove('hidden');}
        else{apToast('error',res.title,res.message);}
    }).catch(err=>{document.querySelector('.global-loaderSpinner').textContent='';apToastError();});
}

document.querySelector('.modal-editItem-btn').addEventListener('click',function(){
    var m=document.getElementById('modal-editItem'),id=m.querySelector('input[name="currency-id"]').value,sym=m.querySelector('input[name="currency-symbol"]').value,rate=m.querySelector('input[name="currency-rate"]').value;
    if(!id||!sym||!rate){apToast('error','Incomplete','Please fill in all required fields.');return;}
    var btnEl=this,btn=btnEl.innerHTML;btnEl.innerHTML='<div class="op-spinner" style="width:16px;height:16px;border-width:2px"></div>';
    opFetch('currency-edit',{currency_id:id,currency_symbol:sym,currency_rate:rate}).then(res=>{document.getElementById('modal-editItem').classList.add('hidden');btnEl.textContent=btn;if(res.status==='true'){apToast('success',res.title,res.message);load_data_list(1);}else{apToast('error',res.title,res.message);}}).catch(err=>{btnEl.textContent='Save Changes';apToastError();});
});
</script>
