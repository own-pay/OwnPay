<?php
    if (!defined('OWNPAY_INIT')) { http_response_code(403); exit('Direct access not allowed'); }
    if (!canAccessPage(json_decode($global_response_permission['response'][0]['permission'], true), 'staff_management', $global_user_response['response'][0]['role'])) { http_response_code(403); exit('Access denied.'); }
    if (!hasPermission(json_decode($global_response_permission['response'][0]['permission'], true), 'staff', 'create', $global_user_response['response'][0]['role'])) { http_response_code(403); exit('Access denied.'); }
?>

<div class="op-page-header">
    <div>
        <nav class="flex mb-1" aria-label="Breadcrumb"><ol class="inline-flex items-center space-x-1 text-sm text-gray-500 dark:text-gray-400"><li><a href="javascript:void(0)" onclick="load_content('Staff Management','<?php echo $site_url.$path_admin ?>/staff-management','nav-item-staff-management')" class="hover:text-primary-600">Staff Management</a></li><li class="flex items-center"><svg class="w-3 h-3 mx-1" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M7.293 14.707a1 1 0 010-1.414L10.586 10 7.293 6.707a1 1 0 011.414-1.414l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0z" clip-rule="evenodd"></path></svg><span class="text-gray-900 dark:text-white">Create Staff</span></li></ol></nav>
        <h2 class="op-page-title">Create Staff</h2>
    </div>
</div>

