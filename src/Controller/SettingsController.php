<?php
declare(strict_types=1);

namespace OwnPay\Controller;

use OwnPay\Http\RequestContext;
use OwnPay\Service\EnvironmentService;
use OwnPay\Service\PermissionGuard;

class SettingsController
{
    public static function handle(string $action, RequestContext $ctx): void
    {
        if ($action === 'cron-job-command-generate') {
            if (!$ctx->isLoggedIn) {
                echo json_encode([
                    'status' => 'false',
                    'title' => 'Request Failed',
                    'message' => 'Invalid request',
                    'csrf_token' => $ctx->csrfToken,
                ]);
                return;
            }

            if (PermissionGuard::denyUnlessCanAccess($ctx, 'system_settings')) {
                return;
            }

            if (PermissionGuard::denyUnlessHas($ctx, 'system_settings', 'manage_cron')) {
                return;
            }

            $cronCommand = bin2hex(random_bytes(8));
            EnvironmentService::set('cron-job', $cronCommand);

            echo json_encode([
                'status' => 'true',
                'title' => 'Cron Command Generated',
                'message' => 'Your cron command has been updated. You can now copy it or use it immediately.',
                'cron_command' => $cronCommand,
                'csrf_token' => $ctx->csrfToken,
            ]);
        }
    }
}
