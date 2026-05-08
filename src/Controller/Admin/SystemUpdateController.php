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

final class SystemUpdateController
{
    use AdminPageTrait;

    private Container $c;
    private AdminSession $session;
    private UpdateService $updater;
    private SettingsRepository $settingsRepo;
    private UpdateHistoryRepository $historyRepo;

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

    public function index(Request $req): Response
    {
        $cacheFile = dirname(__DIR__, 3) . '/storage/cache/update_check.json';
        $page   = max(1, (int)($req->query('page') ?? 1));
        $offset = ($page - 1) * 10;

        $history = $this->historyRepo->listFinished(10, $offset);
        $total   = $this->historyRepo->countFinished();

        $latestCheck = null;
        if (is_file($cacheFile) && (time() - filemtime($cacheFile)) < 3600) {
            $latestCheck = json_decode(file_get_contents($cacheFile), true);
        }

        $autoUpdate     = $this->settingsRepo->get('general', 'auto_update', '0');
        $currentVersion = $this->c->get('config.app')['version'] ?? '0.1.0';
        $latestVersion  = $latestCheck['version'] ?? $currentVersion;

        return $this->renderAdminPage('admin/system-update.twig', [
            'current_version'  => $currentVersion,
            'latest_version'   => $latestVersion,
            'update_available' => version_compare($latestVersion, $currentVersion, '>'),
            'update_info'      => $latestCheck,
            'update_history'   => $history,
            'auto_update'      => $autoUpdate === '1',
            'active_page'      => 'settings',
        ]);
    }

    public function check(Request $req): Response
    {
        try {
            $result = $this->updater->check();
            if (!empty($result['error'])) {
                $this->session->flashError('Unable to reach update server. ' . ($result['message'] ?? 'Check your internet connection and try again.'));
            } elseif ($result['available']) {
                $cacheFile = dirname(__DIR__, 3) . '/storage/cache/update_check.json';
                @file_put_contents($cacheFile, json_encode($result));
                $this->session->flashSuccess("Update available: v{$result['version']}");
            } else {
                $this->session->flashSuccess('You are on the latest version.');
            }
        } catch (\Throwable $e) {
            $this->session->flashError('Update check failed: ' . $e->getMessage());
        }
        return Response::redirect('/admin/system-update');
    }

    public function install(Request $req): Response
    {
        $version = $req->post('version', '');
        $url     = $req->post('url', '');
        if ($version === '' || $url === '') {
            $this->session->flashError('Missing version or download URL.');
            return Response::redirect('/admin/system-update');
        }
        $result = $this->updater->execute($version, $url);
        if ($result['success']) {
            $this->session->flashSuccess("Updated to v{$version}!");
        } else {
            $this->session->flashError("Update failed: {$result['error']}");
        }
        return Response::redirect('/admin/system-update');
    }

    public function settings(Request $req): Response
    {
        $val = $req->post('auto_update') ? '1' : '0';
        $this->settingsRepo->set('general', 'auto_update', $val);
        $this->session->flashSuccess('Update settings saved');
        return Response::redirect('/admin/system-update');
    }
}
