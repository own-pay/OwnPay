<?php
declare(strict_types=1);

namespace AnirbanPay\Controller;

use AnirbanPay\Http\RequestContext;

class ThemeController
{
    public static function handle(string $action, ?RequestContext $ctx = null): void
    {
        $ctx ??= $GLOBALS['requestContext'] ?? throw new \RuntimeException('RequestContext not available');
        $global_user_login = $ctx->isLoggedIn;
        $global_response_permission = $ctx->permissionResponse;
        $global_user_response = $ctx->userResponse;
        $new_csrf_token = $ctx->csrfToken;
        $db_prefix = $ctx->dbPrefix;
        $global_response_brand = $ctx->brandResponse;
        $site_url = $ctx->siteUrl;

        if ($action == "themes-new-active") {
            if ($global_user_login == true) {
                if (!canAccessPage(json_decode($global_response_permission['response'][0]['permission'], true), 'brand_settings', $global_user_response['response'][0]['role'])) {
                    echo json_encode(['status' => 'false', 'title' => 'Access denied', 'message' => 'You need permission to perform this action. Please contact the admin.', 'csrf_token' => $new_csrf_token]);
                    exit();
                }

                if (!hasPermission(json_decode($global_response_permission['response'][0]['permission'], true), 'theme_settings', 'edit', $global_user_response['response'][0]['role'])) {
                    echo json_encode(['status' => 'false', 'title' => 'Access denied', 'message' => 'You need permission to perform this action. Please contact the admin.', 'csrf_token' => $new_csrf_token]);
                    exit();
                }

                $request = \AnirbanPay\Http\Request::createFromGlobals();

                $slug = $request->post('slug', '');

                $values = [$slug, getCurrentDatetime('Y-m-d H:i:s')];

                $condition = "id = :id";
                $whereParams = [':id' => $global_response_brand['response'][0]['id']];

                updateData($db_prefix . 'brands', $columns, $values, $condition, $whereParams);

                echo json_encode(['status' => 'true', 'title' => 'Theme Activated', 'message' => 'The theme has been activated successfully.', 'csrf_token' => $new_csrf_token]);
            } else {
                echo json_encode(['status' => 'false', 'title' => 'Request Failed', 'message' => 'Invalid request', 'csrf_token' => $new_csrf_token]);
            }
        }

        if ($action == "theme-setting-update") {
            if ($global_user_login == true) {
                if (!canAccessPage(json_decode($global_response_permission['response'][0]['permission'], true), 'brand_settings', $global_user_response['response'][0]['role'])) {
                    echo json_encode(['status' => 'false', 'title' => 'Access denied', 'message' => 'You need permission to perform this action. Please contact the admin.', 'csrf_token' => $new_csrf_token]);
                    exit();
                }

                if (!hasPermission(json_decode($global_response_permission['response'][0]['permission'], true), 'theme_settings', 'edit', $global_user_response['response'][0]['role'])) {
                    echo json_encode(['status' => 'false', 'title' => 'Access denied', 'message' => 'You need permission to perform this action. Please contact the admin.', 'csrf_token' => $new_csrf_token]);
                    exit();
                }

                $request = \AnirbanPay\Http\Request::createFromGlobals();

                $themeSlug = $global_response_brand['response'][0]['theme'];
                $postData = $request->postAll(false);

                foreach ($postData as $key => $value) {
                    if (in_array($key, ['action', 'csrf_token']))
                        continue;

                    $optionName = $themeSlug . '-' . $key;

                    // Multi-select arrays -> JSON
                    if (is_array($value)) {
                        $value = json_encode($request->post($key, [])); // sanitize inner array
                    } else {
                        $value = $request->post($key); // sanitize string
                    }

                    // Checkbox unchecked -> 0
                    if (!isset($postData[$key]) && strpos((string) $key, 'is_') === 0) {
                        $value = 0;
                    }

                    set_env($optionName, $value, $global_response_brand['response'][0]['brand_id']);  // save in DB
                }

                foreach ($_FILES as $key => $file) {
                    // Skip empty uploads
                    if (empty($file['name']))
                        continue;

                    $max_file_size = 5 * 1024 * 1024;

                    $optionName = $themeSlug . '-' . $key;

                    $mediaUpload = json_decode(uploadImage($_FILES[$key] ?? null, $max_file_size), true);
                    if ($mediaUpload['status'] == true) {
                        set_env($optionName, $site_url . 'media/storage/' . $mediaUpload['file'], $global_response_brand['response'][0]['brand_id']);
                    }
                }

                echo json_encode(['status' => 'true', 'title' => 'Theme Setting Updated', 'message' => 'The theme setting has been updated successfully.', 'csrf_token' => $new_csrf_token]);
            } else {
                echo json_encode(['status' => 'false', 'title' => 'Request Failed', 'message' => 'Invalid request', 'csrf_token' => $new_csrf_token]);
            }
        }

        if (in_array($action, ["transaction-list", "transaction-bulk-action", "transaction-delete", "transaction-ipn", "transaction-verify"])) {
            \AnirbanPay\Controller\TransactionController::handle($action);
            exit;
        }

        if (in_array($action, ["gateways-bulk-action", "gateway-setting-update", "gateway-setting-create"])) {
            \AnirbanPay\Controller\GatewayController::handle($action);
            exit;
        }

        if (in_array($action, ["addons-create", "addons-list", "addons-delete", "addons-bulk-action", "addon-setting-update", "addon-configuration-update"])) {
            \AnirbanPay\Controller\AddonController::handle($action, $ctx);
            exit;
        }

        if (in_array($action, ["customer-list", "customers-create", "customers-bulk-action", "customers-delete", "customers-info-byID", "customers-edit"])) {
            \AnirbanPay\Controller\CustomerController::handle($action);
            exit;
        }

        if (in_array($action, ["invoice-list", "invoice-create", "invoice-edit", "invoice-manageStatus", "invoice-bulk-action", "invoice-delete"])) {
            \AnirbanPay\Controller\InvoiceController::handle($action);
            exit;
        }

    }
}
