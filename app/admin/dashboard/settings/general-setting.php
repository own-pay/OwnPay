<?php
if (!defined('OWNPAY_INIT')) { http_response_code(403); exit('Direct access not allowed'); }
if (!canAccessPage(json_decode($global_response_permission['response'][0]['permission'], true), 'brand_settings', $global_user_response['response'][0]['role'])) { http_response_code(403); exit('Access denied.'); }
if (!hasPermission(json_decode($global_response_permission['response'][0]['permission'], true), 'brand_settings', 'view', $global_user_response['response'][0]['role'])) { http_response_code(403); exit('Access denied.'); }
$b = $global_response_brand['response'][0];
function bv($b, $k, $d='') { return empty($b[$k]) ? $d : $b[$k]; }
?>
<div class="op-page-header"><div>
    <nav class="flex mb-1"><ol class="inline-flex items-center space-x-1 text-sm text-gray-500"><li><a href="javascript:void(0)" onclick="load_content('Settings','<?php echo $site_url.$path_admin ?>/settings','nav-item-settings')" class="hover:text-primary-600">Settings</a></li><li class="flex items-center"><svg class="w-3 h-3 mx-1" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M7.293 14.707a1 1 0 010-1.414L10.586 10 7.293 6.707a1 1 0 011.414-1.414l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0z" clip-rule="evenodd"/></svg><span class="text-gray-900 dark:text-white">General Settings</span></li></ol></nav>
    <h2 class="op-page-title">General Settings</h2>
</div></div>