<form class="form-staff-management-create">
    <input type="hidden" name="action" value="staff-create">
    <input type="hidden" name="csrf_token" value="<?= $csrf_token; ?>">

    <div class="op-card">
        <div class="p-4">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div><label class="op-label">Full Name <span class="text-red-500">*</span></label><input type="text" class="op-input" name="full-name" placeholder="Full Name" required></div>
                <div><label class="op-label">Username <span class="text-red-500">*</span></label><input type="text" class="op-input" name="username" placeholder="Username" required></div>
                <div><label class="op-label">Email Address <span class="text-red-500">*</span></label><input type="email" class="op-input" name="email-address" placeholder="Email Address" required></div>
                <div><label class="op-label">Password <span class="text-red-500">*</span></label><input type="password" class="op-input" name="password" placeholder="Password" required minlength="6"></div>
                <div><label class="op-label">Brands <span class="text-red-500">*</span></label>
                    <select class="js-select op-select" name="brands[]" multiple data-search="true" data-remove="true" data-placeholder="Select brands" required>
                        <?php $response_brand = json_decode(getData($db_prefix . 'brands', ' ORDER BY 1 DESC'), true); if ($response_brand['status'] == true) { foreach ($response_brand['response'] as $row) { ?>
                            <option value="<?php echo $row['brand_id'] ?>"><?php echo $row['identify_name'] ?></option>
                        <?php } } ?>
                    </select>
                </div>
                <div><label class="op-label">Select All Permissions</label>
                    <label class="relative inline-flex items-center cursor-pointer"><input type="checkbox" id="btnAllPermission" class="sr-only peer" checked><div class="w-11 h-6 bg-gray-200 peer-focus:ring-4 peer-focus:ring-primary-300 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:start-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-primary-600"></div></label>
                    <p class="text-xs text-gray-500 mt-1">Enables or disables all permissions for this staff</p>
                </div>
            </div>
        </div>
    </div>

    <div class="op-card mt-4">
        <div class="border-b border-gray-200 dark:border-gray-700">
            <ul class="flex flex-wrap -mb-px text-sm font-medium text-center" role="tablist">
                <?php $i = 0; $schema = permissionSchema(); $savedPermissions = [];
                    foreach ($schema as $tabKey => $tabData):
                        $tabId = 'tab-' . $tabKey; $totalCount = countPermissions($tabKey, $tabData); ?>
                    <li class="me-2" role="presentation"><button class="inline-block p-4 border-b-2 rounded-t-lg <?= $i === 0 ? 'border-primary-600 text-primary-600' : 'border-transparent hover:text-gray-600 hover:border-gray-300' ?>" data-tabs-target="#<?= $tabId ?>" type="button" role="tab"><?= ucfirst(str_replace('_',' ', $tabKey)) ?> <span class="bg-primary-100 text-primary-800 text-xs font-medium ms-1 px-2 py-0.5 rounded-full dark:bg-primary-900 dark:text-primary-300"><?= $totalCount ?></span></button></li>
                <?php $i++; endforeach; ?>
            </ul>
        </div>
        <div class="p-4">
            <?php $i = 0; foreach ($schema as $tabKey => $tabData): $tabId = 'tab-' . $tabKey; ?>
                <div class="<?= $i === 0 ? '' : 'hidden' ?>" id="<?= $tabId ?>" role="tabpanel">
                    <?php if ($tabKey === 'resources'): ?>
                        <?php foreach ($tabData as $module => $actions): $rand_id = uniqid(); ?>
                            <div class="border rounded-lg p-4 mb-3 dark:border-gray-700">
                                <div class="flex justify-between items-center border-b border-gray-200 dark:border-gray-700 pb-2 mb-3">
                                    <span class="font-semibold text-sm text-gray-900 dark:text-white"><?= ucfirst(str_replace(['_', '-'], ' ', $module)) ?></span>
                                    <span onclick="select_by_box('<?php echo $rand_id?>')" class="btn-<?php echo $rand_id?> text-primary-600 cursor-pointer text-sm font-medium">Select All</span>
                                </div>
                                <div class="grid grid-cols-2 md:grid-cols-4 gap-2">
                                    <?php foreach ($actions as $action => $_): $checked = $savedPermissions['resources'][$module][$action] ?? false; ?>
                                        <label class="flex items-center gap-2 text-sm"><input type="checkbox" class="op-checkbox perm-checkbox checkbox-<?php echo $rand_id?>" data-type="resources" data-module="<?= $module ?>" data-action="<?= $action ?>" <?= $checked ? 'checked' : '' ?>><span><?= ucfirst(str_replace(['_', '-'], ' ', $action)) ?> <?= ucfirst(str_replace(['_', '-'], ' ', $module)) ?></span></label>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php elseif ($tabKey === 'pages'): $rand_id = uniqid(); ?>
                        <div class="mb-3"><span onclick="select_by_box('<?php echo $rand_id?>')" class="btn-<?php echo $rand_id?> text-primary-600 cursor-pointer text-sm font-medium">Select All</span></div>
                        <div class="grid grid-cols-2 md:grid-cols-4 gap-2">
                            <?php foreach ($tabData as $page => $_): $checked = $savedPermissions['pages'][$page] ?? false; ?>
                                <label class="flex items-center gap-2 text-sm"><input type="checkbox" class="op-checkbox perm-checkbox checkbox-<?php echo $rand_id?>" data-type="pages" data-page="<?= $page ?>" <?= $checked ? 'checked' : '' ?>><span>View <?= ucfirst(str_replace(['_', '-'], ' ', $page)) ?></span></label>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php $i++; endforeach; ?>
        </div>
    </div>

    <div class="text-end pt-3"><button class="op-btn-primary btn-staff-management-create" type="submit">Create</button></div>
</form>

