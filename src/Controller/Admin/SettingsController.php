<?php
declare(strict_types=1);

namespace OwnPay\Controller\Admin;

use OwnPay\Container;
use OwnPay\Http\Request;
use OwnPay\Http\Response;
use OwnPay\Event\EventManager;

final class SettingsController
{
    private Container $c;
    private EventManager $events;

    public function __construct(Container $c, EventManager $events) { $this->c = $c; $this->events = $events; }

    public function index(Request $req): Response
    {
        $db = $this->c->get(\OwnPay\Core\Database::class);
        $rows = $db->fetchAll("SELECT setting_key, setting_value FROM op_settings");
        $settings = [];
        foreach ($rows as $r) { $settings[$r['setting_key']] = $r['setting_value']; }
        if (isset($settings['faqs'])) { $settings['faqs'] = json_decode($settings['faqs'], true); }

        $currencies = $db->fetchAll("SELECT code, name FROM op_currencies ORDER BY code");
        $timezones = \DateTimeZone::listIdentifiers();

        return $this->render('admin/settings/index.twig', [
            'settings'    => $settings,
            'currencies'  => $currencies,
            'timezones'   => $timezones,
            'active_page' => 'settings',
        ]);
    }

    public function save(Request $req): Response
    {
        $db = $this->c->get(\OwnPay\Core\Database::class);
        $data = $req->post();
        unset($data['_csrf']);

        // Handle FAQs as JSON
        if (isset($data['faqs'])) { $data['faqs'] = json_encode(array_values($data['faqs'])); }

        foreach ($data as $key => $value) {
            if (is_array($value)) { $value = json_encode($value); }
            $exists = $db->fetchOne("SELECT id FROM op_settings WHERE setting_key = :k", ['k' => $key]);
            if ($exists) {
                $db->update("UPDATE op_settings SET setting_value = :v WHERE setting_key = :k", ['v' => $value, 'k' => $key]);
            } else {
                $db->insert("INSERT INTO op_settings (setting_key, setting_value) VALUES (:k, :v)", ['k' => $key, 'v' => $value]);
            }
        }

        $this->events->doAction('settings.saved', $data);
        $_SESSION['flash_success'] = 'Settings saved';
        return Response::redirect('/admin/settings');
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
