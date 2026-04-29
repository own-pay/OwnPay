<?php
    if (!defined('OWNPAY_INIT')) { http_response_code(403); exit('Direct access not allowed'); }
    if (!\OwnPay\Service\Auth\PermissionService::canAccessPage(json_decode($global_response_permission['response'][0]['permission'], true), 'staff_management', $global_user_response['response'][0]['role'])) { http_response_code(403); exit('Access denied.'); }
    if (!\OwnPay\Service\Auth\PermissionService::hasPermission(json_decode($global_response_permission['response'][0]['permission'], true), 'staff', 'view_permission_list', $global_user_response['response'][0]['role'])) { http_response_code(403); exit('Access denied.'); }
    $params = json_decode($_POST['params'] ?? '{}', true);
    $staff_id = getParam($params, 'staff');
    if ($staff_id === null) { http_response_code(403); exit('Invalid staff id'); }
    $staff_id = clean_input($staff_id);
    $response_staff = json_decode(\OwnPay\Service\System\CrudService::selectLegacy($db_prefix . 'admin', 'WHERE a_id = :a_id AND role = :role', '* FROM', [':a_id' => $staff_id, ':role' => 'staff']), true);
    if ($response_staff['status'] != true) { http_response_code(403); exit('Direct access not allowed'); }
    if ($global_user_response['response'][0]['id'] == $staff_id) { http_response_code(403); exit("You can't edit your info"); }
?>
<div class="op-page-header">
    <div>
        <nav class="flex mb-1"><ol class="inline-flex items-center space-x-1 text-sm text-gray-500"><li><a href="javascript:void(0)" onclick="load_content('Staff Management','<?php echo htmlspecialchars((string) ($site_url.$path_admin), ENT_QUOTES, 'UTF-8'); ?>/staff-management','nav-item-staff-management')" class="hover:text-primary-600">Staff Management</a></li><li class="flex items-center"><svg class="w-3 h-3 mx-1" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M7.293 14.707a1 1 0 010-1.414L10.586 10 7.293 6.707a1 1 0 011.414-1.414l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0z" clip-rule="evenodd"/></svg><span class="text-gray-900 dark:text-white">Permission List</span></li></ol></nav>
        <h2 class="op-page-title">Permission List</h2>
    </div>
    <div class="flex items-center gap-2">
        <span class="global-loaderSpinner"></span>
        <span class="<?= htmlspecialchars((string) (\OwnPay\Service\Auth\PermissionService::hasPermission(json_decode($global_response_permission['response'][0]['permission'], true), 'staff', 'assign_brand_to', $global_user_response['response'][0]['role']) ? '' : 'hidden'), ENT_QUOTES, 'UTF-8'); ?>">
            <button class="op-btn-primary" data-modal-target="modal-createItem" data-modal-toggle="modal-createItem">Add Brand</button>
        </span>
    </div>
</div>

<div class="op-card">
    <div class="border-b border-gray-200 dark:border-gray-700">
        <div class="filter-tab-data hidden p-4">
            <div class="flex justify-between items-center mb-3"><h3 class="text-sm font-semibold">Filters</h3><button class="text-sm text-red-500" onclick="filter_hide_show_reset('filter-tab-data')">Reset</button></div>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                <div><label class="op-label">Status</label><select class="op-select" id="filter-status"><option value="">All</option><option value="active">Active</option><option value="suspend">Suspend</option></select></div>
                <div><label class="op-label">Created From</label><input type="date" class="op-input" id="filter-created-from"></div>
                <div><label class="op-label">Created Until</label><input type="date" class="op-input" id="filter-created-until"></div>
            </div>
        </div>
        <div class="flex justify-end items-center h-12 px-4"><svg onclick="filter_hide_show('filter-tab-data')" class="w-5 h-5 cursor-pointer text-gray-500 hover:text-gray-700" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16v2.172a2 2 0 0 1 -.586 1.414l-4.414 4.414v7l-6 2v-8.5l-4.48 -4.928a2 2 0 0 1 -.52 -1.345v-2.227z"/></svg></div>
    </div>
    <div class="p-4 border-b border-gray-200 dark:border-gray-700">
        <div class="flex flex-col sm:flex-row justify-between gap-3">
            <div class="flex items-center gap-2 text-sm text-gray-500">Show <input type="number" min="1" max="100" class="op-input w-16 text-center show_limit" value="8"> entries</div>
            <div class="flex items-center gap-2">
                <div class="flex items-center gap-2 text-sm text-gray-500">Search: <input type="text" class="op-input w-48 search_input"></div>
                <button class="op-btn-danger bulk-action hidden" data-modal-target="model-bulkAction" data-modal-toggle="model-bulkAction"><span id="bulkActionBTN-count">(0)</span></button>
            </div>
        </div>
    </div>
    <div class="overflow-x-auto"><table class="op-table"><thead><tr><th class="w-8"><input class="op-checkbox select-all" type="checkbox"></th><th>Brand</th><th>Created Date</th><th>Status</th><th></th></tr></thead><tbody class="table-data-list"></tbody></table></div>
    <div class="op-card-footer"><p class="text-sm text-gray-500 table-data-list-entries"></p><div class="table-data-list-pagination"></div></div>
