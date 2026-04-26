    <!-- ========== 2FA VERIFY MODAL ========== -->
    <div id="model-my-two-step-verify" data-op-modal class="hidden fixed inset-0 z-50 flex items-start justify-center pt-20 bg-gray-900/50 dark:bg-gray-900/80">
        <div class="relative w-full max-w-md mx-4">
            <div class="relative bg-white rounded-xl shadow-xl dark:bg-gray-800">
                <div class="flex items-center justify-between p-4 border-b border-gray-200 dark:border-gray-700 rounded-t-xl">
                    <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Two Step Verify</h3>
                    <button type="button" data-op-modal-close class="text-gray-400 hover:bg-gray-200 hover:text-gray-900 rounded-lg text-sm w-8 h-8 inline-flex justify-center items-center dark:hover:bg-gray-600 dark:hover:text-white">
                        <svg class="w-3 h-3" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 14 14"><path stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m1 1 6 6m0 0 6 6M7 7l6-6M7 7l-6 6"/></svg>
                    </button>
                </div>
                <div class="p-4">
                    <p class="text-sm text-gray-500 dark:text-gray-400 mb-4">To perform this action, you need to complete 2-step verification to prevent unauthorized access.</p>
                    <input type="hidden" id="my-two-step-verify-btn">
                    <?php if ($global_user_response['response'][0]['2fa_status'] == "enable") { ?>
                        <div class="mb-4">
                            <label for="my-two-step-verify-code" class="op-label">Enter the 6-digit code from the authenticator app <span class="text-red-500">*</span></label>
                            <input type="text" class="op-input" id="my-two-step-verify-code" name="my-two-step-verify-code" placeholder="Enter code" required>
                        </div>
                    <?php } else { ?>
                        <div class="mb-4">
                            <label for="my-two-step-verify-code" class="op-label">Password <span class="text-red-500">*</span></label>
                            <input type="password" class="op-input" id="my-two-step-verify-code" name="my-two-step-verify-code" placeholder="Password" required>
                        </div>
                    <?php } ?>
                </div>
                <div class="flex items-center justify-end gap-2 p-4 border-t border-gray-200 dark:border-gray-700">
                    <button type="button" data-op-modal-close class="op-btn-secondary op-btn-sm">Close</button>
                    <button type="button" class="op-btn-primary op-btn-sm" id="model-my-two-step-verify-btn" data-op-action="two-step-verify">Confirm</button>
                </div>
            </div>
        </div>
    </div>

    <!-- ========== ACTION CONFIRMATION MODAL ========== -->
    <div id="model-my-action-confirmation" data-op-modal class="hidden fixed inset-0 z-50 flex items-start justify-center pt-20 bg-gray-900/50 dark:bg-gray-900/80">
        <div class="relative w-full max-w-md mx-4">
            <div class="relative bg-white rounded-xl shadow-xl dark:bg-gray-800">
                <div class="flex items-center justify-between p-4 border-b border-gray-200 dark:border-gray-700 rounded-t-xl">
                    <h3 class="text-lg font-semibold text-gray-900 dark:text-white model-my-action-confirmation-btn-title"></h3>
                    <button type="button" data-op-modal-close class="text-gray-400 hover:bg-gray-200 hover:text-gray-900 rounded-lg text-sm w-8 h-8 inline-flex justify-center items-center dark:hover:bg-gray-600 dark:hover:text-white">
                        <svg class="w-3 h-3" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 14 14"><path stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m1 1 6 6m0 0 6 6M7 7l6-6M7 7l-6 6"/></svg>
                    </button>
                </div>
                <div class="p-4">
                    <p class="text-sm text-gray-500 dark:text-gray-400">Are you sure you would like to do this?</p>
                    <input type="hidden" id="my-action-confirmation-btn">
                </div>
                <div class="flex items-center justify-end gap-2 p-4 border-t border-gray-200 dark:border-gray-700">
                    <button type="button" data-op-modal-close class="op-btn-secondary op-btn-sm">Close</button>
                    <button type="button" class="op-btn-primary op-btn-sm" id="model-my-action-confirmation-btn" data-op-action="action-confirm">Confirm</button>
                </div>
            </div>
        </div>
    </div>