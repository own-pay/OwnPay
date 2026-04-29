<?php

declare(strict_types=1);

namespace OwnPay\Core;

use OwnPay\Http\RequestContext;

/**
 * SPA Content Loader — Handles AJAX content loading for the admin dashboard.
 *
 * When the admin panel requests a page via POST `root`, this class
 * resolves the PHP template file under `app/admin/dashboard/` and
 * includes it with proper path traversal protection.
 */
final class ContentLoader
{
    /**
     * Handle a content load request.
     *
     * @return bool True if the request was handled.
     */
    public static function handle(?RequestContext $requestContext = null): bool
    {
        $userLogin = $GLOBALS['global_user_login'] ?? false;

        if (!$userLogin) {
            echo json_encode(['status' => 'false', 'message' => 'Invalid request']);
            exit;
        }

        $root = \OwnPay\Service\System\InputSanitizer::trim($_POST['root'] ?? '');

        // Strict identifier validation
        if ($root === '' || strlen($root) > 64 || !preg_match('/^[a-zA-Z0-9_\-\/]+$/', $root) || str_contains($root, '..')) {
            echo json_encode(['status' => 'false', 'message' => 'Invalid request.']);
            exit;
        }

        // Render pending transaction badge
        self::renderPendingBadge();

        // Resolve and include the dashboard template
        $base = realpath(dirname(__DIR__, 2) . '/app/admin/dashboard/');

        if ($base === false) {
            self::render404();
            exit;
        }

        $targetFile = realpath($base . '/' . $root . '.php');
        $targetIndex = realpath($base . '/' . $root . '/index.php');

        if ($targetFile !== false && str_starts_with($targetFile, $base) && file_exists($targetFile)) {
            include($targetFile);
        } elseif ($targetIndex !== false && str_starts_with($targetIndex, $base) && file_exists($targetIndex)) {
            include($targetIndex);
        } else {
            self::render404();
        }

        exit;
    }

    /**
     * Render the pending transaction count badge update script.
     */
    private static function renderPendingBadge(): void
    {
        global $db_prefix;
        $cspNonce = $GLOBALS['csp_nonce'] ?? '';

        $count = 0;
        try {
            $pdo = \OwnPay\Core\Database::getInstance()->getPdo();
            $brandId = $GLOBALS['global_response_brand']['response'][0]['brand_id'] ?? '';
            $stmt = $pdo->prepare("SELECT COUNT(*) AS cnt FROM `{$db_prefix}transaction` WHERE brand_id = :bid AND status = 'pending'");
            $stmt->execute([':bid' => $brandId]);
            $count = (int) ($stmt->fetch(\PDO::FETCH_ASSOC)['cnt'] ?? 0);
        } catch (\Throwable $e) {
            error_log('Pending count error: ' . $e->getMessage());
        }

        echo '<script nonce="' . $cspNonce . '">';
        echo 'function initPendingTrs(){';
        if ($count === 0) {
            echo 'var b=document.querySelector(".nav-item-transaction .op-badge-danger");if(b)b.style.display="none";';
        } else {
            echo 'var b=document.querySelector(".nav-item-transaction .op-badge-danger");if(b)b.innerHTML="' . $count . '";';
        }
        echo '}initPendingTrs();';
        echo '</script>';
    }

    /**
     * Render a 404 page-not-found card.
     */
    private static function render404(): void
    {
        echo '<div class="flex flex-col items-center justify-center py-32">'
            . '<div class="w-20 h-20 mb-4 rounded-full bg-gray-50 dark:bg-gray-800 flex items-center justify-center">'
            . '<svg xmlns="http://www.w3.org/2000/svg" class="w-10 h-10 text-gray-400 dark:text-gray-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">'
            . '<path d="M3 7v4a1 1 0 0 0 1 1h3"/><path d="M7 7v10"/>'
            . '<path d="M10 8v8a1 1 0 0 0 1 1h2a1 1 0 0 0 1 -1v-8a1 1 0 0 0 -1 -1h-2a1 1 0 0 0 -1 1z"/>'
            . '<path d="M17 7v4a1 1 0 0 0 1 1h3"/><path d="M21 7v10"/>'
            . '</svg></div>'
            . '<h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-1">Page Not Found</h3>'
            . '<p class="text-sm text-gray-500 dark:text-gray-400">The page you are looking for does not exist or has been moved.</p>'
            . '</div>';
    }
}
