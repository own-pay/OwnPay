<?php
    if (!defined('OWNPAY_INIT')) { http_response_code(403); exit('Direct access not allowed'); }
    if (!canAccessPage(json_decode($global_response_permission['response'][0]['permission'], true), 'addons', $global_user_response['response'][0]['role'])) { http_response_code(403); exit('Access denied.'); }
    if (!hasPermission(json_decode($global_response_permission['response'][0]['permission'], true), 'addons', 'edit', $global_user_response['response'][0]['role'])) { http_response_code(403); exit('Access denied.'); }

    $params = json_decode($_POST['params'] ?? '{}', true);
    $ref = getParam($params, 'ref');
    if ($ref === null) { http_response_code(403); exit('Invalid slug'); }

    $ref = clean_input($ref);
    $response_addon = json_decode(getData($db_prefix.'addon','WHERE addon_id = :addon_id', '* FROM', [':addon_id' => $ref]),true);
    if($response_addon['status'] == false){ http_response_code(403); exit('Invalid slug'); }

    $rawSlug = $response_addon['response'][0]['slug'];
    $safeSlug = preg_replace('/[^a-zA-Z0-9_\-]/', '', $rawSlug);
    $addonBase = realpath(__DIR__ . '/../../../modules/addons');
    $classFile = realpath($addonBase . '/' . $safeSlug . '/class.php');
    if ($addonBase === false || $classFile === false || strpos($classFile, $addonBase . DIRECTORY_SEPARATOR) !== 0) {
        http_response_code(403); exit('Invalid slug');
    }
    // nosemgrep: php.laravel.security.laravel-path-traversal.laravel-path-traversal, php.lang.security.tainted-path-traversal.tainted-path-traversal
    require_once $classFile;
    $slug = basename($safeSlug);
    $class = str_replace(' ', '', ucwords(str_replace('-', ' ', $slug))) . 'Addon';
    if (!class_exists($class)) { http_response_code(403); exit('Invalid slug'); }
    // nosemgrep: php.lang.security.injection.tainted-object-instantiation.tainted-object-instantiation
    $addonObj = new $class();
    if (method_exists($addonObj, 'info')) { $addonInfo = $addonObj->info(); }
    else { http_response_code(403); exit('Invalid info'); }
?>

<div class="op-page-header">
    <div>
        <nav class="flex mb-1" aria-label="Breadcrumb">
            <ol class="inline-flex items-center space-x-1 text-sm text-gray-500 dark:text-gray-400">
                <li><a href="javascript:void(0)" onclick="load_content('Addons','<?php echo $site_url.$path_admin ?>/addons','nav-item-addons')" class="hover:text-primary-600">Addons</a></li>
                <li class="flex items-center"><svg class="w-3 h-3 mx-1" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M7.293 14.707a1 1 0 010-1.414L10.586 10 7.293 6.707a1 1 0 011.414-1.414l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0z" clip-rule="evenodd"></path></svg><span class="text-gray-900 dark:text-white">Addon Setting</span></li>
            </ol>
        </nav>
        <h2 class="op-page-title">Addon Setting</h2>
    </div>
</div>

<form class="form-submit" enctype="multipart/form-data">
    <input type="hidden" name="action" value="addon-setting-update">
    <input type="hidden" name="addon-id" value="<?php echo htmlspecialchars($response_addon['response'][0]['addon_id'], ENT_QUOTES, 'UTF-8'); ?>">
    <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">

    <div class="op-card">
        <div class="op-card-header"><h3 class="text-lg font-semibold text-gray-900 dark:text-white">Information</h3></div>
        <div class="p-4">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div><label class="op-label">Addon Name <span class="text-red-500">*</span></label><input type="text" class="op-input" name="addon_name" value="<?php echo htmlspecialchars($response_addon['response'][0]['name'], ENT_QUOTES, 'UTF-8'); ?>" readonly></div>
                <div><label class="op-label">Status <span class="text-red-500">*</span></label><select class="op-select" name="status"><option value="active" <?php echo ($response_addon['response'][0]['status'] == "active") ? 'selected' : '';?>>Active</option><option value="inactive" <?php echo ($response_addon['response'][0]['status'] == "inactive") ? 'selected' : '';?>>Inactive</option></select></div>
            </div>
        </div>
    </div>

    <div class="text-end pt-3"><button class="op-btn-primary btn-saveChanges" type="submit">Save Changes</button></div>
</form>

<?php
    if (method_exists($addonObj, 'configuration')) {
        echo $addonObj->configuration();
    }
?>

<script nonce="<?= $csp_nonce ?? '' ?>" data-cfasync="false">
    window.OP_DASHBOARD_URL = '<?php echo $site_url.$path_admin ?>/dashboard';

    document.querySelector('.form-submit').addEventListener('submit', function(e){
        e.preventDefault();
        let formData = new FormData(this);
        var btnEl = document.querySelector('.btn-saveChanges'); var btn = btnEl.innerHTML;
        btnEl.innerHTML = '<div class="op-spinner" style="width:16px;height:16px;border-width:2px"></div>';
        fetch('<?php echo $site_url.$path_admin ?>/dashboard', { method: 'POST', body: formData })
            .then(r => r.json())
            .then(response => {
                apRotateCsrf(response.csrf_token); closeAllModals(); btnEl.innerHTML = btn;
                response.status === 'true' ? apToast('success', response.title, response.message) : apToast('error', response.title, response.message);
            })
            .catch(err => apToastError());
    });
</script>
