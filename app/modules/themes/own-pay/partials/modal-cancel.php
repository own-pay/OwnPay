<?php
declare(strict_types=1);
if (!defined('OWNPAY_INIT')) { http_response_code(403); exit('Direct access not allowed'); }
$e = static fn(mixed $v): string => htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8');
$lang = $lang ?? [];
?>
<div class="mdl-bg" id="mCancel" onclick="closeOut(event,'mCancel')" role="dialog" aria-modal="true" aria-label="Cancel Payment">
  <div class="mdl-box">
    <div class="flex items-center gap-3 mb-4">
      <div class="w-11 h-11 rounded-2xl bg-red-50 flex items-center justify-center"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#EF4444" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg></div>
      <div>
        <p class="text-[15px] font-extrabold"><?= $e($lang['cancel_title'] ?? 'Cancel Payment?') ?></p>
        <p class="text-[11px] text-[#7A84A0]"><?= $e($lang['cancel_caption'] ?? 'This action cannot be undone') ?></p>
      </div>
    </div>
    <p class="text-[13px] text-[#4A5578] mb-5 leading-relaxed"><?= $e($lang['cancel_body'] ?? "You'll be redirected back to the merchant's website. Any progress will be lost.") ?></p>
    <div class="flex gap-3">
      <button type="button" onclick="closeMdl('mCancel')" class="flex-1 py-3 rounded-xl border border-[#ECEEF5] text-[13px] font-bold text-[#4A5578] hover:bg-[#F5F6FA] transition"><?= $e($lang['go_back'] ?? 'Go Back') ?></button>
      <button type="button" onclick="doCancel()" class="flex-1 py-3 rounded-xl bg-[#EF4444] text-white text-[13px] font-bold hover:bg-red-600 transition"><?= $e($lang['cancel_confirm'] ?? 'Cancel Payment') ?></button>
    </div>
  </div>
</div>
