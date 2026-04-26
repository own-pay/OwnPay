<?php
/**
 * Own Pay — Checkout view
 *
 * Receives $data array from the framework with keys:
 *   $data['transaction']  → ref, amount, currency, currency_symbol, local_net_amount, ...
 *   $data['brand']        → name, logo, favicon, support_email, ...
 *   $data['lang']         → translated strings array
 *   $data['gateway']      → currently-picked gateway (optional)
 *   $data['invoice']      → optional invoice items[]
 *   $data['paymentLink']  → optional payment-link metadata
 *
 * Architectural rules (per docs/security_audit/full_codebase_audit.md):
 *   §1.1 Brand color → regex-validated; insecure fallback to #0D9488
 *   §1.2 op-fetch.js loaded BEFORE checkout.js (handled by Theme::enqueueAssets)
 *   §1.3 filemtime() cache-busting (handled by Theme::enqueueAssets)
 */
declare(strict_types=1);

if (!defined('OWNPAY_INIT')) {
    http_response_code(403);
    exit('Direct access not allowed');
}

use OwnPayPlugin\OwnPay\Theme;

// ── Language / cancel handlers (preserved from twenty-six pattern) ─────────
if (isset($_GET['lang']) && $_GET['lang'] !== '') {
    op_set_lang((string) $_GET['lang']);
    echo '<script nonce="' . htmlspecialchars((string) ($csp_nonce ?? ''), ENT_QUOTES, 'UTF-8') . '">location.href="?lang=";</script>';
    exit;
}
if (isset($_GET['cancel'])) {
    op_set_transaction_status((string) ($data['transaction']['ref'] ?? ''), 'canceled');
    echo '<script nonce="' . htmlspecialchars((string) ($csp_nonce ?? ''), ENT_QUOTES, 'UTF-8') . '">location.href=' . json_encode(op_checkout_address()) . ';</script>';
    exit;
}

