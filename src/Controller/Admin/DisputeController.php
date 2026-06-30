<?php
declare(strict_types=1);

namespace OwnPay\Controller\Admin;

use OwnPay\Container;
use OwnPay\Http\Request;
use OwnPay\Http\Response;
use OwnPay\Service\Admin\AdminSession;
use OwnPay\Service\Brand\BrandContext;
use OwnPay\Repository\DisputeRepository;
use OwnPay\Repository\TransactionRepository;
use OwnPay\Service\Payment\DisputeService;
use OwnPay\Service\System\InputSanitizer;

/**
 * Controller for managing brand-specific customer and transaction disputes in the Admin panel.
 *
 * Scopes all records strictly to the active brand/tenant to prevent leakage.
 */
final class DisputeController
{
    use AdminPageTrait;

    /**
     * @var Container The dependency injection container.
     */
    private Container $c;

    /**
     * @var AdminSession The administrative session manager.
     */
    private AdminSession $session;

    /**
     * @var BrandContext The brand context resolution service.
     */
    private BrandContext $brand;

    /**
     * @var DisputeRepository The dispute database repository.
     */
    private DisputeRepository $disputes;

    /**
     * @var DisputeService The dispute management service.
     */
    private DisputeService $disputeService;

    /**
     * @var TransactionRepository The transaction database repository.
     */
    private TransactionRepository $txnRepo;

    /**
     * DisputeController constructor.
     *
     * @param Container $c The dependency injection container.
     * @param AdminSession $session The administrative session manager.
     * @param BrandContext $brand The brand context resolution service.
     * @param DisputeRepository $disputes The dispute repository.
     * @param DisputeService $disputeService The dispute management service.
     * @param TransactionRepository $txnRepo The transaction repository.
     */
    public function __construct(
        Container $c,
        AdminSession $session,
        BrandContext $brand,
        DisputeRepository $disputes,
        DisputeService $disputeService,
        TransactionRepository $txnRepo
    ) {
        $this->c              = $c;
        $this->session        = $session;
        $this->brand          = $brand;
        $this->disputes       = $disputes;
        $this->disputeService = $disputeService;
        $this->txnRepo        = $txnRepo;
    }

    /**
     * Lists disputes scoped by the active brand with support for pagination.
     *
     * @param Request $req The incoming HTTP request.
     * @return Response The rendered dispute index page.
     */
    public function index(Request $req): Response
    {
        $this->brand->resolveFromRequest($req);
        $isGlobal = $this->brand->isGlobalView();
        $mid = $this->brand->getActiveBrandId();

        if ($mid === null && !$isGlobal) {
            $this->session->flashError('Please select a specific brand first.');
            return Response::redirect('/admin');
        }

        $pageQuery = $req->query('page', '1');
        $pageVal = (is_int($pageQuery) || is_string($pageQuery) || is_numeric($pageQuery)) ? (int) $pageQuery : 1;
        $page = max(1, $pageVal);

        $paginated = ($isGlobal ? $this->disputes->forAllTenants() : $this->disputes->forTenant((int) $mid))->paginateScoped($page, 20, '1=1', [], 'id DESC');

        return $this->renderAdminPage('admin/disputes/index.twig', [
            'disputes'    => $paginated['items'],
            'total'       => $paginated['total'],
            'page'        => $paginated['page'],
            'pages'       => $paginated['pages'],
            'active_page' => 'disputes',
        ]);
    }

    /**
     * Shows detail parameters for a single customer dispute.
     *
     * @param Request $req The incoming HTTP request.
     * @return Response The rendered dispute detail view or redirect on error.
     */
    public function show(Request $req): Response
    {
        $this->brand->resolveFromRequest($req);
        $mid = $this->brand->getActiveBrandId();

        if ($mid === null) {
            $this->session->flashError('Select a brand first.');
            return Response::redirect('/admin/disputes');
        }

        $id = (int) $req->param('id');
        $dispute = $this->disputes->forTenant((int) $mid)->findByIdScoped($id);

        if ($dispute === null) {
            $this->session->flashError('Dispute record not found.');
            return Response::redirect('/admin/disputes');
        }

        if (isset($dispute['evidence']) && is_string($dispute['evidence'])) {
            $decoded = json_decode($dispute['evidence'], true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $dispute['evidence'] = $decoded;
            }
        }

        $txId = $dispute['transaction_id'] ?? null;
        $transaction = null;
        if (is_int($txId) || is_string($txId) || is_numeric($txId)) {
            $transaction = $this->txnRepo->forTenant((int) $mid)->findScoped((int) $txId);
            if ($transaction) {
                if (!empty($transaction['customer_id'])) {
                    $customerRepo = $this->c->get(\OwnPay\Repository\CustomerRepository::class);
                    if ($customerRepo instanceof \OwnPay\Repository\CustomerRepository) {
                        $cId = is_scalar($transaction['customer_id']) ? (int) $transaction['customer_id'] : 0;
                        $customer = $customerRepo->forTenant((int) $mid)->findScoped($cId);
                        if ($customer) {
                            $enc = $this->c->get(\OwnPay\Security\FieldEncryptor::class);
                            if ($enc instanceof \OwnPay\Security\FieldEncryptor) {
                                try {
                                    $transaction['customer_name']  = (!empty($customer['name_enc']) && is_string($customer['name_enc'])) ? $enc->decrypt($customer['name_enc']) : ($customer['name'] ?? '-');
                                    $transaction['customer_email'] = (!empty($customer['email_enc']) && is_string($customer['email_enc'])) ? $enc->decrypt($customer['email_enc']) : ($customer['email'] ?? '-');
                                } catch (\Throwable $e) {
                                    $transaction['customer_name']  = '[encrypted]';
                                    $transaction['customer_email'] = '[encrypted]';
                                }
                            }
                        }
                    }
                }
            }
        }

        return $this->renderAdminPage('admin/disputes/show.twig', [
            'dispute'     => $dispute,
            'transaction' => $transaction,
            'active_page' => 'disputes',
        ]);
    }

