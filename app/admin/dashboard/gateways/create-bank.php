<?php
    if (!defined('OWNPAY_INIT')) {
        http_response_code(403);
        exit('Direct access not allowed');
    }

    if (!canAccessPage(json_decode($global_response_permission['response'][0]['permission'], true), 'gateways', $global_user_response['response'][0]['role'])) {
        http_response_code(403);
        exit('Access denied. You need permission to perform this action. Please contact the admin.');
    }

    if (!hasPermission(json_decode($global_response_permission['response'][0]['permission'], true), 'gateways', 'create', $global_user_response['response'][0]['role'])) {
        http_response_code(403);
        exit('Access denied. You need permission to perform this action. Please contact the admin.');
    }

    $fields = [
        ['name' => 'bank_name', 'label' => 'Bank Name', 'type' => 'text', 'value' => '', 'required' => true, 'placeholder' => 'Enter bank name'],
        ['name' => 'account_holder_name', 'label' => 'Account Holder Name', 'type' => 'text', 'value' => '', 'required' => true, 'placeholder' => 'Enter account holder name'],
        ['name' => 'account_number', 'label' => 'Account Number', 'type' => 'text', 'value' => '', 'required' => true, 'placeholder' => 'Enter account number'],
        ['name' => 'branch_name', 'label' => 'Branch Name', 'type' => 'text', 'value' => '', 'required' => true, 'placeholder' => 'Enter branch name'],
        ['name' => 'routing_number', 'label' => 'Routing Number', 'type' => 'text', 'value' => '', 'required' => true, 'placeholder' => 'Enter routing number'],
        ['name' => 'swift_code', 'label' => 'SWIFT/BIC Code', 'type' => 'text', 'value' => '', 'required' => true, 'placeholder' => 'Enter code'],
    ];
?>

<div class="op-page-header">
    <div>
        <nav class="flex mb-1" aria-label="Breadcrumb">
            <ol class="inline-flex items-center space-x-1 text-sm text-gray-500 dark:text-gray-400">
                <li><a href="javascript:void(0)" onclick="load_content('Gateways','<?php echo $site_url.$path_admin ?>/gateways','nav-item-gateways')" class="hover:text-primary-600">Gateways</a></li>
                <li class="flex items-center"><svg class="w-3 h-3 mx-1" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M7.293 14.707a1 1 0 010-1.414L10.586 10 7.293 6.707a1 1 0 011.414-1.414l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0z" clip-rule="evenodd"></path></svg><span class="text-gray-900 dark:text-white">Create Bank Gateway</span></li>
            </ol>
        </nav>
        <h2 class="op-page-title">Create Bank Gateway</h2>
    </div>
</div>

