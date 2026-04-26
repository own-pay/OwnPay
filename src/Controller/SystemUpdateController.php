<?php
declare(strict_types=1);

namespace OwnPay\Controller;

use OwnPay\Http\RequestContext;
use OwnPay\Service\EnvironmentService;
use OwnPay\Service\PermissionGuard;

class SystemUpdateController
{
    public static function handle(string $action, ?RequestContext $ctx = null): void
    {
        global $OwnPay_current_version;
        $ctx ??= $GLOBALS['requestContext'] ?? throw new \RuntimeException('RequestContext not available');
        $global_user_login = $ctx->isLoggedIn;
        $new_csrf_token = $ctx->csrfToken;
        $op_demo_mode = $ctx->demoMode;

        if ($action == "system-settings-update-setting") {
            if ($global_user_login == true) {
                if (!empty($op_demo_mode)) {
                    echo json_encode(['status' => "false", 'title' => 'Demo Restriction', 'message' => 'This feature is disabled in the demo version.', 'csrf_token' => $new_csrf_token]);
                } else {
                    if (!PermissionGuard::canAccess($ctx, 'system_settings') || !PermissionGuard::has($ctx, 'system_settings', 'manage_update')) {
                        echo json_encode(['status' => 'false', 'title' => 'Access denied', 'message' => 'You need permission to perform this action. Please contact the admin.', 'csrf_token' => $new_csrf_token]);
                        exit();
                    }

                    $request = \OwnPay\Http\Request::createFromGlobals();

                    $update_channel = $request->post('update_channel', '');
                    $automatic_update = $request->post('automatic_update', '');
                    $create_backup = $request->post('create_backup', '');

                    EnvironmentService::set('system-settings-update_channel', $update_channel);
                    EnvironmentService::set('system-settings-automatic_update', $automatic_update);
                    EnvironmentService::set('system-settings-create_backup', $create_backup);

                    echo json_encode(['status' => 'true', 'title' => 'Settings Updated', 'message' => 'Your changes have been saved successfully.', 'csrf_token' => $new_csrf_token]);
                }
            } else {
                echo json_encode(['status' => 'false', 'title' => 'Request Failed', 'message' => 'Invalid request', 'csrf_token' => $new_csrf_token]);
            }
        }

        if ($action == "system-settings-update-check") {
            if ($global_user_login == true) {
                if (!empty($op_demo_mode)) {
                    echo json_encode(['status' => "false", 'title' => 'Demo Restriction', 'message' => 'This feature is disabled in the demo version.', 'csrf_token' => $new_csrf_token]);
                } else {
                    if (!PermissionGuard::canAccess($ctx, 'system_settings') || !PermissionGuard::has($ctx, 'system_settings', 'manage_update')) {
                        echo json_encode(['status' => 'false', 'title' => 'Access denied', 'message' => 'You need permission to perform this action. Please contact the admin.', 'csrf_token' => $new_csrf_token]);
                        exit();
                    }

                    EnvironmentService::set('last-auto-update-check', getCurrentDatetime('Y-m-d H:i:s'));

                    $manifest = json_decode(\OwnPay\Service\HttpClient::get('https://updates.OwnPay.com/manifest.json') ?? '', true);

                    $current_code = $OwnPay_current_version['version_code'];
                    $current_name = $OwnPay_current_version['version_name'];
                    $version_hash = $OwnPay_current_version['version_hash'];

                    $channelPref = EnvironmentService::get('system-settings-update_channel');
                    if ($channelPref === '' || empty($channelPref) || $channelPref === 'stable') {
                        $update_channel = 'stable';
                    } else {
                        $update_channel = 'beta';
                    }

                    $channel_data = $manifest['channels'][$update_channel] ?? null;

                    $update_available = false;
                    $latest_name = null;
                    $latest_code = null;
                    $latest_hash = null;

                    if ($channel_data) {
                        $latest_name = $channel_data['latest_version_name'];
                        $latest_code = $channel_data['latest_version_code'];

                        $latest_hash = '';
                        foreach ($channel_data['versions'] as $version) {
                            if ($version['version_code'] === $latest_code) {
                                $latest_hash = $version['checksum'];
                                break;
                            }
                        }

                        if (version_compare($latest_code, $current_code, '>')) {
                            $update_available = true;
                        }
                    }

                    if ($update_available == true) {
                        EnvironmentService::set('last-update-version-name', $latest_name);
                        EnvironmentService::set('last-update-version-hash', $latest_hash);
                        EnvironmentService::set('last-update-version', $latest_code);

                        echo json_encode(['status' => 'true', 'title' => 'Update Available', 'message' => 'A new system update is available. Please update to get the latest features and improvements.', 'csrf_token' => $new_csrf_token]);
                    } else {
                        EnvironmentService::set('last-update-version-name', $current_name);
                        EnvironmentService::set('last-update-version-hash', $version_hash);
                        EnvironmentService::set('last-update-version', $current_code);

                        echo json_encode(['status' => 'true', 'title' => 'System Up to Date', 'message' => 'Everything is up to date. No updates were found.', 'csrf_token' => $new_csrf_token]);
                    }
                }
            } else {
                echo json_encode(['status' => 'false', 'title' => 'Request Failed', 'message' => 'Invalid request', 'csrf_token' => $new_csrf_token]);
            }
        }


        if ($action == "system-settings-update-download") {
            if ($global_user_login == true) {
                if (!empty($op_demo_mode)) {
                    echo json_encode(['status' => "false", 'title' => 'Demo Restriction', 'message' => 'This feature is disabled in the demo version.', 'csrf_token' => $new_csrf_token]);
                } else {
                    if (!PermissionGuard::canAccess($ctx, 'system_settings') || !PermissionGuard::has($ctx, 'system_settings', 'manage_update')) {
                        echo json_encode(['status' => 'false', 'title' => 'Access denied', 'message' => 'You need permission to perform this action. Please contact the admin.', 'csrf_token' => $new_csrf_token]);
                        exit();
                    }

                    $lasted_update_version = EnvironmentService::get('last-update-version');
                    $update_available = false;

                    if (version_compare($lasted_update_version, $OwnPay_current_version['version_code'], '>')) {
                        $update_available = true;
                    }

                    if ($update_available == true) {
                        $url = "https://updates.OwnPay.com/download.php?version=$lasted_update_version";

                        $saveDir = __DIR__ . '/../../media/storage/updates/';

                        if (!is_dir($saveDir)) {
                            mkdir($saveDir, 0755, true);
                        }

                        $saveTo = $saveDir . $lasted_update_version . '.zip';

                        // Initialize curl
                        $ch = curl_init($url);
                        $fp = fopen($saveTo, 'w');

                        curl_setopt($ch, CURLOPT_FILE, $fp);          // write to file
                        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true); // follow redirects
                        curl_setopt($ch, CURLOPT_FAILONERROR, true);    // HTTP >= 400 will fail
                        curl_setopt($ch, CURLOPT_TIMEOUT, 120);        // max 2 minutes
                        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);  // connection timeout

                        $success = curl_exec($ch);
                        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                        $error = curl_error($ch);


                        fclose($fp);

                        if (!$success || $httpCode >= 400) {
                            echo json_encode(['status' => 'false', 'title' => 'Download Failed', 'message' => 'The latest update could not be downloaded. Please check your internet connection or try again later.', 'csrf_token' => $new_csrf_token]);
                        } else {
                            echo json_encode(['status' => 'true', 'title' => 'Update Downloaded', 'message' => 'The latest version has been downloaded successfully and is ready to be installed.', 'csrf_token' => $new_csrf_token]);
                        }
                    } else {
                        echo json_encode(['status' => 'true', 'title' => 'System Up to Date', 'message' => 'Everything is up to date. No updates were found.', 'csrf_token' => $new_csrf_token]);
                    }
                }
            } else {
                echo json_encode(['status' => 'false', 'title' => 'Request Failed', 'message' => 'Invalid request', 'csrf_token' => $new_csrf_token]);
            }
        }


        if ($action == "system-settings-update-install") {
            if ($global_user_login == true) {
                if (!empty($op_demo_mode)) {
                    echo json_encode(['status' => "false", 'title' => 'Demo Restriction', 'message' => 'This feature is disabled in the demo version.', 'csrf_token' => $new_csrf_token]);
                } else {
                    if (!PermissionGuard::canAccess($ctx, 'system_settings') || !PermissionGuard::has($ctx, 'system_settings', 'manage_update')) {
                        echo json_encode(['status' => 'false', 'title' => 'Access denied', 'message' => 'You need permission to perform this action. Please contact the admin.', 'csrf_token' => $new_csrf_token]);
                        exit();
                    }

                    $lasted_update_version = EnvironmentService::get('last-update-version');
                    $lasted_update_version_hash = EnvironmentService::get('last-update-version-hash');
                    $update_available = false;

                    if (version_compare($lasted_update_version, $OwnPay_current_version['version_code'], '>')) {
                        $update_available = true;
                    }

                    if ($update_available == true) {
                        $root = realpath(__DIR__ . '/../../');
                        $storage = __DIR__ . '/../../media/storage/';

                        $backupDir = $storage . 'backup/';
                        $tempDir = $storage . "temp/$lasted_update_version/";
                        $zipFile = $storage . "updates/$lasted_update_version.zip";

                        if (hash_file('sha256', $zipFile) !== $lasted_update_version_hash) { // SEC-05 fix: SHA-256 replaces weak SHA-1
                            echo json_encode(['status' => 'false', 'title' => 'Request Failed', 'message' => 'Update file checksum mismatch (SHA-256)! Possible corruption or tampering.', 'csrf_token' => $new_csrf_token]);
                            exit();
                        }

                        @mkdir($backupDir, 0755, true);
                        @mkdir($tempDir, 0755, true);

                        zipFolder($root, "$backupDir/" . $OwnPay_current_version['version_code'] . ".zip");

                        backupDatabasePDO("$backupDir/db_" . $OwnPay_current_version['version_code'] . ".sql");

                        file_put_contents("$root/.maintenance", 'updating');

                        extractUpdate($zipFile, $tempDir);

                        copyFolder($tempDir, $root);

                        if (file_exists("$tempDir/update.sql")) {
                            runSql("$tempDir/update.sql");
                        }

                        deleteFolder($tempDir);
                        unlink("$root/.maintenance");

                        echo json_encode(['status' => 'true', 'title' => 'Installation Successful', 'message' => 'The latest version has been installed successfully. Your system is now up to date.', 'csrf_token' => $new_csrf_token]);
                    } else {
                        echo json_encode(['status' => 'true', 'title' => 'System Up to Date', 'message' => 'Everything is up to date. No updates were found.', 'csrf_token' => $new_csrf_token]);
                    }
                }
            } else {
                echo json_encode(['status' => 'false', 'title' => 'Request Failed', 'message' => 'Invalid request', 'csrf_token' => $new_csrf_token]);
            }
        }

        if ($action == "system-settings-import") {
            if ($global_user_login == true) {
                if (!empty($op_demo_mode)) {
                    echo json_encode(['status' => "false", 'title' => 'Demo Restriction', 'message' => 'This feature is disabled in the demo version.', 'csrf_token' => $new_csrf_token]);
                } else {
                    if (!PermissionGuard::canAccess($ctx, 'system_settings') || !PermissionGuard::has($ctx, 'system_settings', 'manage_import')) {
                        echo json_encode(['status' => 'false', 'title' => 'Access denied', 'message' => 'You need permission to perform this action. Please contact the admin.', 'csrf_token' => $new_csrf_token]);
                        exit();
                    }

                    if (!isset($_FILES['zip_file']) || $_FILES['zip_file']['error'] !== UPLOAD_ERR_OK) {
                        echo json_encode([
                            'status' => 'false',
                            'title' => 'Upload Failed',
                            'message' => 'No file uploaded or upload error occurred.',
                            'csrf_token' => $new_csrf_token
                        ]);
                        exit;
                    }

                    $uploadedFile = $_FILES['zip_file'];
                    $max_file_size = 100 * 1024 * 1024; // 100MB

                    if ($uploadedFile['size'] > $max_file_size) {
                        echo json_encode([
                            'status' => 'false',
                            'title' => 'File Too Large',
                            'message' => 'File exceeds maximum allowed size of 100MB.',
                            'csrf_token' => $new_csrf_token
                        ]);
                        exit;
                    }

                    $fileExt = strtolower(pathinfo($uploadedFile['name'], PATHINFO_EXTENSION));
                    if ($fileExt !== 'zip') {
                        echo json_encode([
                            'status' => 'false',
                            'title' => 'Invalid File',
                            'message' => 'Only ZIP files are allowed.',
                            'csrf_token' => $new_csrf_token
                        ]);
                        exit;
                    }

                    // MIME type validation
                    $finfo = finfo_open(FILEINFO_MIME_TYPE);
                    $mimeType = finfo_file($finfo, $uploadedFile['tmp_name']);
                    finfo_close($finfo);
                    if ($mimeType !== 'application/zip' && $mimeType !== 'application/x-zip-compressed') {
                        echo json_encode([
                            'status' => 'false',
                            'title' => 'Invalid File',
                            'message' => 'File MIME type is not a valid ZIP archive.',
                            'csrf_token' => $new_csrf_token
                        ]);
                        exit;
                    }

                    $zip = new ZipArchive;
                    if ($zip->open($uploadedFile['tmp_name']) !== true) {
                        echo json_encode([
                            'status' => 'false',
                            'title' => 'Invalid File',
                            'message' => 'Uploaded file is not a valid ZIP.',
                            'csrf_token' => $new_csrf_token
                        ]);
                        exit;
                    }

                    // Path traversal and per-entry size validation
                    $maxEntrySize = 50 * 1024 * 1024; // 50MB per entry
                    for ($i = 0; $i < $zip->numFiles; $i++) {
                        $entry = $zip->getNameIndex($i);
                        if (str_contains($entry, '..') || str_starts_with($entry, '/')) {
                            $zip->close();
                            echo json_encode([
                                'status' => 'false',
                                'title' => 'Security Error',
                                'message' => 'ZIP contains a potentially dangerous path: ' . basename($entry),
                                'csrf_token' => $new_csrf_token
                            ]);
                            exit;
                        }
                        $stat = $zip->statIndex($i);
                        if ($stat['size'] > $maxEntrySize) {
                            $zip->close();
                            echo json_encode([
                                'status' => 'false',
                                'title' => 'File Too Large',
                                'message' => 'ZIP entry exceeds 50MB limit: ' . basename($entry),
                                'csrf_token' => $new_csrf_token
                            ]);
                            exit;
                        }
                    }
                    $zip->close();

                    $root = realpath(__DIR__ . '/../../');
                    $storage = __DIR__ . '/../../media/storage/';
                    $updatesDir = $storage . "import/";

                    if (!is_dir($storage))
                        mkdir($storage, 0755, true);
                    if (!is_dir($updatesDir))
                        mkdir($updatesDir, 0755, true);

                    // Sanitize filename: strip path separators and restrict to safe characters
                    $sanitizedName = preg_replace('/[^a-zA-Z0-9_\-]/', '_', pathinfo(basename($uploadedFile['name']), PATHINFO_FILENAME));
                    $tempDir = $storage . "temp/" . $sanitizedName . "/";
                    if (!is_dir($tempDir))
                        mkdir($tempDir, 0755, true);

                    $destination = $updatesDir . $sanitizedName . '.zip';
                    if (!move_uploaded_file($uploadedFile['tmp_name'], $destination)) {
                        echo json_encode([
                            'status' => 'false',
                            'title' => 'Upload Failed',
                            'message' => 'Failed to move uploaded file.',
                            'csrf_token' => $new_csrf_token
                        ]);
                        exit;
                    }

                    try {
                        extractUpdate($destination, $tempDir);
                        copyFolder($tempDir, $root);

                        $sqlFile = $tempDir . "sql.sql";
                        if (file_exists($sqlFile))
                            runSql($sqlFile);

                        deleteFolder($tempDir);

                        if (file_exists($destination)) {
                            unlink($destination); // deletes the file
                        }

                        echo json_encode([
                            'status' => 'true',
                            'title' => 'Import Successful',
                            'message' => 'ZIP file imported and applied successfully!',
                            'csrf_token' => $new_csrf_token
                        ]);

                    } catch (Exception $e) {
                        error_log('[OwnPay] Import failed: ' . $e->getMessage());
                        echo json_encode([
                            'status' => 'false',
                            'title' => 'Server Error',
                            'message' => 'Import failed. Please check server logs for details.',
                            'csrf_token' => $new_csrf_token
                        ]);
                        exit;
                    }
                }
            } else {
                echo json_encode(['status' => 'false', 'title' => 'Request Failed', 'message' => 'Invalid request', 'csrf_token' => $new_csrf_token]);
            }
        }

    }
}
