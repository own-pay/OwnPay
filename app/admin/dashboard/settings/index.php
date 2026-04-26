<?php
    if (!defined('OWNPAY_INIT')) { http_response_code(403); exit('Direct access not allowed'); }
    if (!canAccessPage(json_decode($global_response_permission['response'][0]['permission'], true), 'brand_settings', $global_user_response['response'][0]['role'])) { http_response_code(403); exit('Access denied.'); }
?>

<div class="op-page-header">
    <div>
        <div class="op-page-pretitle">Configuration</div>
        <h2 class="op-page-title">Unified Settings Hub</h2>
        <p class="text-sm mt-1 text-slate-400/50">Configure your business environment, security, and developer preferences.</p>
    </div>
</div>

<div class="flex flex-col lg:flex-row gap-6">

    <!-- LEFT: Vertical Tab Navigation -->
    <div class="w-full lg:w-56 flex-shrink-0 overflow-x-auto lg:overflow-visible">
        <nav class="flex lg:flex-col gap-1 lg:gap-0 lg:space-y-1 lg:sticky lg:top-20 pb-2 lg:pb-0" id="settings-tabs">

            <!-- General -->
            <button class="op-settings-tab flex-shrink-0 lg:flex-shrink active" data-settings-tab="general" onclick="switchSettingsTab(this, 'general')">
                <svg xmlns="http://www.w3.org/2000/svg" class="op-settings-tab-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.325 4.317c.426 -1.756 2.924 -1.756 3.35 0a1.724 1.724 0 0 0 2.573 1.066c1.543 -.94 3.31 .826 2.37 2.37a1.724 1.724 0 0 0 1.065 2.572c1.756 .426 1.756 2.924 0 3.35a1.724 1.724 0 0 0 -1.066 2.573c.94 1.543 -.826 3.31 -2.37 2.37a1.724 1.724 0 0 0 -2.572 1.065c-.426 1.756 -2.924 1.756 -3.35 0a1.724 1.724 0 0 0 -2.573 -1.066c-1.543 .94 -3.31 -.826 -2.37 -2.37a1.724 1.724 0 0 0 -1.065 -2.572c-1.756 -.426 -1.756 -2.924 0 -3.35a1.724 1.724 0 0 0 1.066 -2.573c-.94 -1.543 .826 -3.31 2.37 -2.37c1 .608 2.296 .07 2.572 -1.065z"/><path d="M9 12a3 3 0 1 0 6 0a3 3 0 0 0 -6 0"/></svg>
                General
            </button>

            <!-- API & Security -->
            <button class="op-settings-tab flex-shrink-0 lg:flex-shrink" data-settings-tab="api" onclick="switchSettingsTab(this, 'api')">
                <svg xmlns="http://www.w3.org/2000/svg" class="op-settings-tab-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16.555 3.843l3.602 3.602a2.877 2.877 0 0 1 0 4.069l-2.643 2.643a2.877 2.877 0 0 1 -4.069 0l-.301 -.301l-6.558 6.558a2 2 0 0 1 -1.239 .578l-.175 .008h-1.172a1 1 0 0 1 -.993 -.883l-.007 -.117v-1.172a2 2 0 0 1 .467 -1.284l.119 -.13l.414 -.414h2v-2h2v-2l2.144 -2.144l-.301 -.301a2.877 2.877 0 0 1 0 -4.069l2.643 -2.643a2.877 2.877 0 0 1 4.069 0"/><path d="M15 9h.01"/></svg>
                API & Security
            </button>

            <!-- Appearance -->
            <button class="op-settings-tab flex-shrink-0 lg:flex-shrink" data-settings-tab="appearance" onclick="switchSettingsTab(this, 'appearance')">
                <svg xmlns="http://www.w3.org/2000/svg" class="op-settings-tab-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 21v-4a4 4 0 1 1 4 4h-4"/><path d="M21 3a16 16 0 0 0 -12.8 10.2"/><path d="M21 3a16 16 0 0 1 -10.2 12.8"/><path d="M10.6 9a9 9 0 0 1 4.4 4.4"/></svg>
                Appearance
            </button>

            <!-- Team & Access -->
            <button class="op-settings-tab flex-shrink-0 lg:flex-shrink" data-settings-tab="team" onclick="switchSettingsTab(this, 'team')">
                <svg xmlns="http://www.w3.org/2000/svg" class="op-settings-tab-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 7m-4 0a4 4 0 1 0 8 0a4 4 0 1 0 -8 0"/><path d="M3 21v-2a4 4 0 0 1 4 -4h4a4 4 0 0 1 4 4v2"/><path d="M16 11h6m-3 -3v6"/></svg>
                Team & Access
            </button>

            <!-- Domains -->
            <button class="op-settings-tab flex-shrink-0 lg:flex-shrink" data-settings-tab="domains" onclick="switchSettingsTab(this, 'domains')">
                <svg xmlns="http://www.w3.org/2000/svg" class="op-settings-tab-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 12m-9 0a9 9 0 1 0 18 0a9 9 0 1 0 -18 0"/><path d="M3.6 9h16.8"/><path d="M3.6 15h16.8"/><path d="M11.5 3a17 17 0 0 0 0 18"/><path d="M12.5 3a17 17 0 0 1 0 18"/></svg>
                Domains
            </button>

            <!-- System -->
            <button class="op-settings-tab flex-shrink-0 lg:flex-shrink" data-settings-tab="system" onclick="switchSettingsTab(this, 'system')">
                <svg xmlns="http://www.w3.org/2000/svg" class="op-settings-tab-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 5a2 2 0 0 1 2 -2h8a2 2 0 0 1 2 2v14a2 2 0 0 1 -2 2h-8a2 2 0 0 1 -2 -2v-14z"/><path d="M11 4h2"/><path d="M12 17v.01"/></svg>
                System
            </button>

            <!-- Activity -->
            <button class="op-settings-tab flex-shrink-0 lg:flex-shrink" data-settings-tab="activity" onclick="switchSettingsTab(this, 'activity')">
                <svg xmlns="http://www.w3.org/2000/svg" class="op-settings-tab-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12h4l3 8l4 -16l3 8h4"/></svg>
                Activity
            </button>

        </nav>
    </div>

    <!-- RIGHT: Tab Content -->
    <div class="flex-1 min-w-0" id="settings-content">

        <!-- Loading state -->
        <div id="settings-loading" class="hidden flex justify-center items-center py-16">
            <div class="op-spinner"></div>
        </div>

        <!-- Default: General Tab Content -->
        <div id="settings-panel" data-current-tab="general">
            <?php
            // Build settings cards data for each tab
            $tabs = [
                'general' => [
                    ['perm' => 'brand_settings', 'action' => 'view', 'title' => 'Brand General Settings', 'desc' => 'Set your site name, timezone, language, default currency, payment tolerance, logos, and favicon.',
                     'route' => 'settings/general-setting', 'nav' => 'nav-item-settings',
                     'icon' => '<path d="M10.325 4.317c.426 -1.756 2.924 -1.756 3.35 0a1.724 1.724 0 0 0 2.573 1.066c1.543 -.94 3.31 .826 2.37 2.37a1.724 1.724 0 0 0 1.065 2.572c1.756 .426 1.756 2.924 0 3.35a1.724 1.724 0 0 0 -1.066 2.573c.94 1.543 -.826 3.31 -2.37 2.37a1.724 1.724 0 0 0 -2.572 1.065c-.426 1.756 -2.924 1.756 -3.35 0a1.724 1.724 0 0 0 -2.573 -1.066c-1.543 .94 -3.31 -.826 -2.37 -2.37a1.724 1.724 0 0 0 -1.065 -2.572c-1.756 -.426 -1.756 -2.924 0 -3.35a1.724 1.724 0 0 0 1.066 -2.573c-.94 -1.543 .826 -3.31 2.37 -2.37c1 .608 2.296 .07 2.572 -1.065"/><path d="M9 12a3 3 0 1 0 6 0a3 3 0 0 0 -6 0"/>'],
                    ['perm' => 'system_settings', 'action' => 'manage_general', 'title' => 'System General Settings', 'desc' => 'Configure registration mode, maintenance settings, SMTP email delivery, and core system behavior.',
                     'route' => 'system-settings/geneal', 'nav' => 'nav-item-settings',
                     'icon' => '<path d="M17 8v-3a1 1 0 0 0 -1 -1h-10a2 2 0 0 0 0 4h12a1 1 0 0 1 1 1v3m0 4v3a1 1 0 0 1 -1 1h-12a2 2 0 0 1 -2 -2v-12"/><path d="M20 12v4h-4a2 2 0 0 1 0 -4h4"/>'],
                    ['perm' => 'currency_settings', 'action' => 'view', 'title' => 'Currency Settings', 'desc' => 'Add or remove accepted currencies, set exchange rates, and configure currency display format.',
                     'route' => 'settings/currency-setting', 'nav' => 'nav-item-settings',
                     'icon' => '<path d="M16.7 8a3 3 0 0 0 -2.7 -2h-4a3 3 0 0 0 0 6h4a3 3 0 0 1 0 6h-4a3 3 0 0 1 -2.7 -2"/><path d="M12 3v3m0 12v3"/>'],
                ],
                'api' => [
                    ['perm' => 'api_settings', 'action' => 'view', 'title' => 'API Keys & Tokens', 'desc' => 'Generate and manage API keys for third-party integrations and webhook endpoints.',
                     'route' => 'settings/api-setting', 'nav' => 'nav-item-settings',
                     'icon' => '<path d="M16.555 3.843l3.602 3.602a2.877 2.877 0 0 1 0 4.069l-2.643 2.643a2.877 2.877 0 0 1 -4.069 0l-.301 -.301l-6.558 6.558a2 2 0 0 1 -1.239 .578l-.175 .008h-1.172a1 1 0 0 1 -.993 -.883l-.007 -.117v-1.172a2 2 0 0 1 .467 -1.284l.119 -.13l.414 -.414h2v-2h2v-2l2.144 -2.144l-.301 -.301a2.877 2.877 0 0 1 0 -4.069l2.643 -2.643a2.877 2.877 0 0 1 4.069 0"/><path d="M15 9h.01"/>'],
                ],
                'appearance' => [
                    ['perm' => 'theme_settings', 'action' => 'view', 'title' => 'Checkout Themes', 'desc' => 'Choose and activate a checkout page theme. Preview different layouts before going live.',
                     'route' => 'settings/themes', 'nav' => 'nav-item-settings',
                     'icon' => '<path d="M3 21v-4a4 4 0 1 1 4 4h-4"/><path d="M21 3a16 16 0 0 0 -12.8 10.2"/><path d="M21 3a16 16 0 0 1 -10.2 12.8"/><path d="M10.6 9a9 9 0 0 1 4.4 4.4"/>'],
                    ['perm' => 'faq_settings', 'action' => 'view', 'title' => 'FAQ Management', 'desc' => 'Create, edit, or reorder FAQs displayed on your checkout page to help customers.',
                     'route' => 'settings/faq-setting', 'nav' => 'nav-item-settings',
                     'icon' => '<path d="M12 16v.01"/><path d="M12 13a2 2 0 0 0 .914 -3.782a1.98 1.98 0 0 0 -2.414 .483"/><path d="M12 12m-9 0a9 9 0 1 0 18 0a9 9 0 1 0 -18 0"/>'],
                ],
                'team' => [
                    ['perm' => 'staff_management', 'action' => 'view', 'title' => 'Staff Members', 'desc' => 'Add or remove staff accounts, assign roles, and control what each member can access.',
                     'route' => 'staff-management', 'nav' => 'nav-item-settings',
                     'icon' => '<path d="M9 7m-4 0a4 4 0 1 0 8 0a4 4 0 1 0 -8 0"/><path d="M3 21v-2a4 4 0 0 1 4 -4h4a4 4 0 0 1 4 4v2"/><path d="M16 11h6m-3 -3v6"/>'],
                ],
                'domains' => [
                    ['perm' => 'domains', 'action' => 'view', 'title' => 'Custom Domains', 'desc' => 'Connect your own domain to serve branded checkout pages instead of the default URL.',
                     'route' => 'domains', 'nav' => 'nav-item-settings',
                     'icon' => '<path d="M12 12m-9 0a9 9 0 1 0 18 0a9 9 0 1 0 -18 0"/><path d="M3.6 9h16.8"/><path d="M3.6 15h16.8"/><path d="M11.5 3a17 17 0 0 0 0 18"/><path d="M12.5 3a17 17 0 0 1 0 18"/>'],
                ],
                'system' => [
                    ['perm' => 'system_settings', 'action' => 'manage_cron', 'title' => 'Cron Jobs', 'desc' => 'Set up and monitor automated tasks like invoice expiry, rate updates, and cleanup jobs.',
                     'route' => 'system-settings/cron-job', 'nav' => 'nav-item-settings',
                     'icon' => '<path d="M10.5 21h-4.5a2 2 0 0 1 -2 -2v-12a2 2 0 0 1 2 -2h12a2 2 0 0 1 2 2v3"/><path d="M16 3v4"/><path d="M8 3v4"/><path d="M4 11h10"/><path d="M14 18a4 4 0 1 0 8 0a4 4 0 1 0 -8 0"/><path d="M18 16.5v1.5l.5 .5"/>'],
                    ['perm' => 'system_settings', 'action' => 'manage_update', 'title' => 'Updates', 'desc' => 'Check for new versions, apply patches, and switch between stable and beta update channels.',
                     'route' => 'system-settings/update', 'nav' => 'nav-item-settings',
                     'icon' => '<path d="M20 11a8.1 8.1 0 0 0 -15.5 -2m-.5 -4v4h4"/><path d="M4 13a8.1 8.1 0 0 0 15.5 2m.5 4v-4h-4"/>'],
                    ['perm' => 'system_settings', 'action' => 'manage_import', 'title' => 'Import Modules', 'desc' => 'Upload and install theme files, add-on packages, or payment gateway modules from .zip archives.',
                     'route' => 'system-settings/import', 'nav' => 'nav-item-settings',
                     'icon' => '<path d="M4 17v2a2 2 0 0 0 2 2h12a2 2 0 0 0 2 -2v-2"/><path d="M7 9l5 -5l5 5"/><path d="M12 4l0 12"/>'],
                    ['perm' => 'addons', 'action' => 'view', 'title' => 'Addons', 'desc' => 'View installed add-ons, activate or deactivate extensions, and check for addon updates.',
                     'route' => 'addons', 'nav' => 'nav-item-settings',
                     'icon' => '<path d="M4 7h3a1 1 0 0 0 1 -1v-1a2 2 0 0 1 4 0v1a1 1 0 0 0 1 1h3a1 1 0 0 1 1 1v3a1 1 0 0 0 1 1h1a2 2 0 0 1 0 4h-1a1 1 0 0 0 -1 1v3a1 1 0 0 1 -1 1h-3a1 1 0 0 1 -1 -1v-1a2 2 0 0 0 -4 0v1a1 1 0 0 1 -1 1h-3a1 1 0 0 1 -1 -1v-3a1 1 0 0 1 1 -1h1a2 2 0 0 0 0 -4h-1a1 1 0 0 1 -1 -1v-3a1 1 0 0 1 1 -1"/>'],
                ],
                'activity' => [
                    ['perm' => 'brand_settings', 'action' => 'view', 'title' => 'Activity Log', 'desc' => 'Track staff logins, setting changes, and all admin actions for security auditing.',
                     'route' => 'activities', 'nav' => 'nav-item-settings',
                     'icon' => '<path d="M3 12h4l3 8l4 -16l3 8h4"/>'],
                ],
            ];
            ?>

            <!-- Render cards for the default "general" tab -->
            <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4" id="settings-cards-grid">
                <?php foreach ($tabs as $tabKey => $tabCards): ?>
                    <?php foreach ($tabCards as $card):
                        $visible = hasPermission(json_decode($global_response_permission['response'][0]['permission'], true), $card['perm'], $card['action'], $global_user_response['response'][0]['role']);
                    ?>
                    <div class="op-settings-card-item <?= $visible ? '' : 'hidden' ?> <?= $tabKey !== 'general' ? 'hidden' : '' ?>"
                         data-tab-group="<?= $tabKey ?>"
                         onclick="load_content('Settings','<?php echo $site_url . $path_admin ?>/<?= $card['route'] ?>','<?= $card['nav'] ?>')">
                        <div class="op-settings-section cursor-pointer group hover:border-indigo-500/30 transition-all duration-200">
                            <div class="flex items-start gap-3">
                                <div class="w-10 h-10 rounded-lg flex items-center justify-center flex-shrink-0 bg-indigo-500/10">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 text-indigo-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><?= $card['icon'] ?></svg>
                                </div>
                                <div class="flex-1">
                                    <h5 class="text-sm font-semibold text-white group-hover:text-indigo-300 transition-colors"><?= $card['title'] ?></h5>
                                    <p class="text-xs mt-1 text-slate-400/50"><?= $card['desc'] ?></p>
                                </div>
                                <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 mt-0.5 flex-shrink-0 opacity-0 group-hover:opacity-100 transition-opacity text-slate-400/40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 6l6 6l-6 6"/></svg>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php endforeach; ?>
            </div>
        </div>

    </div>
</div>

<script nonce="<?= $csp_nonce ?? '' ?>">
    function switchSettingsTab(btn, tabKey) {
        // Update active tab button
        document.querySelectorAll('.op-settings-tab').forEach(t => t.classList.remove('active'));
        btn.classList.add('active');

        // Show/hide cards for the selected tab
        document.querySelectorAll('.op-settings-card-item').forEach(card => {
            if (card.dataset.tabGroup === tabKey) {
                card.classList.remove('hidden');
            } else {
                card.classList.add('hidden');
            }
        });

        // Update panel data attribute
        document.getElementById('settings-panel').dataset.currentTab = tabKey;
    }

    // Deep-link: auto-switch tab if ?tab= param is present
    (function() {
        var urlParams = new URLSearchParams(window.location.search);
        var tabParam = urlParams.get('tab');
        if (tabParam) {
            var tabBtn = document.querySelector('[data-settings-tab="' + tabParam + '"]');
            if (tabBtn) switchSettingsTab(tabBtn, tabParam);
        }
    })();
</script>