<form class="form-submit" enctype="multipart/form-data">
    <input type="hidden" name="action" value="gateway-setting-create">
    <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">

    <!-- Information -->
    <div class="op-card">
        <div class="op-card-header"><h3 class="text-sm font-semibold text-gray-900 dark:text-white">Information</h3></div>
        <div class="p-4">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="op-label">Gateway Name <span class="text-red-500">*</span></label>
                    <input type="text" class="op-input" name="gateway_name" placeholder="Enter gateway name" required>
                </div>
                <div>
                    <label class="op-label">Display Name <span class="text-red-500">*</span></label>
                    <input type="text" class="op-input" name="display_name" placeholder="Enter display name" required>
                </div>
                <div>
                    <label class="op-label">Min Amount <span class="text-red-500">*</span></label>
                    <div class="flex"><span class="inline-flex items-center px-3 text-sm text-gray-500 bg-gray-100 border border-e-0 border-gray-300 rounded-s-lg dark:bg-gray-600 dark:text-gray-400 dark:border-gray-600 gt-currency"><?php echo $global_response_brand['response'][0]['currency_code']?></span><input type="text" class="op-input rounded-s-none" name="min_amount" value="0" required></div>
                </div>
                <div>
                    <label class="op-label">Max Amount <span class="text-red-500">*</span></label>
                    <div class="flex"><span class="inline-flex items-center px-3 text-sm text-gray-500 bg-gray-100 border border-e-0 border-gray-300 rounded-s-lg dark:bg-gray-600 dark:text-gray-400 dark:border-gray-600 gt-currency"><?php echo $global_response_brand['response'][0]['currency_code']?></span><input type="text" class="op-input rounded-s-none" name="max_amount" value="0" required></div>
                </div>
                <div>
                    <label class="op-label">Fixed Charge <span class="text-red-500">*</span></label>
                    <div class="flex"><span class="inline-flex items-center px-3 text-sm text-gray-500 bg-gray-100 border border-e-0 border-gray-300 rounded-s-lg dark:bg-gray-600 dark:text-gray-400 dark:border-gray-600 gt-currency"><?php echo $global_response_brand['response'][0]['currency_code']?></span><input type="text" class="op-input rounded-s-none" name="fixed_charge" value="0" required></div>
                </div>
                <div>
                    <label class="op-label">Percentage Charge <span class="text-red-500">*</span></label>
                    <div class="flex"><span class="inline-flex items-center px-3 text-sm text-gray-500 bg-gray-100 border border-e-0 border-gray-300 rounded-s-lg dark:bg-gray-600 dark:text-gray-400 dark:border-gray-600">%</span><input type="text" class="op-input rounded-s-none" name="percentage_charge" value="0" required></div>
                </div>
                <div>
                    <label class="op-label">Fixed Discount <span class="text-red-500">*</span></label>
                    <div class="flex"><span class="inline-flex items-center px-3 text-sm text-gray-500 bg-gray-100 border border-e-0 border-gray-300 rounded-s-lg dark:bg-gray-600 dark:text-gray-400 dark:border-gray-600 gt-currency"><?php echo $global_response_brand['response'][0]['currency_code']?></span><input type="text" class="op-input rounded-s-none" name="fixed_discount" value="0" required></div>
                </div>
                <div>
                    <label class="op-label">Percentage Discount <span class="text-red-500">*</span></label>
                    <div class="flex"><span class="inline-flex items-center px-3 text-sm text-gray-500 bg-gray-100 border border-e-0 border-gray-300 rounded-s-lg dark:bg-gray-600 dark:text-gray-400 dark:border-gray-600">%</span><input type="text" class="op-input rounded-s-none" name="percentage_discount" value="0" required></div>
                </div>
                <div>
                    <label class="op-label">Currency <span class="text-red-500">*</span></label>
                    <select class="js-select op-select" id="currency" name="currency" data-search="true" data-remove="true" required onchange="FNcurrency()">
                        <?php
                            $response_brand = json_decode(getData($db_prefix . 'currency', 'WHERE brand_id ="'.$global_response_brand['response'][0]['brand_id'].'" ORDER BY 1 DESC'), true);
                            if ($response_brand['status'] == true) {
                                foreach ($response_brand['response'] as $row) {
                                    $isSelected = ($row['code'] === $global_response_brand['response'][0]['currency_code']) ? 'selected' : '';
                                    echo '<option value="'.$row['code'].'" '.$isSelected.'>'.$row['code'].'</option>';
                                }
                            }
                        ?>
                    </select>
                </div>
                <div>
                    <label class="op-label">Status <span class="text-red-500">*</span></label>
                    <select class="js-select op-select" name="status" required>
                        <option value="active" selected>Active</option>
                        <option value="inactive">Inactive</option>
                    </select>
                </div>
            </div>
        </div>
    </div>

    <!-- Assets -->
    <div class="op-card mt-4">
        <div class="op-card-header"><h3 class="text-sm font-semibold text-gray-900 dark:text-white">Assets</h3></div>
        <div class="p-4">
            <div>
                <label class="op-label">Gateway Logo <span class="text-xs text-gray-400">(JPG, JPEG, PNG — 500×250px)</span></label>
                <input type="file" class="op-input img-input" name="gateway_logo" data-preview="preview2">
            </div>
            <div class="border rounded-lg p-2 mt-2 flex items-center justify-center h-20 max-w-xs dark:border-gray-700">
                <img src="" alt="" id="preview2" class="max-w-full max-h-full hidden">
            </div>
        </div>
    </div>

    <!-- Colors -->
    <div class="op-card mt-4">
        <div class="op-card-header"><h3 class="text-sm font-semibold text-gray-900 dark:text-white">Colors</h3></div>
        <div class="p-4">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div><label class="op-label">Primary Color <span class="text-red-500">*</span></label><input type="color" class="op-input h-10" name="primary_color" required readonly></div>
                <div><label class="op-label">Text Color <span class="text-red-500">*</span></label><input type="color" class="op-input h-10" name="text_color" required></div>
                <div><label class="op-label">Button Color <span class="text-red-500">*</span></label><input type="color" class="op-input h-10" name="btn_color" required></div>
                <div><label class="op-label">Button Text Color <span class="text-red-500">*</span></label><input type="color" class="op-input h-10" name="btn_text_color" required></div>
            </div>
        </div>
    </div>

    <!-- Configuration -->
    <div class="op-card mt-4">
        <div class="op-card-header"><h3 class="text-sm font-semibold text-gray-900 dark:text-white">Configuration</h3></div>
        <div class="p-4">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <?php foreach($fields as $field):
                    $value = '';
                    if(!empty($field['multiple']) && !empty($value)) $value = is_array($value) ? $value : json_decode($value, true);
                ?>
                <div>
                    <label class="op-label"><?= $field['label'] ?> <?php if(!empty($field['required'])): ?><span class="text-red-500">*</span><?php endif; ?></label>
                    <?php
                    switch($field['type']) {
                        case 'text':
                            echo "<input type='text' class='op-input' name='{$field['name']}' value='".htmlspecialchars($value)."' placeholder='".($field['placeholder'] ?? '')."' ".(!empty($field['required']) ? 'required' : '').">";
                            break;
                        case 'textarea':
                            echo "<textarea class='op-input' name='{$field['name']}' placeholder='".($field['placeholder'] ?? '')."'>".htmlspecialchars($value)."</textarea>";
                            break;
                        case 'select':
                            $multiple = !empty($field['multiple']);
                            $name = $multiple ? $field['name'].'[]' : $field['name'];
                            $valueArray = $multiple ? (array)$value : [$value];
                            echo "<select class='js-select op-select' data-search='true' data-remove='true' name='$name' ".($multiple ? 'multiple' : '')." ".(!empty($field['required']) ? 'required' : '').">";
                            foreach($field['options'] as $k=>$v){ $selected = in_array($k, $valueArray) ? 'selected' : ''; echo "<option value='$k' $selected>$v</option>"; }
                            echo "</select>";
                            break;
                        case 'checkbox':
                            $checked = $value ? 'checked' : '';
                            echo "<label class='relative inline-flex items-center cursor-pointer'><input type='checkbox' class='sr-only peer' name='{$field['name']}' value='1' $checked><div class='w-11 h-6 bg-gray-200 peer-focus:outline-none rounded-full peer dark:bg-gray-700 peer-checked:after:translate-x-full rtl:peer-checked:after:-translate-x-full peer-checked:after:border-white after:content-[\"\"] after:absolute after:top-[2px] after:start-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all dark:border-gray-600 peer-checked:bg-primary-600'></div></label>";
                            if(!empty($field['hint'])) echo "<p class='text-xs text-gray-500 mt-1'>{$field['hint']}</p>";
                            break;
                        case 'radio':
                            foreach($field['options'] as $k=>$v){ $checked = $value == $k ? 'checked' : ''; echo "<div class='flex items-center mb-2'><input type='radio' class='w-4 h-4 text-primary-600' name='{$field['name']}' value='$k' $checked><label class='ms-2 text-sm text-gray-900 dark:text-gray-300'>$v</label></div>"; }
                            break;
                    }
                    ?>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <div class="text-end pt-4">
        <button class="op-btn-primary btn-saveChanges" type="submit">Create</button>
    </div>
