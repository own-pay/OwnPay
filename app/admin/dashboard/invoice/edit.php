<?php
if (!defined('OWNPAY_INIT')) {
    http_response_code(403);
    exit('Direct access not allowed');
}

    if (!canAccessPage(json_decode($global_response_permission['response'][0]['permission'], true), 'invoice', $global_user_response['response'][0]['role'])) {
        http_response_code(403);
        exit('Access denied. You need permission to perform this action. Please contact the admin.');
    }

    if (!hasPermission(json_decode($global_response_permission['response'][0]['permission'], true), 'invoice', 'edit', $global_user_response['response'][0]['role'])) {
        http_response_code(403);
        exit('Access denied. You need permission to perform this action. Please contact the admin.');
    }

    $params = json_decode($_POST['params'] ?? '{}', true);
    $i_id = getParam($params, 'i_id');

    if ($i_id === null) {
        http_response_code(403);
        exit('Invalid invoice id');
    } else {
        $i_id = clean_input($i_id);
        $response_invoice = json_decode(getData($db_prefix.'invoice','WHERE ref = "'.$i_id.'" AND brand_id = "'.$global_response_brand['response'][0]['brand_id'].'"'),true);
        if($response_invoice['status'] != true){
            http_response_code(403);
            exit('Direct access not allowed');
        }
    }
?>

<div class="op-page-header">
    <div>
        <nav class="flex mb-1" aria-label="Breadcrumb">
            <ol class="inline-flex items-center space-x-1 text-sm text-gray-500 dark:text-gray-400">
                <li><a href="javascript:void(0)" onclick="load_content('Invoice','<?php echo $site_url.$path_admin ?>/invoice','nav-item-invoice')" class="hover:text-primary-600">Invoice</a></li>
                <li class="flex items-center"><svg class="w-3 h-3 mx-1" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M7.293 14.707a1 1 0 010-1.414L10.586 10 7.293 6.707a1 1 0 011.414-1.414l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0z" clip-rule="evenodd"></path></svg><span class="text-gray-900 dark:text-white">Edit Invoice</span></li>
            </ol>
        </nav>
        <h2 class="op-page-title">Edit Invoice</h2>
    </div>
    <div class="flex items-center gap-2">
        <button class="op-btn-primary" onclick="copyContent('<?php echo $site_url.$path_invoice ?>/<?php echo $i_id;?>', 'Copied!', 'Invoice URL copied successfully.')">Copy Link</button>
        <button class="op-btn-danger btnDeleteItem-<?php echo $i_id;?> <?= hasPermission(json_decode($global_response_permission['response'][0]['permission'], true), 'invoice', 'delete', $global_user_response['response'][0]['role']) ? '' : 'hidden' ?>" onclick="deleteItem('<?php echo $i_id;?>')">Delete</button>
    </div>
</div>

