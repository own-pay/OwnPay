<?php
declare(strict_types=1);

namespace OwnPay\Controller;

use OwnPay\Container;
use OwnPay\Event\EventManager;
use OwnPay\Http\Request;
use OwnPay\Http\Response;
use Twig\Environment as Twig;

/**
 * Base controller — all controllers extend this.
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

    // ——— Rendering —————————————————————————————————————————————

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
        
        $session = $this->container->has(\OwnPay\Service\Admin\AdminSession::class)
            ? $this->container->get(\OwnPay\Service\Admin\AdminSession::class)
            : null;
        $data['current_user'] = [
            'id' => $session?->userId(),
            'name' => $session?->userName() ?? 'Admin',
            'email' => $session?->userEmail() ?? '',
        ];
        $data['is_superadmin'] = $session?->isSuperadmin() ?? false;
        
        if ($this->container->has(\OwnPay\Service\Brand\BrandContext::class)) {
            $brandCtx = $this->container->get(\OwnPay\Service\Brand\BrandContext::class);
            $data['brands'] = $brandCtx->getAllBrands();
            $data['active_brand'] = $brandCtx->getActiveBrand();
            $data['active_brand_id'] = $brandCtx->getActiveBrandId();
        }

        $flash = $session?->consumeFlash() ?? ['success' => null, 'error' => null];
        $data['flash_success'] = $flash['success'];
        $data['flash_error'] = $flash['error'];

        // Allow plugins to modify data before render
        $data = $events->applyFilter('admin.page.before_render', $data, $template);

        $html = $twig->render($template, $data);

        // Allow plugins to modify rendered HTML
        $html = $events->applyFilter('admin.page.after_render', $html, $template);

        return Response::html($html, $status);
    }

    /**
     * Render a Twig template and return just the HTML fragment (for AJAX SPA loads).
     *
     * @param string $template The template path.
     * @param array<string, mixed> $data The template variables.
     */
    protected function renderFragment(string $template, array $data = []): Response
    {
        /** @var Twig $twig */
        $twig = $this->container->get(Twig::class);
        $html = $twig->render($template, $data);
        return Response::html($html);
    }

    // ——— JSON ——————————————————————————————————————————————————

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
     *
     * @param string $message The error message.
     * @param int $status The HTTP status code.
     * @param array<string, mixed>|array<int, mixed> $errors The details of specific errors.
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

    // ——— Redirect ——————————————————————————————————————————————

    protected function redirect(string $url, int $status = 302): Response
    {
        return Response::redirect($url, $status);
    }

    // ——— Session Helpers ———————————————————————————————————————

    /**
     * Set a flash message (stored in session, shown once).
     */
    protected function flash(string $type, string $message): void
    {
        $session = $this->container->has(\OwnPay\Service\Admin\AdminSession::class)
            ? $this->container->get(\OwnPay\Service\Admin\AdminSession::class)
            : null;
        $session?->flash($type, $message);
    }

    /**
     * Get and clear flash messages.
     *
     * @return array{success: ?string, error: ?string}
     */
    protected function getFlash(): array
    {
        $session = $this->container->has(\OwnPay\Service\Admin\AdminSession::class)
            ? $this->container->get(\OwnPay\Service\Admin\AdminSession::class)
            : null;
        return $session?->consumeFlash() ?? ['success' => null, 'error' => null];
    }

    // ——— DI Helpers ————————————————————————————————————————————

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