</div>

<!-- Bulk Action Modal -->
<div id="model-bulkAction" tabindex="-1" class="hidden overflow-y-auto overflow-x-hidden fixed top-0 right-0 left-0 z-50 justify-center items-center w-full md:inset-0 h-[calc(100%-1rem)] max-h-full" data-modal-backdrop="static">
    <div class="relative p-4 w-full max-w-md max-h-full"><div class="relative bg-white rounded-lg shadow-sm dark:bg-gray-800">
        <div class="flex items-center justify-between p-4 border-b dark:border-gray-700"><h3 class="text-lg font-semibold">Action for Selected Items</h3><button type="button" class="op-modal-close" data-modal-hide="model-bulkAction"><svg class="w-3 h-3" fill="none" viewBox="0 0 14 14"><path stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m1 1 6 6m0 0 6 6M7 7l6-6M7 7l-6 6"/></svg></button></div>
        <div class="p-4"><label class="op-label">Action <span class="text-red-500">*</span></label><select class="op-select" id="model-bulkActionID"><option value="" selected>Select a Action</option>
            <?php if (\OwnPay\Service\Auth\PermissionService::hasPermission(json_decode($global_response_permission['response'][0]['permission'], true), 'staff', 'delete_permission_of', $global_user_response['response'][0]['role'])): ?><option value="deleted">Delete Selected</option><?php endif; ?>
            <?php if (\OwnPay\Service\Auth\PermissionService::hasPermission(json_decode($global_response_permission['response'][0]['permission'], true), 'staff', 'edit_permission', $global_user_response['response'][0]['role'])): ?><option value="activated">Activate Selected</option><option value="suspended">Suspend Selected</option><?php endif; ?>
        </select></div>
        <div class="flex justify-end gap-2 p-4 border-t dark:border-gray-700"><button class="op-btn-secondary" data-modal-hide="model-bulkAction">Close</button><button class="op-btn-primary model-bulkAction-btn">Confirm</button></div>
    </div></div>
</div>

<!-- Add Brand Modal -->
<div id="modal-createItem" tabindex="-1" class="hidden overflow-y-auto overflow-x-hidden fixed top-0 right-0 left-0 z-50 justify-center items-center w-full md:inset-0 h-[calc(100%-1rem)] max-h-full" data-modal-backdrop="static">
    <div class="relative p-4 w-full max-w-lg max-h-full"><div class="relative bg-white rounded-lg shadow-sm dark:bg-gray-800">
        <div class="flex items-center justify-between p-4 border-b dark:border-gray-700"><h3 class="text-lg font-semibold">Add Brand</h3><button type="button" class="op-modal-close" data-modal-hide="modal-createItem"><svg class="w-3 h-3" fill="none" viewBox="0 0 14 14"><path stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m1 1 6 6m0 0 6 6M7 7l6-6M7 7l-6 6"/></svg></button></div>
        <form class="staff-brand-add">
            <input type="hidden" name="action" value="staff-brand-add">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) ($csrf_token), ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="staff_id" value="<?= htmlspecialchars((string) ($staff_id), ENT_QUOTES, 'UTF-8'); ?>">
            <div class="p-4"><label class="op-label">Brands <span class="text-red-500">*</span></label>
                <select class="js-select op-select" name="brands[]" multiple data-search="true" data-remove="true" data-placeholder="Select brands" required>
                    <?php $response_brand = json_decode(\OwnPay\Service\System\CrudService::selectLegacy($db_prefix . 'brands', ' ORDER BY 1 DESC'), true);
                    if ($response_brand['status'] == true) { foreach ($response_brand['response'] as $row) {
                        $rp = json_decode(\OwnPay\Service\System\CrudService::selectLegacy($db_prefix . 'permission', ' WHERE a_id = :a_id AND brand_id = :brand_id', '* FROM', [':a_id' => $response_staff['response'][0]['a_id'], ':brand_id' => $row['brand_id']]), true);
                        if($rp['status'] != true) { ?>
                            <option value="<?php echo htmlspecialchars((string) ($row['brand_id']), ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars((string) ($row['identify_name']), ENT_QUOTES, 'UTF-8'); ?></option>
                    <?php } } } ?>
                </select>
            </div>
            <div class="flex justify-end gap-2 p-4 border-t dark:border-gray-700"><button class="op-btn-secondary" data-modal-hide="modal-createItem" type="button">Cancel</button><button class="op-btn-primary modal-createItem-btn" type="submit">Confirm</button></div>
        </form>
    </div></div>
