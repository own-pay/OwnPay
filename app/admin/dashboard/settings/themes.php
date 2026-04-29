<?php
if (!defined('OWNPAY_INIT')) { http_response_code(403); exit('Direct access not allowed'); }
if (!\OwnPay\Service\Auth\PermissionService::canAccessPage(json_decode($global_response_permission['response'][0]['permission'], true), 'brand_settings', $global_user_response['response'][0]['role'])) { http_response_code(403); exit('Access denied.'); }
if (!\OwnPay\Service\Auth\PermissionService::hasPermission(json_decode($global_response_permission['response'][0]['permission'], true), 'theme_settings', 'view', $global_user_response['response'][0]['role'])) { http_response_code(403); exit('Access denied.'); }
?>
<div class="op-page-header"><div>
    <nav class="flex mb-1"><ol class="inline-flex items-center space-x-1 text-sm text-gray-500"><li><a href="javascript:void(0)" onclick="load_content('Settings','<?php echo $site_url.$path_admin ?>/settings','nav-item-settings')" class="hover:text-primary-600">Settings</a></li><li class="flex items-center"><svg class="w-3 h-3 mx-1" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M7.293 14.707a1 1 0 010-1.414L10.586 10 7.293 6.707a1 1 0 011.414-1.414l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0z" clip-rule="evenodd"/></svg><span class="text-gray-900 dark:text-white">Themes</span></li></ol></nav>
    <h2 class="op-page-title">Themes</h2>
</div></div>

<div class="op-card">
    <div class="p-4 border-b border-gray-200 dark:border-gray-700"><h3 class="text-sm font-semibold">All Themes</h3></div>
    <div class="p-4">
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
            <?php
            $themes = [];
            $themeDirs = glob(__DIR__ . '/../../../modules/themes/*', GLOB_ONLYDIR);
            foreach ($themeDirs as $dir) {
                if (!file_exists($dir . '/class.php')) continue;
                require_once $dir . '/class.php';
                $slug = basename($dir);
                $class = str_replace(' ', '', ucwords(str_replace('-', ' ', $slug))) . 'Theme';
                if (!class_exists($class)) continue;
                $themeObj = new $class();
                $themes[$slug] = $themeObj->info();
            }
            $allowEdit = \OwnPay\Service\Auth\PermissionService::hasPermission(json_decode($global_response_permission['response'][0]['permission'], true), 'theme_settings', 'edit', $global_user_response['response'][0]['role']);
            foreach ($themes as $slug => $theme):
                $isActive = ($global_response_brand['response'][0]['theme'] === $slug);
            ?>
            <div class="theme-li <?= $slug ?> border rounded-lg overflow-hidden dark:border-gray-700">
                <img src="<?= $site_url ?>app/modules/themes/<?= $slug.'/'.htmlspecialchars($theme['logo']) ?>" class="w-full h-48 object-cover p-3" alt="<?= htmlspecialchars($theme['title']) ?>">
                <div class="p-4 pt-0">
                    <h5 class="font-semibold text-sm text-gray-900 dark:text-white"><?= htmlspecialchars($theme['title']) ?></h5>
                    <div class="flex gap-2 mt-3">
                        <button onclick="activeTheme('<?= $slug ?>')" class="op-btn-primary text-xs activeBTN active-btn-<?= $slug ?> <?= $isActive || !$allowEdit ? 'hidden' : '' ?>">Activate</button>
                        <button onclick="load_content('Manage Setting','<?= $site_url.$path_admin ?>/settings/themes-setting?slug=<?= $slug ?>','nav-item-settings')" class="op-btn-secondary text-xs manage-btn <?= !$isActive || !$allowEdit ? 'hidden' : '' ?>">Manage</button>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<script nonce="<?= $csp_nonce ?? '' ?>" data-cfasync="false">
window.OP_DASHBOARD_URL='<?php echo $site_url.$path_admin ?>/dashboard';
function activeTheme(slug){
    var my=document.querySelector("#my-action-confirmation-btn")?.value||'';
    var btnClass='active-btn-'+slug;
    if(my!==""){
        var btnEl=document.querySelector('.'+btnClass),btn=btnEl.innerHTML;
        btnEl.innerHTML='<div class="op-spinner" style="width:16px;height:16px;border-width:2px"></div>';
        opFetch('themes-new-active',{slug}).then(res=>{
            closeAllModals();document.querySelector("#my-action-confirmation-btn").value='';btnEl.innerHTML=btn;
            if(res.status==='true'){
                apToast('success',res.title,res.message);
                document.querySelectorAll('.theme-li').forEach(d=>{const a=d.querySelector('.activeBTN'),m=d.querySelector('.manage-btn');if(a)a.classList.remove('hidden');if(m)m.classList.add('hidden');});
                document.querySelectorAll('.'+slug).forEach(d=>{const a=d.querySelector('.activeBTN'),m=d.querySelector('.manage-btn');if(a)a.classList.add('hidden');if(m)m.classList.remove('hidden');});
            }else{apToast('error',res.title,res.message);}
        }).catch(err=>{btnEl.textContent='Activate';apToastError();});
    }else{show_action_confirmation_tab(btnClass,'Active Theme','Confirm','btn-primary');}
}
</script>
