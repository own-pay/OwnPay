<?php
if (!defined('OWNPAY_INIT')) { http_response_code(403); exit('Direct access not allowed'); }
if (!\OwnPay\Service\Auth\PermissionService::canAccessPage(json_decode($global_response_permission['response'][0]['permission'], true), 'system_settings', $global_user_response['response'][0]['role'])) { http_response_code(403); exit('Access denied.'); }
if (!\OwnPay\Service\Auth\PermissionService::hasPermission(json_decode($global_response_permission['response'][0]['permission'], true), 'system_settings', 'manage_general', $global_user_response['response'][0]['role'])) { http_response_code(403); exit('Access denied.'); }
?>
<div class="op-page-header"><div>
    <nav class="flex mb-1"><ol class="inline-flex items-center space-x-1 text-sm text-gray-500"><li><a href="javascript:void(0)" onclick="load_content('Settings','<?php echo $site_url.$path_admin ?>/settings?tab=system','nav-item-settings')" class="hover:text-primary-600">Settings</a></li><li class="flex items-center"><svg class="w-3 h-3 mx-1" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M7.293 14.707a1 1 0 010-1.414L10.586 10 7.293 6.707a1 1 0 011.414-1.414l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0z" clip-rule="evenodd"/></svg><span class="text-gray-900 dark:text-white">General Settings</span></li></ol></nav>
    <h2 class="op-page-title">General Settings</h2>
</div></div>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    <div><h3 class="font-semibold text-gray-900 dark:text-white">Application Settings</h3><p class="text-sm text-gray-500 mt-1">Configure the general settings for your application</p></div>
    <div class="lg:col-span-2">
        <div class="op-card"><div class="p-4">
            <div class="space-y-4">
                <div><label class="op-label">Default Timezone <span class="text-red-500">*</span></label>
                    <?php $timezones = DateTimeZone::listIdentifiers(DateTimeZone::ALL);
                    $selectedTimezone = \OwnPay\Service\System\EnvironmentService::get('geneal-application-settings-default_timezone');
                    $selectedTimezone = empty($selectedTimezone) ? '' : $selectedTimezone; ?>
                    <select class="js-select op-select" id="default_timezone" data-search="true" data-remove="true" data-placeholder="Select timezone" required>
                        <?php foreach ($timezones as $tz): ?><option value="<?= $tz ?>" <?= ($tz === $selectedTimezone) ? 'selected' : '' ?>><?= $tz ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div><label class="op-label">Webhook Attempt Limit</label>
                    <?php $selWh = \OwnPay\Service\System\EnvironmentService::get('geneal-application-settings-webhook_attempts_limit'); $selWh = empty($selWh) ? '1' : $selWh; ?>
                    <select class="js-select op-select" id="webhook_attempts_limit" data-search="true" data-remove="true" data-placeholder="Select attempt limit" required>
                        <?php for ($i = 0; $i <= 10; $i++): ?><option value="<?= $i ?>" <?= ($i == $selWh) ? 'selected' : '' ?>><?= $i ?> <?= $i === 1 ? 'Attempt' : 'Attempts' ?></option><?php endfor; ?>
                    </select>
                    <p class="text-xs text-gray-500 mt-1">Number of webhook retry attempts on failure. Set 0 to disable.</p>
                </div>
                <div><label class="op-label">Homepage Redirect</label>
                    <div class="flex"><span class="op-input-group-text">https://</span><input type="text" class="op-input rounded-s-none" id="homepageRedirect" placeholder="example.com" value="<?= \OwnPay\Service\System\EnvironmentService::get('geneal-application-settings-homepageRedirect'); ?>"></div>
                    <p class="text-xs text-gray-500 mt-1">Visitors to the base domain will be redirected here.</p>
                </div>
                <?php
                $pathFields = [
                    ['id' => 'adminPath', 'label' => 'Admin path', 'placeholder' => 'admin', 'hint' => 'Example: admin, console, portal'],
                    ['id' => 'invoicePath', 'label' => 'Invoice path', 'placeholder' => 'invoice', 'hint' => 'Example: invoice, myinvoice'],
                    ['id' => 'paymentLinkPath', 'label' => 'Payment Link path', 'placeholder' => 'payment-link', 'hint' => 'Example: payment-link'],
                    ['id' => 'paymentPath', 'label' => 'Checkout path', 'placeholder' => 'payment', 'hint' => 'Example: payment'],
                    ['id' => 'cronPath', 'label' => 'Cron path', 'placeholder' => 'cron', 'hint' => 'Example: cron'],
                ];
                foreach ($pathFields as $pf): ?>
                <div><label class="op-label"><?= $pf['label'] ?></label>
                    <div class="flex"><span class="op-input-group-text"><?= $site_url ?></span><input type="text" class="op-input rounded-s-none" id="<?= $pf['id'] ?>" placeholder="<?= $pf['placeholder'] ?>" value="<?= \OwnPay\Service\System\EnvironmentService::get('geneal-application-settings-'.$pf['id']); ?>"></div>
                    <p class="text-xs text-gray-500 mt-1">Lowercase letters, numbers, and dashes only. <?= $pf['hint'] ?></p>
                </div>
                <?php endforeach; ?>
            </div>
        </div></div>
        <div class="text-end pt-3"><button class="op-btn-primary btn-geneal-application-settings" type="button">Save Changes</button></div>
    </div>
</div>

<script nonce="<?= $csp_nonce ?? '' ?>" data-cfasync="false">
window.OP_DASHBOARD_URL='<?php echo $site_url.$path_admin ?>/dashboard';
document.querySelector('.btn-geneal-application-settings').addEventListener('click',function(){
    var btnEl=this,btn=btnEl.innerHTML;
    btnEl.innerHTML='<div class="op-spinner" style="width:16px;height:16px;border-width:2px"></div>';
    opFetch('geneal-application-settings',{default_timezone:document.getElementById('default_timezone').value,webhook_attempts_limit:document.getElementById('webhook_attempts_limit').value,homepageRedirect:document.getElementById('homepageRedirect').value,adminPath:document.getElementById('adminPath').value,invoicePath:document.getElementById('invoicePath').value,paymentLinkPath:document.getElementById('paymentLinkPath').value,paymentPath:document.getElementById('paymentPath').value,cronPath:document.getElementById('cronPath').value}).then(res=>{btnEl.innerHTML=btn;res.status==='true'?apToast('success',res.title,res.message):apToast('error',res.title,res.message);}).catch(err=>apToastError());
});
</script>
