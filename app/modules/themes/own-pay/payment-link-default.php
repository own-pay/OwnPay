<?php
/**
 * Payment-link default fallback — shown when the requested payment link
 * does not exist, is disabled, or has been deleted.
 */
declare(strict_types=1);

if (!defined('OWNPAY_INIT')) {
    http_response_code(403);
    exit('Direct access not allowed');
}

use OwnPayPlugin\OwnPay\Theme;

$e = static fn(mixed $v): string => htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8');
$brandColor = Theme::safeBrandColor();
$lang = $data['lang'] ?? [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Link not available — Own Pay</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;700;800&display=swap" rel="stylesheet">
<style nonce="<?= $e($csp_nonce ?? '') ?>">
:root { --teal: <?= $brandColor ?>; }
body { font-family: 'Outfit', sans-serif; background: linear-gradient(180deg,#FFF7ED,#F5F6FA); min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 24px; margin: 0; }
.card { max-width: 440px; width: 100%; background: #fff; border-radius: 28px; padding: 36px; box-shadow: 0 12px 32px rgba(8,13,26,0.04); text-align: center; }
h1 { font-size: 24px; font-weight: 800; color: #080D1A; margin: 0 0 12px; }
p { font-size: 14px; color: #7A84A0; line-height: 1.6; margin: 0; }
</style>
</head>
<body>
<div class="card">
  <h1><?= $e($lang['link_not_available'] ?? 'This payment link is no longer available.') ?></h1>
  <p><?= $e($lang['link_not_available_body'] ?? 'The link may have been disabled, deleted, or expired. Please contact the merchant for a new link.') ?></p>
</div>
</body>
</html>
