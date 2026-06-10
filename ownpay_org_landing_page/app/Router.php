<?php
declare(strict_types=1);

/**
 * OwnPay Landing Page Router
 * File: app/Router.php
 */

class Router
{
    private array $routes = [];

    /**
     * Map a GET route.
     */
    public function get(string $path, string $handler): void
    {
        $this->addRoute('GET', $path, $handler);
    }

    /**
     * Map a POST route.
     */
    public function post(string $path, string $handler): void
    {
        $this->addRoute('POST', $path, $handler);
    }

    /**
     * Register a route with the method, pattern and class/method handler.
     */
    private function addRoute(string $method, string $path, string $handler): void
    {
        // Convert dynamic parameters: {id} to a strict regex pattern ([a-zA-Z0-9_\-\.]+)
        // This explicitly forbids @ and + symbols as per security Rule 6
        $pattern = preg_replace('/\{([a-zA-Z0-9_]+)\}/', '(?P<$1>[a-zA-Z0-9_\-\.]+)', $path);
        $pattern = '#^' . $pattern . '$#';

        $this->routes[] = [
            'method'  => $method,
            'pattern' => $pattern,
            'handler' => $handler
        ];
    }

    /**
     * Dispatch the current request.
     */
    public function dispatch(): void
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        // Handle fallback redirect for POST method override if needed
        if ($method === 'POST' && isset($_POST['_method'])) {
            $method = strtoupper((string) $_POST['_method']);
        }

        $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        $uri = rtrim((string) $uri, '/');
        if (empty($uri)) {
            $uri = '/';
        }

        foreach ($this->routes as $route) {
            if ($route['method'] === $method && preg_match($route['pattern'], $uri, $matches)) {
                // Extract named parameter matches
                $params = [];
                foreach ($matches as $key => $value) {
                    if (is_string($key)) {
                        $params[$key] = $value;
                    }
                }

                // Parse controller and action
                list($controllerClass, $action) = explode('@', $route['handler']);

                if (!class_exists($controllerClass)) {
                    $this->notFound("Controller {$controllerClass} not found.");
                    return;
                }

                $controller = new $controllerClass();
                if (!method_exists($controller, $action)) {
                    $this->notFound("Action {$action} not found on controller {$controllerClass}.");
                    return;
                }

                // Call the controller action with parameters
                call_user_func_array([$controller, $action], [$params]);
                return;
            }
        }

        $this->notFound();
    }

    private function notFound(string $logMsg = ''): void
    {
        if (!empty($logMsg)) {
            error_log($logMsg);
        }
        http_response_code(404);
        // Load custom 404 page if exists
        $notFoundFile = TEMPLATE_PATH . '/error/404.php';
        if (file_exists($notFoundFile)) {
            include $notFoundFile;
        } else {
            echo "<h1>404 Not Found</h1><p>The page you are looking for does not exist.</p>";
        }
        exit;
    }
}
