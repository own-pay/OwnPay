<?php
declare(strict_types=1);

namespace OwnPay\Controller\Admin;

use OwnPay\Container;
use OwnPay\Http\Request;
use OwnPay\Http\Response;
use OwnPay\Repository\LoginAttemptRepository;
use OwnPay\Service\Admin\AdminSession;
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

    /**
     * Initialises the LoginAttemptController.
     */
    public function __construct(Container $c, AdminSession $session, LoginAttemptRepository $attemptsRepo)
    {
        $this->c = $c;
        if (!$this->c->has(LoginAttemptRepository::class)) {
        }
        $this->session = $session;
        $this->attemptsRepo = $attemptsRepo;
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

        if ($ip !== '') {
            $db->delete("DELETE FROM op_login_attempts WHERE ip_address = :ip", ['ip' => $ip]);
            $this->session->flashSuccess("Login attempts from IP {$ip} have been cleared/unlocked.");
        } elseif ($email !== '') {
            $db->delete("DELETE FROM op_login_attempts WHERE email = :email", ['email' => $email]);
            $this->session->flashSuccess("Login attempts for user {$email} have been cleared/unlocked.");
        } else {
            $this->session->flashError("Invalid IP or Email provided.");
        }

        return Response::redirect('/admin/login-attempts');
    }
}