</div>

<script nonce="<?= htmlspecialchars((string) ($csp_nonce ?? ''), ENT_QUOTES, 'UTF-8'); ?>" data-cfasync="false">
window.OP_DASHBOARD_URL='<?php echo htmlspecialchars((string) ($site_url.$path_admin), ENT_QUOTES, 'UTF-8'); ?>/dashboard';
document.querySelector('.staff-brand-add').addEventListener('submit',function(e){e.preventDefault();var btnEl=document.querySelector('.modal-createItem-btn'),btn=btnEl.innerHTML;btnEl.innerHTML='<div class="op-spinner" style="width:16px;height:16px;border-width:2px"></div>';fetch('<?php echo htmlspecialchars((string) ($site_url.$path_admin), ENT_QUOTES, 'UTF-8'); ?>/dashboard',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams(new FormData(this)).toString()}).then(r=>r.json()).then(res=>{apRotateCsrf(res.csrf_token);closeAllModals();btnEl.innerHTML=btn;res.status==='true'?(apToast('success',res.title,res.message),load_data_list(1)):apToast('error',res.title,res.message);}).catch(err=>apToastError());});

document.querySelector('.model-bulkAction-btn').addEventListener('click',function(){var my=document.querySelector("#my-action-confirmation-btn")?.value||'';var actionID=document.getElementById("model-bulkActionID").value;if(!actionID){apToast('error','Action Required','Please select an action.');return;}const sel=Array.from(document.querySelectorAll('.rowCheckbox:checked')).map(cb=>cb.closest('tr').dataset.id);if(my!==""){document.querySelector('.global-loaderSpinner').innerHTML='<div class="op-spinner"></div>';opFetch('staff-permission-bulk-action',{actionID,selected_ids:JSON.stringify(sel)}).then(res=>{closeAllModals();document.querySelector("#my-action-confirmation-btn").value='';document.getElementById("model-bulkActionID").selectedIndex=0;document.querySelector('.global-loaderSpinner').innerHTML='';if(res.status==='true'){document.querySelectorAll('.select-all').forEach(cb=>cb.checked=false);document.querySelector('.bulk-action').classList.add('hidden');apToast('success',res.title,res.message);load_data_list(1);}else{apToast('error',res.title,res.message);}}).catch(err=>apToastError());}else{show_action_confirmation_tab('model-bulkAction-btn','Confirm Action','Confirm','btn-danger');}});

function initCheckboxTable(){const sa=document.querySelector('.select-all'),rc=document.querySelectorAll('.rowCheckbox'),ba=document.querySelector('.bulk-action');function u(){const s=document.querySelectorAll('.rowCheckbox:checked');document.getElementById("bulkActionBTN-count").innerHTML=`(${s.length})`;s.length>0?ba.classList.remove('hidden'):ba.classList.add('hidden');}sa.addEventListener('change',()=>{rc.forEach(cb=>cb.checked=sa.checked);u();});rc.forEach(cb=>cb.addEventListener('change',()=>{sa.checked=rc.length===document.querySelectorAll('.rowCheckbox:checked').length;u();}));}

function deleteItem(ItemID){var my=document.querySelector("#my-action-confirmation-btn")?.value||'';if(my!==""){var btnEl=document.querySelector('#model-my-action-confirmation-btn'),btn=btnEl.innerHTML;btnEl.innerHTML='<div class="op-spinner" style="width:16px;height:16px;border-width:2px"></div>';opFetch('staff-permission-delete',{ItemID}).then(res=>{closeAllModals();document.querySelector("#my-action-confirmation-btn").value='';btnEl.innerHTML=btn;res.status==='true'?(apToast('success',res.title,res.message),load_data_list(1)):apToast('error',res.title,res.message);}).catch(err=>apToastError());}else{show_action_confirmation_tab('btnDeleteItem-'+ItemID,'Delete Staff Permission','Delete','btn-danger');}}

