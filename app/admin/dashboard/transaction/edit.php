<?php
if (!defined('OWNPAY_INIT')) {
    http_response_code(403);
    exit('Direct access not allowed');
}

    if (!canAccessPage(json_decode($global_response_permission['response'][0]['permission'], true), 'transaction', $global_user_response['response'][0]['role'])) {
        http_response_code(403);
        exit('Access denied. You need permission to perform this action. Please contact the admin.');
    }

    if (!hasPermission(json_decode($global_response_permission['response'][0]['permission'], true), 'transaction', 'edit', $global_user_response['response'][0]['role'])) {
        http_response_code(403);
        exit('Access denied. You need permission to perform this action. Please contact the admin.');
    }

    $params = json_decode($_POST['params'] ?? '{}', true);

    $ref = getParam($params, 't_id');

    if ($ref === null) {
        http_response_code(403);
        exit('Invalid transaction id');
    }else{
        $ref = clean_input($ref);

        $response_transaction = json_decode(getData($db_prefix.'transaction','WHERE ref = :ref AND brand_id = :brand_id AND status != :exc_status', '* FROM', [':ref' => $ref, ':brand_id' => $global_response_brand['response'][0]['brand_id'], ':exc_status' => 'initiated']),true);
        if($response_transaction['status'] == true){
            $response_gateway = json_decode(getData($db_prefix.'gateways',' WHERE brand_id = :brand_id AND gateway_id = :gateway_id', '* FROM', [':brand_id' => $global_response_brand['response'][0]['brand_id'], ':gateway_id' => $response_transaction['response'][0]['gateway_id']]),true);

            $gateway_name = $response_gateway['response'][0]['name'] ?? 'Unknown';

            $customer_info = json_decode($response_transaction['response'][0]['customer_info'], true) ?: [];
        }else{
            http_response_code(403);
            exit('Direct access not allowed');
        }
    }

    // Determine badge
    $status = $response_transaction['response'][0]['status'];
    $badgeText = ucfirst($status);
    $badgeClass = 'op-badge-gray';
    switch ($status) {
        case 'completed': $badgeClass = 'op-badge-success'; break;
        case 'pending': $badgeClass = 'op-badge-warning'; break;
        case 'refunded': $badgeClass = 'op-badge-info'; break;
        case 'canceled': $badgeClass = 'op-badge-danger'; break;
    }

    $metadata = json_decode($response_transaction['response'][0]['metadata'], true) ?: [];
    $source_info = json_decode($response_transaction['response'][0]['source_info'], true) ?: [];
?>

<div class="op-page-header">
    <div>
        <nav class="flex mb-1" aria-label="Breadcrumb">
            <ol class="inline-flex items-center space-x-1 text-sm text-gray-500 dark:text-gray-400">
                <li><a href="javascript:void(0)" onclick="load_content('Transaction','<?php echo $site_url.$path_admin ?>/transaction','nav-item-transaction')" class="hover:text-primary-600">Transaction</a></li>
                <li class="flex items-center"><svg class="w-3 h-3 mx-1" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M7.293 14.707a1 1 0 010-1.414L10.586 10 7.293 6.707a1 1 0 011.414-1.414l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0z" clip-rule="evenodd"></path></svg><span class="text-gray-900 dark:text-white font-medium">View Transaction</span></li>
            </ol>
        </nav>
        <h2 class="op-page-title">View Transaction</h2>
    </div>
    <div class="flex items-center gap-2">
        <button data-modal-target="model-bulkAction" data-modal-toggle="model-bulkAction" class="op-btn-primary <?= hasPermission(json_decode($global_response_permission['response'][0]['permission'], true), 'transaction', 'edit', $global_user_response['response'][0]['role']) ? '' : 'hidden' ?>">
            <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 me-1" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M7 7h-1a2 2 0 0 0 -2 2v9a2 2 0 0 0 2 2h9a2 2 0 0 0 2 -2v-1" /><path d="M20.385 6.585a2.1 2.1 0 0 0 -2.97 -2.97l-8.415 8.385v3h3l8.385 -8.415" /><path d="M16 5l3 3" /></svg> Edit
        </button>
        <button class="op-btn-primary btnIpnItem-<?php echo $ref;?> <?= hasPermission(json_decode($global_response_permission['response'][0]['permission'], true), 'transaction', 'send_ipn', $global_user_response['response'][0]['role']) ? '' : 'hidden' ?>" onclick="ipnItem('<?php echo $ref;?>')" style="background-color:#059669">Send IPN</button>
        <button class="op-btn-danger btnDeleteItem-<?php echo $ref;?> <?= hasPermission(json_decode($global_response_permission['response'][0]['permission'], true), 'transaction', 'delete', $global_user_response['response'][0]['role']) ? '' : 'hidden' ?>" onclick="deleteItem('<?php echo $ref;?>')">Delete</button>
    </div>
