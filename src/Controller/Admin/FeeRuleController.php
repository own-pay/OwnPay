<?php
declare(strict_types=1);

namespace OwnPay\Controller\Admin;

use OwnPay\Container;
use OwnPay\Http\Request;
use OwnPay\Http\Response;
use OwnPay\Service\Admin\AdminSession;
use OwnPay\Service\Brand\BrandContext;
use OwnPay\Service\Payment\CurrencyService;
use OwnPay\Repository\FeeRuleRepository;
use OwnPay\Repository\GatewayRepository;
use OwnPay\Repository\MerchantRepository;
use OwnPay\Service\System\InputSanitizer;

/**
 * Class FeeRuleController
 *
 * Coordinates administrative CRUD actions for managing transaction processing fee rules.
 *
 * @package OwnPay\Controller\Admin
 */
final class FeeRuleController
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
     * @var FeeRuleRepository The fee rules repository interface.
     */
    private FeeRuleRepository $feeRuleRepo;

    /**
     * @var GatewayRepository The gateway definition storage repository.
     */
    private GatewayRepository $gatewayRepo;

    /**
     * @var BrandContext The brand context manager.
     */
    private BrandContext $brand;

    /**
     * @var CurrencyService The currency service layer.
     */
    private CurrencyService $currencySvc;

    /**
     * FeeRuleController constructor.
     *
     * @param Container         $c           The dependency injection container.
     * @param AdminSession      $session     The administrative session service.
     * @param FeeRuleRepository $feeRuleRepo The fee rules repository interface.
     * @param GatewayRepository $gatewayRepo The gateway definition storage repository.
     * @param BrandContext      $brand       The brand context manager.
     * @param CurrencyService   $currencySvc The currency service layer.
     */
    public function __construct(
        Container $c,
        AdminSession $session,
        FeeRuleRepository $feeRuleRepo,
        GatewayRepository $gatewayRepo,
        BrandContext $brand,
        CurrencyService $currencySvc
    ) {
        $this->c = $c;
        $this->session = $session;
        $this->feeRuleRepo = $feeRuleRepo;
        $this->gatewayRepo = $gatewayRepo;
        $this->brand = $brand;
        $this->currencySvc = $currencySvc;
    }

    /**
     * Renders the overview index of registered fee rules.
     *
     * @param Request $req The incoming HTTP request.
     * @return Response The rendered index view page.
     */
    public function index(Request $req): Response
    {
        $this->brand->resolveFromRequest($req);
        $isGlobal = $this->session->isSuperadmin() && $this->brand->isGlobalView();
        $mid = $this->brand->getActiveBrandId();

        $pageVal = $req->query('page', '1');
        $page = max(1, is_int($pageVal) || is_string($pageVal) ? (int)$pageVal : 1);

        if ($isGlobal) {
            $paginated = $this->feeRuleRepo->forAllTenants()->paginate($page, 20);
        } else {
            if ($mid === null) {
                throw new \RuntimeException('Active brand ID is not set.');
            }
            $paginated = $this->feeRuleRepo->forTenant($mid)->paginateScoped($page, 20);
        }

        $merchantRepo = $this->c->get(MerchantRepository::class);
        if (!$merchantRepo instanceof MerchantRepository) {
            throw new \RuntimeException('MerchantRepository service unavailable');
        }

        /** @var array<int, array<string, mixed>> $items */
        $items = isset($paginated['items']) && is_array($paginated['items']) ? $paginated['items'] : [];

        foreach ($items as &$item) {
            $merchantIdVal = $item['merchant_id'] ?? null;
            if (is_int($merchantIdVal) || (is_string($merchantIdVal) && $merchantIdVal !== '')) {
                $merchant = $merchantRepo->find((int)$merchantIdVal);
                $item['brand_name'] = is_array($merchant) ? ($merchant['name'] ?? '-') : '-';
            } else {
                $item['brand_name'] = 'Global';
            }

            $gatewaySlugVal = $item['gateway_slug'] ?? null;
            if (is_string($gatewaySlugVal) && $gatewaySlugVal !== '') {
                $gateway = $this->gatewayRepo->findBySlug($gatewaySlugVal);
                $item['gateway_name'] = is_array($gateway) ? ($gateway['name'] ?? $gatewaySlugVal) : $gatewaySlugVal;
            } else {
                $item['gateway_name'] = 'All Gateways';
            }
        }
        unset($item);

        $paginated['items'] = $items;

        return $this->renderAdminPage('admin/fee-rules/index.twig', [
            'rules'          => $paginated['items'],
            'pagination'     => [
                'page'        => $paginated['page'],
                'total_pages' => $paginated['pages'],
                'total'       => $paginated['total']
            ],
            'active_page'    => 'fee-rules',
            'is_global_view' => $isGlobal
        ]);
    }

    /**
     * Renders the form to create a new fee rule.
     *
     * @param Request $req The incoming HTTP request.
     * @return Response The rendered creation page.
     */
    public function create(Request $req): Response
    {
        $this->brand->resolveFromRequest($req);
        $mid = $this->brand->getActiveBrandId();
        $isGlobal = $this->session->isSuperadmin() && $this->brand->isGlobalView();

        $currencies = $this->currencySvc->listAll();
        $activeCurrencies = array_filter($currencies, fn($c) => ($c['status'] ?? 'active') === 'active');

        $gateways = $this->gatewayRepo->listActive();

        return $this->renderAdminPage('admin/fee-rules/create.twig', [
            'active_page'     => 'fee-rules',
            'currencies'      => $activeCurrencies,
            'gateways'        => $gateways,
            'is_global'       => $isGlobal,
            'active_brand_id' => $mid
        ]);
    }

    /**
     * Stores a new fee rule in the database.
     *
     * @param Request $req The incoming HTTP request.
     * @return Response The HTTP redirect response.
     */
    public function store(Request $req): Response
    {
        $this->brand->resolveFromRequest($req);
        $mid = $this->brand->getActiveBrandId();
        $isGlobal = $this->session->isSuperadmin() && $this->brand->isGlobalView();

        $currencyVal = $req->post('currency', 'BDT');
        $currency = InputSanitizer::string(is_string($currencyVal) ? $currencyVal : 'BDT');
        $gatewayVal = $req->post('gateway_slug');
        $gatewaySlug = is_string($gatewayVal) && $gatewayVal !== '' ? InputSanitizer::string($gatewayVal) : null;
        $typeVal = $req->post('type', 'percentage');
        $type = InputSanitizer::string(is_string($typeVal) ? $typeVal : 'percentage');
        
        $valueVal = $req->post('value', '0.00');
        $value = $type !== 'tiered' ? InputSanitizer::decimal($valueVal) : '0.0000';

        $minVal = $req->post('min_fee');
        $minFee = is_string($minVal) && $minVal !== '' ? InputSanitizer::decimal($minVal) : null;
        $maxVal = $req->post('max_fee');
        $maxFee = is_string($maxVal) && $maxVal !== '' ? InputSanitizer::decimal($maxVal) : null;
        
        $statusVal = $req->post('status', 'active');
        $status = is_string($statusVal) && $statusVal === 'active' ? 'active' : 'inactive';

        if ($isGlobal) {
            $selectedMerchantVal = $req->post('merchant_id');
            $merchantId = is_numeric($selectedMerchantVal) && (int)$selectedMerchantVal > 0 ? (int)$selectedMerchantVal : null;
        } else {
            $merchantId = $mid;
        }

        $tiers = null;
        if ($type === 'tiered') {
            $rawTiers = $req->post('tiers');
            $tiersArray = [];
            if (is_array($rawTiers)) {
                foreach ($rawTiers as $tier) {
                    if (is_array($tier)) {
                        $tierLimitVal = $tier['limit'] ?? '';
                        $tierLimit = is_numeric($tierLimitVal) ? (string)$tierLimitVal : null;
                        
                        $tierTypeVal = $tier['type'] ?? 'percentage';
                        $tierType = is_string($tierTypeVal) && in_array($tierTypeVal, ['flat', 'percentage'], true) ? $tierTypeVal : 'percentage';
                        
                        $tierValueVal = $tier['value'] ?? '0.00';
                        $tierValue = InputSanitizer::decimal($tierValueVal);
                        
                        $tiersArray[] = [
                            'limit' => $tierLimit === null ? null : (float)$tierLimit,
                            'type'  => $tierType,
                            'value' => (float)$tierValue
                        ];
                    }
                }
            }
            $tiers = json_encode($tiersArray);
        }

        $data = [
            'merchant_id'  => $merchantId,
            'gateway_slug' => $gatewaySlug,
            'type'         => $type,
            'value'        => $value,
            'min_fee'      => $minFee,
            'max_fee'      => $maxFee,
            'currency'     => $currency,
            'tiers'        => $tiers,
            'status'       => $status
        ];

        if ($isGlobal) {
            $this->feeRuleRepo->forAllTenants()->create($data);
        } else {
            if ($merchantId === null) {
                throw new \RuntimeException('Tenant ID required for scoped operations.');
            }
            $this->feeRuleRepo->forTenant($merchantId)->createScoped($data);
        }

        $this->session->flashSuccess('Fee rule created successfully.');
        return Response::redirect('/admin/fee-rules');
    }

    /**
     * Renders the edit form for an existing fee rule.
     *
     * @param Request $req The incoming HTTP request.
     * @return Response The rendered edit page.
     */
    public function edit(Request $req): Response
    {
        $id = (int)$req->param('id');
        $this->brand->resolveFromRequest($req);
        $mid = $this->brand->getActiveBrandId();
        $isGlobal = $this->session->isSuperadmin() && $this->brand->isGlobalView();

        if ($isGlobal) {
            $rule = $this->feeRuleRepo->forAllTenants()->find($id);
        } else {
            if ($mid === null) {
                throw new \RuntimeException('Active brand ID is not set.');
            }
            $rule = $this->feeRuleRepo->forTenant($mid)->findScoped($id);
        }

        if (!$rule) {
            $this->session->flashError('Fee rule not found.');
            return Response::redirect('/admin/fee-rules');
        }

        $currencies = $this->currencySvc->listAll();
        $activeCurrencies = array_filter($currencies, fn($c) => ($c['status'] ?? 'active') === 'active');
        $gateways = $this->gatewayRepo->listActive();

        $tiers = [];
        if (!empty($rule['tiers'])) {
            if (is_string($rule['tiers'])) {
                $tiers = json_decode($rule['tiers'], true) ?: [];
            } elseif (is_array($rule['tiers'])) {
                $tiers = $rule['tiers'];
            }
        }

        return $this->renderAdminPage('admin/fee-rules/edit.twig', [
            'active_page'     => 'fee-rules',
            'rule'            => $rule,
            'currencies'      => $activeCurrencies,
            'gateways'        => $gateways,
            'is_global'       => $isGlobal,
            'tiers'           => $tiers,
            'active_brand_id' => $mid
        ]);
    }

    /**
     * Persists updates to an existing fee rule.
     *
     * @param Request $req The incoming HTTP request.
     * @return Response The HTTP redirect response.
     */
    public function update(Request $req): Response
    {
        $id = (int)$req->param('id');
        $this->brand->resolveFromRequest($req);
        $mid = $this->brand->getActiveBrandId();
        $isGlobal = $this->session->isSuperadmin() && $this->brand->isGlobalView();

        if ($isGlobal) {
            $rule = $this->feeRuleRepo->forAllTenants()->find($id);
        } else {
            if ($mid === null) {
                throw new \RuntimeException('Active brand ID is not set.');
            }
            $rule = $this->feeRuleRepo->forTenant($mid)->findScoped($id);
        }

        if (!$rule) {
            $this->session->flashError('Fee rule not found.');
            return Response::redirect('/admin/fee-rules');
        }

        $currencyVal = $req->post('currency', 'BDT');
        $currency = InputSanitizer::string(is_string($currencyVal) ? $currencyVal : 'BDT');
        $gatewayVal = $req->post('gateway_slug');
        $gatewaySlug = is_string($gatewayVal) && $gatewayVal !== '' ? InputSanitizer::string($gatewayVal) : null;
        $typeVal = $req->post('type', 'percentage');
        $type = InputSanitizer::string(is_string($typeVal) ? $typeVal : 'percentage');
        
        $valueVal = $req->post('value', '0.00');
        $value = $type !== 'tiered' ? InputSanitizer::decimal($valueVal) : '0.0000';

        $minVal = $req->post('min_fee');
        $minFee = is_string($minVal) && $minVal !== '' ? InputSanitizer::decimal($minVal) : null;
        $maxVal = $req->post('max_fee');
        $maxFee = is_string($maxVal) && $maxVal !== '' ? InputSanitizer::decimal($maxVal) : null;
        
        $statusVal = $req->post('status', 'active');
        $status = is_string($statusVal) && $statusVal === 'active' ? 'active' : 'inactive';

        if ($isGlobal) {
            $selectedMerchantVal = $req->post('merchant_id');
            $merchantId = is_numeric($selectedMerchantVal) && (int)$selectedMerchantVal > 0 ? (int)$selectedMerchantVal : null;
        } else {
            $merchantId = $mid;
        }

        $tiers = null;
        if ($type === 'tiered') {
            $rawTiers = $req->post('tiers');
            $tiersArray = [];
            if (is_array($rawTiers)) {
                foreach ($rawTiers as $tier) {
                    if (is_array($tier)) {
                        $tierLimitVal = $tier['limit'] ?? '';
                        $tierLimit = is_numeric($tierLimitVal) ? (string)$tierLimitVal : null;
                        
                        $tierTypeVal = $tier['type'] ?? 'percentage';
                        $tierType = is_string($tierTypeVal) && in_array($tierTypeVal, ['flat', 'percentage'], true) ? $tierTypeVal : 'percentage';
                        
                        $tierValueVal = $tier['value'] ?? '0.00';
                        $tierValue = InputSanitizer::decimal($tierValueVal);
                        
                        $tiersArray[] = [
                            'limit' => $tierLimit === null ? null : (float)$tierLimit,
                            'type'  => $tierType,
                            'value' => (float)$tierValue
                        ];
                    }
                }
            }
            $tiers = json_encode($tiersArray);
        }

        $data = [
            'merchant_id'  => $merchantId,
            'gateway_slug' => $gatewaySlug,
            'type'         => $type,
            'value'        => $value,
            'min_fee'      => $minFee,
            'max_fee'      => $maxFee,
            'currency'     => $currency,
            'tiers'        => $tiers,
            'status'       => $status
        ];

        if ($isGlobal) {
            $this->feeRuleRepo->forAllTenants()->update($id, $data);
        } else {
            if ($mid === null) {
                throw new \RuntimeException('Tenant ID required for scoped operations.');
            }
            $this->feeRuleRepo->forTenant($mid)->updateScoped($id, $data);
        }

        $this->session->flashSuccess('Fee rule updated successfully.');
        return Response::redirect('/admin/fee-rules');
    }

    /**
     * Deletes a fee rule from the database.
     *
     * @param Request $req The incoming HTTP request.
     * @return Response The HTTP redirect response.
     */
    public function delete(Request $req): Response
    {
        $id = (int)$req->param('id');
        $this->brand->resolveFromRequest($req);
        $mid = $this->brand->getActiveBrandId();
        $isGlobal = $this->session->isSuperadmin() && $this->brand->isGlobalView();

        if ($isGlobal) {
            $rule = $this->feeRuleRepo->forAllTenants()->find($id);
        } else {
            if ($mid === null) {
                throw new \RuntimeException('Active brand ID is not set.');
            }
            $rule = $this->feeRuleRepo->forTenant($mid)->findScoped($id);
        }

        if (!$rule) {
            $this->session->flashError('Fee rule not found.');
            return Response::redirect('/admin/fee-rules');
        }

        if ($isGlobal) {
            $this->feeRuleRepo->forAllTenants()->delete($id);
        } else {
            if ($mid === null) {
                throw new \RuntimeException('Tenant ID required for scoped operations.');
            }
            $this->feeRuleRepo->forTenant($mid)->deleteScoped($id);
        }

        $this->session->flashSuccess('Fee rule deleted.');
        return Response::redirect('/admin/fee-rules');
    }
}
