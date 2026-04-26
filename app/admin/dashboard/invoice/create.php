<?php
if (!defined('OWNPAY_INIT')) {
    http_response_code(403);
    exit('Direct access not allowed');
}

    if (!canAccessPage(json_decode($global_response_permission['response'][0]['permission'], true), 'invoice', $global_user_response['response'][0]['role'])) {
        http_response_code(403);
        exit('Access denied. You need permission to perform this action. Please contact the admin.');
    }

    if (!hasPermission(json_decode($global_response_permission['response'][0]['permission'], true), 'invoice', 'create', $global_user_response['response'][0]['role'])) {
        http_response_code(403);
        exit('Access denied. You need permission to perform this action. Please contact the admin.');
    }
?>

<div class="op-page-header">
    <div>
        <nav class="flex mb-1" aria-label="Breadcrumb">
            <ol class="inline-flex items-center space-x-1 text-sm text-gray-500 dark:text-gray-400">
                <li><a href="javascript:void(0)" onclick="load_content('Invoice','<?php echo $site_url.$path_admin ?>/invoice','nav-item-invoice')" class="hover:text-primary-600">Invoice</a></li>
                <li class="flex items-center"><svg class="w-3 h-3 mx-1" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M7.293 14.707a1 1 0 010-1.414L10.586 10 7.293 6.707a1 1 0 011.414-1.414l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0z" clip-rule="evenodd"></path></svg><span class="text-gray-900 dark:text-white">Create Invoice</span></li>
            </ol>
        </nav>
        <h2 class="op-page-title">Create Invoice</h2>
    </div>
</div>