<form class="form-invoice-edit">
    <input type="hidden" name="action" value="invoice-edit">
    <input type="hidden" name="csrf_token" value="<?= $csrf_token; ?>">
    <input type="hidden" name="invoiceID" value="<?php echo $response_invoice['response'][0]['ref']?>">
    <input type="hidden" name="deleted_items" id="deleted_items" value="">

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
        <!-- Main Content -->
        <div class="lg:col-span-2 space-y-4">
            <div class="op-card p-5">
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div class="md:col-span-3">
                        <label class="op-label">Customer <span class="text-red-500">*</span></label>
                        <?php $customer_info = json_decode($response_invoice['response'][0]['customer_info'], true); ?>
                        <select class="js-select op-select" name="customer" data-search="false" data-remove="false" required>
                            <option value="<?php echo $customer_info['id']?>" selected><?php echo htmlspecialchars($customer_info['name'])?> - <?php echo htmlspecialchars($customer_info['email'])?></option>
                        </select>
                    </div>

                    <div>
                        <label class="op-label">Currency <span class="text-red-500">*</span></label>
                        <select class="js-select in-currency op-select" name="currency" data-search="true" data-remove="true" required onchange="FNcurrency()">
                            <?php
                                $response_brand = json_decode(getData($db_prefix . 'currency', 'WHERE brand_id ="'.$global_response_brand['response'][0]['brand_id'].'" ORDER BY 1 DESC'), true);
                                if ($response_brand['status'] == true) {
                                    foreach ($response_brand['response'] as $row) {
                            ?>
                                        <option value="<?php echo $row['code'] ?>" <?php echo ($response_invoice['response'][0]['currency'] == $row['code']) ? 'selected' : '';?>><?php echo $row['code'] ?></option>
                            <?php
                                    }
                                }
                            ?>
                        </select>
                    </div>

                    <div>
                        <label class="op-label">Due Date</label>
                        <input type="date" class="op-input" name="due_date" value="<?php echo $response_invoice['response'][0]['due_date'] ?? '';?>">
                    </div>

                    <div>
                        <label class="op-label">Status <span class="text-red-500">*</span></label>
                        <select class="js-select op-select" name="status" required>
                            <option value="paid" <?= ($response_invoice['response'][0]['status'] === 'paid') ? 'selected' : '' ?>>Paid</option>
                            <option value="unpaid" <?= ($response_invoice['response'][0]['status'] === 'unpaid') ? 'selected' : '' ?>>Unpaid</option>
                            <option value="refunded" <?= ($response_invoice['response'][0]['status'] === 'refunded') ? 'selected' : '' ?>>Refunded</option>
                            <option value="canceled" <?= ($response_invoice['response'][0]['status'] === 'canceled') ? 'selected' : '' ?>>Canceled</option>
                        </select>
                    </div>
                </div>
            </div>

            <!-- Existing Items -->
            <div class="item-list space-y-4">
                <?php
                    $response = json_decode(getData($db_prefix.'invoice_items','WHERE brand_id ="'.$global_response_brand['response'][0]['brand_id'].'" AND invoice_id ="'.$i_id.'"'),true);
                    if ($response['status'] == true) { foreach($response['response'] as $row){
                ?>
                        <div class="op-card item-<?php echo $row['id']?>">
                            <div class="flex items-center justify-between p-4 border-b border-gray-200 dark:border-gray-700">
                                <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Item</h3>
                                <button type="button" class="remove-item text-red-500 hover:text-red-700" onclick="delete_item('<?php echo $row['id']?>')">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 pointer-events-none" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 7l16 0" /><path d="M10 11l0 6" /><path d="M14 11l0 6" /><path d="M5 7l1 12a2 2 0 0 0 2 2h8a2 2 0 0 0 2 -2l1 -12" /><path d="M9 7v-3a1 1 0 0 1 1 -1h4a1 1 0 0 1 1 1v3" /></svg>
                                </button>
                            </div>
                            <div class="p-4">
                                <div class="grid grid-cols-1 md:grid-cols-4 gap-3">
                                    <div class="md:col-span-4">
                                        <label class="op-label">Description <span class="text-red-500">*</span></label>
                                        <input type="hidden" name="item-id" value="<?php echo $row['id']?>">
                                        <input type="text" class="op-input" name="item-description" value="<?php echo htmlspecialchars($row['description'])?>" required>
                                    </div>
                                    <div>
                                        <label class="op-label">Quantity <span class="text-red-500">*</span></label>
                                        <input type="text" class="op-input" name="item-quantity" value="<?php echo money_round($row['quantity'])?>" required>
                                    </div>
                                    <div>
                                        <label class="op-label">Amount <span class="text-red-500">*</span></label>
                                        <div class="flex"><span class="inline-flex items-center px-3 text-sm text-gray-500 bg-gray-100 border border-e-0 border-gray-300 rounded-s-lg dark:bg-gray-600 dark:text-gray-400 dark:border-gray-600 currency-code"><?php echo $global_brand_currency_code?></span><input type="text" class="op-input rounded-s-none" name="item-amount" value="<?php echo money_round($row['amount'])?>" required></div>
                                    </div>
                                    <div>
                                        <label class="op-label">Discount <span class="text-red-500">*</span></label>
                                        <div class="flex"><span class="inline-flex items-center px-3 text-sm text-gray-500 bg-gray-100 border border-e-0 border-gray-300 rounded-s-lg dark:bg-gray-600 dark:text-gray-400 dark:border-gray-600 currency-code"><?php echo $global_brand_currency_code?></span><input type="text" class="op-input rounded-s-none" name="item-discount" value="<?php echo money_round($row['discount'])?>" required></div>
                                    </div>
                                    <div>
                                        <label class="op-label">Vat <span class="text-red-500">*</span></label>
                                        <div class="flex"><span class="inline-flex items-center px-3 text-sm text-gray-500 bg-gray-100 border border-e-0 border-gray-300 rounded-s-lg dark:bg-gray-600 dark:text-gray-400 dark:border-gray-600">%</span><input type="text" class="op-input rounded-s-none" name="item-vat" value="<?php echo money_round($row['vat'])?>" required></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                <?php } } ?>
            </div>

            <div class="text-center">
                <button type="button" class="op-btn-secondary" onclick="add_new_item()">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 me-1" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 5l0 14" /><path d="M5 12l14 0" /></svg>
                    Add New Item
                </button>
            </div>

            <!-- Email Note -->
            <div class="op-card">
                <div class="op-card-header"><h3 class="text-sm font-semibold text-gray-900 dark:text-white">Email Note</h3></div>
                <div class="p-4"><textarea class="hugerte-textArea" name="private-note-content"><?php echo $response_invoice['response'][0]['private_note'] ?? '';?></textarea></div>
            </div>
        </div>

        <!-- Sidebar -->
        <div class="space-y-4">
            <div class="op-card">
                <div class="op-card-header"><h3 class="text-sm font-semibold text-gray-900 dark:text-white">Total</h3></div>
                <div class="p-4 space-y-3">
                    <div>
                        <label class="op-label">Shipping</label>
                        <div class="flex"><span class="inline-flex items-center px-3 text-sm text-gray-500 bg-gray-100 border border-e-0 border-gray-300 rounded-s-lg dark:bg-gray-600 dark:text-gray-400 dark:border-gray-600 currency-code"><?php echo $global_brand_currency_code?></span><input type="text" class="op-input rounded-s-none invoice-shipping" name="shipping" value="<?php echo money_round($response_invoice['response'][0]['shipping'])?>" required></div>
                    </div>
                    <div>
                        <label class="op-label">Discount</label>
                        <div class="flex"><span class="inline-flex items-center px-3 text-sm text-gray-500 bg-gray-100 border border-e-0 border-gray-300 rounded-s-lg dark:bg-gray-600 dark:text-gray-400 dark:border-gray-600 currency-code"><?php echo $global_brand_currency_code?></span><input type="text" class="op-input rounded-s-none invoice-discount" name="discount" readonly value="0" required></div>
                    </div>
                    <div>
                        <label class="op-label">Vat</label>
                        <div class="flex"><span class="inline-flex items-center px-3 text-sm text-gray-500 bg-gray-100 border border-e-0 border-gray-300 rounded-s-lg dark:bg-gray-600 dark:text-gray-400 dark:border-gray-600 currency-code"><?php echo $global_brand_currency_code?></span><input type="text" class="op-input rounded-s-none invoice-vat" name="vat" readonly value="0" required></div>
                    </div>
                    <div>
                        <label class="op-label">Total</label>
                        <div class="flex"><span class="inline-flex items-center px-3 text-sm text-gray-500 bg-gray-100 border border-e-0 border-gray-300 rounded-s-lg dark:bg-gray-600 dark:text-gray-400 dark:border-gray-600 currency-code"><?php echo $global_brand_currency_code?></span><input type="text" class="op-input rounded-s-none invoice-total" name="total" readonly value="0" required></div>
                    </div>
                </div>
            </div>

            <div class="op-card">
                <div class="op-card-header"><h3 class="text-sm font-semibold text-gray-900 dark:text-white">Note</h3></div>
                <div class="p-4"><textarea name="note" class="op-input" rows="3"><?php if(!empty($response_invoice['response'][0]['note'])){ echo $response_invoice['response'][0]['note']; } ?></textarea></div>
            </div>
        </div>
    </div>

    <div class="pt-4">
        <button class="op-btn-primary btn-invoicet-edit" type="submit">Save Changes</button>
    </div>
