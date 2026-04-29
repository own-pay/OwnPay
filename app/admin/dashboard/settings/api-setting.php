<?php
if (!defined('OWNPAY_INIT')) { http_response_code(403); exit('Direct access not allowed'); }
if (!\OwnPay\Service\Auth\PermissionService::canAccessPage(json_decode($global_response_permission['response'][0]['permission'], true), 'brand_settings', $global_user_response['response'][0]['role'])) { http_response_code(403); exit('Access denied.'); }
if (!\OwnPay\Service\Auth\PermissionService::hasPermission(json_decode($global_response_permission['response'][0]['permission'], true), 'api_settings', 'view', $global_user_response['response'][0]['role'])) { http_response_code(403); exit('Access denied.'); }
$allowCreate = \OwnPay\Service\Auth\PermissionService::hasPermission(json_decode($global_response_permission['response'][0]['permission'], true), 'api_settings', 'create', $global_user_response['response'][0]['role']);
$allowEdit = \OwnPay\Service\Auth\PermissionService::hasPermission(json_decode($global_response_permission['response'][0]['permission'], true), 'api_settings', 'edit', $global_user_response['response'][0]['role']);
$allowDelete = \OwnPay\Service\Auth\PermissionService::hasPermission(json_decode($global_response_permission['response'][0]['permission'], true), 'api_settings', 'delete', $global_user_response['response'][0]['role']);
$apiScopes = [['value'=>'create_payment','label'=>'Create Payment'],['value'=>'verify_payment','label'=>'Verify Payment'],['value'=>'refund_payment','label'=>'Refund Payment']];
$apiEndpoints = [['label'=>'Base URL','value'=>$site_url.'api','hint'=>'Root API endpoint.'],['label'=>'Gateway Checkout','value'=>$site_url.'api/checkout/redirect','hint'=>'Redirects to hosted checkout.'],['label'=>'Verify Payment','value'=>$site_url.'api/verify-payment','hint'=>'Checks payment status.'],['label'=>'Refund Payment','value'=>$site_url.'api/refund-payment','hint'=>'Use this to refund payment.']];
?>
<div class="op-page-header"><div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
    <div>
        <nav class="flex mb-1"><ol class="inline-flex items-center space-x-1 text-sm text-gray-500"><li><a href="javascript:void(0)" onclick="load_content('Settings','<?php echo $site_url.$path_admin ?>/settings','nav-item-settings')" class="hover:text-primary-600">Settings</a></li><li class="flex items-center"><svg class="w-3 h-3 mx-1" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M7.293 14.707a1 1 0 010-1.414L10.586 10 7.293 6.707a1 1 0 011.414-1.414l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0z" clip-rule="evenodd"/></svg><span class="text-gray-900 dark:text-white">API Settings</span></li></ol></nav>
        <h2 class="op-page-title">API Settings</h2>
    </div>
    <div class="flex items-center gap-2">
        <span class="global-loaderSpinner"></span>
        <a href="https://OwnPay.readme.io/" target="_blank" class="op-btn-secondary text-sm">API Docs</a>
        <button onclick="document.getElementById('modal-apiEndPoint').classList.remove('hidden')" class="op-btn-secondary text-sm">Endpoints</button>
        <?php if ($allowCreate): ?><button onclick="document.getElementById('modal-createItem').classList.remove('hidden')" class="op-btn-primary text-sm">Create API</button><?php endif; ?>
    </div>
</div></div>

