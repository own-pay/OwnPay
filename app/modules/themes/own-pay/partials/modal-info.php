<?php
declare(strict_types=1);
if (!defined('OWNPAY_INIT')) { http_response_code(403); exit('Direct access not allowed'); }
$e = static fn(mixed $v): string => htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8');
$lang = $lang ?? [];
$tx = $tx ?? [];
$brand = $brand ?? [];
$amountFmt = $amountFmt ?? '';
$currencySym = $currencySym ?? '';
$invoiceId = $invoiceId ?? '';
$merchantName = $merchantName ?? '';
?>
<div class="mdl-bg" id="mInfo" onclick="closeOut(event,'mInfo')" role="dialog" aria-modal="true" aria-label="Transaction Info">
  <div class="mdl-box">
    <div class="flex items-center justify-between mb-5">
      <p class="text-[15px] font-extrabold"><?= $e($lang['transaction_info'] ?? 'Transaction Info') ?></p>
      <button type="button" onclick="closeMdl('mInfo')" class="w-8 h-8 rounded-lg flex items-center justify-center hover:bg-[#F5F6FA]" aria-label="Close"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="#7A84A0" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>
    </div>
    <div class="space-y-3 text-[13px]">
      <div class="flex justify-between"><span class="text-[#7A84A0]"><?= $e($lang['merchant'] ?? 'Merchant') ?></span><span class="font-bold"><?= $e($merchantName) ?></span></div>
      <div class="flex justify-between"><span class="text-[#7A84A0]"><?= $e($lang['invoice'] ?? 'Invoice') ?></span><span class="font-mono"><?= $e($invoiceId) ?></span></div>
      <div class="flex justify-between"><span class="text-[#7A84A0]"><?= $e($lang['amount'] ?? 'Amount') ?></span><span class="font-mono font-bold"><?= $e($currencySym) ?><?= $e($amountFmt) ?></span></div>
      <div class="flex justify-between"><span class="text-[#7A84A0]"><?= $e($lang['gateway'] ?? 'Gateway') ?></span><span>Own Pay</span></div>
      <div class="flex justify-between"><span class="text-[#7A84A0]"><?= $e($lang['encryption'] ?? 'Encryption') ?></span><span>TLS 1.3 / AES-256</span></div>
    </div>
  </div>
</div>
