<?php
declare(strict_types=1);

namespace OwnPay\Controller\Admin;

use OwnPay\Container;
use OwnPay\Http\Request;
use OwnPay\Http\Response;
use OwnPay\Service\Payment\ReconciliationService;

final class BalanceVerificationController
{
    private Container $c;
    private ReconciliationService $recon;

    public function __construct(Container $c, ReconciliationService $recon) { $this->c = $c; $this->recon = $recon; }

    public function index(Request $req): Response
    {
        $mid = (int) $req->getAttribute('merchant_id');
        $db = $this->c->get(\OwnPay\Core\Database::class);
        $currencies = $db->fetchAll("SELECT DISTINCT currency FROM op_transactions WHERE merchant_id = :mid", ['mid' => $mid]);

        $results = [];
        foreach ($currencies as $cur) {
            $results[] = array_merge(['currency' => $cur['currency']], $this->recon->reconcile($mid, $cur['currency']));
        }

        return $this->render('admin/reports.twig', ['balance_results' => $results, 'active_page' => 'reports']);
    }

    private function render(string $tpl, array $data = []): Response
    {
        $twig = $this->c->get(\Twig\Environment::class);
        $data['csrf_token'] = $_SESSION['csrf_token'] ?? ''; $data['app_name'] = $this->c->get('config.app')['name'] ?? 'Own Pay'; $data['current_user'] = $_SESSION['user'] ?? [];
        return Response::html($twig->render($tpl, $data));
    }
}
