<?php
    if (!defined('OWNPAY_INIT')) { http_response_code(403); exit('Direct access not allowed'); }
    if (!canAccessPage(json_decode($global_response_permission['response'][0]['permission'], true), 'system_settings', $global_user_response['response'][0]['role'])) { http_response_code(403); exit('Access denied.'); }
?>

<div class="op-page-header"><div><div class="op-page-pretitle">System Settings</div><h2 class="op-page-title">System Settings</h2></div></div>

<div class="op-card">
    <div class="p-4 border-b border-gray-200 dark:border-gray-700"><h3 class="text-sm font-semibold text-gray-900 dark:text-white">All Settings</h3></div>
    <div class="p-4">
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
            <?php
            $settingsCards = [
                ['perm' => 'system_settings', 'action' => 'manage_general', 'title' => 'General Setting', 'desc' => 'Manage essential system preferences and core configurations.', 'route' => 'geneal',
                 'icon' => '<path d="M10.325 4.317c.426 -1.756 2.924 -1.756 3.35 0a1.724 1.724 0 0 0 2.573 1.066c1.543 -.94 3.31 .826 2.37 2.37a1.724 1.724 0 0 0 1.065 2.572c1.756 .426 1.756 2.924 0 3.35a1.724 1.724 0 0 0 -1.066 2.573c.94 1.543 -.826 3.31 -2.37 2.37a1.724 1.724 0 0 0 -2.572 1.065c-.426 1.756 -2.924 1.756 -3.35 0a1.724 1.724 0 0 0 -2.573 -1.066c-1.543 .94 -3.31 -.826 -2.37 -2.37a1.724 1.724 0 0 0 -1.065 -2.572c-1.756 -.426 -1.756 -2.924 0 -3.35a1.724 1.724 0 0 0 1.066 -2.573c-.94 -1.543 .826 -3.31 2.37 -2.37c1 .608 2.296 .07 2.572 -1.065"/><path d="M9 12a3 3 0 1 0 6 0a3 3 0 0 0 -6 0"/>'],
                ['perm' => 'system_settings', 'action' => 'manage_cron', 'title' => 'Cron Job', 'desc' => 'Manage scheduled tasks and automated system processes.', 'route' => 'cron-job',
                 'icon' => '<path d="M10.5 21h-4.5a2 2 0 0 1 -2 -2v-12a2 2 0 0 1 2 -2h12a2 2 0 0 1 2 2v3"/><path d="M16 3v4"/><path d="M8 3v4"/><path d="M4 11h10"/><path d="M14 18a4 4 0 1 0 8 0a4 4 0 1 0 -8 0"/><path d="M18 16.5v1.5l.5 .5"/>'],
                ['perm' => 'system_settings', 'action' => 'manage_update', 'title' => 'Update', 'desc' => 'Manage system updates, patches, and version upgrades.', 'route' => 'update',
                 'icon' => '<path d="M20 11a8.1 8.1 0 0 0 -15.5 -2m-.5 -4v4h4"/><path d="M4 13a8.1 8.1 0 0 0 15.5 2m.5 4v-4h-4"/>'],
                ['perm' => 'system_settings', 'action' => 'manage_import', 'title' => 'Import', 'desc' => 'Import themes, add-ons, payment gateways, or any other modules.', 'route' => 'import',
                 'icon' => '<path d="M4 17v2a2 2 0 0 0 2 2h12a2 2 0 0 0 2 -2v-2"/><path d="M7 9l5 -5l5 5"/><path d="M12 4l0 12"/>'],
            ];
            foreach ($settingsCards as $card):
                $visible = hasPermission(json_decode($global_response_permission['response'][0]['permission'], true), $card['perm'], $card['action'], $global_user_response['response'][0]['role']);
            ?>
            <div class="<?= $visible ? '' : 'hidden' ?> cursor-pointer group" onclick="load_content('System Settings','<?php echo $site_url.$path_admin ?>/system-settings/<?= $card['route'] ?>','nav-item-system-settings')">
                <div class="border rounded-lg p-4 h-full hover:border-primary-500 hover:shadow-sm transition-all dark:border-gray-700">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 bg-primary-50 text-primary-600 rounded-lg flex items-center justify-center flex-shrink-0 dark:bg-primary-900/30"><svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><?= $card['icon'] ?></svg></div>
                        <div><h5 class="font-medium text-primary-600 text-sm"><?= $card['title'] ?></h5><p class="text-xs text-gray-500 mt-0.5"><?= $card['desc'] ?></p></div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>
