<?php
declare(strict_types=1);
if (!defined('OWNPAY_INIT')) { http_response_code(403); exit('Direct access not allowed'); }
$e = static fn(mixed $v): string => htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8');
?>
<!-- Manual Payment Fullscreen Popup -->
<div class="manual-overlay" id="manualPopup" role="dialog" aria-modal="true" aria-label="Manual Payment">
  <div class="blur-bg" onclick="closeManual()"></div>
  <div class="manual-panel">
    <div class="p-6 pb-0">
      <div class="flex items-center justify-between mb-5">
        <div class="flex items-center gap-3">
          <div class="w-11 h-11 rounded-2xl flex items-center justify-center" id="mpLogo"></div>
          <div>
            <p class="text-[16px] font-extrabold text-[#080D1A]" id="mpName">—</p>
            <p class="text-[11px] text-[#7A84A0] font-semibold">Manual Payment</p>
          </div>
        </div>
        <button type="button" onclick="closeManual()" class="w-9 h-9 rounded-xl border border-[#ECEEF5] flex items-center justify-center hover:bg-[#F5F6FA] transition" aria-label="Close"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="#7A84A0" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>
      </div>
    </div>
    <div class="mx-6 rounded-2xl p-5 mb-5" id="mpCard" style="background:linear-gradient(135deg,#0D9488,#0a7b70)">
      <div id="mpSteps"></div>
    </div>
    <div class="px-6 pb-6">
      <label for="mpTxn" class="block text-[12px] font-bold text-[#080D1A] mb-2 uppercase tracking-wider">Transaction ID</label>
      <input type="text" id="mpTxn" placeholder="Enter transaction ID from app" autocomplete="off" maxlength="80"
        class="w-full px-4 py-[14px] rounded-2xl bg-[#F5F6FA] text-[14px] font-mono font-medium text-[#080D1A] outline-none transition-all placeholder:text-[#B0B8CC] mb-4"
        style="border:1.5px solid #ECEEF5">
      <button type="button" onclick="verifyManual()" class="w-full py-[15px] rounded-2xl bg-[var(--teal)] text-white font-bold text-[14px] hover:bg-[var(--teal-deep)] active:scale-[.98] transition-all flex items-center justify-center gap-2 shadow-[0_4px_20px_rgba(13,148,136,.22)]">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
        Verify Payment
      </button>
      <div id="mpVerify" class="hidden mt-4">
        <div class="bg-[#F5F6FA] border border-[#ECEEF5] rounded-2xl p-7 text-center">
          <div class="ldr mx-auto mb-3"></div>
          <p class="text-[14px] font-bold text-[#080D1A]">Verifying transaction…</p>
          <p class="text-[11px] text-[#7A84A0] mt-1">Please wait</p>
        </div>
      </div>
    </div>
  </div>
</div>
