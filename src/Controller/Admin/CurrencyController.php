<?php
declare(strict_types=1);

namespace OwnPay\Controller\Admin;

use OwnPay\Container;
use OwnPay\Service\Admin\AdminSession;
use OwnPay\Http\Request;
use OwnPay\Http\Response;
use OwnPay\Service\Payment\CurrencyService;

/**
 * Class CurrencyController
 *
 * Coordinates administrative currency actions, letting administrators configure supported currencies.
 *
 * @package OwnPay\Controller\Admin
 */
final class CurrencyController
{
    use AdminPageTrait;

    /**
     * @var Container The dependency injection container.
     */
    private Container $c;

    /**
     * @var AdminSession The administrative session service.
     */
    private AdminSession $session;

    /**
     * CurrencyController constructor.
     *
     * @param Container    $c       The dependency injection container.
     * @param AdminSession $session The administrative session service.
     */
    public function __construct(Container $c, AdminSession $session)
    {
        $this->c       = $c;
        $this->session = $session;
    }

    /**
     * Redirects to the payment settings tab.
     *
     * @param Request $req The incoming HTTP request.
     *
     * @return Response The HTTP redirect response.
     */
    public function index(Request $req): Response
    {
        return Response::redirect('/admin/settings/payment');
    }

    /**
     * Creates or updates a currency configuration in the system.
     *
     * @param Request $req The incoming HTTP request.
     *
     * @return Response The HTTP redirect response.
     */
    public function update(Request $req): Response
    {
        $codeVal = $req->post('code');
        $nameVal = $req->post('name');
        $symbolVal = $req->post('symbol', '');
        $statusVal = $req->post('status', 'active');
        $decVal = $req->post('decimal_places', '2');
        $rateVal = $req->post('rate');

        $code = is_string($codeVal) ? trim($codeVal) : '';
        $name = is_string($nameVal) ? trim($nameVal) : '';
        $symbol = is_string($symbolVal) ? trim($symbolVal) : '';
        $status = is_string($statusVal) ? trim($statusVal) : 'active';
        $dec = is_int($decVal) || is_string($decVal) ? (int)$decVal : 2;
        $rate = is_scalar($rateVal) ? trim((string) $rateVal) : '';

        if ($code !== '' && $name !== '') {
            $svc = $this->c->get(CurrencyService::class);
            if (!$svc instanceof CurrencyService) {
                throw new \RuntimeException('CurrencyService unavailable');
            }
            $codeUpper = strtoupper($code);
            $svc->upsert(
                $codeUpper,
                $name,
                $symbol,
                $status,
                max(0, min(8, $dec))
            );

            if ($rate !== '' && is_numeric($rate)) {
                $svc->updateExchangeRate($codeUpper, $rate);
            }
        }

        $this->session->flashSuccess('Currency saved');
        return Response::redirect('/admin/settings/payment');
    }

    /**
     * Toggles status between active and inactive.
     *
     * @param Request $req The incoming HTTP request.
     * @return Response The HTTP redirect response.
     */
    public function toggle(Request $req): Response
    {
        $codeVal = $req->param('code', '');
        $code = strtoupper(trim((string) $codeVal));
        if ($code === '') {
            $this->session->flashError('Invalid currency code');
            return Response::redirect('/admin/settings/payment');
        }

        $db = $this->c->get(\OwnPay\Core\Database::class);
        if (!$db instanceof \OwnPay\Core\Database) {
            throw new \RuntimeException('Database connection unavailable');
        }

        $cur = $db->fetchOne("SELECT status FROM op_currencies WHERE code = :code", ['code' => $code]);
        if ($cur) {
            $newStatus = ($cur['status'] ?? 'active') === 'active' ? 'inactive' : 'active';
            $db->execute("UPDATE op_currencies SET status = :status WHERE code = :code", [
                'status' => $newStatus,
                'code' => $code
            ]);
            $this->session->flashSuccess("Currency {$code} status set to {$newStatus}");
        } else {
            $this->session->flashError("Currency {$code} not found");
        }

        return Response::redirect('/admin/settings/payment');
    }

    /**
     * Instantly triggers the exchange rate synchronization job.
     *
     * @param Request $req The incoming HTTP request.
     * @return Response The HTTP redirect response.
     */
    public function syncRates(Request $req): Response
    {
        $svc = $this->c->get(CurrencyService::class);
        if (!$svc instanceof CurrencyService) {
            throw new \RuntimeException('CurrencyService unavailable');
        }

        $res = $svc->syncRates();
        if ($res['success']) {
            $updatedCount = $res['updated'] ?? 0;
            $this->session->flashSuccess("Exchange rates synchronized successfully. Updated {$updatedCount} currencies.");
        } else {
            $this->session->flashError("Rates synchronization failed: " . ($res['error'] ?? 'Unknown error'));
        }

        return Response::redirect('/admin/settings/payment');
    }
}
