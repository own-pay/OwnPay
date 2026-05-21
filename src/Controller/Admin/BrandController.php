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
        $data = $req->post();
        $name  = InputSanitizer::string($data['name'] ?? '');
        $email = InputSanitizer::email($data['email'] ?? '');

        if ($name === '' || $email === '') {
            $this->session->flashError('Name and email are required');
            return Response::redirect('/admin/brands/create');
        }

        $slug = preg_replace('/[^a-z0-9\-]/', '', strtolower(str_replace(' ', '-', $name)));

        $merchantId = (int) $this->merchants->createMerchant([
            'name'             => $name,
            'slug'             => $slug,
            'email'            => $data['email'] ?? '',
            'phone'            => $data['phone'] ?? '',
            'timezone'         => $data['timezone'] ?? 'Asia/Dhaka',
            'default_currency' => $data['default_currency'] ?? 'BDT',
            'status'           => $data['status'] ?? 'active',
        ]);

        $domain = trim($req->post('custom_domain', ''));
        if ($domain !== '') {
            try {
                $this->c->get(\OwnPay\Service\Domain\DomainService::class)->map($merchantId, $domain);
            } catch (\Throwable $e) {
                $this->session->flashError('Brand created but domain error: ' . $e->getMessage());
                return Response::redirect('/admin/brands');
            }
        }

        $this->audit->log('brand.created', 'merchant', $merchantId, null, ['name' => $name]);

        // Auto-create default payment link for new brand
        $currency = $data['default_currency'] ?? 'BDT';
        $this->c->get(\OwnPay\Service\Payment\PaymentLinkService::class)->ensureDefault($merchantId, $name, $slug, $currency);

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
        $data = $req->post();
        $name  = InputSanitizer::string($data['name'] ?? '');
        $email = $data['email'] ?? '';

        if ($name === '' || $email === '') {
            $this->session->flashError('Name and email are required');
            return Response::redirect("/admin/brands/{$id}");
        }

        $this->merchants->updateBrand($id, $data);

        $domain = trim($req->post('custom_domain', ''));
        if ($domain !== '') {
            try {
                $this->c->get(\OwnPay\Service\Domain\DomainService::class)->map($id, $domain);
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
        if ($id === 'global') {
            $this->brand->setGlobalView(true);
            $this->brand->setActiveBrandId(0);
        } elseif (is_numeric($id)) {
            $this->brand->setGlobalView(false);
            $this->brand->setActiveBrandId((int) $id);
        }

        // Regenerate session ID on brand switch (auth context change).
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }

        $ref = $req->header('referer', '/admin');
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
        $db->execute("DELETE FROM op_merchants WHERE id = :id", ['id' => $id]);

        $this->audit->log('brand.deleted', 'merchant', $id, ['name' => $brand['name']]);
        $this->session->flashSuccess("Brand '{$brand['name']}' deleted");
        return Response::redirect('/admin/brands');
    }
}
