<?php
declare(strict_types=1);

namespace OwnPay\Service;

use Exception;
use ZipArchive;

class UpdaterService
{
    private string $githubRepo;
    private string $tempDir;
    private string $backupDir;
    private string $rootDir;
    private array $excludeDirs;

    public function __construct(string $githubRepo = 'YOUR_ORG/ownpay')
    {
        $this->githubRepo = $githubRepo;
        $this->rootDir = realpath(__DIR__ . '/../../');
        $this->tempDir = $this->rootDir . '/app/temp/update';
        $this->backupDir = $this->rootDir . '/app/backups';

        // Directories/files that should not be overwritten during core update
        $this->excludeDirs = [
            'op-config.php',
            '.env',
            'media',
            'app/temp',
            'app/backups',
            'vendor' // We will run composer instead of overriding vendor
        ];

        $this->ensureDirectories();
    }

    private function ensureDirectories(): void
    {
        if (!is_dir($this->tempDir)) {
            mkdir($this->tempDir, 0755, true);
        }
        if (!is_dir($this->backupDir)) {
            mkdir($this->backupDir, 0755, true);
        }
    }

    /**
     * Poll GitHub for the latest release version and download URL.
     */
    public function checkLatestRelease(): array
    {
        $url = "https://api.github.com/repos/{$this->githubRepo}/releases/latest";

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_USERAGENT, 'OwnPay-AutoUpdate');
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || !$response) {
            throw new Exception("Failed to fetch latest release from GitHub API. HTTP Code: $httpCode");
        }

        $data = json_decode($response, true);
        if (!$data || !isset($data['tag_name']) || !isset($data['zipball_url'])) {
            throw new Exception("Invalid response format from GitHub API.");
        }

        return [
            'version' => $data['tag_name'],
            'download_url' => $data['zipball_url'],
            'notes' => $data['body'] ?? ''
        ];
    }

    /**
     * Download the specified URL to the temp directory.
     */
    public function downloadUpdate(string $downloadUrl, string $version): string
    {
        $zipFile = $this->tempDir . "/update-{$version}.zip";

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $downloadUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
        curl_setopt($ch, CURLOPT_USERAGENT, 'OwnPay-AutoUpdate');

        $file = fopen($zipFile, 'w+');
        if (!$file) {
            throw new Exception("Cannot open file for writing: $zipFile");
        }
        curl_setopt($ch, CURLOPT_FILE, $file);

        $result = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);
        fclose($file);

        if ($result === false) {
            $realZip = realpath($zipFile);
            $realTemp = realpath($this->tempDir);
            if ($realZip !== false && $realTemp !== false && strpos($realZip, $realTemp . DIRECTORY_SEPARATOR) === 0) {
                unlink($realZip);
            }
            throw new Exception("Failed to download update: $error");
        }

        return $zipFile;
    }

    /**
     * Creates a ZIP backup of the current installation.
     */
    public function backupCurrentInstallation(): string
    {
        $backupFile = $this->backupDir . '/backup-' . date('Y-m-d_H-i-s') . '.zip';
        $zip = new ZipArchive();

        if ($zip->open($backupFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new Exception("Cannot create backup zip file.");
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->rootDir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            $relativePath = str_replace($this->rootDir . '/', '', $item->getPathname());

            // Skip backup/temp directories
            if (str_starts_with($relativePath, 'app/temp') || str_starts_with($relativePath, 'app/backups')) {
                continue;
            }

            if ($item->isDir()) {
                $zip->addEmptyDir($relativePath);
            } else {
                $zip->addFile($item->getPathname(), $relativePath);
            }
        }

        $zip->close();
        return $backupFile;
    }

    /**
     * Extract the updated ZIP and perform smart overwrite.
     */
    public function installUpdate(string $zipFile): bool
    {
        $extractPath = $this->tempDir . '/extracted';
        if (is_dir($extractPath)) {
            $this->deleteDirectory($extractPath);
        }
        mkdir($extractPath, 0755, true);

        $zip = new ZipArchive();
        if ($zip->open($zipFile) !== true) {
            throw new Exception("Cannot open update zip file.");
        }
        $zip->extractTo($extractPath);
        $zip->close();

        // GitHub zipballs contain a root wrap folder (e.g. Org-Repo-commitHash)
        $extractedFolders = scandir($extractPath);
        $updateSource = $extractPath;
        foreach ($extractedFolders as $folder) {
            if ($folder !== '.' && $folder !== '..' && is_dir($extractPath . '/' . $folder)) {
                $updateSource = $extractPath . '/' . $folder;
                break;
            }
        }

        // Perform smart overwrite
        $this->smartCopy($updateSource, $this->rootDir);

        // Run post update hooks
        $this->runPostUpdateHooks();

        // Cleanup
        $this->deleteDirectory($this->tempDir);

        return true;
    }

    /**
     * Recursively copy files, skipping excluded directories.
     */
    private function smartCopy(string $source, string $dest): void
    {
        $dir = opendir($source);
        @mkdir($dest);

        while (false !== ($file = readdir($dir))) {
            if (($file != '.') && ($file != '..')) {
                $srcFile = $source . '/' . $file;
                $destFile = $dest . '/' . $file;
                $relativeDest = str_replace($this->rootDir . '/', '', $destFile);

                $skip = false;
                foreach ($this->excludeDirs as $exclude) {
                    if (str_starts_with($relativeDest, $exclude)) {
                        $skip = true;
                        break;
                    }
                }

                if ($skip) {
                    continue;
                }

                if (is_dir($srcFile)) {
                    $this->smartCopy($srcFile, $destFile);
                } else {
                    copy($srcFile, $destFile);
                }
            }
        }
        closedir($dir);
    }

    private function deleteDirectory(string $dir): void
    {
        if (!file_exists($dir))
            return;

        $it = new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS);
        $files = new \RecursiveIteratorIterator($it, \RecursiveIteratorIterator::CHILD_FIRST);

        foreach ($files as $file) {
            $realFile = $file->getRealPath();
            $realBase = realpath($this->tempDir) ?: realpath($this->rootDir);
            if ($realFile === false || $realBase === false || strpos($realFile, $realBase . DIRECTORY_SEPARATOR) !== 0) {
                continue; // Skip files outside expected boundaries
            }
            if ($file->isDir()) {
                rmdir($realFile);
            } else {
                unlink($realFile);
            }
        }
        rmdir($dir);
    }

    private function runPostUpdateHooks(): void
    {
        // 1. Composer update (safe: no shell interpolation via proc_open array)
        $composerCmd = ['composer', 'install', '--no-dev', '--optimize-autoloader', '-d', $this->rootDir];
        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = @proc_open($composerCmd, $descriptors, $pipes);
        if (is_resource($process)) {
            fclose($pipes[0]);
            // Drain stdout and stderr to prevent the child process from blocking
            // on a full pipe buffer before we call proc_close().
            stream_get_contents($pipes[1]);
            stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exitCode = proc_close($process);
            if ($exitCode !== 0) {
                \OwnPay\Service\Logger::app()->warning(
                    'composer install exited with non-zero status',
                    ['exit_code' => $exitCode]
                );
            }
        }

        // 2. Clear OpCache
        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }

        // 3. Database migrations
        $upgradeScript = $this->rootDir . '/updates/upgrade.php';
        $realUpgrade = realpath($upgradeScript);
        $realRoot = realpath($this->rootDir);
        if ($realUpgrade !== false && $realRoot !== false && strpos($realUpgrade, $realRoot . DIRECTORY_SEPARATOR) === 0) {
            require_once $realUpgrade;
            unlink($realUpgrade); // Remove after success
        }
    }
}
