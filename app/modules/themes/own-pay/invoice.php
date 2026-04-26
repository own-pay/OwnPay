<?php
/**
 * Invoice view — re-uses the checkout shell with invoice-specific framing.
 * Delegates to checkout.php with `$data['invoice']` populated.
 */
declare(strict_types=1);

if (!defined('OWNPAY_INIT')) {
    http_response_code(403);
    exit('Direct access not allowed');
}

include __DIR__ . '/checkout.php';
