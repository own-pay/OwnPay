<?php
/**
 * Per-gateway flow — invoked when the customer picks a gateway from the
 * checkout. Hands off to the gateway plugin's `class.php::callback()` method
 * via the existing `op_gateway_info()` helper.
 */
declare(strict_types=1);

if (!defined('OWNPAY_INIT')) {
    http_response_code(403);
    exit('Direct access not allowed');
}

$e = static fn(mixed $v): string => htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8');

if (isset($_GET['lang']) && $_GET['lang'] !== '') {
    op_set_lang((string) $_GET['lang']);
    $redirectTo = op_checkout_address() . '?gateway=' . urlencode((string) ($_GET['gateway'] ?? ''));
    echo '<script nonce="' . $e($csp_nonce ?? '') . '">location.href=' . json_encode($redirectTo) . ';</script>';
    exit;
}

$gatewaySlug = (string) ($_GET['gateway'] ?? '');
if ($gatewaySlug === '') {
    http_response_code(403);
    exit('Gateway not specified');
}

$gatewayInfo = function_exists('op_gateway_info') ? op_gateway_info($gatewaySlug, $data) : ['status' => false];
if (empty($gatewayInfo['status'])) {
    http_response_code(403);
    exit('Gateway not available');
}

// At this point the gateway plugin's callback() has rendered its own UI
// (via op_gateway_info side effects) — nothing more to render here.
?>
