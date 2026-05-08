<?php
declare(strict_types=1);

namespace OwnPay\Controller;

use OwnPay\Container;
use OwnPay\Event\EventManager;
use OwnPay\Http\Request;
use OwnPay\Http\Response;
use Twig\Environment as Twig;

/**
 * Base controller â€” all controllers extend this.
 *
 * Provides:
 * - DI container access
 * - Twig template rendering with hook integration
 * - JSON response helpers
 * - Session flash messages
 * - EventManager access
 */
abstract class BaseController
{
    protected Container $container;

    public function __construct(Container $container)
    {
        $this->container = $container;
    }

    // â”€â”€â”€ Rendering â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

    /**
     * Render a Twig template.
     *
     * Fires admin.page.before_render / admin.page.after_render hooks.
     *
     * @param string               $template  Template path (e.g., 'admin/dashboard.twig')
     * @param array<string, mixed> $data      Template variables
     * @param int                  $status    HTTP status code
     */
    protected function render(string $template, array $data = [], int $status = 200): Response
    {
        /** @var Twig $twig */
        $twig = $this->container->get(Twig::class);
        $events = $this->events();

        // Inject global template vars
        $data['app_name'] = $this->container->get('config.app')['name'] ?? 'Own Pay';
        $data['app_version'] = $this->container->get('config.app')['version'] ?? '0.1.0';
        $data['csrf_token'] = \OwnPay\Security\SecurityHelpers::csrfToken();
        
        $data['current_user'] = [
            'id' => $_SESSION['auth_user_id'] ?? null,
            'name' => $_SESSION['auth_name'] ?? 'Admin',
            'email' => $_SESSION['auth_email'] ?? '',
        ];
        $data['is_superadmin'] = (bool) ($_SESSION['is_superadmin'] ?? false);
        
        if ($this->container->has(\OwnPay\Service\Brand\BrandContext::class)) {
            $brandCtx = $this->container->get(\OwnPay\Service\Brand\BrandContext::class);
            $data['brands'] = $brandCtx->getAllBrands();
            $data['active_brand'] = $brandCtx->getActiveBrand();
            $data['active_brand_id'] = $brandCtx->getActiveBrandId();
        }

        $data['flash_success'] = $_SESSION['flash_success'] ?? null;
        $data['flash_error'] = $_SESSION['flash_error'] ?? null;
        unset($_SESSION['flash_success'], $_SESSION['flash_error']);

        // Allow plugins to modify data before render
        $data = $events->applyFilter('admin.page.before_render', $data, $template);

        $html = $twig->render($template, $data);

        // Allow plugins to modify rendered HTML
        $html = $events->applyFilter('admin.page.after_render', $html, $template);

        return Response::html($html, $status);
    }

    /**
     * Render a Twig template and return just the HTML fragment (for AJAX SPA loads).
     */
    protected function renderFragment(string $template, array $data = []): Response
    {
        /** @var Twig $twig */
        $twig = $this->container->get(Twig::class);
        $html = $twig->render($template, $data);
        return Response::html($html);
    }

    // â”€â”€â”€ JSON â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

    /**
     * Return a JSON success response.
     *
     * @param array<string, mixed> $data
     */
    protected function jsonSuccess(array $data = [], string $message = 'Success', int $status = 200): Response
    {
        return Response::json([
            'success' => true,
            'message' => $message,
            'data'    => $data,
        ], $status)->withApiVersion();
    }

    /**
     * Return a JSON error response.
     */
    protected function jsonError(string $message, int $status = 400, array $errors = []): Response
    {
        $payload = [
            'success' => false,
            'message' => $message,
        ];
        if (!empty($errors)) {
            $payload['errors'] = $errors;
        }
        return Response::json($payload, $status)->withApiVersion();
    }

    /**
     * Return paginated JSON response.
     *
     * @param array<int, mixed> $items
     */
    protected function jsonPaginated(array $items, int $total, int $page, int $perPage): Response
    {
        return Response::json([
            'success' => true,
            'data'    => $items,
            'meta'    => [
                'total'    => $total,
                'page'     => $page,
                'per_page' => $perPage,
                'pages'    => (int) ceil($total / max($perPage, 1)),
            ],
        ])->withApiVersion();
    }

    // â”€â”€â”€ Redirect â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

    protected function redirect(string $url, int $status = 302): Response
    {
        return Response::redirect($url, $status);
    }

    // â”€â”€â”€ Session Helpers â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

    /**
     * Set a flash message (stored in session, shown once).
     */
    protected function flash(string $type, string $message): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION['_flash'][$type][] = $message;
        }
    }

    /**
     * Get and clear flash messages.
     *
     * @return array<string, string[]>
     */
    protected function getFlash(): array
    {
        $flash = $_SESSION['_flash'] ?? [];
        unset($_SESSION['_flash']);
        return $flash;
    }

    // â”€â”€â”€ DI Helpers â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

    /**
     * Get the EventManager instance.
     */
    protected function events(): EventManager
    {
        return $this->container->get(EventManager::class);
    }

    /**
     * Get a service from the container.
     */
    protected function service(string $abstract): mixed
    {
        return $this->container->get($abstract);
    }

    /**
     * Get current authenticated user from request attributes.
     *
     * @return array<string, mixed>|null
     */
    protected function user(Request $request): ?array
    {
        return $request->getAttribute('auth_user');
    }

    /**
     * Get current merchant context from request attributes.
     *
     * @return array<string, mixed>|null
     */
    protected function merchant(Request $request): ?array
    {
        return $request->getAttribute('merchant');
    }
}
