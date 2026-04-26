    <!-- ========== SIDEBAR BACKDROP (Mobile) ========== -->
    <div id="op-sidebar-backdrop" class="hidden fixed inset-0 z-30 bg-gray-900/50 dark:bg-gray-900/80 md:hidden"></div>

    <!-- ========== SIDEBAR ========== -->
    <aside id="op-sidebar" class="op-sidebar" aria-label="Sidebar">
        <div class="h-full px-3 pb-4 overflow-y-auto">

            <!-- Brand Switcher -->
            <div class="relative mb-6 mt-3">
                <button type="button" data-dropdown-toggle="op-brand-dropdown"
                    class="flex items-center w-full p-2 text-left rounded-lg hover:bg-gray-100 dark:hover:bg-gray-700 transition-colors">
                    <img class="w-8 h-8 rounded-full flex-shrink-0"
                        src="https://ui-avatars.com/api/?name=<?php echo getNameChars($global_response_brand['response'][0]['identify_name'], 1); ?>&color=FFFFFF&background=343a40&size=64"
                        alt="">
                    <div class="ms-3 min-w-0 flex-1">
                        <div class="text-sm font-medium text-gray-900 dark:text-white truncate">
                            <?php echo htmlspecialchars($global_response_brand['response'][0]['identify_name']); ?>
                        </div>
                        <div class="text-xs text-gray-500 dark:text-gray-400">Active brand</div>
                    </div>
                    <svg class="w-4 h-4 text-gray-400 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd" /></svg>
                </button>

                <!-- Brand Dropdown -->
                <div id="op-brand-dropdown" class="hidden absolute left-0 right-0 mt-1 bg-white rounded-lg shadow-lg border border-gray-100 dark:bg-gray-700 dark:border-gray-600 z-50">
                    <?php
                    $params_perm = [':a_id' => $global_user_response['response'][0]['a_id'], ':status' => 'active', ':brand_id' => $global_response_permission['response'][0]['brand_id']];
                    $response_permission = json_decode(getData($db_prefix . 'permission', 'WHERE a_id = :a_id AND status = :status AND brand_id != :brand_id', '* FROM', $params_perm), true);
                    if ($response_permission['status'] == true) {
                        foreach ($response_permission['response'] as $row) {
                            $params_brand = [':brand_id' => $row['brand_id']];
                            $response_brand = json_decode(getData($db_prefix . 'brands', 'WHERE brand_id = :brand_id', '* FROM', $params_brand), true);
                            ?>
                            <a href="#"
                                data-op-action="set-brand" data-op-brand="<?php echo $row['brand_id'] ?>"
                                class="flex items-center gap-2 px-4 py-2 text-sm text-gray-700 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-600 first:rounded-t-lg">
                                <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 text-gray-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 21l18 0" /><path d="M3 7v1a3 3 0 0 0 6 0v-1m0 1a3 3 0 0 0 6 0v-1m0 1a3 3 0 0 0 6 0v-1h-18l2 -4h14l2 4" /><path d="M5 21l0 -10.15" /><path d="M19 21l0 -10.15" /><path d="M9 21v-4a2 2 0 0 1 2 -2h2a2 2 0 0 1 2 2v4" /></svg>
                                <?php echo htmlspecialchars($response_brand['response'][0]['identify_name']) ?>
                            </a>
                            <?php
                        }
                        ?>
                        <div class="border-t border-gray-100 dark:border-gray-600"></div>
                        <?php
                    }
                    ?>

                    <a href="#"
                        class="flex items-center gap-2 px-4 py-2 text-sm text-primary-600 hover:bg-gray-100 dark:text-primary-400 dark:hover:bg-gray-600 last:rounded-b-lg <?= hasPermission(json_decode($global_response_permission['response'][0]['permission'], true), 'brands', 'create', $global_user_response['response'][0]['role']) ? '' : 'hidden' ?>"
                        data-op-nav-title="Create New Brand" data-op-nav-url="<?php echo $site_url . $path_admin ?>/brands/create" data-op-nav-id="nav-item-brands" data-op-close="op-brand-dropdown">
                        <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5l0 14" /><path d="M5 12l14 0" /></svg>
                        Create New Brand
                    </a>
                </div>
            </div>

            <!-- Navigation -->
            <ul class="space-y-1">
                <!-- ─── OVERVIEW ─── -->
                <li class="op-sidebar-section-title">Overview</li>

                <!-- Dashboard -->
                <li class="nav-item-dashboard <?= canAccessPage(json_decode($global_response_permission['response'][0]['permission'], true), 'dashboard', $global_user_response['response'][0]['role']) ? '' : 'hidden' ?>"
                    data-op-nav-title="Dashboard" data-op-nav-url="<?php echo $site_url . $path_admin ?>/dashboard" data-op-nav-id="nav-item-dashboard">
                    <a href="#" class="op-sidebar-link">
                        <svg xmlns="http://www.w3.org/2000/svg" class="op-sidebar-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h6v8h-6z"/><path d="M4 16h6v4h-6z"/><path d="M14 12h6v8h-6z"/><path d="M14 4h6v4h-6z"/></svg>
                        <span class="ms-3">Dashboard</span>
                    </a>
                </li>

                <!-- Reports -->
                <li class="nav-item-reports <?= canAccessPage(json_decode($global_response_permission['response'][0]['permission'], true), 'reports', $global_user_response['response'][0]['role']) ? '' : 'hidden' ?>"
                    data-op-nav-title="Reports" data-op-nav-url="<?php echo $site_url . $path_admin ?>/reports" data-op-nav-id="nav-item-reports">
                    <a href="#" class="op-sidebar-link">
                        <svg xmlns="http://www.w3.org/2000/svg" class="op-sidebar-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3v18h18"/><path d="M7 16l4 -4l4 4l6 -6"/></svg>
                        <span class="ms-3">Reports</span>
                    </a>
                </li>

                <!-- ─── PAYMENTS ─── -->
                <li class="op-sidebar-section-title">Payments</li>

                <!-- Transaction -->
                <li class="nav-item-transaction <?= canAccessPage(json_decode($global_response_permission['response'][0]['permission'], true), 'transaction', $global_user_response['response'][0]['role']) ? '' : 'hidden' ?>"
                    data-op-nav-title="Transaction" data-op-nav-url="<?php echo $site_url . $path_admin ?>/transaction" data-op-nav-id="nav-item-transaction">
                    <a href="#" class="op-sidebar-link">
                        <svg xmlns="http://www.w3.org/2000/svg" class="op-sidebar-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M7 10h14l-4 -4"/><path d="M17 14h-14l4 4"/></svg>
                        <span class="ms-3 flex-1">Transactions</span>
                        <?php
                        $count = 0;
                        $params_trcount = [':brand_id' => $global_response_brand['response'][0]['brand_id'], ':status' => 'pending'];
                        $response_dashboard_info = json_decode(getData($db_prefix . 'transaction', 'WHERE brand_id = :brand_id AND status = :status', 'COUNT(*) as total FROM', $params_trcount), true);
                        if ($response_dashboard_info['status'] == true && !empty($response_dashboard_info['response'][0]['total'])) {
                            $count = (int)$response_dashboard_info['response'][0]['total'];
                        }
                        ?>
                        <?php if ($count > 0): ?>
                            <span class="op-badge-danger ms-auto"><?php echo number_format($count, 0); ?></span>
                        <?php endif; ?>
                    </a>
                </li>

                <!-- Invoice -->
                <li class="nav-item-invoice <?= canAccessPage(json_decode($global_response_permission['response'][0]['permission'], true), 'invoice', $global_user_response['response'][0]['role']) ? '' : 'hidden' ?>"
                    data-op-nav-title="Invoice" data-op-nav-url="<?php echo $site_url . $path_admin ?>/invoice" data-op-nav-id="nav-item-invoice">
                    <a href="#" class="op-sidebar-link">
                        <svg xmlns="http://www.w3.org/2000/svg" class="op-sidebar-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M14 3v4a1 1 0 0 0 1 1h4"/><path d="M17 21h-10a2 2 0 0 1 -2 -2v-14a2 2 0 0 1 2 -2h7l5 5v11a2 2 0 0 1 -2 2z"/><path d="M9 17h6"/><path d="M9 13h6"/></svg>
                        <span class="ms-3">Invoices</span>
                    </a>
                </li>

                <!-- Payment Link -->
                <li class="nav-item-payment-link <?= canAccessPage(json_decode($global_response_permission['response'][0]['permission'], true), 'payment_link', $global_user_response['response'][0]['role']) ? '' : 'hidden' ?>"
                    data-op-nav-title="Payment Link" data-op-nav-url="<?php echo $site_url . $path_admin ?>/payment-link" data-op-nav-id="nav-item-payment-link">
                    <a href="#" class="op-sidebar-link">
                        <svg xmlns="http://www.w3.org/2000/svg" class="op-sidebar-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M9 15l6 -6"/><path d="M11 6l.463 -.536a5 5 0 0 1 7.071 7.072l-.534 .464"/><path d="M13 18l-.397 .534a5.068 5.068 0 0 1 -7.127 0a4.972 4.972 0 0 1 0 -7.071l.524 -.463"/></svg>
                        <span class="ms-3">Payment Links</span>
                    </a>
                </li>

                <!-- ─── MANAGE ─── -->
                <li class="op-sidebar-section-title">Manage</li>

                <!-- Gateways -->
                <li class="nav-item-gateways <?= canAccessPage(json_decode($global_response_permission['response'][0]['permission'], true), 'gateways', $global_user_response['response'][0]['role']) ? '' : 'hidden' ?>"
                    data-op-nav-title="Gateways" data-op-nav-url="<?php echo $site_url . $path_admin ?>/gateways" data-op-nav-id="nav-item-gateways">
                    <a href="#" class="op-sidebar-link">
                        <svg xmlns="http://www.w3.org/2000/svg" class="op-sidebar-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M17 8v-3a1 1 0 0 0 -1 -1h-10a2 2 0 0 0 0 4h12a1 1 0 0 1 1 1v3m0 4v3a1 1 0 0 1 -1 1h-12a2 2 0 0 1 -2 -2v-12"/><path d="M20 12v4h-4a2 2 0 0 1 0 -4h4"/></svg>
                        <span class="ms-3">Gateways</span>
                    </a>
                </li>

                <!-- Customers -->
                <li class="nav-item-customers <?= canAccessPage(json_decode($global_response_permission['response'][0]['permission'], true), 'customers', $global_user_response['response'][0]['role']) ? '' : 'hidden' ?>"
                    data-op-nav-title="Customers" data-op-nav-url="<?php echo $site_url . $path_admin ?>/customers" data-op-nav-id="nav-item-customers">
                    <a href="#" class="op-sidebar-link">
                        <svg xmlns="http://www.w3.org/2000/svg" class="op-sidebar-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M9 7m-4 0a4 4 0 1 0 8 0a4 4 0 1 0 -8 0"/><path d="M3 21v-2a4 4 0 0 1 4 -4h4a4 4 0 0 1 4 4v2"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/><path d="M21 21v-2a4 4 0 0 0 -3 -3.85"/></svg>
                        <span class="ms-3">Customers</span>
                    </a>
                </li>

                <!-- Brands -->
                <li class="nav-item-brands <?= canAccessPage(json_decode($global_response_permission['response'][0]['permission'], true), 'brands', $global_user_response['response'][0]['role']) ? '' : 'hidden' ?>"
                    data-op-nav-title="Brands" data-op-nav-url="<?php echo $site_url . $path_admin ?>/brands" data-op-nav-id="nav-item-brands">
                    <a href="#" class="op-sidebar-link">
                        <svg xmlns="http://www.w3.org/2000/svg" class="op-sidebar-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M3 21l18 0"/><path d="M3 7v1a3 3 0 0 0 6 0v-1m0 1a3 3 0 0 0 6 0v-1m0 1a3 3 0 0 0 6 0v-1h-18l2 -4h14l2 4"/><path d="M5 21l0 -10.15"/><path d="M19 21l0 -10.15"/><path d="M9 21v-4a2 2 0 0 1 2 -2h2a2 2 0 0 1 2 2v4"/></svg>
                        <span class="ms-3">Brands</span>
                    </a>
                </li>

                <!-- SMS Center -->
                <li class="nav-item-sms-center nav-item-sms-data nav-item-devices <?= canAccessPage(json_decode($global_response_permission['response'][0]['permission'], true), 'sms_data', $global_user_response['response'][0]['role']) ? '' : 'hidden' ?>"
                    data-op-nav-title="SMS Center" data-op-nav-url="<?php echo $site_url . $path_admin ?>/sms-center" data-op-nav-id="nav-item-sms-center">
                    <a href="#" class="op-sidebar-link">
                        <svg xmlns="http://www.w3.org/2000/svg" class="op-sidebar-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M8 9h8"/><path d="M8 13h6"/><path d="M9 18h-3a3 3 0 0 1 -3 -3v-8a3 3 0 0 1 3 -3h12a3 3 0 0 1 3 3v8a3 3 0 0 1 -3 3h-3l-3 3l-3 -3z"/></svg>
                        <span class="ms-3">SMS Center</span>
                    </a>
                </li>

                <!-- Plugins -->
                <li class="nav-item-plugins <?= canAccessPage(json_decode($global_response_permission['response'][0]['permission'], true), 'plugins', $global_user_response['response'][0]['role']) ? '' : 'hidden' ?>"
                    data-op-nav-title="Plugins" data-op-nav-url="<?php echo $site_url . $path_admin ?>/plugins" data-op-nav-id="nav-item-plugins">
                    <a href="#" class="op-sidebar-link">
                        <svg xmlns="http://www.w3.org/2000/svg" class="op-sidebar-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M7 3v3a1 1 0 0 0 1 1h3"/><path d="M14 3v3a1 1 0 0 1 -1 1h-3"/><path d="M11 7v10"/><path d="M7 10h8a1 1 0 0 1 1 1v7a1 1 0 0 1 -1 1h-8a1 1 0 0 1 -1 -1v-7a1 1 0 0 1 1 -1z"/></svg>
                        <span class="ms-3">Plugins</span>
                    </a>
                </li>

                <?php
                // ─── Dynamic Plugin Menu Entries ───
                // Plugins declare admin_menu in their manifest.json.
                // The PluginLoader collects these at boot and fires this hook.
                if (class_exists('\OwnPay\Plugin\PluginLoader') && \OwnPay\Plugin\PluginLoader::isBooted()) {
                    $pluginRegistry = \OwnPay\Plugin\PluginLoader::getRegistry();
                    foreach ($pluginRegistry->allManifests() as $slug => $manifest) {
                        foreach ($manifest->adminMenu as $menuEntry) {
                            $menuTitle = htmlspecialchars($menuEntry['title'], ENT_QUOTES, 'UTF-8');
                            $menuSlug = htmlspecialchars($menuEntry['slug'], ENT_QUOTES, 'UTF-8');
                            $navId = 'nav-item-plugin-' . htmlspecialchars($slug, ENT_QUOTES, 'UTF-8');
                            ?>
                <li class="<?= $navId ?>"
                    data-op-nav-title="<?= $menuTitle ?>" data-op-nav-url="<?php echo $site_url . $path_admin ?>/plugins" data-op-nav-id="<?= $navId ?>">
                    <a href="#" class="op-sidebar-link ps-8">
                        <svg xmlns="http://www.w3.org/2000/svg" class="op-sidebar-icon w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="1"/></svg>
                        <span class="ms-3 text-sm"><?= $menuTitle ?></span>
                    </a>
                </li>
                            <?php
                        }
                    }
                }
                // Fire the hook so plugins can also register menu items dynamically
                \OwnPay\Event\EventManager::getInstance()->doAction('admin.menu.register');
                ?>

                <!-- ─── SETTINGS ─── -->
                <li class="op-sidebar-section-title">Settings</li>

                <!-- Unified Settings Hub -->
                <li class="nav-item-settings nav-item-brand-setting nav-item-system-settings nav-item-staff-management nav-item-domains nav-item-addons nav-item-activities <?= canAccessPage(json_decode($global_response_permission['response'][0]['permission'], true), 'brand_settings', $global_user_response['response'][0]['role']) ? '' : 'hidden' ?>"
                    data-op-nav-title="Settings" data-op-nav-url="<?php echo $site_url . $path_admin ?>/settings" data-op-nav-id="nav-item-settings">
                    <a href="#" class="op-sidebar-link">
                        <svg xmlns="http://www.w3.org/2000/svg" class="op-sidebar-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M10.325 4.317c.426 -1.756 2.924 -1.756 3.35 0a1.724 1.724 0 0 0 2.573 1.066c1.543 -.94 3.31 .826 2.37 2.37a1.724 1.724 0 0 0 1.065 2.572c1.756 .426 1.756 2.924 0 3.35a1.724 1.724 0 0 0 -1.066 2.573c.94 1.543 -.826 3.31 -2.37 2.37a1.724 1.724 0 0 0 -2.572 1.065c-.426 1.756 -2.924 1.756 -3.35 0a1.724 1.724 0 0 0 -2.573 -1.066c-1.543 .94 -3.31 -.826 -2.37 -2.37a1.724 1.724 0 0 0 -1.065 -2.572c-1.756 -.426 -1.756 -2.924 0 -3.35a1.724 1.724 0 0 0 1.066 -2.573c-.94 -1.543 .826 -3.31 2.37 -2.37c1 .608 2.296 .07 2.572 -1.065z"/><circle cx="12" cy="12" r="3"/></svg>
                        <span class="ms-3">Settings</span>
                    </a>
                </li>
            </ul>
        </div>
    </aside>