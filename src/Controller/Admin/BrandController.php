<?php
declare(strict_types=1);

namespace OwnPay\Controller\Admin;

use OwnPay\Container;
use OwnPay\Service\Admin\AdminSession;
use OwnPay\Http\Request;
use OwnPay\Http\Response;
use OwnPay\Service\Brand\BrandContext;
use OwnPay\Service\System\InputSanitizer;
use OwnPay\Service\System\AuditService;
use OwnPay\Repository\MerchantRepository;

/**
 * Class BrandController
 *
 * Coordinates administrative brand (merchant) actions such as creation, updates, deletions,
 * and contextual switching between brands.
 *
 * @package OwnPay\Controller\Admin
 */
final class BrandController
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
     * @var BrandContext The brand context manager.
     */
    private BrandContext $brand;

    /**
     * @var MerchantRepository The repository for merchant records.
     */
    private MerchantRepository $merchants;

    /**
     * @var AuditService The system auditing service.
     */
    private AuditService $audit;

    /**
     * BrandController constructor.
     *
     * @param Container          $c         The dependency injection container.
     * @param AdminSession       $session   The administrative session service.
     * @param BrandContext       $brand     The brand context manager.
     * @param MerchantRepository $merchants The repository for merchant records.
     * @param AuditService       $audit     The system auditing service.
     */
    public function __construct(Container $c, AdminSession $session, BrandContext $brand, MerchantRepository $merchants, AuditService $audit)
    {
        $this->c         = $c;
        $this->session   = $session;
        $this->brand     = $brand;
        $this->merchants = $merchants;
        $this->audit     = $audit;
    }

    /**
     * Lists all registered brands/stores alongside their configured domains.
     *
     * @param Request $req The incoming HTTP request.
     *
     * @return Response The brand listing dashboard page.
     */
    public function index(Request $req): Response
    {
        $brands = $this->merchants->listWithDomains();
        return $this->renderAdminPage('admin/brands/index.twig', [
            'brand_list'  => $brands,
            'active_page' => 'brands',
        ]);
    }

    /**
     * Renders the brand creation page.
     *
     * @param Request $req The incoming HTTP request.
     *
     * @return Response The brand creation page.
     */
    public function create(Request $req): Response
    {
        $transSvc = $this->c->get(\OwnPay\Service\System\TranslationService::class);
        $languages = $transSvc instanceof \OwnPay\Service\System\TranslationService ? $transSvc->getAllLanguages() : [['code' => 'en', 'name' => 'English']];

        return $this->renderAdminPage('admin/brands/edit.twig', [
            'brand'       => null,
            'active_page' => 'brands',
            'is_new'      => true,
            'languages'   => $languages,
        ]);
    }

    /**
     * Validates and persists a newly created brand, mapping domains and initializing payment link dependencies.
     *
     * @param Request $req The incoming HTTP request.
     *
     * @return Response The redirect response to the brand index.
     */
    public function store(Request $req): Response
    {
        $postData = $req->post();
        $data = is_array($postData) ? $postData : [];
        
        $nameVal = $data['name'] ?? '';
        $name = InputSanitizer::string(is_string($nameVal) ? $nameVal : '');
        $emailVal = $data['email'] ?? '';
        $email = InputSanitizer::email(is_string($emailVal) ? $emailVal : '');

        if ($name === '' || $email === '') {
            $this->session->flashError('Name and email are required');
            return Response::redirect('/admin/brands/create');
        }

        $slug = preg_replace('/[^a-z0-9\-]/', '', strtolower(str_replace(' ', '-', $name)));
        $slug = is_string($slug) ? $slug : '';

        $emailField = $data['email'] ?? '';
        $phoneField = $data['phone'] ?? '';
        $timezoneField = $data['timezone'] ?? 'Asia/Dhaka';
        $currencyField = $data['default_currency'] ?? 'BDT';
        $statusField = $data['status'] ?? 'active';

        $defaultSettings = [
            'logo' => null,
            'favicon' => null,
            'primary_color' => '#0D9488',
            'accent_color' => '#0F766E',
            'support_email' => is_string($emailField) ? $emailField : '',
            'footer_text' => '',
            'language' => 'en',
            'checkout_success_msg' => '',
            'checkout_pending_msg' => '',
            'checkout_failed_msg' => '',
            'custom_css' => '',
            'custom_js' => '',
            'show_powered_by' => 0,
        ];

        $merchantId = (int) $this->merchants->createMerchant([
            'name'             => $name,
            'slug'             => $slug,
            'email'            => is_string($emailField) ? $emailField : '',
            'phone'            => is_string($phoneField) ? $phoneField : '',
            'timezone'         => is_string($timezoneField) ? $timezoneField : 'Asia/Dhaka',
            'default_currency' => is_string($currencyField) ? $currencyField : 'BDT',
            'status'           => is_string($statusField) ? $statusField : 'active',
            'logo_path'        => null,
            'settings'         => json_encode($defaultSettings, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ]);

        $customDomainVal = $req->post('custom_domain', '');
        $domain = trim(is_string($customDomainVal) ? $customDomainVal : '');
        if ($domain !== '') {
            try {
                $domainService = $this->c->get(\OwnPay\Service\Domain\DomainService::class);
                if ($domainService instanceof \OwnPay\Service\Domain\DomainService) {
                    $domainService->map($merchantId, $domain);
                }
            } catch (\Throwable $e) {
                $this->session->flashError('Brand created but domain error: ' . $e->getMessage());
                return Response::redirect('/admin/brands');
            }
        }

        $this->audit->log('brand.created', 'merchant', $merchantId, null, ['name' => $name]);

        // Auto-create default payment link for new brand
        $currencyVal = $data['default_currency'] ?? 'BDT';
        $currency = is_string($currencyVal) ? $currencyVal : 'BDT';
        $paymentLinkService = $this->c->get(\OwnPay\Service\Payment\PaymentLinkService::class);
        if ($paymentLinkService instanceof \OwnPay\Service\Payment\PaymentLinkService) {
            $paymentLinkService->ensureDefault($merchantId, $name, $slug, $currency);
        }

        $this->session->flashSuccess('Brand created successfully');
        return Response::redirect('/admin/brands');
    }

    /**
     * Displays details for a single brand instance.
     *
     * @param Request $req The incoming HTTP request.
     *
     * @return Response The brand details edit page.
     */
    public function show(Request $req): Response
    {
        $id = (int) $req->param('id');
        $brand = $this->merchants->findWithDomain($id);

        if (!$brand) {
            $this->session->flashError('Brand not found');
            return Response::redirect('/admin/brands');
        }

        // Decode JSON settings to array for easy UI access
        $settingsVal = $brand['settings'] ?? '';
        if (is_string($settingsVal) && $settingsVal !== '') {
            $brand['theme'] = json_decode($settingsVal, true) ?: [];
        } else {
            $brand['theme'] = [];
        }

        $transSvc = $this->c->get(\OwnPay\Service\System\TranslationService::class);
        $languages = $transSvc instanceof \OwnPay\Service\System\TranslationService ? $transSvc->getAllLanguages() : [['code' => 'en', 'name' => 'English']];

        return $this->renderAdminPage('admin/brands/edit.twig', [
            'brand'       => $brand,
            'active_page' => 'brands',
            'is_new'      => false,
            'languages'   => $languages,
        ]);
    }

    /**
     * Updates an existing brand's configurations and domain routing.
     *
     * @param Request $req The incoming HTTP request.
     *
     * @return Response The redirect response to the brand details page or index.
     */
    public function update(Request $req): Response
    {
        $id   = (int) $req->param('id');
        $postData = $req->post();
        $data = is_array($postData) ? $postData : [];
        
        $nameVal = $data['name'] ?? '';
        $name = InputSanitizer::string(is_string($nameVal) ? $nameVal : '');
        $emailVal = $data['email'] ?? '';
        $email = is_string($emailVal) ? $emailVal : '';

        if ($name === '' || $email === '') {
            $this->session->flashError('Name and email are required');
            return Response::redirect("/admin/brands/{$id}");
        }

        // Fetch current brand to retain existing settings / paths
        $existing = $this->merchants->find($id);
        if (!$existing) {
            $this->session->flashError('Brand not found');
            return Response::redirect('/admin/brands');
        }

        $emailField = $data['email'] ?? '';
        $phoneField = $data['phone'] ?? '';
        $timezoneField = $data['timezone'] ?? 'Asia/Dhaka';
        $currencyField = $data['default_currency'] ?? 'BDT';
        $statusField = $data['status'] ?? 'active';

        $updateData = [
            'name'             => $name,
            'email'            => is_string($emailField) ? $emailField : '',
            'phone'            => is_string($phoneField) ? $phoneField : '',
            'timezone'         => is_string($timezoneField) ? $timezoneField : 'Asia/Dhaka',
            'default_currency' => is_string($currencyField) ? $currencyField : 'BDT',
            'status'           => is_string($statusField) ? $statusField : 'active',
            'logo_path'        => $existing['logo_path'],
            'settings'         => $existing['settings'],
        ];
        $this->merchants->updateBrand($id, $updateData);

        $customDomainVal = $req->post('custom_domain', '');
        $domain = trim(is_string($customDomainVal) ? $customDomainVal : '');
        if ($domain !== '') {
            try {
                $domainService = $this->c->get(\OwnPay\Service\Domain\DomainService::class);
                if ($domainService instanceof \OwnPay\Service\Domain\DomainService) {
                    $domainService->map($id, $domain);
                }
            } catch (\Throwable $e) {
                $this->session->flashError('Brand updated but domain error: ' . $e->getMessage());
                return Response::redirect('/admin/brands');
            }
        }

        $this->audit->log('brand.updated', 'merchant', $id, null, ['name' => $name]);
        $this->session->flashSuccess('Brand updated successfully');
        return Response::redirect('/admin/brands');
    }

    /**
     * Whether the current staff member may enter the All Brands (platform / super-admin) view.
     *
     * Superadmins always may. Other staff need the 'brands.access_all' permission, assignable per
     * role from All Brands. Permissions are populated on the request by PermissionMiddleware.
     *
     * @param Request $req The incoming HTTP request.
     * @return bool True if the user may access the All Brands view.
     */
    private function canAccessAllBrands(Request $req): bool
    {
        $perms = $req->getAttribute('user_permissions');
        return is_array($perms) && in_array('brands.access_all', $perms, true);
    }

    /**
     * Contextually switches the active administrative brand context.
     *
     * @param Request $req The incoming HTTP request.
     *
     * @return Response The redirect response back to the source page.
     */
    public function switchBrand(Request $req): Response
    {
        $id = $req->post('brand_id');
        $isSuperAdmin = !empty($_SESSION['is_superadmin']);
        $authMerchantId = $_SESSION['auth_merchant_id'] ?? 0;
        $homeMerchantId = is_scalar($authMerchantId) && is_numeric($authMerchantId) ? (int) $authMerchantId : 0;

        if ($id === 'global') {
            if (!$isSuperAdmin && !$this->canAccessAllBrands($req)) {
                $this->session->flashError('You do not have permission to access the All Brands view.');
                return Response::redirect('/admin');
            }
            $this->brand->setGlobalView(true);
            $this->brand->setActiveBrandId(0);
        } elseif (is_scalar($id) && is_numeric($id)) {
            $brandId = (int) $id;
            if (!$isSuperAdmin && $brandId !== $homeMerchantId) {
                $this->session->flashError('Permission denied to switch to this brand');
                return Response::redirect('/admin');
            }
            $this->brand->setGlobalView(false);
            $this->brand->setActiveBrandId($brandId);
        }

        $ref = $req->header('referer', '/admin');
        $host = parse_url($ref, PHP_URL_HOST);
        $path = parse_url($ref, PHP_URL_PATH);
        if (
            $path === null 
            || $path === false 
            || !str_starts_with($path, '/admin') 
            || str_contains($path, '\\') 
            || str_contains($path, '//') 
            || str_contains($path, '..')
            || (is_string($host) && strtolower($host) !== strtolower($req->host()))
        ) {
            $ref = '/admin';
        } else {
            $query = parse_url($ref, PHP_URL_QUERY);
            $fragment = parse_url($ref, PHP_URL_FRAGMENT);
            $ref = $path;
            if (is_string($query) && $query !== '') {
                $ref .= '?' . $query;
            }
            if (is_string($fragment) && $fragment !== '') {
                $ref .= '#' . $fragment;
            }
        }

        return Response::redirect($ref);
    }

    /**
     * Removes a brand instance and cascades records via foreign key constraints.
     *
     * @param Request $req The incoming HTTP request.
     *
     * @return Response The redirect response to the brand index.
     */
    public function delete(Request $req): Response
    {
        $id = (int) $req->param('id');

        // Safety: cannot delete last brand
        $allBrands = $this->merchants->listWithDomains();
        if (count($allBrands) <= 1) {
            $this->session->flashError('Cannot delete the only brand');
            return Response::redirect('/admin/brands');
        }

        // Safety: cannot delete currently active brand
        $this->brand->resolveFromRequest($req);
        $activeBrandId = $this->brand->getActiveBrandId();
        if ($id === $activeBrandId) {
            $this->session->flashError('Switch to a different brand before deleting this one');
            return Response::redirect('/admin/brands');
        }

        $brand = $this->merchants->findWithDomain($id);
        if (!$brand) {
            $this->session->flashError('Brand not found');
            return Response::redirect('/admin/brands');
        }

        // Hard delete brand + cascade handled by DB FK constraints
        $db = $this->c->get(\OwnPay\Core\Database::class);
        if ($db instanceof \OwnPay\Core\Database) {
            $db->execute("DELETE FROM op_merchants WHERE id = :id", ['id' => $id]);
        }

        $brandNameVal = $brand['name'] ?? '';
        $brandName = is_string($brandNameVal) ? $brandNameVal : '';
        $this->audit->log('brand.deleted', 'merchant', $id, ['name' => $brandName]);
        $this->session->flashSuccess("Brand '{$brandName}' deleted");
        return Response::redirect('/admin/brands');
    }
}