function load_data_list(page=1){currentPage=page;document.querySelector(".table-data-list").innerHTML=apSkeletonRows(5);opFetch('staff-permissions',{a_id:"<?php echo htmlspecialchars((string) ($staff_id), ENT_QUOTES, 'UTF-8'); ?>",search_input:document.querySelector('.search_input').value,show_limit:document.querySelector('.show_limit').value,page,filter_status:document.getElementById('filter-status').value,filter_start:document.getElementById('filter-created-from').value,filter_end:document.getElementById('filter-created-until').value}).then(res=>{let html='';if(res.status==='true'){let aE=<?= htmlspecialchars((string) (\OwnPay\Service\Auth\PermissionService::hasPermission(json_decode($global_response_permission['response'][0]['permission'],true),'staff','edit_permission',$global_user_response['response'][0]['role'])?'true':'false'), ENT_QUOTES, 'UTF-8'); ?>;let aD=<?= htmlspecialchars((string) (\OwnPay\Service\Auth\PermissionService::hasPermission(json_decode($global_response_permission['response'][0]['permission'],true),'staff','delete_permission_of',$global_user_response['response'][0]['role'])?'true':'false'), ENT_QUOTES, 'UTF-8'); ?>;res.response.forEach(item=>{let badge=item.status==='active'?'op-badge-success':(item.status==='suspend'?'op-badge-danger':'op-badge-secondary');let rE=aE?`style="cursor:pointer;" onclick="load_content('Edit Permission','<?php echo htmlspecialchars((string) ($site_url.$path_admin), ENT_QUOTES, 'UTF-8'); ?>/staff-management/edit-permissions?staff=${item.id}','nav-item-staff-management')"`:'' ;let rD=aD?`onclick="deleteItem('${item.id}')"`:'' ;html+=`<tr data-id="${item.id}"><td><input class="op-checkbox rowCheckbox" type="checkbox"></td><td ${rE}><div class="font-medium">${item.identify_name}</div><div class="text-sm text-gray-500">${item.brandname}</div></td><td ${rE}>${item.created_date}</td><td ${rE}><span class="${badge}">${item.status.charAt(0).toUpperCase()+item.status.slice(1)}</span></td><td class="text-end"><button class="op-btn-secondary text-xs" data-dropdown-toggle="dd-${item.id}">Actions</button><div id="dd-${item.id}" class="hidden z-10 bg-white divide-y divide-gray-100 rounded-lg shadow-sm w-44 dark:bg-gray-700"><ul class="py-2 text-sm"><li class="${aE?'':'hidden'}"><a href="javascript:void(0)" ${rE} class="block px-4 py-2 hover:bg-gray-100">Edit</a></li><li class="${aD?'':'hidden'}"><a href="javascript:void(0)" ${rD} class="block px-4 py-2 hover:bg-gray-100 text-red-500 btnDeleteItem-${item.id}">Delete</a></li></ul></div></td></tr>`;});document.querySelector(".table-data-list").innerHTML=html;initCheckboxTable();document.querySelector(".table-data-list-entries").innerHTML=res.datatableInfo;document.querySelector(".table-data-list-pagination").innerHTML=res.pagination;}else{document.querySelector(".table-data-list").innerHTML=`<tr><td colspan="5">${apEmptyState(res.title,res.message)}</td></tr>`;document.querySelector(".table-data-list-entries").innerHTML='Showing <strong>0 to 0</strong> of <strong>0 entries</strong>';document.querySelector(".table-data-list-pagination").innerHTML='';}}).catch(err=>{document.querySelector(".table-data-list").innerHTML='<tr><td colspan="999" class="py-16 text-center"><div class="flex flex-col items-center justify-center"><div class="w-16 h-16 mb-3 rounded-full bg-red-50 dark:bg-red-900/20 flex items-center justify-center"><svg xmlns="http://www.w3.org/2000/svg" class="w-8 h-8 text-red-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M12 9v4m0 4h.01M5.07 19h13.86c1.54 0 2.5-1.67 1.73-3L13.73 4.99c-.77-1.33-2.69-1.33-3.46 0L3.34 16c-.77 1.33.19 3 1.73 3z"/></svg></div><h3 class="text-base font-semibold text-gray-900 dark:text-white mb-1">Connection Error</h3><p class="text-sm text-gray-500 dark:text-gray-400">Unable to load data. Please check your connection and try again.</p></div></td></tr>';apToastError(err);});}

document.addEventListener('click',function(e){if(e.target.closest('.table-data-list-pagination button')){load_data_list(parseInt(e.target.closest('button').dataset.page));}});
load_data_list(1);
function filter_hide_show_reset(cn){const c=document.querySelector('.'+cn);if(!c)return;c.querySelectorAll('input').forEach(i=>i.value='');c.querySelectorAll('select').forEach(s=>s.selectedIndex=0);load_data_list(1);}
document.querySelectorAll('.filter-tab-data input,.filter-tab-data select,.search_input,.show_limit').forEach(el=>el.addEventListener('change',()=>load_data_list(1)));
</script>
