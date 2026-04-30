<?php
declare(strict_types=1);

namespace OwnPay\Controller\Admin;

use OwnPay\Container;
use OwnPay\Http\Request;
use OwnPay\Http\Response;

final class CurrencyController
{
    private Container $c;

    public function __construct(Container $c) { $this->c = $c; }

    public function index(Request $req): Response
    {
        $db = $this->c->get(\OwnPay\Core\Database::class);
        $currencies = $db->fetchAll("SELECT * FROM op_currencies ORDER BY code");
        return $this->render('admin/settings/index.twig', ['currencies' => $currencies, 'active_page' => 'settings']);
    }

    public function update(Request $req): Response
    {
        $db = $this->c->get(\OwnPay\Core\Database::class);
        $data = $req->post();

        if (!empty($data['code']) && !empty($data['name'])) {
            $exists = $db->fetchOne("SELECT id FROM op_currencies WHERE code = :code", ['code' => strtoupper($data['code'])]);
            if ($exists) {
                $db->update("UPDATE op_currencies SET name = :name, symbol = :sym, status = :st WHERE code = :code", [
                    'name' => $data['name'], 'sym' => $data['symbol'] ?? '', 'st' => $data['status'] ?? 'active', 'code' => strtoupper($data['code']),
                ]);
            } else {
                $db->insert("INSERT INTO op_currencies (code, name, symbol, status) VALUES (:code, :name, :sym, :st)", [
                    'code' => strtoupper($data['code']), 'name' => $data['name'], 'sym' => $data['symbol'] ?? '', 'st' => $data['status'] ?? 'active',
                ]);
            }
        }

        $_SESSION['flash_success'] = 'Currency saved';
        return Response::redirect('/admin/settings#tab-currency');
    }

    private function render(string $tpl, array $data = []): Response
    {
        $twig = $this->c->get(\Twig\Environment::class);
        $data['csrf_token'] = $_SESSION['csrf_token'] ?? '';
        $data['app_name'] = $this->c->get('config.app')['name'] ?? 'Own Pay';
        $data['current_user'] = $_SESSION['user'] ?? [];
        return Response::html($twig->render($tpl, $data));
    }
}