</form>

<script nonce="<?= $csp_nonce ?? '' ?>" data-cfasync="false">
    window.OP_DASHBOARD_URL = '<?php echo $site_url.$path_admin ?>/dashboard';

    function FNcurrency() {
        var c = document.getElementById("currency")?.value;
        document.querySelectorAll(".gt-currency").forEach(el => el.textContent = c);
    }

    function initImagePreview(selector) {
        document.querySelectorAll(selector).forEach(input => {
            input.addEventListener('change', function() {
                const file = this.files[0]; const preview = document.getElementById(this.dataset.preview);
                if (!file || !preview) return;
                if (!['image/jpeg','image/png'].includes(file.type)) { apToast('error', 'Action required!', 'Unsupported image format.'); this.value = ''; preview.classList.add('hidden'); return; }
                if (file.size > 2*1024*1024) { apToast('error', 'Action required!', 'Image size exceeds 2 MB limit.'); this.value = ''; preview.classList.add('hidden'); return; }
                const reader = new FileReader();
                reader.onload = e => { preview.src = e.target.result; preview.classList.remove('hidden'); };
                reader.readAsDataURL(file);
            });
        });
    }
    initImagePreview('.img-input');

    document.querySelector('.form-submit').addEventListener('submit', function(e) {
        e.preventDefault();
        let valid = true;
        this.querySelectorAll('input[type="file"]').forEach(f => {
            if (!f.files.length) return;
            if (!f.files[0].type.startsWith('image/')) { apToast('error', 'Action required!', 'Unsupported image format.'); valid = false; }
            if (f.files[0].size > 2*1024*1024) { apToast('error', 'Action required!', 'Image exceeds 2 MB.'); valid = false; }
        });
        if (!valid) return;
        var btnEl = document.querySelector('.btn-saveChanges'); var btn = btnEl.innerHTML;
        btnEl.innerHTML = '<div class="op-spinner" style="width:16px;height:16px;border-width:2px"></div>';
        fetch('<?php echo $site_url.$path_admin ?>/dashboard', { method: 'POST', body: new FormData(this) })
            .then(r => r.json()).then(response => {
                apRotateCsrf(response.csrf_token); btnEl.innerHTML = btn;
                response.status === 'true' ? (apToast('success', response.title, response.message), load_content('Gateways','<?php echo $site_url.$path_admin ?>/gateways','nav-item-gateways')) : apToast('error', response.title, response.message);
            }).catch(err => apToastError());
    });
</script>