<form class="form-invoice-create">
    <input type="hidden" name="action" value="invoice-create">
    <input type="hidden" name="csrf_token" value="<?= $csrf_token; ?>">

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
        <!-- Main Content -->
        <div class="lg:col-span-2 space-y-4">
            <div class="op-card p-5">
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div class="md:col-span-3">
                        <label class="op-label">Customers <span class="text-red-500">*</span></label>
                        <div class="flex">
                            <select class="js-select customersList op-select flex-1" name="customers[]" multiple data-search="true" data-remove="true" data-placeholder="Select customers" required>
                                <?php
                                    $response_brand = json_decode(getData($db_prefix . 'customer', 'WHERE status = "active" AND brand_id ="'.$global_response_brand['response'][0]['brand_id'].'" ORDER BY 1 DESC'), true);
                                    if ($response_brand['status'] == true) {
                                        foreach ($response_brand['response'] as $row) {
                                ?>
                                            <option value="<?php echo $row['ref'] ?>"><?php echo $row['name'] ?> - <?php echo !empty($row['email']) ? $row['email'] : $row['mobile']; ?></option>
                                <?php
                                        }
                                    }
                                ?>
                            </select>
                            <button type="button" class="op-btn-secondary rounded-s-none <?= hasPermission(json_decode($global_response_permission['response'][0]['permission'], true), 'customers', 'create', $global_user_response['response'][0]['role']) ? '' : 'hidden' ?>" data-modal-target="modal-createItem" data-modal-toggle="modal-createItem">
                                <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 5l0 14" /><path d="M5 12l14 0" /></svg>
                            </button>
                        </div>
                    </div>

                    <div>
                        <label class="op-label">Currency <span class="text-red-500">*</span></label>
                        <select class="js-select in-currency op-select" name="currency" data-search="true" data-remove="true" required onchange="FNcurrency()">
                            <?php
                                $response_brand = json_decode(getData($db_prefix . 'currency', 'WHERE brand_id ="'.$global_response_brand['response'][0]['brand_id'].'" ORDER BY 1 DESC'), true);
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
                        <label class="op-label">Due Date</label>
                        <input type="date" class="op-input" name="due_date">
                    </div>

                    <div>
                        <label class="op-label">Status <span class="text-red-500">*</span></label>
                        <select class="js-select op-select" name="status" required>
                            <option value="paid">Paid</option>
                            <option value="unpaid" selected>Unpaid</option>
                            <option value="refunded">Refunded</option>
                            <option value="canceled">Canceled</option>
                        </select>
                    </div>
                </div>
            </div>

            <!-- Dynamic Item List -->
            <div class="item-list space-y-4"></div>

            <div class="text-center">
                <button type="button" class="op-btn-secondary" onclick="add_new_item()">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 me-1" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 5l0 14" /><path d="M5 12l14 0" /></svg>
                    Add New Item
                </button>
            </div>

            <!-- Email Note -->
            <div class="op-card">
                <div class="op-card-header"><h3 class="text-sm font-semibold text-gray-900 dark:text-white">Email Note</h3></div>
                <div class="p-4"><textarea class="hugerte-textArea" name="private-note-content"></textarea></div>
            </div>
        </div>

        <!-- Sidebar -->
        <div class="space-y-4 lg:relative">
            <div class="lg:sticky lg:top-20 space-y-4">
            <div class="op-card">
                <div class="op-card-header"><h3 class="text-sm font-semibold text-gray-900 dark:text-white">Total</h3></div>
                <div class="p-4 space-y-3">
                    <div>
                        <label class="op-label">Shipping</label>
                        <div class="flex">
                            <span class="inline-flex items-center px-3 text-sm text-gray-500 bg-gray-100 border border-e-0 border-gray-300 rounded-s-lg dark:bg-gray-600 dark:text-gray-400 dark:border-gray-600 currency-code"><?php echo $global_brand_currency_code?></span>
                            <input type="text" class="op-input rounded-s-none invoice-shipping" name="shipping" value="0" required>
                        </div>
                    </div>
                    <div>
                        <label class="op-label">Discount</label>
                        <div class="flex">
                            <span class="inline-flex items-center px-3 text-sm text-gray-500 bg-gray-100 border border-e-0 border-gray-300 rounded-s-lg dark:bg-gray-600 dark:text-gray-400 dark:border-gray-600 currency-code"><?php echo $global_brand_currency_code?></span>
                            <input type="text" class="op-input rounded-s-none invoice-discount" name="discount" readonly value="0" required>
                        </div>
                    </div>
                    <div>
                        <label class="op-label">Vat</label>
                        <div class="flex">
                            <span class="inline-flex items-center px-3 text-sm text-gray-500 bg-gray-100 border border-e-0 border-gray-300 rounded-s-lg dark:bg-gray-600 dark:text-gray-400 dark:border-gray-600 currency-code"><?php echo $global_brand_currency_code?></span>
                            <input type="text" class="op-input rounded-s-none invoice-vat" name="vat" readonly value="0" required>
                        </div>
                    </div>
                    <div>
                        <label class="op-label">Total</label>
                        <div class="flex">
                            <span class="inline-flex items-center px-3 text-sm text-gray-500 bg-gray-100 border border-e-0 border-gray-300 rounded-s-lg dark:bg-gray-600 dark:text-gray-400 dark:border-gray-600 currency-code"><?php echo $global_brand_currency_code?></span>
                            <input type="text" class="op-input rounded-s-none invoice-total" name="total" readonly value="0" required>
                        </div>
                    </div>
                </div>
            </div>

            <div class="op-card">
                <div class="op-card-header"><h3 class="text-sm font-semibold text-gray-900 dark:text-white">Note</h3></div>
                <div class="p-4"><textarea name="note" class="op-input" rows="3"></textarea></div>
            </div>
            </div>
        </div>
    </div>

    <div class="pt-4">
        <button class="op-btn-primary btn-invoicet-create" type="submit">Create</button>
    </div>
</form>

