<?php
    if (!defined('OWNPAY_INIT')) { http_response_code(403); exit('Direct access not allowed'); }
    if (!canAccessPage(json_decode($global_response_permission['response'][0]['permission'], true), 'staff_management', $global_user_response['response'][0]['role'])) { http_response_code(403); exit('Access denied.'); }
    if (!hasPermission(json_decode($global_response_permission['response'][0]['permission'], true), 'staff', 'edit_permission', $global_user_response['response'][0]['role'])) { http_response_code(403); exit('Access denied.'); }
    $params = json_decode($_POST['params'] ?? '{}', true);
    $staff_id = getParam($params, 'staff');
    if ($staff_id === null) { http_response_code(403); exit('Invalid staff id'); }
    $staff_id = clean_input($staff_id);
    $response_permission = json_decode(getData($db_prefix.'permission','WHERE id = :id', '* FROM', [':id' => $staff_id]),true);
    if($response_permission['status'] != true){ http_response_code(403); exit('Direct access not allowed'); }
    $response_staff = json_decode(getData($db_prefix.'admin','WHERE a_id = :a_id AND role = :role', '* FROM', [':a_id' => $response_permission['response'][0]['a_id'], ':role' => 'staff']),true);
    if($response_staff['status'] != true){ http_response_code(403); exit('Direct access not allowed'); }
    if($global_user_response['response'][0]['id'] == $response_staff['response'][0]['id']){ http_response_code(403); exit("You can't edit your info"); }
?>
<div class="op-page-header"><div>
    <nav class="flex mb-1"><ol class="inline-flex items-center space-x-1 text-sm text-gray-500"><li><a href="javascript:void(0)" onclick="load_content('Staff Management','<?php echo htmlspecialchars((string) ($site_url.$path_admin), ENT_QUOTES, 'UTF-8'); ?>/staff-management','nav-item-staff-management')" class="hover:text-primary-600">Staff Management</a></li><li class="flex items-center"><svg class="w-3 h-3 mx-1" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M7.293 14.707a1 1 0 010-1.414L10.586 10 7.293 6.707a1 1 0 011.414-1.414l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0z" clip-rule="evenodd"/></svg><a href="javascript:void(0)" onclick="load_content('Permission List','<?php echo htmlspecialchars((string) ($site_url.$path_admin), ENT_QUOTES, 'UTF-8'); ?>/staff-management/permissions-list?staff=<?php echo htmlspecialchars((string) ($response_permission['response'][0]['a_id']), ENT_QUOTES, 'UTF-8'); ?>','nav-item-staff-management')" class="hover:text-primary-600">Permission List</a></li><li class="flex items-center"><svg class="w-3 h-3 mx-1" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M7.293 14.707a1 1 0 010-1.414L10.586 10 7.293 6.707a1 1 0 011.414-1.414l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0z" clip-rule="evenodd"/></svg><span class="text-gray-900 dark:text-white">Edit Permissions</span></li></ol></nav>
    <h2 class="op-page-title">Edit Permissions</h2>