</form>

<script nonce="<?= $csp_nonce ?? '' ?>" data-cfasync="false">
    window.OP_DASHBOARD_URL = '<?php echo $site_url.$path_admin ?>/dashboard';

    function FNcurrency(){
        var currency_main = document.querySelector(".in-currency")?.value || '<?php echo $global_brand_currency_code?>';
        document.querySelectorAll('.currency-code').forEach(el => el.textContent = currency_main);
    }
    FNcurrency();

    function FNcalculate(){
        const itemList = document.querySelector('.item-list');
        const shippingInput = document.querySelector('.invoice-shipping');
        const discountInput = document.querySelector('.invoice-discount');
        const vatInput = document.querySelector('.invoice-vat');
        const totalInput = document.querySelector('.invoice-total');

        function calculateTotals() {
            let subtotal = 0, totalDiscount = 0, totalVat = 0;
            itemList.querySelectorAll('.op-card').forEach(card => {
                const qty = parseFloat(card.querySelector('input[name="item-quantity"]')?.value) || 0;
                const amount = parseFloat(card.querySelector('input[name="item-amount"]')?.value) || 0;
                const discount = parseFloat(card.querySelector('input[name="item-discount"]')?.value) || 0;
                const vat = parseFloat(card.querySelector('input[name="item-vat"]')?.value) || 0;
                const itemTotal = qty * amount;
                subtotal += itemTotal; totalDiscount += discount; totalVat += (itemTotal - discount) * (vat / 100);
            });
            const shipping = parseFloat(shippingInput.value) || 0;
            discountInput.value = totalDiscount.toFixed(2); vatInput.value = totalVat.toFixed(2); totalInput.value = (subtotal - totalDiscount + totalVat + shipping).toFixed(2);
        }
        window.calculateTotals = calculateTotals;
        itemList.addEventListener('input', e => { if (['item-quantity','item-amount','item-discount','item-vat'].includes(e.target.name)) calculateTotals(); });
        shippingInput.addEventListener('input', calculateTotals);
        itemList.addEventListener('click', e => { if (e.target.closest('.remove-item')) calculateTotals(); });
        calculateTotals();
    }

    function delete_item(divID) {
        var item = document.querySelector(".item-" + divID);
        var deletedInput = document.getElementById('deleted_items');
        if (item) item.remove();
        if (deletedInput) {
            let current = deletedInput.value ? deletedInput.value.split(',') : [];
            if (!current.includes(String(divID))) current.push(divID);
            deletedInput.value = current.join(',');
        }
        calculateTotals();
    }

    function add_new_item(){
        const itemList = document.querySelector('.item-list');
        const itemCard = document.createElement('div');
        itemCard.className = 'op-card';
        itemCard.innerHTML = `
            <div class="flex items-center justify-between p-4 border-b border-gray-200 dark:border-gray-700">
                <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Item</h3>
                <button type="button" class="remove-item text-red-500 hover:text-red-700">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 pointer-events-none" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 7l16 0" /><path d="M10 11l0 6" /><path d="M14 11l0 6" /><path d="M5 7l1 12a2 2 0 0 0 2 2h8a2 2 0 0 0 2 -2l1 -12" /><path d="M9 7v-3a1 1 0 0 1 1 -1h4a1 1 0 0 1 1 1v3" /></svg>
                </button>
            </div>
            <div class="p-4">
                <div class="grid grid-cols-1 md:grid-cols-4 gap-3">
                    <div class="md:col-span-4">
                        <label class="op-label">Description <span class="text-red-500">*</span></label>
                        <input type="text" class="op-input" name="item-description" required>
                    </div>
                    <div>
                        <label class="op-label">Quantity <span class="text-red-500">*</span></label>
                        <input type="text" class="op-input" name="item-quantity" value="1" required>
                    </div>
                    <div>
                        <label class="op-label">Amount <span class="text-red-500">*</span></label>
                        <div class="flex"><span class="inline-flex items-center px-3 text-sm text-gray-500 bg-gray-100 border border-e-0 border-gray-300 rounded-s-lg dark:bg-gray-600 dark:text-gray-400 dark:border-gray-600 currency-code"></span><input type="text" class="op-input rounded-s-none" name="item-amount" value="0" required></div>
                    </div>
                    <div>
                        <label class="op-label">Discount <span class="text-red-500">*</span></label>
                        <div class="flex"><span class="inline-flex items-center px-3 text-sm text-gray-500 bg-gray-100 border border-e-0 border-gray-300 rounded-s-lg dark:bg-gray-600 dark:text-gray-400 dark:border-gray-600 currency-code"></span><input type="text" class="op-input rounded-s-none" name="item-discount" value="0" required></div>
                    </div>
                    <div>
                        <label class="op-label">Vat <span class="text-red-500">*</span></label>
                        <div class="flex"><span class="inline-flex items-center px-3 text-sm text-gray-500 bg-gray-100 border border-e-0 border-gray-300 rounded-s-lg dark:bg-gray-600 dark:text-gray-400 dark:border-gray-600">%</span><input type="text" class="op-input rounded-s-none" name="item-vat" value="0" required></div>
                    </div>
                </div>
            </div>
        `;
        itemList.appendChild(itemCard); FNcurrency(); FNcalculate();
        itemCard.querySelector('.remove-item').addEventListener('click', () => { itemCard.remove(); FNcalculate(); });
    }
    FNcalculate();

    document.querySelector('.form-invoice-edit').addEventListener('submit', function(e) {
        e.preventDefault();
        var btnEl = document.querySelector(".btn-invoicet-edit"); var btn = btnEl.innerHTML;
        btnEl.innerHTML = '<div class="op-spinner" style="width:16px;height:16px;border-width:2px"></div>';

        document.querySelectorAll('.hugerte-textArea').forEach(el => { const editor = hugeRTE?.get(el); if (editor) el.value = editor.getContent({format:'html'}); });

        setTimeout(() => {
            var formData = new URLSearchParams(new FormData(this)).toString();
            fetch('<?php echo $site_url.$path_admin ?>/dashboard', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: formData })
                .then(r => r.json()).then(response => {
                    apRotateCsrf(response.csrf_token); btnEl.innerHTML = btn;
                    response.status === 'true' ? apToast('success', response.title, response.message) : apToast('error', response.title, response.message);
                }).catch(err => { btnEl.textContent = 'Save Changes'; apToastError(); });
        }, 10);
    });

    function deleteItem(ItemID){
        var my_action_confirmation_btn = document.querySelector("#my-action-confirmation-btn")?.value || '';
        var btnClass = 'btnDeleteItem-'+ItemID;
        if(my_action_confirmation_btn !== ""){
            var btnEl = document.querySelector('#model-my-action-confirmation-btn'); var btn = btnEl.innerHTML;
            btnEl.innerHTML = '<div class="op-spinner" style="width:16px;height:16px;border-width:2px"></div>';
            opFetch('invoice-delete', { ItemID }).then(response => {
                closeAllModals(); document.querySelector("#my-action-confirmation-btn").value = ''; btnEl.innerHTML = btn;
                response.status === 'true' ? (apToast('success', response.title, response.message), load_content('Invoice','<?php echo $site_url.$path_admin ?>/invoice','nav-item-invoice')) : apToast('error', response.title, response.message);
            }).catch(err => apToastError());
        } else { show_action_confirmation_tab(btnClass, 'Delete Invoice', 'Delete', 'btn-danger'); }
    }
</script>
