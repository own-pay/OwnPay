<?php
if (!defined('OWNPAY_INIT')) { http_response_code(403); exit('Direct access not allowed'); }
if (!canAccessPage(json_decode($global_response_permission['response'][0]['permission'], true), 'brand_settings', $global_user_response['response'][0]['role'])) { http_response_code(403); exit('Access denied.'); }
if (!hasPermission(json_decode($global_response_permission['response'][0]['permission'], true), 'faq_settings', 'view', $global_user_response['response'][0]['role'])) { http_response_code(403); exit('Access denied.'); }
$allowCreate = hasPermission(json_decode($global_response_permission['response'][0]['permission'], true), 'faq_settings', 'create', $global_user_response['response'][0]['role']);
$allowEdit = hasPermission(json_decode($global_response_permission['response'][0]['permission'], true), 'faq_settings', 'edit', $global_user_response['response'][0]['role']);
$allowDelete = hasPermission(json_decode($global_response_permission['response'][0]['permission'], true), 'faq_settings', 'delete', $global_user_response['response'][0]['role']);
?>
<div class="op-page-header"><div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
    <div>
        <nav class="flex mb-1"><ol class="inline-flex items-center space-x-1 text-sm text-gray-500"><li><a href="javascript:void(0)" onclick="load_content('Settings','<?php echo $site_url.$path_admin ?>/settings','nav-item-settings')" class="hover:text-primary-600">Settings</a></li><li class="flex items-center"><svg class="w-3 h-3 mx-1" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M7.293 14.707a1 1 0 010-1.414L10.586 10 7.293 6.707a1 1 0 011.414-1.414l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0z" clip-rule="evenodd"/></svg><span class="text-gray-900 dark:text-white">FAQ Settings</span></li></ol></nav>
        <h2 class="op-page-title">FAQ Settings</h2>
    </div>
    <div class="flex items-center gap-2">
        <span class="global-loaderSpinner"></span>
        <?php if ($allowCreate): ?><button onclick="document.getElementById('modal-createItem').classList.remove('hidden')" class="op-btn-primary text-sm">Create FAQ</button><?php endif; ?>
    </div>
</div></div>

<div class="op-card">
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
        <div class="flex items-center gap-2 text-sm">Show <input type="number" min="1" max="100" class="op-input w-16 text-center show_limit" value="8"> entries</div>
        <div class="flex items-center gap-2"><input type="text" class="op-input w-48 search_input" placeholder="Search..."><button class="op-btn-danger text-sm bulk-action hidden" onclick="document.getElementById('model-bulkAction').classList.remove('hidden')">Bulk <span id="bulkActionBTN-count">(0)</span></button></div>
    </div></div>
    <div class="overflow-x-auto"><table class="op-table w-full text-sm">
        <thead><tr><th class="p-3 w-8"><input class="op-checkbox select-all" type="checkbox"></th><th class="p-3">FAQ</th><th class="p-3">Created Date</th><th class="p-3">Status</th><th class="p-3"></th></tr></thead>
        <tbody class="table-data-list"></tbody>
    </table></div>
    <div class="p-4 border-t border-gray-200 dark:border-gray-700"><div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2">
        <p class="text-sm text-gray-600 table-data-list-entries"></p>
        <div class="table-data-list-pagination"></div>
    </div></div>
</div>

<!-- Bulk Action Modal -->
<div id="model-bulkAction" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-gray-900/50"><div class="relative w-full max-w-md p-4"><div class="bg-white rounded-lg shadow dark:bg-gray-800">
    <div class="flex items-center justify-between p-4 border-b dark:border-gray-700"><h3 class="text-lg font-semibold dark:text-white">Bulk Action</h3><button onclick="document.getElementById('model-bulkAction').classList.add('hidden')" class="text-gray-400 hover:text-gray-900 dark:hover:text-white"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg></button></div>
    <div class="p-4"><label class="op-label">Action *</label><select class="op-select" id="model-bulkActionID"><option value="">Select</option><?= $allowDelete?'<option value="deleted">Delete</option>':'' ?><?= $allowEdit?'<option value="activated">Activate</option><option value="inactivated">Inactivate</option>':'' ?></select></div>
    <div class="flex justify-end gap-2 p-4 border-t dark:border-gray-700"><button onclick="document.getElementById('model-bulkAction').classList.add('hidden')" class="op-btn-secondary">Close</button><button class="op-btn-primary model-bulkAction-btn">Confirm</button></div>
