<?php
declare(strict_types=1);

namespace OwnPay\Controller\Admin;

use OwnPay\Container;
use OwnPay\Http\Request;
use OwnPay\Http\Response;
use OwnPay\Service\Admin\AdminSession;

final class LedgerController
{
    use AdminPageTrait;

    private Container $c;
    private AdminSession $session;

    public function __construct(Container $c, AdminSession $session)
    {
        $this->c = $c;
        $this->session = $session;
    }

    public function index(Request $req): Response
    {
        $brand = $this->c->get(\OwnPay\Service\Brand\BrandContext::class);
        $brand->resolveFromRequest($req);
        $mid = $brand->getActiveBrandId();

        $ledgerService = $this->c->get(\OwnPay\Service\Payment\LedgerService::class);
        $page = max(1, (int) $req->query('page', '1'));

        $ledger = $ledgerService->entries($mid, $page, 50);

        // BUG-43 FIX: Resolve brand's default currency instead of hardcoding 'BDT'.
        // Hardcoded BDT shows wrong balance for any non-BDT brand.
        $db = $this->c->get(\OwnPay\Core\Database::class);
        $merchant = $db->fetchOne("SELECT default_currency FROM op_merchants WHERE id = :id LIMIT 1", ['id' => $mid]);
        $currency = $merchant['default_currency'] ?? 'USD';

        $balance = $ledgerService->calculateBalance($mid, $currency);

        return $this->renderAdminPage('admin/ledger/index.twig', [
            'active_page'     => 'ledger',
            'ledger'          => $ledger,
            'current_balance' => $balance,
        ]);
    }
}
