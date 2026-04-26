<?php
declare(strict_types=1);

namespace OwnPay\Http\Controller;

use OwnPay\Service\UpdaterService;
use Exception;

class AdminUpdateController
{
    private UpdaterService $updaterService;

    public function __construct(UpdaterService $updaterService)
    {
        $this->updaterService = $updaterService;
    }

    /**
     * Check for newer versions from GitHub
     */
    public function checkUpdates(): void
    {
        try {
            $latest = $this->updaterService->checkLatestRelease();
            $currentVersion = defined('OP_VERSION') ? OP_VERSION : '1.0.0';

            if (version_compare($latest['version'], $currentVersion, '>')) {
                echo json_encode([
                    'status' => 'true',
                    'update_available' => true,
                    'latest_version' => $latest['version'],
                    'changelog' => $latest['notes']
                ]);
            } else {
                echo json_encode([
                    'status' => 'true',
                    'update_available' => false,
                    'message' => 'System is up to date.'
                ]);
            }
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
    }

    /**
     * Trigger OTA Update process automatically.
     */
    public function triggerAutoUpdate(): void
    {
        try {
            // 1. Fetch
            $latest = $this->updaterService->checkLatestRelease();

            // 2. Backup
            $this->updaterService->backupCurrentInstallation();

            // 3. Download
            $zipFile = $this->updaterService->downloadUpdate($latest['download_url'], $latest['version']);

            // 4. Extract and Smart Replace
            $this->updaterService->installUpdate($zipFile);

            echo json_encode(['status' => 'true', 'message' => 'System successfully updated to v' . $latest['version']]);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['status' => 'error', 'message' => 'Update failed: ' . $e->getMessage()]);
        }
    }

    /**
     * Accept a Manual ZIP upload for environments without outbound internet access.
     */
    public function manualUpdateUpload(): void
    {
        if (!isset($_FILES['update_zip']) || $_FILES['update_zip']['error'] !== UPLOAD_ERR_OK) {
            echo json_encode(['status' => 'error', 'message' => 'Please provide a valid update .zip file.']);
            return;
        }

        $tempFile = $_FILES['update_zip']['tmp_name'];

        // Security checks: Check MIME type and extension
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $tempFile);
        finfo_close($finfo);

        if ($mime !== 'application/zip') {
            echo json_encode(['status' => 'error', 'message' => 'Invalid file format. Only ZIP archives are supported.']);
            return;
        }

        try {
            // Backup
            $this->updaterService->backupCurrentInstallation();

            // Force move uploaded file to secure temp location
            $targetPath = sys_get_temp_dir() . '/manual_update_' . time() . '.zip';
            move_uploaded_file($tempFile, $targetPath);

            // Extract and Smart Replace directly using the service
            $this->updaterService->installUpdate($targetPath);

            // Cleanup
            @unlink($targetPath);

            echo json_encode(['status' => 'true', 'message' => 'Manual update completed successfully.']);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['status' => 'error', 'message' => 'Manual update failed: ' . $e->getMessage()]);
        }
    }
}