    /**
     * Post handler to record dispute resolution status and description.
     *
     * @param Request $req The incoming HTTP request.
     * @return Response The HTTP redirect response.
     */
    public function resolve(Request $req): Response
    {
        $this->brand->resolveFromRequest($req);
        $mid = $this->brand->getActiveBrandId();

        if ($mid === null) {
            $this->session->flashError('Select a brand first.');
            return Response::redirect('/admin/disputes');
        }

        $id = (int) $req->param('id');
        $dispute = $this->disputes->forTenant((int) $mid)->findByIdScoped($id);

        if ($dispute === null) {
            $this->session->flashError('Dispute record not found.');
            return Response::redirect('/admin/disputes');
        }

        $postData = $req->post();
        if (!is_array($postData)) {
            $postData = [];
        }

        $statusVal = $postData['status'] ?? '';
        $status = is_string($statusVal) ? trim(strtolower($statusVal)) : '';
        
        $resolutionVal = $postData['resolution'] ?? '';
        $resolution = is_string($resolutionVal) ? InputSanitizer::string(trim($resolutionVal)) : '';

        $shippingCarrierVal = $postData['shipping_carrier'] ?? '';
        $shippingCarrier = is_string($shippingCarrierVal) ? InputSanitizer::string(trim($shippingCarrierVal)) : '';

        $trackingNumberVal = $postData['tracking_number'] ?? '';
        $trackingNumber = is_string($trackingNumberVal) ? InputSanitizer::string(trim($trackingNumberVal)) : '';

        if (!in_array($status, ['won', 'lost', 'closed'], true)) {
            $this->session->flashError('Invalid resolution status outcome.');
            return Response::redirect("/admin/disputes/{$id}");
        }

        $filePath = '';
        $evidenceFile = $req->file('evidence_file');
        if (
            is_array($evidenceFile)
            && isset($evidenceFile['error'], $evidenceFile['name'], $evidenceFile['tmp_name'])
            && is_int($evidenceFile['error'])
            && is_string($evidenceFile['name'])
            && is_string($evidenceFile['tmp_name'])
            && $evidenceFile['error'] === UPLOAD_ERR_OK
        ) {
            try {
                $fs = new \OwnPay\Service\System\FilesystemService(dirname(__DIR__, 3) . '/public/assets');
                $storedPath = $fs->storeUpload($evidenceFile, 'uploads/disputes');
                $filePath = '/assets/' . $storedPath;
            } catch (\Throwable $e) {
                $this->session->flashError('Invalid file for dispute evidence: ' . $e->getMessage());
                return Response::redirect("/admin/disputes/{$id}");
            }
        }

        $evidenceData = [
            'notes'            => $resolution,
            'shipping_carrier' => $shippingCarrier,
            'tracking_number'  => $trackingNumber,
            'file_path'        => $filePath,
            'resolved_by'      => $this->session->currentUser()['id'] ?? null,
        ];
        $evidenceJson = json_encode($evidenceData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: null;

        try {
            $this->disputeService->resolve((int) $mid, $id, $status, $evidenceJson);
            
            // Record audit log for secure tracing
            $db = $this->c->get(\OwnPay\Core\Database::class);
            if ($db instanceof \OwnPay\Core\Database) {
                $now = \OwnPay\Support\DateHelper::now();
                $db->insert('op_audit_logs', [
                    'merchant_id' => $mid,
                    'user_id'     => $this->session->currentUser()['id'] ?? null,
                    'action'      => 'dispute.resolve',
                    'entity_type' => 'dispute',
                    'entity_id'   => $id,
                    'new_values'  => json_encode([
                        'status'           => $status,
                        'resolution'       => $resolution,
                        'shipping_carrier' => $shippingCarrier,
                        'tracking_number'  => $trackingNumber,
                        'file_path'        => $filePath
                    ]),
                    'ip_address'  => $req->server('REMOTE_ADDR'),
                    'user_agent'  => $req->server('HTTP_USER_AGENT'),
                    'created_at'  => $now,
                ]);
            }

            $this->session->flashSuccess('Dispute status resolved and updated successfully.');
        } catch (\Throwable $e) {
            $this->session->flashError('Failed to resolve dispute: ' . $e->getMessage());
        }

        return Response::redirect("/admin/disputes/{$id}");
    }
}