</div></div></div>

<!-- Create FAQ Modal -->
<div id="modal-createItem" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-gray-900/50"><div class="relative w-full max-w-2xl p-4"><div class="bg-white rounded-lg shadow dark:bg-gray-800">
    <div class="flex items-center justify-between p-4 border-b dark:border-gray-700"><h3 class="text-lg font-semibold dark:text-white">New FAQ</h3><button onclick="document.getElementById('modal-createItem').classList.add('hidden')" class="text-gray-400 hover:text-gray-900 dark:hover:text-white"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg></button></div>
    <div class="p-4 space-y-4">
        <div><label class="op-label">Title *</label><input type="text" class="op-input" name="faq-title" placeholder="FAQ Title"></div>
        <div><label class="op-label">Description *</label><textarea class="op-input min-h-[150px]" name="faq-description" placeholder="FAQ Description"></textarea></div>
        <div><label class="op-label">Status *</label><div class="grid grid-cols-2 gap-3">
            <label class="op-card p-3 cursor-pointer has-[:checked]:ring-2 has-[:checked]:ring-primary-500"><input type="radio" name="faq-status" value="active" class="sr-only" checked><span class="font-medium text-sm">Active</span></label>
            <label class="op-card p-3 cursor-pointer has-[:checked]:ring-2 has-[:checked]:ring-primary-500"><input type="radio" name="faq-status" value="inactive" class="sr-only"><span class="font-medium text-sm">Inactive</span></label>
        </div></div>
    </div>
    <div class="flex justify-end gap-2 p-4 border-t dark:border-gray-700"><button onclick="document.getElementById('modal-createItem').classList.add('hidden')" class="op-btn-secondary">Cancel</button><button class="op-btn-primary modal-createItem-btn">Create</button></div>
</div></div></div>

<!-- Edit FAQ Modal -->
<div id="modal-editItem" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-gray-900/50"><div class="relative w-full max-w-2xl p-4"><div class="bg-white rounded-lg shadow dark:bg-gray-800">
    <div class="flex items-center justify-between p-4 border-b dark:border-gray-700"><h3 class="text-lg font-semibold dark:text-white">Edit FAQ</h3><button onclick="document.getElementById('modal-editItem').classList.add('hidden')" class="text-gray-400 hover:text-gray-900 dark:hover:text-white"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg></button></div>
    <div class="p-4 space-y-4">
        <input type="hidden" name="faq-id">
        <div><label class="op-label">Title *</label><input type="text" class="op-input" name="faq-title" placeholder="FAQ Title"></div>
        <div><label class="op-label">Description *</label><textarea class="op-input min-h-[150px]" name="faq-description" placeholder="FAQ Description"></textarea></div>
        <div><label class="op-label">Status *</label><div class="grid grid-cols-2 gap-3">
            <label class="op-card p-3 cursor-pointer has-[:checked]:ring-2 has-[:checked]:ring-primary-500"><input type="radio" name="faq-status" value="active" class="sr-only" checked><span class="font-medium text-sm">Active</span></label>
            <label class="op-card p-3 cursor-pointer has-[:checked]:ring-2 has-[:checked]:ring-primary-500"><input type="radio" name="faq-status" value="inactive" class="sr-only"><span class="font-medium text-sm">Inactive</span></label>
        </div></div>
    </div>
    <div class="flex justify-end gap-2 p-4 border-t dark:border-gray-700"><button onclick="document.getElementById('modal-editItem').classList.add('hidden')" class="op-btn-secondary">Cancel</button><button class="op-btn-primary modal-editItem-btn">Save Changes</button></div>
</div></div></div>

<script nonce="<?= $csp_nonce ?? '' ?>" data-cfasync="false">
window.OP_DASHBOARD_URL='<?php echo $site_url.$path_admin ?>/dashboard';
function initCheckboxTable(){const sa=document.querySelector('.select-all'),rcs=document.querySelectorAll('.rowCheckbox'),ba=document.querySelector('.bulk-action');function up(){const s=document.querySelectorAll('.rowCheckbox:checked');document.getElementById('bulkActionBTN-count').innerHTML=`(${s.length})`;s.length>0?ba.classList.remove('hidden'):ba.classList.add('hidden');}sa.addEventListener('change',()=>{rcs.forEach(c=>c.checked=sa.checked);up();});rcs.forEach(c=>{c.addEventListener('change',()=>{sa.checked=rcs.length===document.querySelectorAll('.rowCheckbox:checked').length;up();});});}

