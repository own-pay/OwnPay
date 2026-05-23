<?php
declare(strict_types=1);

namespace OwnPay\Controller\Admin;

use OwnPay\Container;
use OwnPay\Service\Admin\AdminSession;
use OwnPay\Http\Request;
use OwnPay\Http\Response;
use OwnPay\Update\UpdateService;
use OwnPay\Repository\SettingsRepository;
use OwnPay\Repository\UpdateHistoryRepository;

/**
 * Controller for checking and installing system updates.
 */
final class SystemUpdateController
{
    use AdminPageTrait;

    /**
     * The dependency injection container.
     */
    private Container $c;

    /**
     * The admin session manager.
     */
    private AdminSession $session;

    /**
     * The update service instance.
     */
    private UpdateService $updater;

    /**
     * The settings repository.
     */
    private SettingsRepository $settingsRepo;

    /**
     * The update history repository.
     */
    private UpdateHistoryRepository $historyRepo;

    /**
     * SystemUpdateController constructor.
     *
     * @param Container $c The dependency injection container.
     * @param AdminSession $session The admin session manager.
     * @param UpdateService $updater The update service instance.
     * @param SettingsRepository $settingsRepo The settings repository.
     * @param UpdateHistoryRepository $historyRepo The update history repository.
     */
    public function __construct(
        Container $c,
        AdminSession $session,
        UpdateService $updater,
        SettingsRepository $settingsRepo,
        UpdateHistoryRepository $historyRepo
    ) {
        $this->c            = $c;
        $this->session      = $session;
        $this->updater      = $updater;
        $this->settingsRepo = $settingsRepo;
        $this->historyRepo  = $historyRepo;
    }

    /**
     * Render the main system updates dashboard page.
     *
     * @param Request $req The incoming HTTP request.
     * @return Response The HTTP response rendering the updates page.
     * @throws \Exception If database queries fail.
     */
    public function index(Request $req): Response
    {
        $cacheFile = dirname(__DIR__, 3) . '/storage/cache/update_check.json';
        $pageVal = $req->query('page') ?? 1;
        $page   = max(1, is_scalar($pageVal) && is_numeric($pageVal) ? (int)$pageVal : 1);
        $offset = ($page - 1) * 10;

        $history = $this->historyRepo->listFinished(10, $offset);
        $total   = $this->historyRepo->countFinished();

        $latestCheck = null;
        if (is_file($cacheFile) && (time() - filemtime($cacheFile)) < 3600) {
            $content = file_get_contents($cacheFile);
            if (is_string($content)) {
                $latestCheck = json_decode($content, true);
            }
        }

        $autoUpdate     = $this->settingsRepo->get('general', 'auto_update', '0');
        $configApp = $this->c->get('config.app');
        $currentVersion = is_array($configApp) && isset($configApp['version']) && is_string($configApp['version']) ? $configApp['version'] : '0.1.0';
        $latestVersion  = is_array($latestCheck) && isset($latestCheck['version']) && is_string($latestCheck['version']) ? $latestCheck['version'] : $currentVersion;

        return $this->renderAdminPage('admin/system-update.twig', [
            'current_version'  => $currentVersion,
            'latest_version'   => $latestVersion,
            'update_available' => version_compare($latestVersion, $currentVersion, '>'),
            'update_info'      => $latestCheck,
            'update_history'   => $history,
            'total_updates'    => $total,
            'auto_update'      => $autoUpdate === '1',
            'active_page'      => 'system-update',
        ]);
    }

    /**
     * Trigger an update availability check.
     *
     * @param Request $req The incoming HTTP request.
     * @return Response The HTTP redirect response.
     */
    public function check(Request $req): Response
    {
        try {
            $result = $this->updater->check();
            /** @phpstan-ignore-next-line */
            if (!empty($result['error'])) {
                /** @phpstan-ignore-next-line */
                $this->session->flashError('Unable to reach update server. ' . ($result['message'] ?? 'Check your internet connection and try again.'));
            } elseif ($result['available']) {
                $cacheFile = dirname(__DIR__, 3) . '/storage/cache/update_check.json';
                @file_put_contents($cacheFile, json_encode($result));
                $version = $result['version'] ?? 'unknown';
                $this->session->flashSuccess("Update available: v{$version}");
            } else {
                $this->session->flashSuccess('You are on the latest version.');
            }
        } catch (\Throwable $e) {
            $this->session->flashError('Update check failed: ' . $e->getMessage());
        }
        return Response::redirect('/admin/system-update');
    }

    /**
     * Install a selected system update version.
     *
     * @param Request $req The incoming HTTP request.
     * @return Response The HTTP redirect response.
     */
    public function install(Request $req): Response
    {
        $versionRaw  = $req->post('version', '');
        $version = is_string($versionRaw) ? $versionRaw : '';
        if ($version === '') {
            $this->session->flashError('Missing version.');
            return Response::redirect('/admin/system-update');
        }
        $result = $this->updater->execute($version);
        if ($result['success']) {
            $this->session->flashSuccess("Updated to v{$version}!");
        } else {
            $error = $result['error'] ?? 'unknown error';
            $this->session->flashError("Update failed: {$error}");
        }
        return Response::redirect('/admin/system-update');
    }

    /**
     * Save auto-update system settings.
     *
     * @param Request $req The incoming HTTP request.
     * @return Response The HTTP redirect response.
     */
    public function settings(Request $req): Response
    {
        $val = $req->post('auto_update') ? '1' : '0';
        $this->settingsRepo->set('general', 'auto_update', $val);
        $this->session->flashSuccess('Update settings saved');
        return Response::redirect('/admin/system-update');
    }
}
