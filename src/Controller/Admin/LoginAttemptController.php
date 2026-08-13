<?php
declare(strict_types=1);

namespace OwnPay\Controller\Admin;

use OwnPay\Container;
use OwnPay\Http\Request;
use OwnPay\Http\Response;
use OwnPay\Repository\LoginAttemptRepository;
use OwnPay\Service\Admin\AdminSession;
use OwnPay\Service\System\AuditService;
use OwnPay\Service\System\PaginationService;

/**
 * Controller managing administrative login attempt log records and lockout mitigation.
 */
final class LoginAttemptController
{
    use AdminPageTrait;

    private Container $c;
    private AdminSession $session;
    private LoginAttemptRepository $attemptsRepo;
    private AuditService $audit;

    /**
     * Initialises the LoginAttemptController.
     *
     * @param Container              $c            The dependency injection container.
     * @param AdminSession           $session      The admin session service.
     * @param LoginAttemptRepository $attemptsRepo The login attempt repository.
     * @param AuditService           $audit        The audit log service.
     */
    public function __construct(Container $c, AdminSession $session, LoginAttemptRepository $attemptsRepo, AuditService $audit)
    {
        $this->c = $c;
        if (!$this->c->has(LoginAttemptRepository::class)) {
        }
        $this->session = $session;
        $this->attemptsRepo = $attemptsRepo;
        $this->audit = $audit;
    }

    /**
     * Renders a list of recent login attempt log records.
     */
    public function index(Request $req): Response
    {
        if (!$this->session->isSuperadmin()) {
            return Response::html('<h1>403 Forbidden</h1><p>Only the super-administrator can access this resource.</p>', 403);
        }

        $pageVal = $req->query('page', '1');
        $page = is_numeric($pageVal) ? (int)$pageVal : 1;
        $page = max(1, $page);
        $perPage = 50;

        $paginated = $this->attemptsRepo->paginate($page, $perPage, '1=1', [], 'id DESC');
        $pagination = PaginationService::calculate($page, $perPage, $paginated['total']);

        return $this->renderAdminPage('admin/settings/login-attempts.twig', [
            'attempts'   => $paginated['items'],
            'pagination' => $pagination,
            'active_page' => 'activities',
            'active_subpage' => 'login_attempts',
        ]);
    }

    /**
     * Clears failed login attempts for a specific IP or Email to unlock the target.
     */
    public function unlock(Request $req): Response
    {
        if (!$this->session->isSuperadmin()) {
            return Response::redirect('/admin/login-attempts');
        }

        $ipVal = $req->post('ip', '');
        $emailVal = $req->post('email', '');

        $ip = trim(is_string($ipVal) ? $ipVal : '');
        $email = trim(is_string($emailVal) ? $emailVal : '');

        $db = $this->attemptsRepo->getDatabase();

        // Audit fix ROL-2: the previous implementation ran
        // `DELETE FROM op_login_attempts WHERE ip_address = :ip` (or email),
        // destroying ALL login records — both successes and failures. That
        // wiped the audit trail of legitimate successful logins for the
        // target, making it impossible to investigate "did the user actually
        // log in successfully before being unlocked?". Scope the DELETE to
        // AND success = 0 so only the failed attempts that constitute the
        // lockout are cleared; successful logins are preserved for forensic
        // review.
        //
        // Additionally, write an audit-log entry recording who unlocked whom,
        // so the unlock action itself is traceable.
        $target = '';
        if ($ip !== '') {
            $db->delete("DELETE FROM op_login_attempts WHERE ip_address = :ip AND success = 0", ['ip' => $ip]);
            $target = $ip;
            $this->session->flashSuccess("Failed login attempts from IP {$ip} have been cleared/unlocked.");
        } elseif ($email !== '') {
            $db->delete("DELETE FROM op_login_attempts WHERE email = :email AND success = 0", ['email' => $email]);
            $target = $email;
            $this->session->flashSuccess("Failed login attempts for user {$email} have been cleared/unlocked.");
        } else {
            $this->session->flashError("Invalid IP or Email provided.");
            return Response::redirect('/admin/login-attempts');
        }

        $this->audit->log(
            'login_attempts.unlocked',
            'auth',
            null,
            null,
            [
                'admin_id' => $this->session->userId(),
                'target'   => $target,
            ]
        );

        return Response::redirect('/admin/login-attempts');
    }
}