document.querySelector('.model-bulkAction-btn').addEventListener('click',function(){var actionID=document.getElementById('model-bulkActionID').value,my=document.querySelector("#my-action-confirmation-btn")?.value||'';if(!actionID){apToast('error','Required','Choose an action.');return;}const ids=Array.from(document.querySelectorAll('.rowCheckbox:checked')).map(c=>c.closest('tr').dataset.id);if(my!==""){document.querySelector('.global-loaderSpinner').innerHTML='<div class="op-spinner"></div>';opFetch('faq-bulk-action',{actionID,selected_ids:JSON.stringify(ids)}).then(res=>{closeAllModals();document.querySelector("#my-action-confirmation-btn").value='';document.getElementById('model-bulkActionID').selectedIndex=0;document.querySelector('.global-loaderSpinner').innerHTML='';if(res.status==='true'){document.querySelectorAll('.select-all').forEach(c=>c.checked=false);document.querySelector('.bulk-action').classList.add('hidden');apToast('success',res.title,res.message);load_data_list(1);}else{apToast('error',res.title,res.message);}}).catch(err=>{document.querySelector('.global-loaderSpinner').textContent='';apToastError();});}else{show_action_confirmation_tab('model-bulkAction-btn','Confirm','Confirm','btn-danger');}});

function deleteItem(ID){var my=document.querySelector("#my-action-confirmation-btn")?.value||'',bc='btnDeleteItem-'+ID;if(my!==""){var be=document.querySelector('#model-my-action-confirmation-btn'),b=be.innerHTML;be.innerHTML='<div class="op-spinner" style="width:16px;height:16px;border-width:2px"></div>';opFetch('faq-delete',{ItemID:ID}).then(r=>{closeAllModals();document.querySelector("#my-action-confirmation-btn").value='';be.innerHTML=b;r.status==='true'?(apToast('success',r.title,r.message),load_data_list(1)):apToast('error',r.title,r.message);}).catch(e=>{be.textContent=b;apToastError();});}else{show_action_confirmation_tab(bc,'Delete FAQ','Delete','btn-danger');}}

function load_data_list(page=1){currentPage=page;var s=document.querySelector('.search_input').value,l=document.querySelector('.show_limit').value,fs=document.getElementById('filter-status')?.value||'',f1=document.getElementById('filter-created-from')?.value||'',f2=document.getElementById('filter-created-until')?.value||'';document.querySelector(".table-data-list").innerHTML=apSkeletonRows(5);opFetch('faq-list',{search_input:s,show_limit:l,page,filter_status:fs,filter_start:f1,filter_end:f2}).then(res=>{let h='';if(res.status==='true'){res.response.forEach(item=>{let ae=<?=$allowEdit?'true':'false'?>,ad=<?=$allowDelete?'true':'false'?>;let bg=item.status==='active'?'op-badge-success':'op-badge-secondary';h+=`<tr data-id="${apEscapeHtml(item.id)}"><td class="p-3"><input class="op-checkbox rowCheckbox" type="checkbox"></td><td class="p-3 cursor-pointer" ${ae?`onclick="openEditModel('${apEscapeHtml(item.id)}')"`:''}><div class="font-medium">${apEscapeHtml(item.title)}</div><div class="text-xs text-gray-500 truncate max-w-xs">${apEscapeHtml(item.description)}</div></td><td class="p-3">${apEscapeHtml(item.created_date)}</td><td class="p-3"><span class="${bg}">${apEscapeHtml(item.status.charAt(0).toUpperCase()+item.status.slice(1))}</span></td><td class="p-3 text-end"><div class="op-dropdown inline-block"><button class="op-btn-secondary text-xs" onclick="this.nextElementSibling.classList.toggle('hidden')">Actions ▾</button><div class="hidden absolute right-0 mt-1 w-36 bg-white rounded-lg shadow-lg border dark:bg-gray-800 dark:border-gray-700 z-50">${ae?`<a href="javascript:void(0)" onclick="openEditModel('${apEscapeHtml(item.id)}')" class="block px-3 py-2 text-sm hover:bg-gray-100 dark:hover:bg-gray-700">Edit</a>`:''}${ad?`<a href="javascript:void(0)" onclick="deleteItem('${apEscapeHtml(item.id)}')" class="btnDeleteItem-${apEscapeHtml(item.id)} block px-3 py-2 text-sm text-red-600 hover:bg-gray-100 dark:hover:bg-gray-700">Delete</a>`:''}</div></div></td></tr>`;});document.querySelector(".table-data-list").innerHTML=h;initCheckboxTable();document.querySelector(".table-data-list-entries").innerHTML=res.datatableInfo;document.querySelector(".table-data-list-pagination").innerHTML=res.pagination;}else{document.querySelector(".table-data-list").innerHTML=`<tr><td colspan="5">${apEmptyState(res.title,res.message)}</td></tr>`;document.querySelector(".table-data-list-entries").innerHTML='Showing <strong>0 to 0</strong> of <strong>0 entries</strong>';document.querySelector(".table-data-list-pagination").innerHTML='';}}).catch(e=>{document.querySelector(".table-data-list").innerHTML='<tr><td colspan="999" class="py-16 text-center"><div class="flex flex-col items-center justify-center"><div class="w-16 h-16 mb-3 rounded-full bg-red-50 dark:bg-red-900/20 flex items-center justify-center"><svg xmlns="http://www.w3.org/2000/svg" class="w-8 h-8 text-red-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M12 9v4m0 4h.01M5.07 19h13.86c1.54 0 2.5-1.67 1.73-3L13.73 4.99c-.77-1.33-2.69-1.33-3.46 0L3.34 16c-.77 1.33.19 3 1.73 3z"/></svg></div><h3 class="text-base font-semibold text-gray-900 dark:text-white mb-1">Connection Error</h3><p class="text-sm text-gray-500 dark:text-gray-400">Unable to load data. Please check your connection and try again.</p></div></td></tr>';apToastError(e);});}
document.addEventListener('click',function(e){if(e.target.matches('[data-page]'))load_data_list(parseInt(e.target.dataset.page));});load_data_list(1);
function filter_hide_show_reset(cn){const c=document.querySelector('.'+cn);if(!c)return;c.querySelectorAll('input').forEach(i=>i.value='');c.querySelectorAll('select').forEach(s=>s.selectedIndex=0);load_data_list(1);}
document.querySelectorAll('.filter-tab-data input,.filter-tab-data select,.search_input,.show_limit').forEach(el=>el.addEventListener('change',()=>load_data_list(1)));

