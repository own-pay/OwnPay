<?php

declare(strict_types=1);

namespace OwnPay\Controller\Admin;

use OwnPay\Container;
use OwnPay\Http\Request;
use OwnPay\Http\Response;
use OwnPay\Service\Admin\AdminSession;
use OwnPay\Repository\SmsDataRepository;
use OwnPay\Service\System\PaginationService;
use OwnPay\Service\System\AuditService;
use OwnPay\Security\SmsBodyCipher;

/**
 * Controller for managing brand-specific SMS data logs in the admin portal.
 */
final class SmsDataController
{
    use AdminPageTrait;

    /**
     * The dependency injection container.
     */
    private Container $c;

    /**
     * The SMS data repository instance.
     */
    private SmsDataRepository $smsRepo;

    /**
     * The administrative session service.
     */
    private AdminSession $session;

    /**
     * The session-aware audit logger.
     */
    private AuditService $audit;

    /**
     * Encrypts/decrypts the SMS body column.
     */
    private SmsBodyCipher $cipher;

    /**
     * SmsDataController constructor.
     *
     * @param Container $c The dependency injection container.
     * @param SmsDataRepository $smsRepo The SMS data repository instance.
     * @param AdminSession $session The administrative session service.
     * @param AuditService $audit The audit logger.
     * @param SmsBodyCipher $cipher The SMS body cipher.
     */
    public function __construct(Container $c, SmsDataRepository $smsRepo, AdminSession $session, AuditService $audit, SmsBodyCipher $cipher)
    {
        $this->c       = $c;
        $this->smsRepo = $smsRepo;
        $this->session = $session;
        $this->audit   = $audit;
        $this->cipher  = $cipher;
    }

    /**
     * Render the list of SMS data logs for the active brand.
     *
     * @param Request $req The incoming HTTP request.
     * @return Response The HTTP response with the rendered page.
     * @throws \Exception If the brand context or pagination calculation fails.
     */
    public function index(Request $req): Response
    {
        $brand = $this->c->get(\OwnPay\Service\Brand\BrandContext::class);
        $activeId = null;
        if ($brand instanceof \OwnPay\Service\Brand\BrandContext) {
            $brand->resolveFromRequest($req);
            $activeId = $brand->getActiveBrandId();
        }

        $pageVal = $req->query('page', '1');
        $page = max(1, is_scalar($pageVal) && is_numeric($pageVal) ? (int) $pageVal : 1);
        $statusVal = $req->query('status', '');
        $status = is_string($statusVal) && $statusVal !== '' ? $statusVal : null;

        // All-Brands (global) view lists captures across every brand - including platform-owned captures
        // from devices paired under "All Brands" (stored under the platform merchant), which is exactly
        // where the companion app's SMS land. A specific brand view scopes to that brand only.
        if ($this->isGlobalBrandView()) {
            $repo = $this->smsRepo->forAllTenants();
        } else {
            $mid = ($activeId !== null && $activeId > 0) ? $activeId : 0;
            $repo = $this->smsRepo->forTenant($mid);
        }
        $perPage = 20;
        $offset  = ($page - 1) * $perPage;
        $result  = $repo->listPaginated($perPage, $offset, $status);

        $pagination = PaginationService::calculate($page, $perPage, $result['total']);

        // Raw SMS bodies are gated behind the sms.view_raw permission (Super Admins
        // always pass). Non-privileged viewers get sender/amount/trx metadata only -
        // the ciphertext is stripped so the plaintext can never reach the template.
        $canRaw = $this->canRevealRaw();
        $items = [];
        foreach ($result['items'] as $item) {
            unset($item['body'], $item['encrypted_raw']);
            $items[] = $item;
        }

        return $this->renderAdminPage('admin/sms-data.twig', [
            'sms_data'    => $items,
            'can_raw'     => $canRaw,
            'filters'     => ['status' => $status ?? ''],
            'pagination'  => $pagination,
            'active_page' => 'sms-data',
        ]);
    }

    /**
     * True when the current user may reveal raw SMS bodies.
     */
    private function canRevealRaw(): bool
    {
        return $this->session->isSuperadmin() || $this->session->hasPermission('sms.view_raw');
    }

    /**
     * Reveals the raw body of a single SMS row (POST /admin/sms-view-body/{id}).
     *
     * The reveal is explicitly audited (who, when, which SMS) per the data-retention
     * requirements - Super Admins included. The audit entry never contains the body.
     *
     * @param Request $req The incoming HTTP request.
     * @return Response JSON response with the decrypted body.
     */
    public function reveal(Request $req): Response
    {
        if (!$this->canRevealRaw()) {
            return Response::json(['success' => false, 'error' => 'FORBIDDEN', 'message' => 'You do not have permission to view raw SMS content.'], 403);
        }

        $brand = $this->c->get(\OwnPay\Service\Brand\BrandContext::class);
        $activeId = null;
        if ($brand instanceof \OwnPay\Service\Brand\BrandContext) {
            $brand->resolveFromRequest($req);
            $activeId = $brand->getActiveBrandId();
        }

        $path = $req->path();
        $smsId = 0;
        if (preg_match('#/admin/sms-view-body/(\d+)$#', $path, $m) === 1) {
            $smsId = (int) $m[1];
        }
        if ($smsId <= 0) {
            return Response::json(['success' => false, 'error' => 'INVALID_ID', 'message' => 'Invalid SMS id.'], 400);
        }

        if ($this->isGlobalBrandView()) {
            $row = $this->smsRepo->forAllTenants()->getById($smsId);
        } else {
            $mid = ($activeId !== null && $activeId > 0) ? $activeId : 0;
            $row = $this->smsRepo->forTenant($mid)->getById($smsId);
        }
        if (!is_array($row)) {
            return Response::json(['success' => false, 'error' => 'NOT_FOUND', 'message' => 'SMS record not found.'], 404);
        }

        $bodyEnc = isset($row['body']) && is_scalar($row['body']) ? (string) $row['body'] : '';
        $body = $bodyEnc === '' ? '' : $this->cipher->decryptForDisplay($bodyEnc);

        // Audit BEFORE serving the body (never log the body itself).
        try {
            $this->audit->log('sms.raw_viewed', 'sms_parsed', $smsId, null, ['merchant_id' => is_scalar($row['merchant_id'] ?? null) ? (int) $row['merchant_id'] : null]);
        } catch (\Throwable $e) {
            // The audit log is a security control; failing to record it must not silently
            // reveal data, so fail the request instead.
            $logger = $this->c->get(\OwnPay\Service\System\Logger::class);
            if ($logger instanceof \OwnPay\Service\System\Logger) {
                $logger->error('SMS raw reveal audit failed: ' . $e->getMessage());
            }
            return Response::json(['success' => false, 'error' => 'AUDIT_UNAVAILABLE', 'message' => 'The access could not be logged; reveal aborted.'], 500);
        }

        return Response::json(['success' => true, 'body' => $body]);
    }
}

