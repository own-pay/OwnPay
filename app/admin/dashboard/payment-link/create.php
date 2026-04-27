<?php
if (!defined('OWNPAY_INIT')) {
    http_response_code(403);
    exit('Direct access not allowed');
}

    if (!canAccessPage(json_decode($global_response_permission['response'][0]['permission'], true), 'payment_link', $global_user_response['response'][0]['role'])) {
        http_response_code(403);
        exit('Access denied. You need permission to perform this action. Please contact the admin.');
    }

    if (!hasPermission(json_decode($global_response_permission['response'][0]['permission'], true), 'payment_link', 'create', $global_user_response['response'][0]['role'])) {
        http_response_code(403);
        exit('Access denied. You need permission to perform this action. Please contact the admin.');
    }
?>

<div class="op-page-header">
    <div>
        <nav class="flex mb-1" aria-label="Breadcrumb">
            <ol class="inline-flex items-center space-x-1 text-sm text-gray-500 dark:text-gray-400">
                <li><a href="javascript:void(0)" onclick="load_content('Payment Link','<?php echo $site_url.$path_admin ?>/payment-link','nav-item-payment-link')" class="hover:text-primary-600">Payment Link</a></li>
                <li class="flex items-center"><svg class="w-3 h-3 mx-1" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M7.293 14.707a1 1 0 010-1.414L10.586 10 7.293 6.707a1 1 0 011.414-1.414l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0z" clip-rule="evenodd"></path></svg><span class="text-gray-900 dark:text-white">Create Payment Link</span></li>
            </ol>
        </nav>
        <h2 class="op-page-title">Create Payment Link</h2>
    </div>
</div>

<form class="form-paymentLink-create">
    <input type="hidden" name="action" value="paymentLink-create">
    <input type="hidden" name="csrf_token" value="<?= $csrf_token; ?>">

    <div class="op-card p-5">
        <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
            <div class="md:col-span-3">
                <label class="op-label">Product Title <span class="text-red-500">*</span></label>
                <input type="text" class="op-input" name="title" placeholder="Product Title" required>
            </div>
            <div>
                <label class="op-label">Quantity <span class="text-red-500">*</span></label>
                <input type="text" class="op-input" name="quantity" placeholder="0" required>
            </div>
            <div class="md:col-span-4">
                <label class="op-label">Product Description <span class="text-red-500">*</span></label>
                <textarea name="description" class="op-input" rows="3"></textarea>
            </div>
            <div>
                <label class="op-label">Currency <span class="text-red-500">*</span></label>
                <select class="js-select in-currency op-select" name="currency" data-search="true" data-remove="true" required onchange="FNcurrency()">
                    <?php
                        $response_brand = json_decode(getData($db_prefix . 'currency', 'WHERE brand_id = :brand_id ORDER BY 1 DESC', '* FROM', [':brand_id' => $global_response_brand['response'][0]['brand_id']]), true);
                        if ($response_brand['status'] == true) {
                            foreach ($response_brand['response'] as $row) {
                    ?>
                                <option value="<?php echo $row['code'] ?>" <?php echo ($global_brand_currency_code == $row['code']) ? 'selected' : '';?>><?php echo $row['code'] ?></option>
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
                    <input type="text" class="op-input rounded-s-none" name="amount" value="0" required>
                </div>
            </div>
            <div>
                <label class="op-label">Expiry Date</label>
                <input type="date" class="op-input" name="expiry_date">
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

    <!-- Dynamic Fields -->
    <div class="item-list space-y-4 mt-4"></div>

    <div class="text-center mt-4">
        <button type="button" class="op-btn-secondary" onclick="add_new_item()">
            <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 me-1" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 5l0 14" /><path d="M5 12l14 0" /></svg>
            Add New Field
        </button>
    </div>

    <div class="pt-4">
        <button class="op-btn-primary btn-paymentLink-create" type="submit">Create</button>
    </div>
</form>

<script nonce="<?= $csp_nonce ?? '' ?>" data-cfasync="false">
    window.OP_DASHBOARD_URL = '<?php echo $site_url.$path_admin ?>/dashboard';

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
            <div class="flex items-center justify-between p-4 border-b border-gray-200 dark:border-gray-700">
                <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Input Field</h3>
                <button type="button" class="remove-item text-red-500 hover:text-red-700"><svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 pointer-events-none" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 7l16 0" /><path d="M10 11l0 6" /><path d="M14 11l0 6" /><path d="M5 7l1 12a2 2 0 0 0 2 2h8a2 2 0 0 0 2 -2l1 -12" /><path d="M9 7v-3a1 1 0 0 1 1 -1h4a1 1 0 0 1 1 1v3" /></svg></button>
            </div>
            <div class="p-4">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                    <div>
                        <label class="op-label">Form Type <span class="text-red-500">*</span></label>
                        <select class="js-select op-select" name="items[${uniqueId}][formType]" id="formType-${uniqueId}" onchange="formTypeF('${uniqueId}')" required>
                            <option value="text" selected>Text</option>
                            <option value="textarea">Textarea</option>
                            <option value="select">Select</option>
                            <option value="file">File</option>
                            <option value="checkbox">Checkbox</option>
                            <option value="radio">Radio</option>
                        </select>
                    </div>
                    <div>
                        <label class="op-label">Field Name <span class="text-red-500">*</span></label>
                        <input type="text" class="op-input" name="items[${uniqueId}][fieldName]" placeholder="Enter field name" required>
                    </div>
                    <div>
                        <label class="op-label">Required <span class="text-red-500">*</span></label>
                        <select class="js-select op-select" name="items[${uniqueId}][required]" required>
                            <option value="true" selected>Yes</option>
                            <option value="false">No</option>
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

    document.querySelector('.form-paymentLink-create').addEventListener('submit', function(e) {
        e.preventDefault();
        var btnEl = document.querySelector(".btn-paymentLink-create"); var btn = btnEl.innerHTML;
        btnEl.innerHTML = '<div class="op-spinner" style="width:16px;height:16px;border-width:2px"></div>';
        var formData = new URLSearchParams(new FormData(this)).toString();
        fetch('<?php echo $site_url.$path_admin ?>/dashboard', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: formData })
            .then(r => r.json()).then(response => {
                apRotateCsrf(response.csrf_token); closeAllModals(); btnEl.innerHTML = btn;
                response.status === 'true' ? (apToast('success', response.title, response.message), load_content('Payment Link','<?php echo $site_url.$path_admin ?>/payment-link','nav-item-payment-link')) : apToast('error', response.title, response.message);
            }).catch(err => {btnEl.textContent='Create';apToastError();});
    });
</script>
