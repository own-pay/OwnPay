<?php
declare(strict_types=1);

namespace OwnPay\Controller\Admin;

use OwnPay\Container;
use OwnPay\Http\Request;
use OwnPay\Http\Response;
use OwnPay\Service\Admin\AdminSession;
use OwnPay\Service\System\AuditService;

/**
 * Controller providing real-time cryptographic audit log integrity checking within the admin panel.
 */
final class AuditIntegrityController
{
    use AdminPageTrait;

    private Container $c;
    private AdminSession $session;
    private AuditService $audit;

    /**
     * AuditIntegrityController constructor.
     */
    public function __construct(Container $c, AdminSession $session, AuditService $audit)
    {
        $this->c = $c;
        $this->session = $session;
        $this->audit = $audit;
    }

    /**
     * Executes the cryptographic integrity scan across the audit trail.
     */
    public function scan(Request $req): Response
    {
        if (!$this->session->isSuperadmin()) {
            return new Response('Unauthorized. Superadmin access required.', 403);
        }

        // Proactively sign any pre-existing logs that do not have a signature
        $signedCount = $this->audit->signExistingLogs();

        // Run integrity verification check
        $compromised = $this->audit->verifyIntegrity();
        $isSecure = empty($compromised);

        return $this->renderAdminPage('admin/audit_integrity.twig', [
            'is_secure'     => $isSecure,
            'compromised'   => $compromised,
            'signed_count'  => $signedCount,
            'active_page'   => 'audit_integrity',
        ]);
    }
}
