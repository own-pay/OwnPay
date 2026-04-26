        <!-- Footer -->
        <footer class="op-page-container mt-8 mb-4">
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between text-sm text-gray-500 dark:text-gray-400">
                <div class="flex items-center gap-1 mb-2 sm:mb-0">
                    © <?php echo date('Y'); ?>
                    <a href="https://OwnPay.com/" class="hover:text-primary-600 dark:hover:text-primary-400" target="_blank">OwnPay</a>.
                    All rights reserved.
                    <span class="text-gray-300 dark:text-gray-600 mx-1">·</span>
                    <a href="https://updates.OwnPay.com/?version=<?php echo $OwnPay_current_version['version_code']; ?>" class="hover:text-primary-600 dark:hover:text-primary-400" target="_blank">
                        <?php echo $OwnPay_current_version['version_name']; ?>
                    </a>
                </div>
                <div class="flex items-center gap-3">
                    <a href="https://help.OwnPay.com/" target="_blank" class="hover:text-primary-600 dark:hover:text-primary-400">Documentation</a>
                    <a href="https://github.com/OwnPay" target="_blank" class="hover:text-primary-600 dark:hover:text-primary-400">Modules</a>
                </div>
            </div>
        </footer>