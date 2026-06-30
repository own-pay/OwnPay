<?php
declare(strict_types=1);

namespace OwnPay\Controller\Admin;

use OwnPay\Container;
use OwnPay\Service\Admin\AdminSession;
use OwnPay\Http\Request;
use OwnPay\Http\Response;
use OwnPay\Service\System\PaginationService;

/**
 * Class CustomerController
 *
 * Coordinates administrative customer management operations, handling creation, display,
 * search/pagination, and deletion of customer profiles while ensuring proper context isolation
 * and PII decryption.
 *
 * @package OwnPay\Controller\Admin
 */
final class CustomerController
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
     * @var \OwnPay\Repository\CustomerRepository The customer records repository.
     */
    private \OwnPay\Repository\CustomerRepository $customerRepo;

    /**
     * CustomerController constructor.
     *
     * @param Container                             $c            The dependency injection container.
     * @param AdminSession                          $session      The administrative session service.
     * @param \OwnPay\Repository\CustomerRepository $customerRepo The customer records repository.
     */
    public function __construct(Container $c, AdminSession $session, \OwnPay\Repository\CustomerRepository $customerRepo) 
    { 
        $this->c = $c;
        $this->session = $session; 
        $this->customerRepo = $customerRepo;
    }

    /**
     * Lists customers of the active brand with pagination and search filtering.
     *
     * @param Request $req The incoming HTTP request.
     *
     * @return Response The customers list overview response.
     */
    public function index(Request $req): Response
    {
        $brand = $this->c->get(\OwnPay\Service\Brand\BrandContext::class); 
        if (!$brand instanceof \OwnPay\Service\Brand\BrandContext) {
            throw new \RuntimeException('BrandContext service unavailable');
        }
        $brand->resolveFromRequest($req); 
        $isGlobal = $brand->isGlobalView();
        $mid = $brand->getActiveBrandId();
        if ($mid === null && !$isGlobal) {
            throw new \RuntimeException('No active brand found.');
        }

        $pageVal = $req->query('page', '1');
        $page = max(1, is_int($pageVal) || is_string($pageVal) ? (int)$pageVal : 1);
        $qVal = $req->query('q', '');
        $q = is_string($qVal) ? $qVal : '';

        $paginated = $this->customerRepo->paginateWithStats($isGlobal ? null : $mid, $q, $page, 20);

        // Decrypt PII fields for display
        $enc = $this->c->get(\OwnPay\Security\FieldEncryptor::class);
        if (!$enc instanceof \OwnPay\Security\FieldEncryptor) {
            throw new \RuntimeException('FieldEncryptor service unavailable');
        }
        $customers = array_map(function (array $c) use ($enc) {
            try {
                $c['name']  = !empty($c['name_enc']) && is_string($c['name_enc']) ? $enc->decrypt($c['name_enc']) : (is_string($c['name'] ?? null) ? $c['name'] : '-');
                $c['email'] = !empty($c['email_enc']) && is_string($c['email_enc']) ? $enc->decrypt($c['email_enc']) : (is_string($c['email'] ?? null) ? $c['email'] : '-');
                $c['phone'] = !empty($c['phone_enc']) && is_string($c['phone_enc']) ? $enc->decrypt($c['phone_enc']) : (is_string($c['phone'] ?? null) ? $c['phone'] : '-');
            } catch (\Throwable $e) {
                $c['name']  = is_string($c['name'] ?? null) ? $c['name'] : '[encrypted]';
                $c['email'] = is_string($c['email'] ?? null) ? $c['email'] : '[encrypted]';
                $c['phone'] = is_string($c['phone'] ?? null) ? $c['phone'] : '-';
            }
            return $c;
        }, $paginated['items']);

        return $this->renderAdminPage('admin/customers.twig', [
            'customers'   => $customers,
            'filters'     => ['q' => $q],
            'pagination'  => [
                'page'         => $paginated['page'],
                'current_page' => $paginated['page'],
                'per_page'     => $paginated['per_page'],
                'total_items'  => $paginated['total'],
                'total_pages'  => $paginated['total_pages'],
                'has_prev'     => $paginated['page'] > 1,
                'has_next'     => $paginated['page'] < $paginated['total_pages'],
                'offset'       => ($paginated['page'] - 1) * $paginated['per_page'],
            ],
            'active_page' => 'customers',
        ]);
    }

    /**
     * Displays details for a single customer profile, decrypted, along with transaction history.
     *
     * @param Request $req The incoming HTTP request.
     *
     * @return Response The customer details page.
     */
    public function show(Request $req): Response
    {
        $id = (int) $req->param('id');
        $brand = $this->c->get(\OwnPay\Service\Brand\BrandContext::class); 
        if (!$brand instanceof \OwnPay\Service\Brand\BrandContext) {
            throw new \RuntimeException('BrandContext service unavailable');
        }
        $brand->resolveFromRequest($req); 
        $mid = $brand->getActiveBrandId();
        if ($mid === null) {
            throw new \RuntimeException('No active brand found.');
        }
        
        $scopedRepo = $this->customerRepo->forTenant($mid);
        $customer = $scopedRepo->findScoped($id);
        
        if (!$customer) { 
            $this->session->flashError('Customer not found'); 
            return Response::redirect('/admin/customers'); 
        }

        // Decrypt PII
        $enc = $this->c->get(\OwnPay\Security\FieldEncryptor::class);
        if (!$enc instanceof \OwnPay\Security\FieldEncryptor) {
            throw new \RuntimeException('FieldEncryptor service unavailable');
        }
        try {
            $customer['name']  = !empty($customer['name_enc']) && is_string($customer['name_enc']) ? $enc->decrypt($customer['name_enc']) : (is_string($customer['name'] ?? null) ? $customer['name'] : '-');
            $customer['email'] = !empty($customer['email_enc']) && is_string($customer['email_enc']) ? $enc->decrypt($customer['email_enc']) : (is_string($customer['email'] ?? null) ? $customer['email'] : '-');
            $customer['phone'] = !empty($customer['phone_enc']) && is_string($customer['phone_enc']) ? $enc->decrypt($customer['phone_enc']) : (is_string($customer['phone'] ?? null) ? $customer['phone'] : '-');
        } catch (\Throwable $e) {
            $customer['name']  = '[encrypted]';
            $customer['email'] = '[encrypted]';
            $customer['phone'] = '-';
        }

        $txns = $this->customerRepo->getRecentTransactions($id, $mid, 50);

        return $this->renderAdminPage('admin/customers/show.twig', [
            'customer'       => $customer,
            'transactions'   => $txns,
            'active_page'    => 'customers',
            'show_detail'    => true,
        ]);
    }

    /**
     * Renders the customer creation view.
     *
     * @param Request $req The incoming HTTP request.
     *
     * @return Response The customer creation page response.
     */
    public function create(Request $req): Response
    {
        return $this->renderAdminPage('admin/customers/create.twig', [
            'active_page' => 'customers',
        ]);
    }

    /**
     * Stores a new customer profile encrypting sensitive PII fields.
     *
     * @param Request $req The incoming HTTP request.
     *
     * @return Response The redirect response to the customer listing.
     */
    public function store(Request $req): Response
    {
        $brand = $this->c->get(\OwnPay\Service\Brand\BrandContext::class);
        if (!$brand instanceof \OwnPay\Service\Brand\BrandContext) {
            throw new \RuntimeException('BrandContext service unavailable');
        }
        $brand->resolveFromRequest($req);
        $mid = $brand->getActiveBrandId();
        if ($guard = $this->requireActiveBrand($mid, '/admin/customers')) {
            return $guard;
        }

        $nameVal = $req->post('name', '');
        $emailVal = $req->post('email', '');
        $phoneVal = $req->post('phone', '');

        $name  = is_string($nameVal) ? trim($nameVal) : '';
        $email = is_string($emailVal) ? trim($emailVal) : '';
        $phone = is_string($phoneVal) ? trim($phoneVal) : '';

        if ($name === '' || $email === '') {
            $this->session->flashError('Name and email are required');
            return Response::redirect('/admin/customers/create');
        }

        $enc = $this->c->get(\OwnPay\Security\FieldEncryptor::class);
        if (!$enc instanceof \OwnPay\Security\FieldEncryptor) {
            throw new \RuntimeException('FieldEncryptor service unavailable');
        }
        $uuid = \Ramsey\Uuid\Uuid::uuid4()->toString();
        $now = \OwnPay\Support\DateHelper::nowMicro();

        $db = $this->c->get(\OwnPay\Core\Database::class);
        if (!$db instanceof \OwnPay\Core\Database) {
            throw new \RuntimeException('Database service unavailable');
        }
        $db->insert(
            "INSERT INTO op_customers (merchant_id, uuid, name_enc, email_enc, email_hash, phone_enc, phone_hash, created_at, updated_at)
             VALUES (:mid, :uuid, :name, :email, :ehash, :phone, :phash, :now, :now2)",
            [
                'mid'   => $mid,
                'uuid'  => $uuid,
                'name'  => $enc->encrypt($name),
                'email' => $enc->encrypt($email),
                'ehash' => $enc->hash($email),
                'phone' => $phone !== '' ? $enc->encrypt($phone) : null,
                'phash' => $phone !== '' ? $enc->hash($phone) : null,
                'now'   => $now,
                'now2'  => $now,
            ]
        );

        $this->session->flashSuccess("Customer '{$name}' created");
        return Response::redirect('/admin/customers');
    }

    /**
     * Deletes a customer profile under the scoped merchant context.
     *
     * @param Request $req The incoming HTTP request.
     *
     * @return Response The redirect response.
     */
    public function delete(Request $req): Response
    {
        $id = (int) $req->param('id');
        $brand = $this->c->get(\OwnPay\Service\Brand\BrandContext::class);
        if (!$brand instanceof \OwnPay\Service\Brand\BrandContext) {
            throw new \RuntimeException('BrandContext service unavailable');
        }
        $brand->resolveFromRequest($req);
        $mid = $brand->getActiveBrandId();
        if ($mid === null) {
            throw new \RuntimeException('No active brand found.');
        }

        $scopedRepo = $this->customerRepo->forTenant($mid);
        $customer = $scopedRepo->findScoped($id);

        if (!$customer) {
            $this->session->flashError('Customer not found or access denied');
            return Response::redirect('/admin/customers');
        }

        $db = $this->c->get(\OwnPay\Core\Database::class);
        if (!$db instanceof \OwnPay\Core\Database) {
            throw new \RuntimeException('Database service unavailable');
        }
        $db->execute('DELETE FROM op_customers WHERE id = :id AND merchant_id = :mid', [
            'id'  => $id,
            'mid' => $mid,
        ]);

        $this->session->flashSuccess('Customer deleted');
        return Response::redirect('/admin/customers');
    }
}
