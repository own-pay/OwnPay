<?php
if (!defined('OWNPAY_INIT')) {
    http_response_code(403);
    exit('Direct access not allowed');
}

    if (!canAccessPage(json_decode($global_response_permission['response'][0]['permission'], true), 'payment_link', $global_user_response['response'][0]['role'])) {
        http_response_code(403);
        exit('Access denied. You need permission to perform this action. Please contact the admin.');
    }

    if (!hasPermission(json_decode($global_response_permission['response'][0]['permission'], true), 'payment_link', 'edit', $global_user_response['response'][0]['role'])) {
        http_response_code(403);
        exit('Access denied. You need permission to perform this action. Please contact the admin.');
    }

    $params = json_decode($_POST['params'] ?? '{}', true);
    $ref = getParam($params, 'p_id');

    if ($ref === null) {
        http_response_code(403);
        exit('Invalid payment link id');
    } else {
        $ref = clean_input($ref);
        $response_paymentLink = json_decode(getData($db_prefix.'payment_link','WHERE ref = "'.$ref.'" AND brand_id = "'.$global_response_brand['response'][0]['brand_id'].'"'),true);
        if($response_paymentLink['status'] == true){
            $response_product_info = json_decode($response_paymentLink['response'][0]['product_info'], true);
        } else {
            http_response_code(403);
            exit('Direct access not allowed');
        }
    }
?>

<div class="op-page-header">
    <div>
        <nav class="flex mb-1" aria-label="Breadcrumb">
            <ol class="inline-flex items-center space-x-1 text-sm text-gray-500 dark:text-gray-400">
                <li><a href="javascript:void(0)" onclick="load_content('Payment Link','<?php echo $site_url.$path_admin ?>/payment-link','nav-item-payment-link')" class="hover:text-primary-600">Payment Link</a></li>
                <li class="flex items-center"><svg class="w-3 h-3 mx-1" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M7.293 14.707a1 1 0 010-1.414L10.586 10 7.293 6.707a1 1 0 011.414-1.414l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0z" clip-rule="evenodd"></path></svg><span class="text-gray-900 dark:text-white">Edit Payment Link</span></li>
            </ol>
        </nav>
        <h2 class="op-page-title">Edit Payment Link</h2>
    </div>
    <div class="flex items-center gap-2">
        <button class="op-btn-primary" onclick="copyContent('<?php echo $site_url.$path_payment_link ?>/<?php echo $ref;?>', 'Copied!', 'Payment Link copied successfully.')">Copy Link</button>
        <button class="op-btn-danger btnDeleteItem-<?php echo $ref;?> <?= hasPermission(json_decode($global_response_permission['response'][0]['permission'], true), 'payment_link', 'delete', $global_user_response['response'][0]['role']) ? '' : 'hidden' ?>" onclick="deleteItem('<?php echo $ref;?>')">Delete</button>
    </div>
</div>