</div>

<!-- Transaction Status Card -->
<div class="op-card mb-6">
    <div class="op-card-header"><h3 class="op-card-title">Transaction Status</h3></div>
    <div class="op-card-body">
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <div>
                <label class="op-label">Payment ID</label>
                <p class="text-sm font-medium text-gray-900 dark:text-white"><?php echo $response_transaction['response'][0]['ref']?></p>
            </div>
            <div>
                <label class="op-label">Date</label>
                <p class="text-sm font-medium text-gray-900 dark:text-white"><?php echo convertUTCtoUserTZ($response_transaction['response'][0]['created_date'], empty($global_response_brand['response'][0]['timezone']) ? 'Asia/Dhaka' : $global_response_brand['response'][0]['timezone'], "M d, Y h:i A")?></p>
            </div>
            <div>
                <label class="op-label">Status</label>
                <p class="text-sm"><span class="<?php echo $badgeClass; ?>"><?php echo $badgeText; ?></span></p>
            </div>
        </div>
    </div>
</div>

<!-- Tabbed Details Card -->
<div class="op-card">
    <div class="op-card-header border-b border-gray-200 dark:border-gray-700">
        <div class="flex flex-wrap gap-1" id="detailTabs" role="tablist" data-tabs-toggle="#detailTabContent" data-tabs-active-classes="op-tab active" data-tabs-inactive-classes="op-tab">
            <button class="op-tab active" id="tab-btn-details" data-tabs-target="#tab-transaction-details" type="button" role="tab" aria-controls="tab-transaction-details" aria-selected="true">Transaction Details</button>
            <button class="op-tab" id="tab-btn-customer" data-tabs-target="#tab-customer" type="button" role="tab" aria-controls="tab-customer" aria-selected="false">Customer</button>
            <?php if(!empty($source_info)){ ?><button class="op-tab" id="tab-btn-more" data-tabs-target="#tab-more" type="button" role="tab" aria-controls="tab-more" aria-selected="false">More Info</button><?php } ?>
            <?php if(!empty($metadata)){ ?><button class="op-tab" id="tab-btn-metadata" data-tabs-target="#tab-metadata" type="button" role="tab" aria-controls="tab-metadata" aria-selected="false">Metadata</button><?php } ?>
            <button class="op-tab" id="tab-btn-endpoint" data-tabs-target="#tab-endpoint" type="button" role="tab" aria-controls="tab-endpoint" aria-selected="false">Endpoint</button>
        </div>
    </div>
    <div class="op-card-body" id="detailTabContent">
        <!-- Transaction Details Tab -->
        <div id="tab-transaction-details" role="tabpanel" aria-labelledby="tab-btn-details">
            <div class="border border-gray-200 dark:border-gray-700 rounded-lg p-4 mb-4">
                <h4 class="text-sm font-semibold text-gray-900 dark:text-white border-b border-gray-200 dark:border-gray-700 pb-3 -mx-4 px-4 -mt-1 mb-4">Gateway Information</h4>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div>
                        <label class="op-label">Gateway</label>
                        <p class="text-sm font-medium text-gray-900 dark:text-white"><?php echo $gateway_name?></p>
                    </div>
                    <div>
                        <label class="op-label">Currency</label>
                        <p class="text-sm font-medium text-gray-900 dark:text-white"><?php echo $response_transaction['response'][0]['local_currency']?></p>
                    </div>
                    <div>
                        <label class="op-label">Sender</label>
                        <p class="text-sm font-medium text-gray-900 dark:text-white"><?php echo $response_transaction['response'][0]['sender']?></p>
                    </div>
                    <?php if(!empty($response_transaction['response'][0]['trx_slip'])){ ?>
                    <div>
                        <label class="op-label">Payment Slip</label>
                        <p class="text-sm"><a href="<?php echo $response_transaction['response'][0]['trx_slip']?>" target="_blank" class="text-primary-600 hover:underline">View</a></p>
                    </div>
                    <?php }else{ ?>
                    <div>
                        <label class="op-label">Transaction Id</label>
                        <p class="text-sm font-medium text-gray-900 dark:text-white"><?php echo $response_transaction['response'][0]['trx_id']?></p>
                    </div>
                    <?php } ?>
                </div>
            </div>

            <div class="border border-gray-200 dark:border-gray-700 rounded-lg p-4">
                <h4 class="text-sm font-semibold text-gray-900 dark:text-white border-b border-gray-200 dark:border-gray-700 pb-3 -mx-4 px-4 -mt-1 mb-4">Transaction Information</h4>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div>
                        <label class="op-label">Currency</label>
                        <p class="text-sm font-medium text-gray-900 dark:text-white"><?php echo $response_transaction['response'][0]['currency']?></p>
                    </div>
                    <div>
                        <label class="op-label">Amount</label>
                        <p class="text-sm font-medium text-gray-900 dark:text-white"><?php echo $response_transaction['response'][0]['currency'].' '.money_round($response_transaction['response'][0]['amount'], 2)?></p>
                    </div>
                    <div>
                        <label class="op-label">Processing Fee</label>
                        <p class="text-sm font-medium text-gray-900 dark:text-white"><?php echo $response_transaction['response'][0]['currency'].' '.money_round($response_transaction['response'][0]['processing_fee'], 2)?></p>
                    </div>
                    <div>
                        <label class="op-label">Discount Amount</label>
                        <p class="text-sm font-medium text-gray-900 dark:text-white"><?php echo $response_transaction['response'][0]['currency'].' '.money_round($response_transaction['response'][0]['discount_amount'], 2)?></p>
                    </div>
                    <div>
                        <label class="op-label">Net Amount</label>
                        <p class="text-sm font-medium text-gray-900 dark:text-white"><?php echo $response_transaction['response'][0]['currency'].' '.money_round($response_transaction['response'][0]['amount']+$response_transaction['response'][0]['processing_fee']-$response_transaction['response'][0]['discount_amount'], 2)?></p>
                    </div>
                    <div>
                        <label class="op-label">Net Local Amount</label>
                        <p class="text-sm font-medium text-gray-900 dark:text-white"><?php echo $response_transaction['response'][0]['local_currency'].' '.money_round($response_transaction['response'][0]['local_net_amount'], 2)?></p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Customer Tab -->
        <div id="tab-customer" class="hidden" role="tabpanel" aria-labelledby="tab-btn-customer">
            <div class="border border-gray-200 dark:border-gray-700 rounded-lg p-4">
                <h4 class="text-sm font-semibold text-gray-900 dark:text-white border-b border-gray-200 dark:border-gray-700 pb-3 -mx-4 px-4 -mt-1 mb-4">Customer Information</h4>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div>
                        <label class="op-label">Full Name</label>
                        <p class="text-sm font-medium text-gray-900 dark:text-white"><?php echo $customer_info['name'] ?? '' ?></p>
                    </div>
                    <div>
                        <label class="op-label">Email Address</label>
                        <p class="text-sm font-medium text-gray-900 dark:text-white"><?php echo $customer_info['email'] ?? '' ?></p>
                    </div>
                    <div>
                        <label class="op-label">Mobile Number</label>
                        <p class="text-sm font-medium text-gray-900 dark:text-white"><?php echo $customer_info['mobile'] ?? '' ?></p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Metadata Tab -->
        <?php if(!empty($metadata)){ ?>
        <div id="tab-metadata" class="hidden" role="tabpanel" aria-labelledby="tab-btn-metadata">
            <div class="border border-gray-200 dark:border-gray-700 rounded-lg p-4">
                <h4 class="text-sm font-semibold text-gray-900 dark:text-white border-b border-gray-200 dark:border-gray-700 pb-3 -mx-4 px-4 -mt-1 mb-4">Metadata Information</h4>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <?php foreach($metadata as $key => $value){ ?>
                    <div>
                        <label class="op-label"><?php echo $key?></label>
                        <p class="text-sm font-medium text-gray-900 dark:text-white"><?php echo $value ?? '' ?></p>
                    </div>
                    <?php } ?>
                </div>
            </div>
        </div>
        <?php } ?>

        <!-- More Info Tab -->
        <?php if(!empty($source_info)){ ?>
        <div id="tab-more" class="hidden" role="tabpanel" aria-labelledby="tab-btn-more">
            <div class="border border-gray-200 dark:border-gray-700 rounded-lg p-4">
                <h4 class="text-sm font-semibold text-gray-900 dark:text-white border-b border-gray-200 dark:border-gray-700 pb-3 -mx-4 px-4 -mt-1 mb-4">More Information</h4>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <?php foreach ($source_info as $item) {
                        $title = $item['label'] ?? '';
                        $description = $item['value'] ?? '';
                    ?>
                    <div>
                        <label class="op-label"><?php echo htmlspecialchars($title); ?></label>
                        <p class="text-sm font-medium text-gray-900 dark:text-white">
                            <?php
                                if (filter_var($description, FILTER_VALIDATE_URL)) {
                                    echo '<a href="'.htmlspecialchars($description).'" target="_blank" class="text-primary-600 hover:underline">View</a>';
                                } else {
                                    echo htmlspecialchars($description);
                                }
                            ?>
                        </p>
                    </div>
                    <?php } ?>
                </div>
            </div>
        </div>
        <?php } ?>

        <!-- Endpoint Tab -->
        <div id="tab-endpoint" class="hidden" role="tabpanel" aria-labelledby="tab-btn-endpoint">
            <div class="border border-gray-200 dark:border-gray-700 rounded-lg p-4">
                <h4 class="text-sm font-semibold text-gray-900 dark:text-white border-b border-gray-200 dark:border-gray-700 pb-3 -mx-4 px-4 -mt-1 mb-4">Endpoint</h4>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="op-label">Return URL</label>
                        <p class="text-sm font-medium text-gray-900 dark:text-white break-all"><?php echo empty($response_transaction['response'][0]['return_url']) ? '—' : $response_transaction['response'][0]['return_url']; ?></p>
                    </div>
                    <div>
                        <label class="op-label">Webhook URL</label>
                        <p class="text-sm font-medium text-gray-900 dark:text-white break-all"><?php echo empty($response_transaction['response'][0]['webhook_url']) ? '—' : $response_transaction['response'][0]['webhook_url']; ?></p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Edit Modal -->