<div class="op-card">
    <!-- Filters -->
    <div class="border-b border-gray-200 dark:border-gray-700">
        <div class="filter-tab-data hidden p-4"><div class="flex justify-between mb-3"><h3 class="text-sm font-semibold">Filters</h3><button class="text-red-500 text-sm" onclick="filter_hide_show_reset('filter-tab-data')">Reset</button></div>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                <div><label class="op-label">Status</label><select class="op-select" id="filter-status"><option value="">All</option><option value="active">Active</option><option value="inactive">Inactive</option></select></div>
                <div><label class="op-label">Created From</label><input type="date" class="op-input" id="filter-created-from"></div>
                <div><label class="op-label">Created Until</label><input type="date" class="op-input" id="filter-created-until"></div>
            </div>
        </div>
        <div class="flex justify-end p-3"><button onclick="filter_hide_show('filter-tab-data')" class="text-gray-500 hover:text-gray-900"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z"/></svg></button></div>
    </div>
    <div class="p-4 border-b border-gray-200 dark:border-gray-700"><div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
        <div class="flex items-center gap-2 text-sm text-gray-600 dark:text-gray-400">Show <input type="number" min="1" max="100" class="op-input w-16 text-center show_limit" value="8"> entries</div>
        <div class="flex items-center gap-2"><input type="text" class="op-input w-48 search_input" placeholder="Search..."><button class="op-btn-danger text-sm bulk-action hidden" onclick="document.getElementById('model-bulkAction').classList.remove('hidden')">Bulk <span id="bulkActionBTN-count">(0)</span></button></div>
    </div></div>
    <div class="overflow-x-auto"><table class="op-table w-full text-sm">
        <thead><tr><th class="p-3 w-8"><input class="op-checkbox select-all" type="checkbox"></th><th class="p-3">Name</th><th class="p-3">API Key</th><th class="p-3">Created Date</th><th class="p-3">Status</th><th class="p-3"></th></tr></thead>
        <tbody class="table-data-list"></tbody>
    </table></div>
    <div class="p-4 border-t border-gray-200 dark:border-gray-700"><div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2">
        <p class="text-sm text-gray-600 dark:text-gray-400 table-data-list-entries"></p>
        <div class="table-data-list-pagination"></div>
    </div></div>
</div>

<!-- Bulk Action Modal -->
<div id="model-bulkAction" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-gray-900/50"><div class="relative w-full max-w-md p-4"><div class="bg-white rounded-lg shadow dark:bg-gray-800">
    <div class="flex items-center justify-between p-4 border-b dark:border-gray-700"><h3 class="text-lg font-semibold dark:text-white">Bulk Action</h3><button onclick="document.getElementById('model-bulkAction').classList.add('hidden')" class="text-gray-400 hover:text-gray-900 dark:hover:text-white"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg></button></div>
    <div class="p-4"><label class="op-label">Action <span class="text-red-500">*</span></label><select class="op-select" id="model-bulkActionID"><option value="">Select Action</option><?= $allowDelete ? '<option value="deleted">Delete Selected</option>' : '' ?><?= $allowEdit ? '<option value="activated">Activate Selected</option><option value="inactivated">Inactivate Selected</option>' : '' ?></select></div>
    <div class="flex justify-end gap-2 p-4 border-t dark:border-gray-700"><button onclick="document.getElementById('model-bulkAction').classList.add('hidden')" class="op-btn-secondary">Close</button><button class="op-btn-primary model-bulkAction-btn">Confirm</button></div>
</div></div></div>

<!-- API Endpoints Modal -->
<div id="modal-apiEndPoint" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-gray-900/50"><div class="relative w-full max-w-2xl p-4"><div class="bg-white rounded-lg shadow dark:bg-gray-800">
    <div class="flex items-center justify-between p-4 border-b dark:border-gray-700"><h3 class="text-lg font-semibold dark:text-white">API Endpoints</h3><button onclick="document.getElementById('modal-apiEndPoint').classList.add('hidden')" class="text-gray-400 hover:text-gray-900 dark:hover:text-white"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg></button></div>
    <div class="p-4"><div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <?php foreach ($apiEndpoints as $ep): ?>
        <div><label class="op-label"><?= $ep['label'] ?></label>
            <div class="flex"><input type="text" class="op-input rounded-e-none text-xs" value="<?= $ep['value'] ?>" readonly><button class="px-3 border border-s-0 border-gray-300 bg-gray-50 hover:bg-gray-100 rounded-e-lg dark:bg-gray-700 dark:border-gray-600" onclick="copyContent('<?= $ep['value'] ?>','Copied!','<?= $ep['label'] ?> copied.')"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"/></svg></button></div>
            <p class="text-xs text-gray-500 mt-1"><?= $ep['hint'] ?></p>
        </div>
        <?php endforeach; ?>
    </div></div>
    <div class="flex justify-end p-4 border-t dark:border-gray-700"><button onclick="document.getElementById('modal-apiEndPoint').classList.add('hidden')" class="op-btn-secondary">Close</button></div>
</div></div></div>