<!-- New Customer Modal -->
<div id="modal-createItem" tabindex="-1" class="fixed top-0 left-0 right-0 z-50 hidden w-full p-4 overflow-x-hidden overflow-y-auto md:inset-0 h-[calc(100%-1rem)] max-h-full flex items-center justify-center">
    <div class="relative w-full max-w-lg">
        <div class="relative bg-white rounded-lg shadow-sm dark:bg-gray-800">
            <div class="flex items-center justify-between p-4 border-b border-gray-200 dark:border-gray-700">
                <h3 class="text-lg font-semibold text-gray-900 dark:text-white">New Customer</h3>
                <button type="button" class="text-gray-400 hover:text-gray-600" data-modal-hide="modal-createItem">
                    <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd"></path></svg>
                </button>
            </div>
            <div class="p-4">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                    <div>
                        <label class="op-label">Name <span class="text-red-500">*</span></label>
                        <input type="text" class="op-input" name="customer-name" placeholder="Customer name">
                    </div>
                    <div>
                        <label class="op-label">Email Address <span class="text-red-500">*</span></label>
                        <input type="email" class="op-input" name="customer-email" placeholder="Customer email">
                    </div>
                    <div>
                        <label class="op-label">Mobile Number <span class="text-red-500">*</span></label>
                        <input type="text" class="op-input" name="customer-mobile" placeholder="Customer mobile" pattern="[0-9+\-\s()]{7,20}">
                    </div>
                </div>
                <input type="radio" name="customer-status" value="active" class="hidden" checked>
            </div>
            <div class="flex items-center justify-between p-4 border-t border-gray-200 dark:border-gray-700">
                <button type="button" class="op-btn-secondary" data-modal-hide="modal-createItem">Cancel</button>
                <button type="button" class="op-btn-primary modal-createItem-btn">Create</button>
            </div>
        </div>
    </div>
</div>

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
        itemList.addEventListener('input', e => { if (['item-quantity','item-amount','item-discount','item-vat'].includes(e.target.name)) calculateTotals(); });
        shippingInput.addEventListener('input', calculateTotals);
        itemList.addEventListener('click', e => { if (e.target.closest('.remove-item')) calculateTotals(); });
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
        itemList.appendChild(itemCard);
        FNcurrency(); FNcalculate();
        itemCard.querySelector('.remove-item').addEventListener('click', () => { itemCard.remove(); FNcalculate(); });
    }
    FNcalculate();

    document.querySelector('.form-invoice-create').addEventListener('submit', function(e) {
        e.preventDefault();
        var btnEl = document.querySelector(".btn-invoicet-create"); var btn = btnEl.innerHTML;
        btnEl.innerHTML = '<div class="op-spinner" style="width:16px;height:16px;border-width:2px"></div>';

        document.querySelectorAll('.hugerte-textArea').forEach(el => { const editor = hugeRTE?.get(el); if (editor) el.value = editor.getContent({format:'html'}); });

        setTimeout(() => {
            var formData = new URLSearchParams(new FormData(this)).toString();
            fetch('<?php echo $site_url.$path_admin ?>/dashboard', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: formData })
                .then(r => r.json()).then(response => {
                    apRotateCsrf(response.csrf_token); closeAllModals(); btnEl.innerHTML = btn;
                    response.status === 'true' ? (apToast('success', response.title, response.message), load_content('Invoice','<?php echo $site_url.$path_admin ?>/invoice','nav-item-invoice')) : apToast('error', response.title, response.message);
                }).catch(err => { btnEl.textContent = 'Create'; apToastError(); });
        }, 10);
    });

    document.querySelector('.modal-createItem-btn').addEventListener('click', function() {
        const modal = document.getElementById("modal-createItem");
        var customer_name = modal.querySelector('input[name="customer-name"]').value;
        var customer_email = modal.querySelector('input[name="customer-email"]').value;
        var customer_mobile = modal.querySelector('input[name="customer-mobile"]').value;
        var customer_status = modal.querySelector('input[name="customer-status"]:checked')?.value || "";

        if(!customer_name || !customer_email || !customer_mobile){ apToast('error', 'Incomplete', 'Please fill in all required fields.'); return; }

        var btnEl = this; var btn = btnEl.innerHTML;
        btnEl.innerHTML = '<div class="op-spinner" style="width:16px;height:16px;border-width:2px"></div>';

        opFetch('customers-create', { name: customer_name, email: customer_email, mobile: customer_mobile, status: customer_status, suspend_reason: '' })
            .then(response => {
                closeAllModals();
                modal.querySelectorAll('input[type="text"], input[type="email"]').forEach(i => i.value = '');
                btnEl.innerHTML = btn;
                if (response.status === 'true') {
                    apToast('success', response.title, response.message);
                    window.InvoiceCustomerChoices?.setChoices([{ value: customer_email, label: customer_name+' - '+customer_email, selected: true }], 'value', 'label', false);
                } else { apToast('error', response.title, response.message); }
            }).catch(err => apToastError());
    });
</script>
