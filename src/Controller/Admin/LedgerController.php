<?php
declare(strict_types=1);

namespace OwnPay\Controller\Admin;

use OwnPay\Container;
use OwnPay\Http\Request;
use OwnPay\Http\Response;
use OwnPay\Service\Admin\AdminSession;

/**
 * Class LedgerController
 *
 * Provides the admin portal interface for reviewing the financial ledger, showing accounts
 * entries, double-entry audit lines, and brand-scoped balances in their default currency.
 *
 * @package OwnPay\Controller\Admin
 */
final class LedgerController
{
    use AdminPageTrait;

    /**
     * @var Container The dependency injection container.
     */
    private Container $c;

    /**
     * LedgerController constructor.
     *
     * @param Container    $c       The dependency injection container.
     */
    public function __construct(Container $c)
    {
        $this->c = $c;
    }

    /**
     * Displays a brand-scoped overview of the double-entry ledger entries and account balances.
     *
     * @param Request $req The incoming HTTP request.
     *
     * @return Response The ledger dashboard page response.
     */
    public function index(Request $req): Response
    {
        $brand = $this->c->get(\OwnPay\Service\Brand\BrandContext::class);
        $mid = 0;
        if ($brand instanceof \OwnPay\Service\Brand\BrandContext) {
            $brand->resolveFromRequest($req);
            $activeId = $brand->getActiveBrandId();
            if ($activeId !== null) {
                $mid = $activeId;
            }
        }

        $ledgerService = $this->c->get(\OwnPay\Service\Payment\LedgerService::class);
        $pageVal = $req->query('page', '1');
        $page = max(1, is_scalar($pageVal) && is_numeric($pageVal) ? (int) $pageVal : 1);

        $ledger = [];
        $balance = '0.00';
        if ($ledgerService instanceof \OwnPay\Service\Payment\LedgerService) {
            $ledger = $ledgerService->entries($mid, $page, 50);

            $db = $this->c->get(\OwnPay\Core\Database::class);
            $currency = 'USD';
            if ($db instanceof \OwnPay\Core\Database) {
                $merchant = $db->fetchOne("SELECT default_currency FROM op_merchants WHERE id = :id LIMIT 1", ['id' => $mid]);
                if (is_array($merchant)) {
                    $currency = is_string($merchant['default_currency'] ?? null) ? $merchant['default_currency'] : 'USD';
                }
            }

            $balance = $ledgerService->calculateBalance($mid, $currency);
        }

        return $this->renderAdminPage('admin/ledger/index.twig', [
            'active_page'     => 'ledger',
            'ledger'          => $ledger,
            'current_balance' => $balance,
        ]);
    }
}