<?php
// Reusable modal fragment for create/edit
function renderApiFormModal($id, $title, $btnLabel, $btnClass, $scopes, $isEdit = false) { ?>
<div id="<?= $id ?>" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-gray-900/50"><div class="relative w-full max-w-2xl p-4"><div class="bg-white rounded-lg shadow dark:bg-gray-800">
    <div class="flex items-center justify-between p-4 border-b dark:border-gray-700"><h3 class="text-lg font-semibold dark:text-white"><?= $title ?></h3><button onclick="document.getElementById('<?= $id ?>').classList.add('hidden')" class="text-gray-400 hover:text-gray-900 dark:hover:text-white"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg></button></div>
    <div class="p-4">
        <?php if ($isEdit): ?><input type="hidden" name="api-id"><?php endif; ?>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
            <div><label class="op-label">Name <span class="text-red-500">*</span></label><input type="text" class="op-input" name="api-name" placeholder="API name"></div>
            <div><label class="op-label">Expire Date</label><input type="date" class="op-input" name="apiExpiryDate"></div>
        </div>
        <div class="mb-4"><label class="op-label">API Scopes</label><div class="op-card p-3"><div class="grid grid-cols-3 gap-2">
            <?php foreach ($scopes as $s): ?>
            <label class="flex items-center gap-2 text-sm"><input type="checkbox" class="op-checkbox" name="api_scopes[]" value="<?= $s['value'] ?>" checked><span><?= $s['label'] ?></span></label>
            <?php endforeach; ?>
        </div></div></div>
        <div><label class="op-label">Status <span class="text-red-500">*</span></label><div class="grid grid-cols-2 gap-3">
            <label class="op-card p-3 cursor-pointer has-[:checked]:ring-2 has-[:checked]:ring-primary-500"><input type="radio" name="api-status" value="active" class="sr-only" checked><span class="font-medium text-sm">Active</span><br><span class="text-xs text-gray-500">Can initiate payments</span></label>
            <label class="op-card p-3 cursor-pointer has-[:checked]:ring-2 has-[:checked]:ring-primary-500"><input type="radio" name="api-status" value="inactive" class="sr-only"><span class="font-medium text-sm">Inactive</span><br><span class="text-xs text-gray-500">Cannot initiate payments</span></label>
        </div></div>
    </div>
    <div class="flex justify-end gap-2 p-4 border-t dark:border-gray-700"><button onclick="document.getElementById('<?= $id ?>').classList.add('hidden')" class="op-btn-secondary">Cancel</button><button class="op-btn-primary <?= $btnClass ?>"><?= $btnLabel ?></button></div>
</div></div></div>
<?php }
renderApiFormModal('modal-createItem', 'New API Key', 'Create', 'modal-createItem-btn', $apiScopes, false);
renderApiFormModal('modal-editItem', 'Edit API Key', 'Save Changes', 'modal-editItem-btn', $apiScopes, true);
?>

<script nonce="<?= $csp_nonce ?? '' ?>" data-cfasync="false">
window.OP_DASHBOARD_URL='<?php echo $site_url.$path_admin ?>/dashboard';
function copyContent(text,title,desc){navigator.clipboard.writeText(text).then(()=>apToast('success',title,desc)).catch(()=>apToast('error','Failed','Unable to copy.'));}
function initCheckboxTable(){const sa=document.querySelector('.select-all'),rcs=document.querySelectorAll('.rowCheckbox'),ba=document.querySelector('.bulk-action');function up(){const s=document.querySelectorAll('.rowCheckbox:checked');document.getElementById('bulkActionBTN-count').innerHTML=`(${s.length})`;s.length>0?ba.classList.remove('hidden'):ba.classList.add('hidden');}sa.addEventListener('change',()=>{rcs.forEach(c=>c.checked=sa.checked);up();});rcs.forEach(c=>{c.addEventListener('change',()=>{sa.checked=rcs.length===document.querySelectorAll('.rowCheckbox:checked').length;up();});});}

document.querySelector('.model-bulkAction-btn').addEventListener('click',function(){
    var actionID=document.getElementById('model-bulkActionID').value,my=document.querySelector("#my-action-confirmation-btn")?.value||'';
    if(!actionID){apToast('error','Action Required','Please choose an action.');return;}
    const ids=Array.from(document.querySelectorAll('.rowCheckbox:checked')).map(c=>c.closest('tr').dataset.id);
    if(my!==""){document.querySelector('.global-loaderSpinner').innerHTML='<div class="op-spinner"></div>';
        opFetch('api-bulk-action',{actionID,selected_ids:JSON.stringify(ids)}).then(res=>{closeAllModals();document.querySelector("#my-action-confirmation-btn").value='';document.getElementById('model-bulkActionID').selectedIndex=0;document.querySelector('.global-loaderSpinner').innerHTML='';if(res.status==='true'){document.querySelectorAll('.select-all').forEach(c=>c.checked=false);document.querySelector('.bulk-action').classList.add('hidden');apToast('success',res.title,res.message);load_data_list(1);}else{apToast('error',res.title,res.message);}}).catch(err=>{document.querySelector('.global-loaderSpinner').textContent='';apToastError();});
    }else{show_action_confirmation_tab('model-bulkAction-btn','Confirm Action','Confirm','btn-danger');}
});

