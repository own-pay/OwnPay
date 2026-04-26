<?php
declare(strict_types=1);

namespace OwnPay\Service;

/**
 * Permission Service
 *
 * Defines the permission schema and provides access-control checks
 * for admin panel pages and actions.
 */
class PermissionService
{
    public static function permissionSchema()
{
    $permissionSchema = [
        'resources' => [
            'customers' => [
                'create' => true,
                'edit' => true,
                'delete' => true
            ],
            'transaction' => [
                'edit' => true,
                'delete' => true,
                'approve' => true,
                'cancel' => true,
                'refund' => true,
                'send_ipn' => true
            ],
            'invoice' => [
                'create' => true,
                'edit' => true,
                'delete' => true
            ],
            'payment_link' => [
                'create' => true,
                'edit' => true,
                'delete' => true
            ],
            'gateways' => [
                'create' => true,
                'edit' => true,
                'delete' => true
            ],
            'addons' => [
                'create' => true,
                'edit' => true,
                'delete' => true
            ],
            'brand_settings' => [
                'view' => true,
                'edit' => true
            ],
            'api_settings' => [
                'view' => true,
                'create' => true,
                'edit' => true,
                'delete' => true
            ],
            'theme_settings' => [
                'view' => true,
                'edit' => true
            ],
            'faq_settings' => [
                'view' => true,
                'create' => true,
                'edit' => true,
                'delete' => true
            ],
            'currency_settings' => [
                'view' => true,
                'sync_rate' => true,
                'import' => true,
                'edit' => true
            ],
            'sms_data' => [
                'create' => true,
                'edit' => true,
                'delete' => true
            ],
            'device' => [
                'connect' => true,
                'delete' => true,
                'balance_verification_for' => true
            ],
            'brands' => [
                'create' => true,
                'edit' => true,
                'delete' => true
            ],
            'staff' => [
                'create' => true,
                'edit' => true,
                'delete' => true,
                'assign_brand_to' => true,
                'edit_permission' => true,
                'view_permission_list' => true,
                'delete_permission_of' => true
            ],
            'domains' => [
                'whitelist' => true,
                'edit' => true,
                'delete' => true
            ],
            'system_settings' => [
                'manage_general' => true,
                'manage_cron' => true,
                'manage_update' => true,
                'manage_import' => true
            ],
        ],
        'pages' => [
            'dashboard' => true,
            'reports' => true,
            'customers' => true,
            'transaction' => true,
            'invoice' => true,
            'payment_link' => true,
            'gateways' => true,
            'addons' => true,
            'brand_settings' => true,
            'sms_data' => true,
            'device' => true,
            'brands' => true,
            'staff_management' => true,
            'domains' => true,
            'system_settings' => true,
        ]
    ];

    return $permissionSchema ?? [];
}

    public static function countPermissions($tabKey, $tabData)
{
    $count = 0;

    if ($tabKey === 'resources') {
        foreach ($tabData as $module => $actions) {
            $count += count($actions);
        }
    }

    if ($tabKey === 'pages') {
        $count = count($tabData);
    }

    return $count;
}

    public static function hasPermission($permissions, $module, $action = 'view', $adminType = 'staff')
{
    if ($adminType === 'admin') {
        return true;
    }

    return isset($permissions['resources'][$module][$action])
        && $permissions['resources'][$module][$action] === true;
}

    public static function canAccessPage($permissions, $page, $adminType = 'staff')
{
    if ($adminType === 'admin') {
        return true;
    }

    return !empty($permissions['pages'][$page]);
}
}
