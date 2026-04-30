<?php
declare(strict_types=1);

namespace OwnPay\Controller\Admin;

use OwnPay\Container;
use OwnPay\Http\Request;
use OwnPay\Http\Response;
use OwnPay\Update\UpdateService;

final class SystemUpdateController
{
    private Container $c;
    private UpdateService $updater;

    public function __construct(Container $c, UpdateService $updater) { $this->c = $c; $this->updater = $updater; }

    public function index(Request $req): Response
    {
        $db = $this->c->get(\OwnPay\Core\Database::class);
        $history = $db->fetchAll("SELECT * FROM op_update_history ORDER BY created_at DESC LIMIT 20");
        $latestCheck = $db->fetchOne("SELECT setting_value FROM op_settings WHERE setting_key = 'latest_version'");
        $autoUpdate = $db->fetchOne("SELECT setting_value FROM op_settings WHERE setting_key = 'auto_update'");
        $currentVersion = $this->c->get('config.app')['version'] ?? '0.1.0';
        $latestVersion = $latestCheck['setting_value'] ?? $currentVersion;

        return $this->render('admin/system-update.twig', [
            'current_version'  => $currentVersion,
            'latest_version'   => $latestVersion,
            'update_available' => version_compare($latestVersion, $currentVersion, '>'),
            'update_history'   => $history,
            'auto_update'      => ($autoUpdate['setting_value'] ?? '0') === '1',
            'active_page'      => 'settings',
        ]);
    }

    public function check(Request $req): Response
    {
        $this->updater->checkForUpdate();
        $_SESSION['flash_success'] = 'Update check complete';
        return Response::redirect('/admin/system-update');
    }

    public function apply(Request $req): Response
    {
        $result = $this->updater->execute();
        if ($result['success']) {
            $_SESSION['flash_success'] = "Updated to v{$result['version']}!";
        } else {
            $_SESSION['flash_error'] = "Update failed: {$result['error']}";
        }
        return Response::redirect('/admin/system-update');
    }

    public function settings(Request $req): Response
    {
        $db = $this->c->get(\OwnPay\Core\Database::class);
        $val = $req->post('auto_update') ? '1' : '0';
        $exists = $db->fetchOne("SELECT id FROM op_settings WHERE setting_key = 'auto_update'");
        if ($exists) { $db->update("UPDATE op_settings SET setting_value = :v WHERE setting_key = 'auto_update'", ['v' => $val]); }
        else { $db->insert("INSERT INTO op_settings (setting_key, setting_value) VALUES ('auto_update', :v)", ['v' => $val]); }
        $_SESSION['flash_success'] = 'Update settings saved';
        return Response::redirect('/admin/system-update');
    }

    private function render(string $tpl, array $data = []): Response
    {
        $twig = $this->c->get(\Twig\Environment::class);
        $data['csrf_token'] = $_SESSION['csrf_token'] ?? ''; $data['app_name'] = $this->c->get('config.app')['name'] ?? 'Own Pay'; $data['current_user'] = $_SESSION['user'] ?? [];
        $data['flash_success'] = $_SESSION['flash_success'] ?? null; $data['flash_error'] = $_SESSION['flash_error'] ?? null;
        unset($_SESSION['flash_success'], $_SESSION['flash_error']);
        return Response::html($twig->render($tpl, $data));
    }
}