function deleteItem(ItemID){var my=document.querySelector("#my-action-confirmation-btn")?.value||'',btnClass='btnDeleteItem-'+ItemID;if(my!==""){var btnEl=document.querySelector('#model-my-action-confirmation-btn'),btn=btnEl.innerHTML;btnEl.innerHTML='<div class="op-spinner" style="width:16px;height:16px;border-width:2px"></div>';opFetch('api-delete',{ItemID}).then(res=>{closeAllModals();document.querySelector("#my-action-confirmation-btn").value='';btnEl.innerHTML=btn;if(res.status==='true'){apToast('success',res.title,res.message);load_data_list(1);}else{apToast('error',res.title,res.message);}}).catch(err=>{btnEl.textContent=btn;apToastError();});}else{show_action_confirmation_tab(btnClass,'Delete API Key','Delete','btn-danger');}}

function load_data_list(page=1){
    currentPage=page;var search=document.querySelector('.search_input').value,limit=document.querySelector('.show_limit').value,fs=document.getElementById('filter-status')?.value||'',fd1=document.getElementById('filter-created-from')?.value||'',fd2=document.getElementById('filter-created-until')?.value||'';
    document.querySelector(".table-data-list").innerHTML=apSkeletonRows(6);
    opFetch('api-list',{search_input:search,show_limit:limit,page,filter_status:fs,filter_start:fd1,filter_end:fd2}).then(res=>{
        let html='';if(res.status==='true'){
            res.response.forEach(item=>{
                let allowEdit=<?= $allowEdit?'true':'false' ?>,allowDelete=<?= $allowDelete?'true':'false' ?>;
                let badge=item.status==='active'?'op-badge-success':item.status==='inactive'?'op-badge-danger':'op-badge-secondary';
                html+=`<tr data-id="${apEscapeHtml(item.id)}"><td class="p-3"><input class="op-checkbox rowCheckbox" type="checkbox"></td><td class="p-3 cursor-pointer" ${allowEdit?`onclick="openEditModel('${apEscapeHtml(item.id)}')"`:''}>${apEscapeHtml(item.name)}</td><td class="p-3"><div class="flex items-center gap-1 max-w-[250px]"><input type="text" value="${apEscapeHtml(item.api_key)}" class="op-input text-xs" readonly><button class="px-2 py-1 text-gray-500 hover:text-primary-600" onclick="copyContent('${apEscapeHtml(item.api_key)}','Copied!','API Key copied.')"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"/></svg></button></div></td><td class="p-3">${apEscapeHtml(item.created_date)}</td><td class="p-3"><span class="${badge}">${apEscapeHtml(item.status.charAt(0).toUpperCase()+item.status.slice(1))}</span></td><td class="p-3 text-end"><div class="op-dropdown inline-block"><button class="op-btn-secondary text-xs" onclick="this.nextElementSibling.classList.toggle('hidden')">Actions ▾</button><div class="hidden absolute right-0 mt-1 w-36 bg-white rounded-lg shadow-lg border dark:bg-gray-800 dark:border-gray-700 z-50">${allowEdit?`<a href="javascript:void(0)" onclick="openEditModel('${apEscapeHtml(item.id)}')" class="block px-3 py-2 text-sm hover:bg-gray-100 dark:hover:bg-gray-700">Edit</a>`:''}${allowDelete?`<a href="javascript:void(0)" onclick="deleteItem('${apEscapeHtml(item.id)}')" class="btnDeleteItem-${apEscapeHtml(item.id)} block px-3 py-2 text-sm text-red-600 hover:bg-gray-100 dark:hover:bg-gray-700">Delete</a>`:''}</div></div></td></tr>`;
            });
            document.querySelector(".table-data-list").innerHTML=html;initCheckboxTable();
            document.querySelector(".table-data-list-entries").innerHTML=res.datatableInfo;
            document.querySelector(".table-data-list-pagination").innerHTML=res.pagination;
        }else{document.querySelector(".table-data-list").innerHTML=`<tr><td colspan="6">${apEmptyState(res.title,res.message)}</td></tr>`;document.querySelector(".table-data-list-entries").innerHTML='Showing <strong>0 to 0</strong> of <strong>0 entries</strong>';document.querySelector(".table-data-list-pagination").innerHTML='';}
    }).catch(err=>{document.querySelector(".table-data-list").innerHTML='<tr><td colspan="999" class="py-16 text-center"><div class="flex flex-col items-center justify-center"><div class="w-16 h-16 mb-3 rounded-full bg-red-50 dark:bg-red-900/20 flex items-center justify-center"><svg xmlns="http://www.w3.org/2000/svg" class="w-8 h-8 text-red-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M12 9v4m0 4h.01M5.07 19h13.86c1.54 0 2.5-1.67 1.73-3L13.73 4.99c-.77-1.33-2.69-1.33-3.46 0L3.34 16c-.77 1.33.19 3 1.73 3z"/></svg></div><h3 class="text-base font-semibold text-gray-900 dark:text-white mb-1">Connection Error</h3><p class="text-sm text-gray-500 dark:text-gray-400">Unable to load data. Please check your connection and try again.</p></div></td></tr>';apToastError(err);});
}
document.addEventListener('click',function(e){if(e.target.matches('[data-page]')){load_data_list(parseInt(e.target.dataset.page));}});
load_data_list(1);
function filter_hide_show_reset(cn){const c=document.querySelector('.'+cn);if(!c)return;c.querySelectorAll('input').forEach(i=>i.value='');c.querySelectorAll('select').forEach(s=>s.selectedIndex=0);load_data_list(1);}
document.querySelectorAll('.filter-tab-data input,.filter-tab-data select,.search_input,.show_limit').forEach(el=>{el.addEventListener('change',()=>load_data_list(1));});