<div id="model-bulkAction" tabindex="-1" class="fixed top-0 left-0 right-0 z-50 hidden w-full p-4 overflow-x-hidden overflow-y-auto md:inset-0 h-[calc(100%-1rem)] max-h-full flex items-center justify-center">
    <div class="relative w-full max-w-md">
        <div class="relative bg-white rounded-lg shadow-sm dark:bg-gray-800">
            <div class="flex items-center justify-between p-4 border-b border-gray-200 dark:border-gray-700">
                <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Update Transaction Status</h3>
                <button type="button" class="text-gray-400 hover:text-gray-600" data-modal-hide="model-bulkAction">
                    <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd"></path></svg>
                </button>
            </div>
            <div class="p-4">
                <label class="op-label">Action <span class="text-red-500">*</span></label>
                <select class="op-select" id="model-bulkActionID">
                    <option value="" selected>Select a Action</option>
                    <?= hasPermission(json_decode($global_response_permission['response'][0]['permission'], true), 'transaction', 'approve', $global_user_response['response'][0]['role']) ? '<option value="approved">Approve</option>' : '' ?>
                    <?= hasPermission(json_decode($global_response_permission['response'][0]['permission'], true), 'transaction', 'cancel', $global_user_response['response'][0]['role']) ? '<option value="canceled">Cancel</option>' : '' ?>
                    <?= hasPermission(json_decode($global_response_permission['response'][0]['permission'], true), 'transaction', 'refund', $global_user_response['response'][0]['role']) ? '<option value="refunded">Refund</option>' : '' ?>
                </select>
            </div>
            <div class="flex items-center justify-between p-4 border-t border-gray-200 dark:border-gray-700">
                <button type="button" class="op-btn-secondary" data-modal-hide="model-bulkAction">Close</button>
                <button type="button" class="op-btn-primary model-bulkAction-btn">Confirm</button>
            </div>
        </div>
    </div>
