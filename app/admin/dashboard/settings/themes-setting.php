<?php
if (!defined('OWNPAY_INIT')) { http_response_code(403); exit('Direct access not allowed'); }
if (!canAccessPage(json_decode($global_response_permission['response'][0]['permission'], true), 'brand_settings', $global_user_response['response'][0]['role'])) { http_response_code(403); exit('Access denied.'); }
if (!hasPermission(json_decode($global_response_permission['response'][0]['permission'], true), 'theme_settings', 'edit', $global_user_response['response'][0]['role'])) { http_response_code(403); exit('Access denied.'); }
$params = json_decode($_POST['params'] ?? '{}', true);
$slug = getParam($params, 'slug');
if ($slug === null) { http_response_code(403); exit('Invalid slug'); }
$slug = clean_input($slug);
if ($global_response_brand['response'][0]['theme'] !== $slug) { http_response_code(403); exit('Invalid slug'); }
if (!file_exists(__DIR__.'/../../../modules/themes/'.$slug.'/class.php')) { http_response_code(403); exit('Invalid slug'); }
require_once __DIR__.'/../../../modules/themes/'.$slug.'/class.php';
$class = str_replace(' ', '', ucwords(str_replace('-', ' ', $slug))) . 'Theme';
$theme = new $class(); $fields = $theme->fields(); $supported_languages = $theme->supported_languages(); $themeSlug = $slug;
?>
<div class="op-page-header"><div>
    <nav class="flex mb-1"><ol class="inline-flex items-center space-x-1 text-sm text-gray-500"><li><a href="javascript:void(0)" onclick="load_content('Settings','<?php echo $site_url.$path_admin ?>/settings','nav-item-settings')" class="hover:text-primary-600">Settings</a></li><li class="flex items-center"><svg class="w-3 h-3 mx-1" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M7.293 14.707a1 1 0 010-1.414L10.586 10 7.293 6.707a1 1 0 011.414-1.414l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0z" clip-rule="evenodd"/></svg><a href="javascript:void(0)" onclick="load_content('Themes','<?php echo $site_url.$path_admin ?>/settings/themes','nav-item-settings')" class="hover:text-primary-600">Themes</a></li><li class="flex items-center"><svg class="w-3 h-3 mx-1" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M7.293 14.707a1 1 0 010-1.414L10.586 10 7.293 6.707a1 1 0 011.414-1.414l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0z" clip-rule="evenodd"/></svg><span class="text-gray-900 dark:text-white">Theme Setting</span></li></ol></nav>
    <h2 class="op-page-title">Theme Setting</h2>
</div></div>