function openEditModel(itemID){
    document.querySelector('.global-loaderSpinner').innerHTML='<div class="op-spinner"></div>';
    opFetch('api-info-byID',{ItemID:itemID}).then(res=>{document.querySelector('.global-loaderSpinner').innerHTML='';if(res.status==='true'){const m=document.getElementById('modal-editItem');m.querySelector('input[name="api-id"]').value=itemID;m.querySelector('input[name="api-name"]').value=res.name||'';m.querySelector('input[name="apiExpiryDate"]').value=res.expired_date||'';m.querySelectorAll('input[name="api-status"]').forEach(r=>r.checked=r.value===res.astatus);const scopes=Array.isArray(res.api_scopes)?res.api_scopes:[];m.querySelectorAll('input[name="api_scopes[]"]').forEach(cb=>cb.checked=scopes.includes(cb.value));m.classList.remove('hidden');}else{apToast('error',res.title,res.message);}}).catch(err=>{document.querySelector('.global-loaderSpinner').textContent='';apToastError();});
}

function handleApiSubmit(modalId,action,isEdit){
    const m=document.getElementById(modalId),name=m.querySelector('input[name="api-name"]').value,exp=m.querySelector('input[name="apiExpiryDate"]').value;
    let scopes=[];m.querySelectorAll('input[name="api_scopes[]"]:checked').forEach(c=>scopes.push(c.value));
    const st=m.querySelector('input[name="api-status"]:checked')?.value||'';
    if(!name||!st){apToast('error','Incomplete','Please fill required fields.');return;}
    const btnClass=isEdit?'modal-editItem-btn':'modal-createItem-btn',btnEl=m.querySelector('.'+btnClass),btn=btnEl.innerHTML;
    btnEl.innerHTML='<div class="op-spinner" style="width:16px;height:16px;border-width:2px"></div>';
    let data={api_name:name,apiExpiryDate:exp,scopes,api_status:st};if(isEdit)data.api_id=m.querySelector('input[name="api-id"]').value;
    opFetch(action,data).then(res=>{document.getElementById(modalId).classList.add('hidden');btnEl.innerHTML=btn;if(!isEdit){m.querySelectorAll('input[type="text"]').forEach(i=>i.value='');m.querySelectorAll('input[name="api-status"]').forEach((r,i)=>r.checked=i===0);m.querySelectorAll('input[name="api_scopes[]"]').forEach(c=>c.checked=true);}if(res.status==='true'){apToast('success',res.title,res.message);load_data_list(1);}else{apToast('error',res.title,res.message);}}).catch(err=>{btnEl.textContent='Save Changes';apToastError();});
}
document.querySelector('.modal-createItem-btn').addEventListener('click',()=>handleApiSubmit('modal-createItem','api-create',false));
document.querySelector('.modal-editItem-btn').addEventListener('click',()=>handleApiSubmit('modal-editItem','api-edit',true));
</script>