<form class="form-general-setting" enctype="multipart/form-data">
    <input type="hidden" name="action" value="general-setting">
    <input type="hidden" name="csrf_token" value="<?= $csrf_token; ?>">

    <div id="generalSettingsContent">
    <!-- General Tab -->
    <div>
        <div class="op-card"><div class="p-4 border-b border-gray-200 dark:border-gray-700"><h3 class="text-sm font-semibold">Basic Information</h3></div><div class="p-4">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div><label class="op-label">Site Name <span class="text-red-500">*</span></label><input type="text" class="op-input" name="site_name" value="<?= bv($b,'name', bv($b,'identify_name')) ?>" placeholder="Enter your site name" required></div>
                <div><label class="op-label">Default Timezone <span class="text-red-500">*</span></label>
                    <select class="js-select op-select" name="default_timezone" data-search="true" data-remove="true" data-placeholder="Select timezone" required>
                        <?php $timezones = DateTimeZone::listIdentifiers(DateTimeZone::ALL); $selTz = bv($b,'timezone');
                        foreach ($timezones as $tz): ?><option value="<?= $tz ?>" <?= ($tz === $selTz) ? 'selected' : '' ?>><?= $tz ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div><label class="op-label">Default Language <span class="text-red-500">*</span></label>
                    <?php $selLang = bv($b,'language'); ?>
                    <select class="js-select op-select" name="default_language" data-search="true" data-remove="true" data-placeholder="Select language" required>
                        <option value="en" <?= ($selLang === 'en') ? 'selected' : '' ?>>English</option>
                        <option value="bn" <?= ($selLang === 'bn') ? 'selected' : '' ?>>Bangla</option>
                        <option value="hi" <?= ($selLang === 'hi') ? 'selected' : '' ?>>Hindi</option>
                        <option value="ur" <?= ($selLang === 'ur') ? 'selected' : '' ?>>Urdu</option>
                        <option value="ar" <?= ($selLang === 'ar') ? 'selected' : '' ?>>Arabic</option>
                    </select>
                </div>
                <div><label class="op-label">Default Currency <span class="text-red-500">*</span></label>
                    <?php $selCurr = bv($b,'currency_code'); $response_brand = json_decode(getData($db_prefix . 'currency', 'WHERE brand_id = :brand_id ORDER BY 1 DESC', '* FROM', [':brand_id' => $b['brand_id']]), true); ?>
                    <select class="js-select op-select" id="default_currency" name="default_currency" data-search="true" data-remove="true" data-placeholder="Select currency" required onchange="FNcurrency()">
                        <?php if ($response_brand['status'] == true) { foreach ($response_brand['response'] as $row) { ?>
                            <option value="<?= $row['code'] ?>" <?= ($row['code'] === $selCurr) ? 'selected' : '' ?>><?= $row['code'] ?></option>
                        <?php } } ?>
                    </select>
                </div>
                <div><label class="op-label">Max Payment Tolerance <span class="text-red-500">*</span></label>
                    <div class="flex"><span class="op-input-group-text payment_tolerance_currency"><?= $selCurr ?></span><input type="number" class="op-input rounded-s-none" name="payment_tolerance" value="<?= bv($b,'payment_tolerance') ?>" placeholder="Enter payment tolerance" required></div>
                </div>
                <div><label class="op-label">Automatic Exchange Rates</label>
                    <?php $autoExchange = bv($b,'autoExchange'); ?>
                    <label class="relative inline-flex items-center cursor-pointer"><input type="checkbox" id="autoExchange" class="sr-only peer" value="enabled" <?= ($autoExchange === 'enabled') ? 'checked' : '' ?>><div class="w-11 h-6 bg-gray-200 peer-focus:ring-4 peer-focus:ring-primary-300 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:start-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-primary-600"></div></label>
                    <p class="text-xs text-gray-500 mt-1">When enabled, exchange rates are automatically fetched. When disabled, configure manually.</p>
                </div>
            </div>
        </div></div>
        <div class="op-card mt-4"><div class="p-4 border-b border-gray-200 dark:border-gray-700"><h3 class="text-sm font-semibold">Logo & Favicon</h3></div><div class="p-4">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div><label class="op-label">Favicon</label><input type="file" class="op-input img-input" name="favicon" data-preview="preview1">
                    <div class="border rounded-lg p-2 mt-2 flex items-center justify-center w-20 h-20 dark:border-gray-700"><img src="<?= bv($b,'favicon', $OwnPay_favicon) ?>" alt="" id="preview1" class="max-w-full max-h-full"></div>
                </div>
                <div><label class="op-label">Primary Logo</label><input type="file" class="op-input img-input" name="primary_logo" data-preview="preview2">
                    <div class="border rounded-lg p-2 mt-2 flex items-center justify-center h-20 max-w-xs dark:border-gray-700"><img src="<?= bv($b,'logo', $OwnPay_logo_light) ?>" alt="" id="preview2" class="max-w-full max-h-full"></div>
                </div>
            </div>
        </div></div>
    </div>

    <!-- Business Details Tab -->
    <div class="mt-4">
        <div class="op-card"><div class="p-4 border-b border-gray-200 dark:border-gray-700"><h3 class="text-sm font-semibold">Business Details</h3></div><div class="p-4">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div><label class="op-label">Street Address</label><input type="text" class="op-input" name="street_address" value="<?= bv($b,'street_address') ?>" placeholder="Enter your street address"></div>
                <div><label class="op-label">City/Town</label><input type="text" class="op-input" name="city_town" value="<?= bv($b,'city_town') ?>" placeholder="Enter your city/town"></div>
                <div><label class="op-label">Postal Code</label><input type="text" class="op-input" name="postal_code" value="<?= bv($b,'postal_code') ?>" placeholder="Enter your postal code"></div>
                <div><label class="op-label">Country</label><input type="text" class="op-input" name="country" value="<?= bv($b,'country') ?>" placeholder="Enter your country"></div>
            </div>
        </div></div>
    </div>

    <!-- Contact & Social Tab -->
    <div class="mt-4">
        <div class="op-card"><div class="p-4 border-b border-gray-200 dark:border-gray-700"><h3 class="text-sm font-semibold">Support Contact Information</h3></div><div class="p-4">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div><label class="op-label">Support Phone Number</label><input type="text" class="op-input" name="support_phone_number" value="<?= bv($b,'support_phone_number') ?>" placeholder="+1234567890" pattern="[0-9+\-\s()]{7,20}"></div>
                <div><label class="op-label">Support Email Address</label><input type="email" class="op-input" name="support_email_address" value="<?= bv($b,'support_email_address') ?>" placeholder="support@yourdomain.com"></div>
                <div><label class="op-label">Support Website</label><input type="text" class="op-input" name="support_website" value="<?= bv($b,'support_website') ?>" placeholder="https://yoursite.com"></div>
            </div>
        </div></div>
        <div class="op-card mt-4"><div class="p-4 border-b border-gray-200 dark:border-gray-700"><h3 class="text-sm font-semibold">Social Media Profiles</h3></div><div class="p-4">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div><label class="op-label">WhatsApp Number</label><input type="text" class="op-input" name="whatsapp_number" value="<?= bv($b,'whatsapp_number') ?>" placeholder="+1234567890"></div>
                <div><label class="op-label">Telegram</label><div class="flex"><span class="op-input-group-text">https://t.me/</span><input type="text" class="op-input rounded-s-none" name="telegram" value="<?= bv($b,'telegram') ?>" placeholder="username"></div></div>
                <div><label class="op-label">Facebook Messenger</label><div class="flex"><span class="op-input-group-text">https://m.me/</span><input type="text" class="op-input rounded-s-none" name="facebook_messenger" value="<?= bv($b,'facebook_messenger') ?>" placeholder="username"></div></div>
                <div><label class="op-label">Facebook Page</label><div class="flex"><span class="op-input-group-text">https://facebook.com/</span><input type="text" class="op-input rounded-s-none" name="facebook_page" value="<?= bv($b,'facebook_page') ?>" placeholder="username"></div></div>
            </div>
        </div></div>
    </div>

    </div><!-- /generalSettingsContent -->

    <div class="text-end pt-3"><button class="op-btn-primary btn-save-changes" type="submit">Save Changes</button></div>