<form class="form-submit" enctype="multipart/form-data">
    <input type="hidden" name="action" value="theme-setting-update">
    <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">

    <div class="op-card"><div class="p-4 border-b border-gray-200 dark:border-gray-700"><h3 class="text-sm font-semibold">Configuration</h3></div><div class="p-4">
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <?php foreach($fields as $field):
                $optionName = $themeSlug . '-' . $field['name'];
                $value = $field['value'] ?? '';
                $envVal = get_env($optionName, $global_response_brand['response'][0]['brand_id']);
                $value = empty($envVal) ? $value : $envVal;
                if(!empty($field['multiple']) && !empty($value)) { $value = is_array($value) ? $value : json_decode($value, true); }
            ?>
            <div>
                <label class="op-label"><?= $field['label'] ?> <?php if(!empty($field['required'])): ?><span class="text-red-500">*</span><?php endif; ?></label>
                <?php switch($field['type']) {
                    case 'text': echo "<input type='text' class='op-input' name='{$field['name']}' value='".htmlspecialchars($value)."' placeholder='".($field['placeholder'] ?? '')."' ".(!empty($field['required']) ? 'required' : '').">"; break;
                    case 'color': echo "<input type='color' class='op-input h-10' name='{$field['name']}' value='".htmlspecialchars($value)."' ".(!empty($field['required']) ? 'required' : '').">"; break;
                    case 'textarea': echo "<textarea class='op-input' name='{$field['name']}' placeholder='".($field['placeholder'] ?? '')."' ".(!empty($field['required']) ? 'required' : '').">".htmlspecialchars($value)."</textarea>"; break;
                    case 'select':
                        $multiple = !empty($field['multiple']); $name = $multiple ? $field['name'].'[]' : $field['name']; $valueArray = $multiple ? (array)$value : [$value];
                        echo "<select class='op-select js-select' data-search='true' data-remove='true' name='$name' ".($multiple ? 'multiple' : '')." ".(!empty($field['required']) ? 'required' : '').">";
                        foreach($field['options'] as $k=>$v){ $selected = in_array($k, $valueArray) ? 'selected' : ''; echo "<option value='$k' $selected>$v</option>"; }
                        echo "</select>"; break;
                    case 'checkbox':
                        $checked = $value ? 'checked' : '';
                        echo "<label class='relative inline-flex items-center cursor-pointer'><input type='checkbox' class='sr-only peer' name='{$field['name']}' value='1' $checked><div class='w-11 h-6 bg-gray-200 peer-focus:ring-4 peer-focus:ring-primary-300 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[\"\"] after:absolute after:top-[2px] after:start-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-primary-600'></div></label>"; break;
                    case 'image':
                        echo "<input type='file' class='op-input img-input' name='{$field['name']}' data-preview='{$field['name']}' ".(!empty($field['required']) ? 'required' : '').">
                        <div class='border rounded-lg p-2 mt-2 flex items-center justify-center h-20 max-w-xs dark:border-gray-700'><img src='$value' alt='' id='{$field['name']}' class='max-w-full max-h-full'></div>"; break;
                    case 'radio':
                        foreach($field['options'] as $k=>$v){ $checked = $value == $k ? 'checked' : '';
                            echo "<label class='flex items-center gap-2 text-sm mb-1'><input type='radio' class='op-checkbox' name='{$field['name']}' value='$k' $checked ".(!empty($field['required']) ? 'required' : '')."><span>$v</span></label>";
                        } break;
                } ?>
                <?php if (!empty($field['hint'])): ?><p class="text-xs text-gray-500 mt-1"><?= $field['hint'] ?></p><?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
    </div></div>

    <div class="op-card mt-4"><div class="p-4 border-b border-gray-200 dark:border-gray-700"><h3 class="text-sm font-semibold">Supported Languages</h3></div><div class="p-4">
        <?php if (!empty($supported_languages)): foreach ($supported_languages as $language): ?>
            <span class="op-badge-primary me-1 mb-1"><?= htmlspecialchars($language) ?></span>
        <?php endforeach; else: ?>
            <p class="text-sm text-gray-500">No supported languages available.</p>
        <?php endif; ?>
    </div></div>

    <div class="text-end pt-3"><button class="op-btn-primary btn-saveChanges" type="submit">Save Changes</button></div>
</form>

<script nonce="<?= $csp_nonce ?? '' ?>" data-cfasync="false">
window.OP_DASHBOARD_URL='<?php echo $site_url.$path_admin ?>/dashboard';
function initImagePreview(sel){document.querySelectorAll(sel).forEach(input=>{input.addEventListener('change',function(){const file=this.files[0],pid=this.dataset.preview,prev=document.getElementById(pid);if(!file||!prev)return;if(!['image/jpeg','image/png'].includes(file.type)){apToast('error','Invalid Format','Only JPG/PNG.');this.value='';return;}if(file.size>2*1024*1024){apToast('error','File Too Large','Max 2MB.');this.value='';return;}const r=new FileReader();r.onload=e=>{prev.src=e.target.result;};r.readAsDataURL(file);});});}
initImagePreview('.img-input');
document.querySelector('.form-submit').addEventListener('submit',function(e){e.preventDefault();let fd=new FormData(this);var btnEl=document.querySelector('.btn-saveChanges'),btn=btnEl.innerHTML;btnEl.innerHTML='<div class="op-spinner" style="width:16px;height:16px;border-width:2px"></div>';fetch('<?php echo $site_url.$path_admin ?>/dashboard',{method:'POST',body:fd}).then(r=>r.json()).then(res=>{apRotateCsrf(res.csrf_token);btnEl.innerHTML=btn;res.status==='true'?apToast('success',res.title,res.message):apToast('error',res.title,res.message);}).catch(err=>{btnEl.textContent='Save Changes';apToastError();});});
</script>