<form class="form-paymentLink-edit">
    <input type="hidden" name="action" value="paymentLink-edit">
    <input type="hidden" name="csrf_token" value="<?= $csrf_token; ?>">
    <input type="hidden" name="paymentLinkID" value="<?php echo $response_paymentLink['response'][0]['ref']?>">
    <input type="hidden" name="deleted_items" id="deleted_items" value="">

    <div class="op-card p-5">
        <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
            <div class="md:col-span-3">
                <label class="op-label">Product Title <span class="text-red-500">*</span></label>
                <input type="text" class="op-input" name="title" placeholder="Product Title" required value="<?php echo htmlspecialchars($response_product_info['title'], ENT_QUOTES, 'UTF-8')?>">
            </div>
            <div>
                <label class="op-label">Quantity <span class="text-red-500">*</span></label>
                <input type="text" class="op-input" name="quantity" placeholder="0" required value="<?php echo htmlspecialchars($response_paymentLink['response'][0]['quantity'], ENT_QUOTES, 'UTF-8')?>">
            </div>
            <div class="md:col-span-4">
                <label class="op-label">Product Description <span class="text-red-500">*</span></label>
                <textarea name="description" class="op-input" rows="3"><?php echo htmlspecialchars($response_product_info['description'], ENT_QUOTES, 'UTF-8')?></textarea>
            </div>
            <div>
                <label class="op-label">Currency <span class="text-red-500">*</span></label>
                <select class="js-select in-currency op-select" name="currency" data-search="true" data-remove="true" required onchange="FNcurrency()">
                    <?php
                        $response_brand = json_decode(getData($db_prefix . 'currency', 'WHERE brand_id ="'.$global_response_brand['response'][0]['brand_id'].'" ORDER BY 1 DESC'), true);
                        if ($response_brand['status'] == true) {
                            foreach ($response_brand['response'] as $row) {
                    ?>
                                <option value="<?php echo $row['code'] ?>" <?php echo ($response_paymentLink['response'][0]['currency'] == $row['code']) ? 'selected' : '';?>><?php echo $row['code'] ?></option>
                    <?php
                            }
                        }
                    ?>
                </select>
            </div>
            <div>
                <label class="op-label">Amount <span class="text-red-500">*</span></label>
                <div class="flex">
                    <span class="inline-flex items-center px-3 text-sm text-gray-500 bg-gray-100 border border-e-0 border-gray-300 rounded-s-lg dark:bg-gray-600 dark:text-gray-400 dark:border-gray-600 currency-code"><?php echo $global_brand_currency_code?></span>
                    <input type="text" class="op-input rounded-s-none" name="amount" value="<?php echo money_round($response_paymentLink['response'][0]['amount'])?>" required>
                </div>
            </div>
            <div>
                <label class="op-label">Expiry Date</label>
                <input type="date" class="op-input" name="expiry_date" value="<?php echo $response_paymentLink['response'][0]['expired_date'] ?? '';?>">
            </div>
            <div>
                <label class="op-label">Status <span class="text-red-500">*</span></label>
                <select class="js-select op-select" name="status" required>
                    <option value="active" <?= ($response_paymentLink['response'][0]['status'] === 'active') ? 'selected' : '' ?>>Active</option>
                    <option value="inactive" <?= ($response_paymentLink['response'][0]['status'] === 'inactive') ? 'selected' : '' ?>>Inactive</option>
                </select>
            </div>
        </div>
    </div>

    <!-- Existing Fields -->
    <div class="item-list space-y-4 mt-4">
        <?php
            $response = json_decode(getData($db_prefix.'payment_link_field','WHERE paymentLinkID ="'.$ref.'"'),true);
            foreach($response['response'] as $row){
                $uniqueID = uniqid();
        ?>
                <input type="hidden" name="items[item-card-<?php echo $uniqueID?>][fieldID]" value="<?php echo $row['id']?>">
                <div class="op-card" id="item-card-<?php echo $uniqueID?>">
                    <div class="flex items-center justify-between p-4 border-b border-gray-200 dark:border-gray-700">
                        <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Input Field</h3>
                        <button type="button" class="remove-item text-red-500 hover:text-red-700" onclick="delete_item('item-card-<?php echo $uniqueID?>', '<?php echo $row['id']?>')">
                            <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 pointer-events-none" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 7l16 0" /><path d="M10 11l0 6" /><path d="M14 11l0 6" /><path d="M5 7l1 12a2 2 0 0 0 2 2h8a2 2 0 0 0 2 -2l1 -12" /><path d="M9 7v-3a1 1 0 0 1 1 -1h4a1 1 0 0 1 1 1v3" /></svg>
                        </button>
                    </div>
                    <div class="p-4">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                            <div>
                                <label class="op-label">Form Type <span class="text-red-500">*</span></label>
                                <select class="js-select op-select" name="items[item-card-<?php echo $uniqueID?>][formType]" id="formType-<?php echo $uniqueID?>" onchange="formTypeF('item-card-<?php echo $uniqueID?>')" required>
                                    <option value="text" <?= ($row['formType'] === 'text') ? 'selected' : '' ?>>Text</option>
                                    <option value="textarea" <?= ($row['formType'] === 'textarea') ? 'selected' : '' ?>>Textarea</option>
                                    <option value="select" <?= ($row['formType'] === 'select') ? 'selected' : '' ?>>Select</option>
                                    <option value="file" <?= ($row['formType'] === 'file') ? 'selected' : '' ?>>File</option>
                                    <option value="checkbox" <?= ($row['formType'] === 'checkbox') ? 'selected' : '' ?>>Checkbox</option>
                                    <option value="radio" <?= ($row['formType'] === 'radio') ? 'selected' : '' ?>>Radio</option>
                                </select>
                            </div>
                            <div>
                                <label class="op-label">Field Name <span class="text-red-500">*</span></label>
                                <input type="text" class="op-input" name="items[item-card-<?php echo $uniqueID?>][fieldName]" placeholder="Enter field name" value="<?php echo htmlspecialchars($row['fieldName'], ENT_QUOTES, 'UTF-8')?>" required>
                            </div>
                            <div>
                                <label class="op-label">Required <span class="text-red-500">*</span></label>
                                <select class="js-select op-select" name="items[item-card-<?php echo $uniqueID?>][required]" required>
                                    <option value="true" <?= ($row['required'] === 'true') ? 'selected' : '' ?>>Yes</option>
                                    <option value="false" <?= ($row['required'] === 'false') ? 'selected' : '' ?>>No</option>
                                </select>
                            </div>
                            <div class="formType-File <?= ($row['formType'] === 'file') ? '' : 'hidden' ?>">
                                <label class="op-label">File Extensions <span class="text-red-500">*</span></label>
                                <?php $selectedValues = array_map('trim', explode(',', $row['value'])); ?>
                                <select class="js-select op-select" name="items[item-card-<?php echo $uniqueID?>][fileExtensions][]" multiple>
                                    <option value="jpg" <?= in_array('jpg', $selectedValues) ? 'selected' : '' ?>>JPG</option>
                                    <option value="jpeg" <?= in_array('jpeg', $selectedValues) ? 'selected' : '' ?>>JPEG</option>
                                    <option value="png" <?= in_array('png', $selectedValues) ? 'selected' : '' ?>>PNG</option>
                                    <option value="gif" <?= in_array('gif', $selectedValues) ? 'selected' : '' ?>>GIF</option>
                                    <option value="webp" <?= in_array('webp', $selectedValues) ? 'selected' : '' ?>>WEBP</option>
                                    <option value="bmp" <?= in_array('bmp', $selectedValues) ? 'selected' : '' ?>>BMP</option>
                                    <option value="svg" <?= in_array('svg', $selectedValues) ? 'selected' : '' ?>>SVG</option>
                                    <option value="ico" <?= in_array('ico', $selectedValues) ? 'selected' : '' ?>>ICO</option>
                                    <option value="tiff" <?= in_array('tiff', $selectedValues) ? 'selected' : '' ?>>TIFF</option>
                                </select>
                            </div>
                            <div class="formType-Select <?= (in_array($row['formType'], ['select','checkbox','radio'])) ? '' : 'hidden' ?>">
                                <label class="op-label">Add Options <span class="text-red-500">*</span></label>
                                <input type="text" class="js-tags op-input" id="items[item-card-<?php echo $uniqueID?>][addOptions][]" value="<?php echo htmlspecialchars($row['value'], ENT_QUOTES, 'UTF-8')?>" placeholder="Type and press Enter">
                            </div>
                        </div>
                    </div>
                </div>
        <?php } ?>
    </div>

    <div class="text-center mt-4">
        <button type="button" class="op-btn-secondary" onclick="add_new_item()">
            <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 me-1" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 5l0 14" /><path d="M5 12l14 0" /></svg>
            Add New Field
        </button>
    </div>

    <div class="pt-4">
        <button class="op-btn-primary btn-paymentLink-edit" type="submit">Save Changes</button>
    </div>
