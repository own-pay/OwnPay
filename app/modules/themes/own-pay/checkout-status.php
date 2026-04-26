<?php
/**
 * Post-transaction status view — Success / Failed / Cancelled / Pending / Expired.
 * Server-side render so search-engine bots and disabled-JS clients see proper status.
 */
declare(strict_types=1);

if (!defined('OWNPAY_INIT')) {
    http_response_code(403);
    exit('Direct access not allowed');
}

use OwnPayPlugin\OwnPay\Theme;

$e = static fn(mixed $v): string => htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8');

$status        = strtolower((string) ($data['transaction']['status'] ?? 'pending'));
$brandColor    = Theme::safeBrandColor();
$tx            = $data['transaction'] ?? [];
$brand         = $data['brand']       ?? [];
$txnId         = (string) ($tx['transaction_id'] ?? $tx['ref'] ?? 'N/A');
$invoiceId     = (string) ($tx['invoice_number'] ?? $tx['ref'] ?? 'N/A');
$merchantName  = (string) ($brand['name'] ?? 'Merchant');
$amount        = number_format((float) ($tx['local_net_amount'] ?? $tx['amount'] ?? 0), 2, '.', ',');
$currencySym   = (string) ($tx['currency_symbol'] ?? '৳');
$returnUrl     = (string) ($tx['return_url'] ?? op_checkout_address());

$states = [
    'completed' => ['title' => 'Payment Successful',  'sub' => 'Your transaction was successful.',                  'color' => '#059669', 'bg' => 'linear-gradient(180deg,#ECFDF5,#F5F6FA)'],
    'canceled'  => ['title' => 'Payment Cancelled',   'sub' => 'No charges were made.',                              'color' => '#E11D48', 'bg' => 'linear-gradient(180deg,#FFF1F2,#F5F6FA)'],
    'failed'    => ['title' => 'Payment Failed',      'sub' => "We couldn't process your payment.",                  'color' => '#DC2626', 'bg' => 'linear-gradient(180deg,#FEF2F2,#F5F6FA)'],
    'pending'   => ['title' => 'Payment Processing',  'sub' => 'Your transaction is being verified.',                'color' => '#2563EB', 'bg' => 'linear-gradient(180deg,#EFF6FF,#F5F6FA)'],
    'expired'   => ['title' => 'Session Expired',     'sub' => 'Please start a new payment session.',                'color' => '#EA580C', 'bg' => 'linear-gradient(180deg,#FFF7ED,#F5F6FA)'],
];
$S = $states[$status] ?? $states['pending'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title><?= $e($S['title']) ?> — <?= $e($merchantName) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700;800;900&family=JetBrains+Mono:wght@600&display=swap" rel="stylesheet">
<style nonce="<?= $e($csp_nonce ?? '') ?>">
:root { --teal: <?= $brandColor ?>; }
body { font-family: 'Outfit', sans-serif; background: <?= $S['bg'] ?>; min-height: 100vh; display: flex; flex-direction: column; align-items: center; justify-content: center; padding: 24px; margin: 0; }
.pt-card { max-width: 440px; width: 100%; background: #fff; border-radius: 28px; padding: 32px; box-shadow: 0 12px 32px rgba(8,13,26,0.04); text-align: center; }
.pt-title { font-size: 26px; font-weight: 800; color: #080D1A; margin: 0 0 14px; }
.pt-sub { font-size: 14px; color: #7A84A0; margin: 0 0 24px; line-height: 1.6; }
.pt-row { display: flex; justify-content: space-between; padding: 12px 0; border-bottom: 1px solid #ECEEF5; font-size: 13px; }
.pt-row:last-child { border-bottom: none; }
.pt-label { color: #7A84A0; }
.pt-val { font-weight: 800; color: #080D1A; font-family: 'JetBrains Mono', monospace; }
.pt-status { color: <?= $S['color'] ?>; }
.pt-btn { display: block; margin-top: 24px; background: #080D1A; color: #fff; padding: 14px 24px; border-radius: 18px; border: 0; font-weight: 800; cursor: pointer; font-family: 'Outfit', sans-serif; text-decoration: none; text-align: center; }
.pt-btn:hover { background: #1A2038; }
</style>
</head>
<body>
<div class="pt-card">
  <h1 class="pt-title pt-status"><?= $e($S['title']) ?></h1>
  <p class="pt-sub"><?= $e($S['sub']) ?></p>
  <div>
    <div class="pt-row"><span class="pt-label">Merchant</span><span class="pt-val"><?= $e($merchantName) ?></span></div>
    <div class="pt-row"><span class="pt-label">Amount</span><span class="pt-val"><?= $e($currencySym) ?><?= $e($amount) ?></span></div>
    <div class="pt-row"><span class="pt-label">Invoice ID</span><span class="pt-val"><?= $e($invoiceId) ?></span></div>
    <div class="pt-row"><span class="pt-label">Transaction ID</span><span class="pt-val"><?= $e($txnId) ?></span></div>
    <div class="pt-row"><span class="pt-label">Date</span><span class="pt-val"><?= $e(date('d M Y H:i')) ?></span></div>
  </div>
  <a href="<?= $e($returnUrl) ?>" class="pt-btn">Return to Merchant</a>
</div>
</body>
</html>
