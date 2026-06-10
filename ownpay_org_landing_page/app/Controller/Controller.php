<?php
declare(strict_types=1);

/**
 * OwnPay Landing Page Base Controller
 * File: app/Controller/Controller.php
 */

class Controller
{
    /**
     * Start session if not already started.
     */
    protected function startSession(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            // Apply strict cookie standards
            ini_set('session.cookie_httponly', '1');
            ini_set('session.cookie_secure', $_ENV['SESSION_SECURE'] ?? '1');
            ini_set('session.cookie_samesite', 'Strict');
            ini_set('session.use_strict_mode', '1');
            session_start();
        }
    }

    /**
     * Escape output HTML strictly.
     */
    protected function esc(?string $value): string
    {
        if ($value === null) {
            return '';
        }
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Render HTML templates safely.
     */
    protected function render(string $templateName, array $data = []): void
    {
        // Extract variables to local scope
        extract($data);

        // Capture output
        $templateFile = TEMPLATE_PATH . '/' . ltrim($templateName, '/') . '.php';

        if (!file_exists($templateFile)) {
            error_log("Template file not found: " . $templateFile);
            http_response_code(500);
            echo "<h1>Template Error</h1><p>The requested template was not found.</p>";
            return;
        }

        // Generate script nonce for Content-Security-Policy injection
        $cspNonce = $this->getCspNonce();

        // Send Content-Security-Policy Header
        header("Content-Security-Policy: default-src 'self'; script-src 'self' 'nonce-{$cspNonce}' https://unpkg.com; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://unpkg.com; font-src 'self' https://fonts.gstatic.com https://unpkg.com; img-src 'self' data: https://img.shields.io; connect-src 'self' https://api.github.com; frame-ancestors 'none';");

        include $templateFile;
    }

    /**
     * Respond with JSON.
     */
    protected function json(array $data, int $statusCode = 200): void
    {
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    /**
     * Perform HTTP redirect.
     */
    protected function redirect(string $url): void
    {
        header('Location: ' . $url);
        exit;
    }

    /**
     * Generate or fetch CSRF token.
     */
    protected function csrfToken(): string
    {
        $this->startSession();
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    /**
     * Verify POST CSRF token.
     */
    protected function verifyCsrf(): bool
    {
        $this->startSession();
        $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        if (empty($token) || empty($_SESSION['csrf_token'])) {
            return false;
        }
        return hash_equals($_SESSION['csrf_token'], (string) $token);
    }

    /**
     * Generate or fetch CSP nonce.
     */
    protected function getCspNonce(): string
    {
        $this->startSession();
        if (empty($_SESSION['csp_nonce'])) {
            $_SESSION['csp_nonce'] = bin2hex(random_bytes(16));
        }
        return $_SESSION['csp_nonce'];
    }
}
