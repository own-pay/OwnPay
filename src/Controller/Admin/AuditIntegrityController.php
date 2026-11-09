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

    /**
     * DI container. Read by AdminPageTrait::renderAdminPage(); declared
     * here so the trait can access `$this->c`.
     *
     * @phpstan-ignore property.onlyWritten
     */
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
     *
     * Runs verifyIntegrity() FIRST and reports unsigned rows as a separate
     * "legacy unsigned" category. Does NOT call signExistingLogs()
     * automatically - that previously blessed any tampered unsigned rows
     * with their current (potentially modified) values, hiding the
     * tampering. Signing legacy rows now requires an explicit confirmation
     * via signLegacy().
     */
    public function scan(Request $req): Response
    {
        if (!$this->session->isSuperadmin()) {
            return new Response('Unauthorized. Superadmin access required.', 403);
        }

        // Run integrity verification check first. Previously scan() called
        // signExistingLogs() BEFORE verifyIntegrity(), which signed tampered
        // unsigned rows with their current (potentially modified) values
        // before the integrity check ran - hiding the tampering. Unsigned
        // rows are now reported as a separate category the operator must
        // explicitly review before signing them via signLegacy().
        $compromised = $this->audit->verifyIntegrity();
        $isSecure = empty($compromised);
        $unsignedCount = $this->audit->countUnsigned();

        return $this->renderAdminPage('admin/audit_integrity.twig', [
            'is_secure'      => $isSecure,
            'compromised'    => $compromised,
            'unsigned_count' => $unsignedCount,
            'active_page'    => 'audit_integrity',
        ]);
    }

    /**
     * Explicitly signs legacy unsigned audit log rows after operator review.
     *
     * Requires:
     *   - Superadmin session.
     *   - A `confirm` POST field set to '1' (a separate confirmation checkbox
     *     in the UI) so the action cannot be triggered by accident or via
     *     CSRF alone.
     *
     * After signing, the action is itself recorded in the audit log so there
     * is a permanent record of who blessed the legacy rows and when.
     */
    public function signLegacy(Request $req): Response
    {
        if (!$this->session->isSuperadmin()) {
            return new Response('Unauthorized. Superadmin access required.', 403);
        }

        $confirm = $req->input('confirm');
        if ($confirm !== '1') {
            $this->session->flashError(
                'Sign-legacy confirmation missing. Tick the confirmation checkbox before signing legacy logs.'
            );
            return Response::redirect('/admin/audit-integrity');
        }

        $signedCount = $this->audit->signExistingLogs();

        // Record the signing action itself in the audit trail so there is a
        // tamper-evident record of who blessed the legacy rows and when.
        $this->audit->log(
            'audit.legacy_signed',
            'audit_log',
            null,
            null,
            ['signed_count' => $signedCount]
        );

        $this->session->flashSuccess(
            "Signed {$signedCount} legacy audit log entries. Re-run the integrity scan to verify."
        );
        return Response::redirect('/admin/audit-integrity');
    }
}