</form>

<script nonce="<?= $csp_nonce ?? '' ?>" data-cfasync="false">
window.OP_DASHBOARD_URL='<?php echo $site_url.$path_admin ?>/dashboard';
function FNcurrency(){document.querySelector(".payment_tolerance_currency").innerHTML=document.querySelector("#default_currency").value;}
function initImagePreview(sel){document.querySelectorAll(sel).forEach(input=>{input.addEventListener('change',function(){const file=this.files[0],pid=this.dataset.preview,prev=document.getElementById(pid);if(!file||!prev)return;if(!['image/jpeg','image/png'].includes(file.type)){apToast('error','Invalid Format','Only JPG/PNG allowed.');this.value='';return;}if(file.size>2*1024*1024){apToast('error','File Too Large','Max 2MB allowed.');this.value='';return;}const r=new FileReader();r.onload=e=>{prev.src=e.target.result;};r.readAsDataURL(file);});});}
initImagePreview('.img-input');
document.querySelector('.form-general-setting').addEventListener('submit',function(e){e.preventDefault();let fd=new FormData(this);fd.set('autoExchange',document.getElementById('autoExchange').checked?'enabled':'disabled');var btnEl=document.querySelector('.btn-save-changes'),btn=btnEl.innerHTML;btnEl.innerHTML='<div class="op-spinner" style="width:16px;height:16px;border-width:2px"></div>';fetch('<?php echo $site_url.$path_admin ?>/dashboard',{method:'POST',body:fd}).then(r=>r.json()).then(res=>{apRotateCsrf(res.csrf_token);btnEl.innerHTML=btn;res.status==='true'?apToast('success',res.title,res.message):apToast('error',res.title,res.message);}).catch(err=>{btnEl.textContent='Save Changes';apToastError();});});
</script>
