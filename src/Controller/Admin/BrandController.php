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
        return $this->renderAdminPage('admin/brands/edit.twig', [
            'brand'       => null,
            'active_page' => 'brands',
            'is_new'      => true,
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

        $merchantId = (int) $this->merchants->createMerchant([
            'name'             => $name,
            'slug'             => $slug,
            'email'            => is_string($emailField) ? $emailField : '',
            'phone'            => is_string($phoneField) ? $phoneField : '',
            'timezone'         => is_string($timezoneField) ? $timezoneField : 'Asia/Dhaka',
            'default_currency' => is_string($currencyField) ? $currencyField : 'BDT',
            'status'           => is_string($statusField) ? $statusField : 'active',
        ]);

        // Process file uploads & customizations now that we have the merchant ID
        $brandingData = $this->handleBrandUploadsAndSettings($req, $merchantId, $data);
        
        /** @var array<string, mixed> $updateData */
        $updateData = array_merge([
            'name'             => $name,
            'email'            => is_string($emailField) ? $emailField : '',
            'phone'            => is_string($phoneField) ? $phoneField : '',
            'timezone'         => is_string($timezoneField) ? $timezoneField : 'Asia/Dhaka',
            'default_currency' => is_string($currencyField) ? $currencyField : 'BDT',
            'status'           => is_string($statusField) ? $statusField : 'active',
        ], $brandingData);
        $this->merchants->updateBrand($merchantId, $updateData);

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

        return $this->renderAdminPage('admin/brands/edit.twig', [
            'brand'       => $brand,
            'active_page' => 'brands',
            'is_new'      => false,
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
        $existingSettings = [];
        if ($existing && isset($existing['settings']) && is_string($existing['settings']) && $existing['settings'] !== '') {
            $decoded = json_decode($existing['settings'], true);
            $existingSettings = is_array($decoded) ? $decoded : [];
        }

        // Process file uploads & customizations
        $existingLogoPath = ($existing && isset($existing['logo_path']) && is_string($existing['logo_path'])) ? $existing['logo_path'] : null;
        $brandingData = $this->handleBrandUploadsAndSettings($req, $id, $data, $existingLogoPath, $existingSettings);

        /** @var array<string, mixed> $updateData */
        $updateData = array_merge($data, $brandingData);
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
            if (!$isSuperAdmin) {
                $this->session->flashError('Permission denied to switch to global view');
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

    /**
     * Handles file uploads for brand logo and favicon, and aggregates all brand customizations
     * into logo_path and JSON settings fields.
     *
     * @param Request $req The incoming HTTP request.
     * @param int $merchantId The brand's merchant ID.
     * @param array<string, mixed> $data The incoming POST/customization data.
     * @param string|null $existingLogoPath The existing brand logo file path, if any.
     * @param array<string, mixed> $existingSettings The existing brand settings, if any.
     * @return array<string, mixed> The compiled branding data.
     */
    private function handleBrandUploadsAndSettings(Request $req, int $merchantId, array $data, ?string $existingLogoPath = null, array $existingSettings = []): array
    {
        $uploadDir = dirname(__DIR__, 3) . '/public/assets/uploads/brands/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        $logoPath = $existingLogoPath;
        $faviconPath = $existingSettings['favicon'] ?? null;

        // Securely instantiate FilesystemService targeting the public assets folder.
        $fs = new \OwnPay\Service\System\FilesystemService(dirname(__DIR__, 3) . '/public/assets');

        // Process Brand Logo File
        $logoFile = $req->file('brand_logo');
        if ($logoFile !== null && ($logoFile['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
            try {
                if (isset($logoFile['name'], $logoFile['tmp_name']) && is_string($logoFile['name']) && is_string($logoFile['tmp_name'])) {
                    $storedPath = $fs->storeUpload($logoFile, 'uploads/brands');
                    $logoPath = '/assets/' . $storedPath;
                }
            } catch (\Throwable $e) {
                $this->session->flashError('Invalid file for brand logo: ' . $e->getMessage());
            }
        }

        // Process Brand Favicon File
        $faviconFile = $req->file('brand_favicon');
        if ($faviconFile !== null && ($faviconFile['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
            try {
                if (isset($faviconFile['name'], $faviconFile['tmp_name']) && is_string($faviconFile['name']) && is_string($faviconFile['tmp_name'])) {
                    $storedPath = $fs->storeUpload($faviconFile, 'uploads/brands');
                    $faviconPath = '/assets/' . $storedPath;
                }
            } catch (\Throwable $e) {
                $this->session->flashError('Invalid file for brand favicon: ' . $e->getMessage());
            }
        }

        // Compile settings JSON
        $settings = $existingSettings;
        $settings['logo']            = $logoPath;
        $settings['favicon']         = $faviconPath;
        
        $primaryColorVal = $data['primary_color'] ?? ($existingSettings['primary_color'] ?? '#0D9488');
        $settings['primary_color']   = InputSanitizer::string(is_string($primaryColorVal) ? $primaryColorVal : '#0D9488');
        
        $accentColorVal = $data['accent_color'] ?? ($existingSettings['accent_color'] ?? '#0F766E');
        $settings['accent_color']    = InputSanitizer::string(is_string($accentColorVal) ? $accentColorVal : '#0F766E');
        
        $supportEmailVal = $data['support_email'] ?? ($existingSettings['support_email'] ?? '');
        $settings['support_email']   = InputSanitizer::email(is_string($supportEmailVal) ? $supportEmailVal : '');
        
        $footerTextVal = $data['footer_text'] ?? ($existingSettings['footer_text'] ?? '');
        $settings['footer_text']     = InputSanitizer::string(is_string($footerTextVal) ? $footerTextVal : '');
        
        // Authorization check: only superadmins can modify custom_css and custom_js
        $isSuperadmin = !empty($_SESSION['is_superadmin']);
        if ($isSuperadmin) {
            $customCssVal = $data['custom_css'] ?? ($existingSettings['custom_css'] ?? '');
            $customCss = is_string($customCssVal) ? $customCssVal : '';
            $customJsVal = $data['custom_js'] ?? ($existingSettings['custom_js'] ?? '');
            $customJs = is_string($customJsVal) ? $customJsVal : '';

            // Clean custom_css of dangerous vectors: expressions, behavior, javascript:, script tags
            $customCss = preg_replace('/expression\s*\(|behavior\s*:|javascript\s*:/i', '', $customCss);
            $customCss = preg_replace('/<\s*script\b[^>]*>(.*?)<\s*\/\s*script\s*>/is', '', is_string($customCss) ? $customCss : '');

            $settings['custom_css'] = is_string($customCss) ? $customCss : '';
            $settings['custom_js']  = $customJs;
        } else {
            // Non-superadmins revert to existing styles/scripts
            $settings['custom_css'] = $existingSettings['custom_css'] ?? '';
            $settings['custom_js']  = $existingSettings['custom_js'] ?? '';
        }
        $settings['show_powered_by'] = isset($data['show_powered_by']) && $data['show_powered_by'] === '1' ? 1 : 0;

        return [
            'logo_path' => $logoPath,
            'settings'  => json_encode($settings, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ];
    }
}
