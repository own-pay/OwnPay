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
        $brand->resolveFromRequest($req); 
        $mid = $brand->getActiveBrandId();
        
        $page = max(1, (int) $req->query('page', '1'));
        $q = $req->query('q', '');

        $paginated = $this->customerRepo->paginateWithStats($mid, $q, $page, 20);

        // Decrypt PII fields for display
        $enc = $this->c->get(\OwnPay\Security\FieldEncryptor::class);
        $customers = array_map(function (array $c) use ($enc) {
            try {
                $c['name']  = !empty($c['name_enc']) ? $enc->decrypt($c['name_enc']) : ($c['name'] ?? '—');
                $c['email'] = !empty($c['email_enc']) ? $enc->decrypt($c['email_enc']) : ($c['email'] ?? '—');
                $c['phone'] = !empty($c['phone_enc']) ? $enc->decrypt($c['phone_enc']) : ($c['phone'] ?? '—');
            } catch (\Throwable $e) {
                $c['name']  = $c['name'] ?? '[encrypted]';
                $c['email'] = $c['email'] ?? '[encrypted]';
                $c['phone'] = $c['phone'] ?? '—';
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
        $brand->resolveFromRequest($req); 
        $mid = $brand->getActiveBrandId();
        
        $scopedRepo = $this->customerRepo->forTenant($mid);
        $customer = $scopedRepo->findScoped($id);
        
        if (!$customer) { 
            $this->session->flashError('Customer not found'); 
            return Response::redirect('/admin/customers'); 
        }

        // Decrypt PII
        $enc = $this->c->get(\OwnPay\Security\FieldEncryptor::class);
        try {
            $customer['name']  = !empty($customer['name_enc']) ? $enc->decrypt($customer['name_enc']) : ($customer['name'] ?? '—');
            $customer['email'] = !empty($customer['email_enc']) ? $enc->decrypt($customer['email_enc']) : ($customer['email'] ?? '—');
            $customer['phone'] = !empty($customer['phone_enc']) ? $enc->decrypt($customer['phone_enc']) : ($customer['phone'] ?? '—');
        } catch (\Throwable $e) {
            $customer['name']  = '[encrypted]';
            $customer['email'] = '[encrypted]';
            $customer['phone'] = '—';
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
        $brand->resolveFromRequest($req);
        $mid = $brand->getActiveBrandId();

        $name  = trim($req->post('name', ''));
        $email = trim($req->post('email', ''));
        $phone = trim($req->post('phone', ''));

        if ($name === '' || $email === '') {
            $this->session->flashError('Name and email are required');
            return Response::redirect('/admin/customers/create');
        }

        $enc = $this->c->get(\OwnPay\Security\FieldEncryptor::class);
        $uuid = \Ramsey\Uuid\Uuid::uuid4()->toString();
        $now = \OwnPay\Support\DateHelper::nowMicro();

        $db = $this->c->get(\OwnPay\Core\Database::class);
        $db->insert(
            "INSERT INTO op_customers (merchant_id, uuid, name_enc, email_enc, email_hash, phone_enc, phone_hash, created_at, updated_at)
             VALUES (:mid, :uuid, :name, :email, :ehash, :phone, :phash, :now, :now2)",
            [
                'mid'   => $mid,
                'uuid'  => $uuid,
                'name'  => $enc->encrypt($name),
                'email' => $enc->encrypt($email),
                'ehash' => $enc->hash($email),
                'phone' => !empty($phone) ? $enc->encrypt($phone) : null,
                'phash' => !empty($phone) ? $enc->hash($phone) : null,
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
        $brand->resolveFromRequest($req);
        $mid = $brand->getActiveBrandId();

        $scopedRepo = $this->customerRepo->forTenant($mid);
        $customer = $scopedRepo->findScoped($id);

        if (!$customer) {
            $this->session->flashError('Customer not found or access denied');
            return Response::redirect('/admin/customers');
        }

        $db = $this->c->get(\OwnPay\Core\Database::class);
        $db->execute('DELETE FROM op_customers WHERE id = :id AND merchant_id = :mid', [
            'id'  => $id,
            'mid' => $mid,
        ]);

        $this->session->flashSuccess('Customer deleted');
        return Response::redirect('/admin/customers');
    }
}