</div>

<script nonce="<?= $csp_nonce ?? '' ?>" data-cfasync="false">
    window.OP_DASHBOARD_URL = '<?php echo $site_url.$path_admin ?>/dashboard';

    // Tabs: handled by Flowbite data-tabs-toggle (auto-initialized via initFlowbite)

    // Edit action
    document.querySelector('.model-bulkAction-btn').addEventListener('click', function() {
        var my_action_confirmation_btn = document.querySelector("#my-action-confirmation-btn")?.value || '';
        var actionID = document.getElementById("model-bulkActionID").value;

        if(actionID === ""){
            apToast('error', 'Action Required', "You haven't selected any action.");
        } else {
            const selectedRows = ['<?php echo $ref ?>'];

            if(my_action_confirmation_btn !== ""){
                var btnEl = document.querySelector('#model-my-action-confirmation-btn');
                var btn = btnEl.innerHTML;
                btnEl.innerHTML = '<div class="op-spinner" style="width:16px;height:16px;border-width:2px"></div>';

                opFetch('transaction-bulk-action', { actionID, selected_ids: JSON.stringify(selectedRows) })
                    .then(response => {
                        closeAllModals();
                        document.querySelector("#my-action-confirmation-btn").value = '';
                        document.getElementById("model-bulkActionID").selectedIndex = 0;
                        btnEl.innerHTML = btn;

                        if (response.status === 'true') {
                            apToast('success', response.title, response.message);
                            load_content('Edit Transaction','<?php echo $site_url.$path_admin ?>/transaction/edit?t_id=<?php echo $ref?>','nav-item-transaction');
                        } else {
                            apToast('error', response.title, response.message);
                        }
                    })
                    .catch(err => apToastError());
            } else {
                show_action_confirmation_tab('model-bulkAction-btn', 'Confirm Action', 'Confirm', 'btn-danger');
            }
        }
    });

    function ipnItem(ItemID){
        var my_action_confirmation_btn = document.querySelector("#my-action-confirmation-btn")?.value || '';
        var btnClass = 'btnIpnItem-'+ItemID;

        if(my_action_confirmation_btn !== ""){
            var btnEl = document.querySelector('#model-my-action-confirmation-btn');
            var btn = btnEl.innerHTML;
            btnEl.innerHTML = '<div class="op-spinner" style="width:16px;height:16px;border-width:2px"></div>';

            opFetch('transaction-ipn', { ItemID })
                .then(response => {
                    closeAllModals();
                    document.querySelector("#my-action-confirmation-btn").value = '';
                    btnEl.innerHTML = btn;
                    response.status === 'true' ? apToast('success', response.title, response.message) : apToast('error', response.title, response.message);
                })
                .catch(err => apToastError());
        } else {
            show_action_confirmation_tab(btnClass, 'Send Transaction IPN', 'Confirm', 'btn-success');
        }
    }

    function deleteItem(ItemID){
        var my_action_confirmation_btn = document.querySelector("#my-action-confirmation-btn")?.value || '';
        var btnClass = 'btnDeleteItem-'+ItemID;

        if(my_action_confirmation_btn !== ""){
            var btnEl = document.querySelector('#model-my-action-confirmation-btn');
            var btn = btnEl.innerHTML;
            btnEl.innerHTML = '<div class="op-spinner" style="width:16px;height:16px;border-width:2px"></div>';

            opFetch('transaction-delete', { ItemID })
                .then(response => {
                    closeAllModals();
                    document.querySelector("#my-action-confirmation-btn").value = '';
                    btnEl.innerHTML = btn;

                    if (response.status === 'true') {
                        apToast('success', response.title, response.message);
                        load_content('Transaction','<?php echo $site_url.$path_admin ?>/transaction','nav-item-transaction');
                    } else {
                        apToast('error', response.title, response.message);
                    }
                })
                .catch(err => apToastError());
        } else {
            show_action_confirmation_tab(btnClass, 'Delete Transaction', 'Delete', 'btn-danger');
        }
    }
</script>