<script nonce="<?= $csp_nonce ?? '' ?>" data-cfasync="false">
    window.OP_DASHBOARD_URL = '<?php echo $site_url.$path_admin ?>/dashboard';

    // Tab switching
    document.querySelectorAll('[data-tabs-target]').forEach(btn => {
        btn.addEventListener('click', function(){
            document.querySelectorAll('[role="tabpanel"]').forEach(p => p.classList.add('hidden'));
            document.querySelectorAll('[data-tabs-target]').forEach(b => { b.classList.remove('border-primary-600','text-primary-600'); b.classList.add('border-transparent'); });
            document.querySelector(this.dataset.tabsTarget).classList.remove('hidden');
            this.classList.add('border-primary-600','text-primary-600'); this.classList.remove('border-transparent');
        });
    });

    function permissionSwitchCheck(){
        const permissionSwitch = document.getElementById('btnAllPermission');
        permissionSwitch.addEventListener('change', () => {
            const checked = permissionSwitch.checked;
            document.querySelectorAll('.perm-checkbox').forEach(cb => cb.checked = checked);
            document.querySelectorAll('[class^="btn-"]').forEach(btn => { if(btn.classList.contains('text-primary-600')) btn.innerHTML = checked ? 'Deselect All' : 'Select All'; });
        });
        document.querySelectorAll('.perm-checkbox').forEach(cb => {
            cb.addEventListener('change', () => {
                const classes = Array.from(cb.classList).filter(c => c.startsWith('checkbox-'));
                classes.forEach(cls => { const boxid = cls.replace('checkbox-', ''); const all = document.querySelectorAll('.' + cls); const btn = document.querySelector('.btn-' + boxid); if(btn) btn.innerHTML = Array.from(all).every(c => c.checked) ? 'Deselect All' : 'Select All'; });
                permissionSwitch.checked = Array.from(document.querySelectorAll('.perm-checkbox')).every(c => c.checked);
            });
        });
    }
    permissionSwitchCheck();

    function select_by_box(boxid) {
        const btn = document.querySelector('.btn-' + boxid); const allCb = document.querySelectorAll('.checkbox-' + boxid); const selectAll = btn.innerHTML === 'Select All';
        allCb.forEach(cb => cb.checked = selectAll);
        const specific = document.getElementById('btnAllPermission');
        if(specific) specific.checked = btn.innerHTML !== 'Deselect All';
        btn.innerHTML = selectAll ? 'Deselect All' : 'Select All';
    }

    function DefaultSync(){
        const ps = document.getElementById('btnAllPermission'); const checked = ps.checked;
        document.querySelectorAll('.perm-checkbox').forEach(cb => cb.checked = checked);
        document.querySelectorAll('[class*="btn-"]').forEach(btn => { if(btn.textContent.trim() === 'Select All' || btn.textContent.trim() === 'Deselect All') btn.innerHTML = checked ? 'Deselect All' : 'Select All'; });
    }
    DefaultSync();

    document.querySelector('.form-staff-management-create').addEventListener('submit', function(e){
        e.preventDefault();
        let permissions = { resources: {}, pages: {} };
        document.querySelectorAll('.perm-checkbox').forEach(cb => {
            if(cb.dataset.type === 'resources'){ if(!permissions.resources[cb.dataset.module]) permissions.resources[cb.dataset.module] = {}; permissions.resources[cb.dataset.module][cb.dataset.action] = cb.checked; }
            if(cb.dataset.type === 'pages'){ permissions.pages[cb.dataset.page] = cb.checked; }
        });
        var btnEl = document.querySelector('.btn-staff-management-create'); var btn = btnEl.innerHTML;
        btnEl.innerHTML = '<div class="op-spinner" style="width:16px;height:16px;border-width:2px"></div>';
        var formData = new URLSearchParams(new FormData(this)).toString();
        formData += '&permissions_json=' + encodeURIComponent(JSON.stringify(permissions));
        fetch('<?php echo $site_url.$path_admin ?>/dashboard', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: formData })
            .then(r => r.json()).then(response => {
                apRotateCsrf(response.csrf_token); btnEl.innerHTML = btn;
                if(response.status === 'true'){ apToast('success', response.title, response.message); load_content('Staff Management','<?php echo $site_url.$path_admin ?>/staff-management','nav-item-staff-management'); }
                else { apToast('error', response.title, response.message); }
            }).catch(err => apToastError());
    });
</script>