// ── Helper closures ───────────────────────────────────────────────────────
$e = static fn(mixed $v): string => htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8');
$json = static fn(mixed $v): string => htmlspecialchars((string) json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8');

// ── Theme settings (resolved with safe defaults) ──────────────────────────
$brandColor       = Theme::safeBrandColor();                                            // §1.1 regex-validated
$logoUrl          = Theme::setting('logo_url');
$showDarkToggle   = Theme::setting('show_dark_toggle', 'enabled') === 'enabled';
$supportEmail     = Theme::setting('support_email');
$helpUrl          = Theme::setting('help_url');
$footerText       = Theme::setting('footer_text', 'Secured by Own Pay · 256-bit encryption');
$customCss        = Theme::setting('custom_css');
$customJs         = Theme::setting('custom_js');
$timeoutEnabled   = Theme::setting('timeout_enabled', 'enabled') === 'enabled';
$timeoutMinutes   = max(1, min(60, (int) Theme::setting('timeout_minutes', '10')));
$expressCheckout  = Theme::setting('express_checkout', 'disabled') === 'enabled';
$showSecBadges    = Theme::setting('show_security_badges', 'enabled') === 'enabled';

// ── Transaction data ──────────────────────────────────────────────────────
$tx           = $data['transaction'] ?? [];
$brand        = $data['brand']       ?? [];
$lang         = $data['lang']        ?? [];
$ref          = (string) ($tx['ref'] ?? '');
$amountRaw    = (string) ($tx['local_net_amount'] ?? $tx['amount'] ?? '0');
$amountFmt    = number_format((float) $amountRaw, 2, '.', ',');
$currency     = (string) ($tx['local_currency'] ?? $tx['currency'] ?? 'BDT');
$currencySym  = (string) ($tx['currency_symbol'] ?? '৳');
$merchantName = (string) ($brand['name'] ?? 'Merchant');
$merchantLogo = $logoUrl !== '' ? $logoUrl : (string) ($brand['logo'] ?? '');
$invoiceId    = (string) ($tx['invoice_number'] ?? $ref);

// Per-line items (fall back to single line if no invoice items)
$items = $data['invoice']['items'] ?? [];
if (!is_array($items) || $items === []) {
    $items = [['name' => $lang['payment'] ?? 'Payment', 'description' => '', 'price' => $amountRaw]];
}
$subtotal = 0.0;
foreach ($items as $it) { $subtotal += (float) ($it['price'] ?? 0); }
$vat = (float) ($tx['tax_amount'] ?? 0);
$total = $subtotal + $vat;

// Available gateways
$opGwMfs    = function_exists('op_gateways') ? op_gateways('mfs',    $data) : [];
$opGwBank   = function_exists('op_gateways') ? op_gateways('bank',   $data) : [];
$opGwGlobal = function_exists('op_gateways') ? op_gateways('global', $data) : [];

// JS config blob (XSS-safe via json_encode + htmlspecialchars)
$jsConfig = [
    'txnRef'           => $ref,
    'gatewayUrl'       => op_checkout_address() . '?gateway=',
    'merchantReturnUrl'=> op_checkout_address(),
    'timeoutEnabled'   => $timeoutEnabled,
    'timeoutSeconds'   => $timeoutMinutes * 60,
    // Gateway metadata used by the manual-popup; minimal default set.
    'gatewayMeta'      => [
        'bkash'  => ['color' => '#E2136E', 'type' => 'Send Money', 'logoText' => 'b'],
        'nagad'  => ['color' => '#F6921E', 'type' => 'Send Money', 'logoText' => 'N'],
        'rocket' => ['color' => '#8B2E86', 'type' => 'Send Money', 'logoText' => 'R'],
        'upay'   => ['color' => '#EF3939', 'type' => 'Send Money', 'logoText' => 'U'],
    ],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
<meta name="robots" content="noindex,nofollow">
<title><?= $e($lang['checkout'] ?? 'Secure Checkout') ?> — <?= $e($merchantName) ?></title>
<?php if (!empty($brand['favicon'])): ?>
<link rel="shortcut icon" href="<?= $e($brand['favicon']) ?>">
<?php endif; ?>
<script nonce="<?= $e($csp_nonce ?? '') ?>" src="https://cdn.tailwindcss.com"></script>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800;900&family=JetBrains+Mono:wght@400;500;600;700&display=swap" rel="stylesheet">
<script nonce="<?= $e($csp_nonce ?? '') ?>">
tailwind.config={theme:{extend:{fontFamily:{sans:['Outfit','sans-serif'],mono:['JetBrains Mono','monospace']}}}}
</script>
<?php
    // Enqueue theme CSS + JS (uses filemtime() per §1.3, op-fetch.js BEFORE checkout.js per §1.2)
    if (function_exists('op_assets')) { op_assets('head'); }
    (new \OwnPayPlugin\OwnPay\Theme())->enqueueAssets();
?>
<!-- §1.1: brand color injected from regex-validated source; theme tokens overridden inline -->
<style nonce="<?= $e($csp_nonce ?? '') ?>">
:root { --teal: <?= $brandColor ?>; --teal-deep: <?= $brandColor ?>cc; --teal-glow: <?= $brandColor ?>1A; }
</style>
<?php if ($customCss !== ''): ?>
<style nonce="<?= $e($csp_nonce ?? '') ?>">
<?= preg_replace('#</?style\b[^>]*>#i', '', (string) $customCss) ?>
</style>
<?php endif; ?>
</head>
<body class="min-h-screen flex items-center justify-center p-3 sm:p-4 lg:p-0">

<div class="ctoast" id="cToast"><?= $e($lang['copied'] ?? 'Copied!') ?></div>

<!-- ============ CHECKOUT SHELL ============ -->
<div id="checkoutShell" class="w-full max-w-[1120px] lg:min-h-[690px] flex rounded-[24px] overflow-hidden shadow-[0_40px_80px_rgba(0,0,0,.06),0_0_0_1px_rgba(0,0,0,.03)]">

  <!-- LEFT: ORDER SUMMARY -->
  <div class="spl-l hidden lg:flex flex-col w-[390px] flex-shrink-0 p-10 relative z-10">
    <div class="fi" style="animation-delay:.05s">
      <div class="flex items-center gap-4 mb-12">
        <div class="w-[52px] h-[52px] rounded-2xl bg-white/10 backdrop-blur-sm flex items-center justify-center border border-white/8 shadow-[0_4px_12px_rgba(0,0,0,.15)]">
          <?php if ($merchantLogo): ?>
            <img src="<?= $e($merchantLogo) ?>" alt="" class="w-7 h-7 rounded-md object-contain">
          <?php else: ?>
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#5EEAD4" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2L2 7l10 5 10-5-10-5z"/><path d="M2 17l10 5 10-5"/><path d="M2 12l10 5 10-5"/></svg>
          <?php endif; ?>
        </div>
        <div>
          <p class="text-[11px] text-white/35 font-semibold uppercase tracking-[.15em]"><?= $e($lang['paying_to'] ?? 'Paying to') ?></p>
          <p class="text-[18px] font-extrabold text-white truncate whitespace-nowrap max-w-[250px] leading-tight mt-0.5"><?= $e($merchantName) ?></p>
        </div>
      </div>
    </div>

    <div class="fi flex-1" style="animation-delay:.12s">
      <p class="text-[9.5px] font-bold text-white/25 uppercase tracking-[.2em] mb-5"><?= $e($lang['order_summary'] ?? 'Order Summary') ?></p>
      <div class="space-y-5">
        <?php foreach ($items as $it): ?>
          <div class="flex justify-between items-start">
            <div>
              <p class="text-[13px] font-semibold text-white/85"><?= $e($it['name'] ?? '') ?></p>
              <?php if (!empty($it['description'])): ?>
                <p class="text-[10.5px] text-white/30 mt-0.5"><?= $e($it['description']) ?></p>
              <?php endif; ?>
            </div>
            <p class="text-[13px] font-bold text-white font-mono"><?= $e($currencySym) ?><?= $e(number_format((float) ($it['price'] ?? 0), 2, '.', ',')) ?></p>
          </div>
        <?php endforeach; ?>
        <div class="h-px bg-white/6"></div>
        <div class="flex justify-between"><p class="text-[11px] text-white/30 font-medium"><?= $e($lang['subtotal'] ?? 'Subtotal') ?></p><p class="text-[12px] text-white/50 font-mono"><?= $e($currencySym) ?><?= $e(number_format($subtotal, 2, '.', ',')) ?></p></div>
        <?php if ($vat > 0): ?>
        <div class="flex justify-between"><p class="text-[11px] text-white/30 font-medium"><?= $e($lang['vat'] ?? 'VAT') ?></p><p class="text-[12px] text-white/50 font-mono"><?= $e($currencySym) ?><?= $e(number_format($vat, 2, '.', ',')) ?></p></div>
        <?php endif; ?>
      </div>
    </div>

    <div class="fi" style="animation-delay:.2s">
      <div class="h-px bg-white/6 mb-5"></div>
      <div class="flex justify-between items-end">
        <p class="text-[12px] text-white/40 font-semibold"><?= $e($lang['total'] ?? 'Total') ?></p>
        <div class="text-right">
          <p class="text-[30px] font-extrabold text-white font-mono leading-none tracking-tight"><?= $e($currencySym) ?><?= $e(number_format($total, 0, '.', ',')) ?></p>
          <p class="text-[10px] text-white/25 font-mono mt-1">.<?= $e(str_pad((string) (int) round(($total - floor($total)) * 100), 2, '0', STR_PAD_LEFT)) ?> <?= $e($currency) ?></p>
        </div>
      </div>
      <?php if ($showSecBadges): ?>
      <div class="mt-7 flex items-center gap-2 text-[10px] text-white/25">
        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
        256-bit SSL · PCI DSS Level 1 · 3D Secure 2.0
      </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- RIGHT: PAYMENT -->
  <div class="flex-1 bg-white flex flex-col relative">

    <!-- TOP BAR -->
    <div class="fi flex items-center justify-between px-5 sm:px-7 py-4 border-b border-[#ECEEF5]" style="animation-delay:.08s">
      <button type="button" onclick="openMdl('mCancel')" class="w-9 h-9 rounded-xl border border-[#ECEEF5] flex items-center justify-center hover:bg-[#F5F6FA] transition-all group" aria-label="Cancel payment"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="#7A84A0" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>
      <div class="flex items-center gap-2 bg-[#F5F6FA] rounded-full px-4 py-[9px]" role="timer" aria-live="polite" aria-label="Transaction countdown timer">
        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="#7A84A0" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
        <span id="timer" class="text-[13px] font-mono font-bold text-[#4A5578]"><?= $e(sprintf('%02d:00', $timeoutMinutes)) ?></span>
      </div>
      <div class="flex items-center gap-0.5">
        <button type="button" onclick="openMdl('mInfo')" class="w-9 h-9 rounded-xl flex items-center justify-center hover:bg-[#F5F6FA] transition" aria-label="Info"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="#7A84A0" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg></button>
        <button type="button" onclick="openMdl('mFaq')"  class="w-9 h-9 rounded-xl flex items-center justify-center hover:bg-[#F5F6FA] transition" aria-label="FAQ"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="#7A84A0" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg></button>
        <button type="button" onclick="openMdl('mHelp')" class="w-9 h-9 rounded-xl flex items-center justify-center hover:bg-[#F5F6FA] transition" aria-label="Support"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="#7A84A0" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg></button>
        <button type="button" onclick="openMdl('mLang')" class="w-9 h-9 rounded-xl flex items-center justify-center hover:bg-[#F5F6FA] transition" aria-label="Language"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="#7A84A0" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg></button>
      </div>
    </div>

    <!-- SCROLLABLE -->
    <div class="flex-1 overflow-y-auto px-5 sm:px-7 py-5 sm:py-6">

      <!-- MOBILE: Merchant + Amount -->
      <div class="lg:hidden fi mb-5" style="animation-delay:.1s">
        <div class="bg-[#F5F6FA] rounded-2xl p-4">
          <div class="flex items-center gap-3 mb-3">
            <div class="w-10 h-10 rounded-xl bg-[#ECEEF5] flex items-center justify-center">
              <?php if ($merchantLogo): ?>
                <img src="<?= $e($merchantLogo) ?>" alt="" class="w-6 h-6 rounded object-contain">
              <?php else: ?>
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="var(--teal)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2L2 7l10 5 10-5-10-5z"/><path d="M2 17l10 5 10-5"/><path d="M2 12l10 5 10-5"/></svg>
              <?php endif; ?>
            </div>
            <div>
              <p class="text-[10px] text-[#7A84A0] font-semibold uppercase tracking-wider"><?= $e($lang['paying_to'] ?? 'Paying to') ?></p>
              <p class="text-[15px] font-extrabold text-[#080D1A] leading-tight"><?= $e($merchantName) ?></p>
            </div>
          </div>
          <div class="flex items-end justify-between">
            <p class="text-[22px] font-extrabold text-[#080D1A] font-mono"><?= $e($currencySym) ?><?= $e($amountFmt) ?></p>
            <div class="flex items-center gap-1.5 text-[10px] text-[#A8B0C8]">
              <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
              <?= $e($lang['encrypted'] ?? 'Encrypted') ?>
            </div>
          </div>
        </div>
      </div>

      <?php if ($expressCheckout): ?>
      <!-- EXPRESS CHECKOUT -->
      <div class="fi mb-5" style="animation-delay:.14s">
        <p class="text-[9.5px] font-bold text-[#A8B0C8] uppercase tracking-[.12em] mb-3"><?= $e($lang['express_checkout'] ?? 'Express Checkout') ?></p>
        <div class="grid grid-cols-2 gap-3">
          <button type="button" onclick="doQP('Apple Pay')"  class="qpay bg-[#080D1A] text-white border-[#080D1A] hover:bg-black">Apple Pay</button>
          <button type="button" onclick="doQP('Google Pay')" class="qpay bg-white text-[#080D1A] border-[#ECEEF5] hover:border-[#D4D9E8] hover:bg-[#FAFBFC]">Google Pay</button>
        </div>
      </div>
      <div class="fi flex items-center gap-4 mb-5" style="animation-delay:.17s">
        <div class="flex-1 h-px bg-[#ECEEF5]"></div>
        <span class="text-[9.5px] font-bold text-[#A8B0C8] uppercase tracking-[.12em]"><?= $e($lang['or_pay_with'] ?? 'Or pay with') ?></span>
        <div class="flex-1 h-px bg-[#ECEEF5]"></div>
      </div>
      <?php endif; ?>

      <!-- TABS -->
      <div class="fi flex gap-1.5 p-1.5 bg-[#F5F6FA] rounded-[16px] mb-5" style="animation-delay:.2s">
        <button type="button" class="ptab on" data-t="cards" onclick="goT('cards')"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg><?= $e($lang['cards'] ?? 'Cards') ?></button>
        <button type="button" class="ptab"    data-t="mfs"   onclick="goT('mfs')"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><rect x="5" y="2" width="14" height="20" rx="2"/><line x1="12" y1="18" x2="12.01" y2="18"/></svg>MFS</button>
        <button type="button" class="ptab"    data-t="bank"  onclick="goT('bank')"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 21h18"/><path d="M3 10h18"/><path d="M5 6l7-3 7 3"/><line x1="4" y1="10" x2="4" y2="21"/><line x1="20" y1="10" x2="20" y2="21"/><line x1="8" y1="14" x2="8" y2="17"/><line x1="12" y1="14" x2="12" y2="17"/><line x1="16" y1="14" x2="16" y2="17"/></svg><?= $e($lang['net_banking'] ?? 'Net Banking') ?></button>
      </div>

      <!-- TAB: CARDS (global gateways) -->
      <div id="t-cards" class="tc">
        <div class="grid grid-cols-2 sm:grid-cols-3 gap-3 mb-5" id="cardG">
          <?php foreach ($opGwGlobal as $gw): ?>
            <div class="gw" onclick="pickGW(this,'card',<?= $json($gw['slug'] ?? '') ?>,<?= $json($gw['name'] ?? '') ?>,<?= $json($gw['mode'] ?? 'api') ?>)">
              <?php if (!empty($gw['mode']) && $gw['mode'] === 'manual'): ?><div class="gw-badge">Manual</div><?php endif; ?>
              <div class="gw-ico" style="background:<?= $e($gw['color'] ?? '#ECEEF5') ?>0F">
                <?php if (!empty($gw['logo'])): ?>
                  <img src="<?= $e($gw['logo']) ?>" alt="" class="w-7 h-7 rounded object-contain">
                <?php else: ?>
                  <span><?= $e(substr((string) ($gw['name'] ?? '?'), 0, 2)) ?></span>
                <?php endif; ?>
              </div>
              <span class="gw-nm"><?= $e($gw['name'] ?? '') ?></span>
            </div>
          <?php endforeach; ?>
          <?php if ($opGwGlobal === []): ?>
            <p class="col-span-full text-center text-[13px] text-[#7A84A0] py-6"><?= $e($lang['no_gateways'] ?? 'No card gateways configured.') ?></p>
          <?php endif; ?>
        </div>
        <button type="button" id="cardBtn" disabled class="w-full py-[15px] rounded-2xl bg-[#ECEEF5] text-[#A8B0C8] font-bold text-[14px] cursor-not-allowed transition-all"><?= $e($lang['select_gateway'] ?? 'Select a gateway') ?></button>
      </div>

      <!-- TAB: MFS -->
      <div id="t-mfs" class="tc hidden">
        <div class="grid grid-cols-2 sm:grid-cols-3 gap-3 mb-5" id="mfsG">
          <?php foreach ($opGwMfs as $gw): ?>
            <div class="gw" onclick="pickGW(this,'mfs',<?= $json($gw['slug'] ?? '') ?>,<?= $json($gw['name'] ?? '') ?>,<?= $json($gw['mode'] ?? 'api') ?>)">
              <?php if (!empty($gw['mode']) && $gw['mode'] === 'manual'): ?><div class="gw-badge">Manual</div><?php endif; ?>
              <div class="gw-ico" style="background:<?= $e($gw['color'] ?? '#0D9488') ?>0F">
                <?php if (!empty($gw['logo'])): ?>
                  <img src="<?= $e($gw['logo']) ?>" alt="" class="w-7 h-7 rounded object-contain">
                <?php else: ?>
                  <span><?= $e(substr((string) ($gw['name'] ?? '?'), 0, 2)) ?></span>
                <?php endif; ?>
              </div>
              <span class="gw-nm"><?= $e($gw['name'] ?? '') ?></span>
            </div>
          <?php endforeach; ?>
          <?php if ($opGwMfs === []): ?>
            <p class="col-span-full text-center text-[13px] text-[#7A84A0] py-6"><?= $e($lang['no_gateways'] ?? 'No MFS gateways configured.') ?></p>
          <?php endif; ?>
        </div>
        <button type="button" id="mfsBtn" disabled class="w-full py-[15px] rounded-2xl bg-[#ECEEF5] text-[#A8B0C8] font-bold text-[14px] cursor-not-allowed transition-all"><?= $e($lang['select_provider'] ?? 'Select a provider') ?></button>
      </div>

      <!-- TAB: NET BANKING -->
      <div id="t-bank" class="tc hidden">
        <div class="grid grid-cols-2 sm:grid-cols-3 gap-3 mb-5" id="bankG">
          <?php foreach ($opGwBank as $gw): ?>
            <div class="gw" onclick="pickGW(this,'bank',<?= $json($gw['slug'] ?? '') ?>,<?= $json($gw['name'] ?? '') ?>,<?= $json($gw['mode'] ?? 'api') ?>)">
              <?php if (!empty($gw['mode']) && $gw['mode'] === 'manual'): ?><div class="gw-badge">Manual</div><?php endif; ?>
              <div class="gw-ico" style="background:<?= $e($gw['color'] ?? '#ECEEF5') ?>1A">
                <?php if (!empty($gw['logo'])): ?>
                  <img src="<?= $e($gw['logo']) ?>" alt="" class="w-7 h-7 rounded object-contain">
                <?php else: ?>
                  <span><?= $e(substr((string) ($gw['name'] ?? '?'), 0, 2)) ?></span>
                <?php endif; ?>
              </div>
              <span class="gw-nm"><?= $e($gw['name'] ?? '') ?></span>
            </div>
          <?php endforeach; ?>
          <?php if ($opGwBank === []): ?>
            <p class="col-span-full text-center text-[13px] text-[#7A84A0] py-6"><?= $e($lang['no_gateways'] ?? 'No banks configured.') ?></p>
          <?php endif; ?>
        </div>
        <button type="button" id="bankBtn" disabled class="w-full py-[15px] rounded-2xl bg-[#ECEEF5] text-[#A8B0C8] font-bold text-[14px] cursor-not-allowed transition-all"><?= $e($lang['select_bank'] ?? 'Select a bank') ?></button>
      </div>

    </div>

    <!-- FOOTER -->
    <div class="fi px-5 sm:px-7 py-3 border-t border-[#ECEEF5] flex items-center justify-center gap-2 text-[9.5px] text-[#A8B0C8]" style="animation-delay:.3s">
      <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
      <?= $e($footerText) ?>
    </div>
  </div>
</div>

<?php
// CSRF token (used by manual-popup verify form via opFetch)
$csrfTokenSafe = $e($csrf_token ?? '');
?>
<input type="hidden" id="op-csrf" value="<?= $csrfTokenSafe ?>">

<?php
include __DIR__ . '/partials/manual-payment.php';
include __DIR__ . '/partials/modal-cancel.php';
include __DIR__ . '/partials/modal-info.php';
include __DIR__ . '/partials/modal-faq.php';
include __DIR__ . '/partials/modal-help.php';
include __DIR__ . '/partials/modal-lang.php';
?>

<!-- JS config (consumed by checkout.js — already loaded by Theme::enqueueAssets) -->
<script nonce="<?= $e($csp_nonce ?? '') ?>">
window.OP_CHECKOUT_CONFIG = <?= json_encode($jsConfig, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
</script>

<?php if ($customJs !== ''): ?>
<script nonce="<?= $e($csp_nonce ?? '') ?>">
<?= preg_replace('#</?script\b[^>]*>#i', '', (string) $customJs) ?>
</script>
<?php endif; ?>

</body>
</html>