</div></div>
<form class="form-staff-management-update">
    <input type="hidden" name="action" value="staff-update-permission">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) ($csrf_token), ENT_QUOTES, 'UTF-8'); ?>">
    <input type="hidden" name="staff_id" value="<?= htmlspecialchars((string) ($staff_id), ENT_QUOTES, 'UTF-8'); ?>">
    <div class="op-card"><div class="p-4"><div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <div><label class="op-label">Status <span class="text-red-500">*</span></label><select class="op-select" name="status"><option value="active" <?= htmlspecialchars((string) ($response_permission['response'][0]['status'] === 'active' ? 'selected' : ''), ENT_QUOTES, 'UTF-8'); ?>>Active</option><option value="suspend" <?= htmlspecialchars((string) ($response_permission['response'][0]['status'] === 'suspend' ? 'selected' : ''), ENT_QUOTES, 'UTF-8'); ?>>Suspend</option></select></div>
        <div><label class="op-label">Select All Permissions</label>
            <label class="relative inline-flex items-center cursor-pointer"><input type="checkbox" id="btnAllPermission" class="sr-only peer"><div class="w-11 h-6 bg-gray-200 peer-focus:ring-4 peer-focus:ring-primary-300 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:start-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-primary-600"></div></label>
            <p class="text-xs text-gray-500 mt-1">Enables or disables all permissions</p>
        </div>
    </div></div></div>
    <div class="op-card mt-4">
        <div class="border-b border-gray-200 dark:border-gray-700"><ul class="flex flex-wrap -mb-px text-sm font-medium text-center" role="tablist">
            <?php $i=0; $schema=permissionSchema(); $savedPermissions=json_decode($response_permission['response'][0]['permission'],true);
            foreach($schema as $tabKey=>$tabData): $tabId='tab-'.$tabKey; $totalCount=countPermissions($tabKey,$tabData); ?>
                <li class="me-2" role="presentation"><button class="inline-block p-4 border-b-2 rounded-t-lg <?= htmlspecialchars((string) ($i===0?'border-primary-600 text-primary-600':'border-transparent hover:text-gray-600'), ENT_QUOTES, 'UTF-8'); ?>" data-tabs-target="#<?= htmlspecialchars((string) ($tabId), ENT_QUOTES, 'UTF-8'); ?>" type="button" role="tab"><?= htmlspecialchars((string) (ucfirst(str_replace('_',' ',$tabKey))), ENT_QUOTES, 'UTF-8'); ?> <span class="bg-primary-100 text-primary-800 text-xs font-medium ms-1 px-2 py-0.5 rounded-full"><?= htmlspecialchars((string) ($totalCount), ENT_QUOTES, 'UTF-8'); ?></span></button></li>
            <?php $i++; endforeach; ?>
        </ul></div>
        <div class="p-4">
            <?php $i=0; foreach($schema as $tabKey=>$tabData): $tabId='tab-'.$tabKey; ?>
            <div class="<?= htmlspecialchars((string) ($i===0?'':'hidden'), ENT_QUOTES, 'UTF-8'); ?>" id="<?= htmlspecialchars((string) ($tabId), ENT_QUOTES, 'UTF-8'); ?>" role="tabpanel">
                <?php if($tabKey==='resources'): foreach($tabData as $module=>$actions): $rid=uniqid(); ?>
                <div class="border rounded-lg p-4 mb-3 dark:border-gray-700">
                    <div class="flex justify-between items-center border-b pb-2 mb-3"><span class="font-semibold text-sm"><?= htmlspecialchars((string) (ucfirst(str_replace(['_','-'],' ',$module))), ENT_QUOTES, 'UTF-8'); ?></span><span onclick="select_by_box('<?= htmlspecialchars((string) ($rid), ENT_QUOTES, 'UTF-8'); ?>')" class="btn-<?= htmlspecialchars((string) ($rid), ENT_QUOTES, 'UTF-8'); ?> text-primary-600 cursor-pointer text-sm">Select All</span></div>
                    <div class="grid grid-cols-2 md:grid-cols-4 gap-2"><?php foreach($actions as $action=>$_): $chk=$savedPermissions['resources'][$module][$action]??false; ?>
                        <label class="flex items-center gap-2 text-sm"><input type="checkbox" class="op-checkbox perm-checkbox checkbox-<?= htmlspecialchars((string) ($rid), ENT_QUOTES, 'UTF-8'); ?>" data-type="resources" data-module="<?= htmlspecialchars((string) ($module), ENT_QUOTES, 'UTF-8'); ?>" data-action="<?= htmlspecialchars((string) ($action), ENT_QUOTES, 'UTF-8'); ?>" <?= htmlspecialchars((string) ($chk?'checked':''), ENT_QUOTES, 'UTF-8'); ?>><span><?= htmlspecialchars((string) (ucfirst(str_replace(['_','-'],' ',$action))), ENT_QUOTES, 'UTF-8'); ?> <?= htmlspecialchars((string) (ucfirst(str_replace(['_','-'],' ',$module))), ENT_QUOTES, 'UTF-8'); ?></span></label>
                    <?php endforeach; ?></div>
                </div>
                <?php endforeach; elseif($tabKey==='pages'): $rid=uniqid(); ?>
                <div class="mb-3"><span onclick="select_by_box('<?= htmlspecialchars((string) ($rid), ENT_QUOTES, 'UTF-8'); ?>')" class="btn-<?= htmlspecialchars((string) ($rid), ENT_QUOTES, 'UTF-8'); ?> text-primary-600 cursor-pointer text-sm">Select All</span></div>
                <div class="grid grid-cols-2 md:grid-cols-4 gap-2"><?php foreach($tabData as $page=>$_): $chk=$savedPermissions['pages'][$page]??false; ?>
                    <label class="flex items-center gap-2 text-sm"><input type="checkbox" class="op-checkbox perm-checkbox checkbox-<?= htmlspecialchars((string) ($rid), ENT_QUOTES, 'UTF-8'); ?>" data-type="pages" data-page="<?= htmlspecialchars((string) ($page), ENT_QUOTES, 'UTF-8'); ?>" <?= htmlspecialchars((string) ($chk?'checked':''), ENT_QUOTES, 'UTF-8'); ?>><span>View <?= htmlspecialchars((string) (ucfirst(str_replace(['_','-'],' ',$page))), ENT_QUOTES, 'UTF-8'); ?></span></label>
                <?php endforeach; ?></div>
                <?php endif; ?>
            </div>
            <?php $i++; endforeach; ?>
        </div>
    </div>
    <div class="text-end pt-3"><button class="op-btn-primary btn-staff-management-update" type="submit">Save Changes</button></div>