function openEditModel(id){document.querySelector('.global-loaderSpinner').innerHTML='<div class="op-spinner"></div>';opFetch('faq-info-byID',{ItemID:id}).then(r=>{document.querySelector('.global-loaderSpinner').innerHTML='';if(r.status==='true'){const m=document.getElementById('modal-editItem');m.querySelector('input[name="faq-id"]').value=id;m.querySelector('input[name="faq-title"]').value=r.title||'';m.querySelector('textarea').value=r.description||'';m.querySelectorAll('input[name="faq-status"]').forEach(x=>x.checked=x.value===r.fstatus);m.classList.remove('hidden');}else apToast('error',r.title,r.message);}).catch(e=>{document.querySelector('.global-loaderSpinner').textContent='';apToastError();});}

function faqSubmit(mid,act,edit){const m=document.getElementById(mid),t=m.querySelector('input[name="faq-title"]').value,d=m.querySelector('textarea').value,s=m.querySelector('input[name="faq-status"]:checked')?.value||'';if(!t||!d||!s){apToast('error','Incomplete','Fill all fields.');return;}const bc=edit?'modal-editItem-btn':'modal-createItem-btn',be=m.querySelector('.'+bc),b=be.innerHTML;be.innerHTML='<div class="op-spinner" style="width:16px;height:16px;border-width:2px"></div>';let data={faq_title:t,faq_description:d,faq_status:s};if(edit)data.faq_id=m.querySelector('input[name="faq-id"]').value;opFetch(act,data).then(r=>{document.getElementById(mid).classList.add('hidden');be.innerHTML=b;if(!edit){m.querySelectorAll('input[type="text"]').forEach(i=>i.value='');m.querySelector('textarea').value='';m.querySelectorAll('input[name="faq-status"]').forEach((x,i)=>x.checked=i===0);}r.status==='true'?(apToast('success',r.title,r.message),load_data_list(1)):apToast('error',r.title,r.message);}).catch(e=>{be.textContent='Save Changes';apToastError();});}
document.querySelector('.modal-createItem-btn').addEventListener('click',()=>faqSubmit('modal-createItem','faq-create',false));
document.querySelector('.modal-editItem-btn').addEventListener('click',()=>faqSubmit('modal-editItem','faq-edit',true));
</script>