</form>

<script nonce="<?= $csp_nonce ?? '' ?>" data-cfasync="false">
    window.OP_DASHBOARD_URL = '<?php echo $site_url.$path_admin ?>/dashboard';

    function deleteItem(ItemID){
        var my_action_confirmation_btn = document.querySelector("#my-action-confirmation-btn")?.value || '';
        var btnClass = 'btnDeleteItem-'+ItemID;
        if(my_action_confirmation_btn !== ""){
            var btnEl = document.querySelector('#model-my-action-confirmation-btn'); var btn = btnEl.innerHTML;
            btnEl.innerHTML = '<div class="op-spinner" style="width:16px;height:16px;border-width:2px"></div>';
            opFetch('paymentLink-delete', { ItemID }).then(response => {
                closeAllModals(); document.querySelector("#my-action-confirmation-btn").value = ''; btnEl.innerHTML = btn;
                response.status === 'true' ? (apToast('success', response.title, response.message), load_content('Payment Link','<?php echo $site_url.$path_admin ?>/payment-link','nav-item-payment-link')) : apToast('error', response.title, response.message);
            }).catch(err => apToastError());
        } else { show_action_confirmation_tab(btnClass, 'Delete Payment Link', 'Delete', 'btn-danger'); }
    }

    function FNcurrency(){
        var currency_main = document.querySelector(".in-currency")?.value || '<?php echo $global_brand_currency_code?>';
        document.querySelectorAll('.currency-code').forEach(el => el.textContent = currency_main);
    }
    FNcurrency();

    function add_new_item(){
        const itemList = document.querySelector('.item-list');
        const uniqueId = 'item-card-' + Date.now() + '-' + Math.floor(Math.random() * 1000);
        const itemCard = document.createElement('div');
        itemCard.className = 'op-card';
        itemCard.id = uniqueId;
        itemCard.innerHTML = `
            <input type="hidden" name="items[${uniqueId}][fieldID]" value="">
            <div class="flex items-center justify-between p-4 border-b border-gray-200 dark:border-gray-700">
                <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Input Field</h3>
                <button type="button" class="remove-item text-red-500 hover:text-red-700"><svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 pointer-events-none" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 7l16 0" /><path d="M10 11l0 6" /><path d="M14 11l0 6" /><path d="M5 7l1 12a2 2 0 0 0 2 2h8a2 2 0 0 0 2 -2l1 -12" /><path d="M9 7v-3a1 1 0 0 1 1 -1h4a1 1 0 0 1 1 1v3" /></svg></button>
            </div>
            <div class="p-4">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                    <div>
                        <label class="op-label">Form Type <span class="text-red-500">*</span></label>
                        <select class="js-select op-select" name="items[${uniqueId}][formType]" id="formType-${uniqueId}" onchange="formTypeF('${uniqueId}')" required>
                            <option value="text" selected>Text</option><option value="textarea">Textarea</option><option value="select">Select</option><option value="file">File</option><option value="checkbox">Checkbox</option><option value="radio">Radio</option>
                        </select>
                    </div>
                    <div>
                        <label class="op-label">Field Name <span class="text-red-500">*</span></label>
                        <input type="text" class="op-input" name="items[${uniqueId}][fieldName]" placeholder="Enter field name" required>
                    </div>
                    <div>
                        <label class="op-label">Required <span class="text-red-500">*</span></label>
                        <select class="js-select op-select" name="items[${uniqueId}][required]" required>
                            <option value="true" selected>Yes</option><option value="false">No</option>
                        </select>
                    </div>
                    <div class="formType-File hidden">
                        <label class="op-label">File Extensions <span class="text-red-500">*</span></label>
                        <select class="js-select op-select" name="items[${uniqueId}][fileExtensions][]" multiple>
                            <option value="jpg">JPG</option><option value="jpeg">JPEG</option><option value="png">PNG</option><option value="gif">GIF</option><option value="webp">WEBP</option><option value="bmp">BMP</option><option value="svg">SVG</option><option value="ico">ICO</option><option value="tiff">TIFF</option>
                        </select>
                    </div>
                    <div class="formType-Select hidden">
                        <label class="op-label">Add Options <span class="text-red-500">*</span></label>
                        <input type="text" class="js-tags op-input" id="items[${uniqueId}][addOptions][]" placeholder="Type and press Enter">
                    </div>
                </div>
            </div>
        `;
        itemList.appendChild(itemCard);
        initChoices('.js-select'); initTags();
        itemCard.querySelector('.remove-item').addEventListener('click', () => itemCard.remove());
    }

    function delete_item(divID, id) {
        var item = document.getElementById(divID);
        var deletedInput = document.getElementById('deleted_items');
        if (item) item.remove();
        if (deletedInput) {
            let current = deletedInput.value ? deletedInput.value.split(',') : [];
            if (!current.includes(String(id))) current.push(id);
            deletedInput.value = current.join(',');
        }
    }

    function formTypeF(itemID) {
        var item = document.getElementById(itemID);
        if (!item) return;
        var formType = item.querySelector('[name*="formType"]')?.value || item.querySelector('#formType')?.value;
        const fileEl = item.querySelector('.formType-File');
        const selectEl = item.querySelector('.formType-Select');
        if (formType === 'file') { fileEl?.classList.remove('hidden'); selectEl?.classList.add('hidden'); }
        else if (['select','checkbox','radio'].includes(formType)) { fileEl?.classList.add('hidden'); selectEl?.classList.remove('hidden'); }
        else { fileEl?.classList.add('hidden'); selectEl?.classList.add('hidden'); }
    }

    document.querySelector('.form-paymentLink-edit').addEventListener('submit', function(e) {
        e.preventDefault();
        var btnEl = document.querySelector(".btn-paymentLink-edit"); var btn = btnEl.innerHTML;
        btnEl.innerHTML = '<div class="op-spinner" style="width:16px;height:16px;border-width:2px"></div>';
        var formData = new URLSearchParams(new FormData(this)).toString();
        fetch('<?php echo $site_url.$path_admin ?>/dashboard', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: formData })
            .then(r => r.json()).then(response => {
                apRotateCsrf(response.csrf_token); btnEl.innerHTML = btn;
                response.status === 'true' ? apToast('success', response.title, response.message) : apToast('error', response.title, response.message);
            }).catch(err => {btnEl.textContent='Save Changes';apToastError();});
    });
</script>