</form>
<script nonce="<?= htmlspecialchars((string) ($csp_nonce ?? ''), ENT_QUOTES, 'UTF-8'); ?>" data-cfasync="false">
window.OP_DASHBOARD_URL='<?php echo htmlspecialchars((string) ($site_url.$path_admin), ENT_QUOTES, 'UTF-8'); ?>/dashboard';
document.querySelectorAll('[data-tabs-target]').forEach(b=>{b.addEventListener('click',function(){document.querySelectorAll('[role="tabpanel"]').forEach(p=>p.classList.add('hidden'));document.querySelectorAll('[data-tabs-target]').forEach(x=>{x.classList.remove('border-primary-600','text-primary-600');x.classList.add('border-transparent');});document.querySelector(this.dataset.tabsTarget).classList.remove('hidden');this.classList.add('border-primary-600','text-primary-600');this.classList.remove('border-transparent');});});
(function(){const ps=document.getElementById('btnAllPermission');ps.addEventListener('change',()=>{document.querySelectorAll('.perm-checkbox').forEach(cb=>cb.checked=ps.checked);});document.querySelectorAll('.perm-checkbox').forEach(cb=>{cb.addEventListener('change',()=>{Array.from(cb.classList).filter(c=>c.startsWith('checkbox-')).forEach(cls=>{const id=cls.replace('checkbox-',''),all=document.querySelectorAll('.'+cls),btn=document.querySelector('.btn-'+id);if(btn)btn.innerHTML=Array.from(all).every(c=>c.checked)?'Deselect All':'Select All';});ps.checked=Array.from(document.querySelectorAll('.perm-checkbox')).every(c=>c.checked);});});
document.querySelectorAll('.perm-checkbox').forEach(cb=>{Array.from(cb.classList).filter(c=>c.startsWith('checkbox-')).forEach(cls=>{const id=cls.replace('checkbox-',''),all=document.querySelectorAll('.'+cls),btn=document.querySelector('.btn-'+id);if(btn)btn.innerHTML=Array.from(all).every(c=>c.checked)?'Deselect All':'Select All';});});ps.checked=Array.from(document.querySelectorAll('.perm-checkbox')).every(c=>c.checked);})();
function select_by_box(id){const btn=document.querySelector('.btn-'+id),all=document.querySelectorAll('.checkbox-'+id),sel=btn.innerHTML==='Select All';all.forEach(cb=>cb.checked=sel);document.getElementById('btnAllPermission').checked=sel;btn.innerHTML=sel?'Deselect All':'Select All';}
document.querySelector('.form-staff-management-update').addEventListener('submit',function(e){e.preventDefault();let perm={resources:{},pages:{}};document.querySelectorAll('.perm-checkbox').forEach(cb=>{if(cb.dataset.type==='resources'){if(!perm.resources[cb.dataset.module])perm.resources[cb.dataset.module]={};perm.resources[cb.dataset.module][cb.dataset.action]=cb.checked;}if(cb.dataset.type==='pages'){perm.pages[cb.dataset.page]=cb.checked;}});var btnEl=document.querySelector('.btn-staff-management-update'),btn=btnEl.innerHTML;btnEl.innerHTML='<div class="op-spinner" style="width:16px;height:16px;border-width:2px"></div>';var fd=new URLSearchParams(new FormData(this)).toString();fd+='&permissions_json='+encodeURIComponent(JSON.stringify(perm));fetch('<?php echo htmlspecialchars((string) ($site_url.$path_admin), ENT_QUOTES, 'UTF-8'); ?>/dashboard',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:fd}).then(r=>r.json()).then(res=>{apRotateCsrf(res.csrf_token);btnEl.innerHTML=btn;res.status==='true'?apToast('success',res.title,res.message):apToast('error',res.title,res.message);}).catch(err=>apToastError());});
</script>
