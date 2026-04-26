<?php
declare(strict_types=1);
if (!defined('OWNPAY_INIT')) { http_response_code(403); exit('Direct access not allowed'); }
$e = static fn(mixed $v): string => htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8');
$lang = $lang ?? [];

$faqs = $data['faqs'] ?? [
    ['q' => $lang['faq_q1'] ?? 'Is my payment secure?',     'a' => $lang['faq_a1'] ?? 'All transactions use PCI DSS Level-1 tokenization with 256-bit encryption.'],
    ['q' => $lang['faq_q2'] ?? 'What is manual payment?',   'a' => $lang['faq_a2'] ?? 'Send money via your MFS / Banking app directly, then verify with the transaction ID.'],
    ['q' => $lang['faq_q3'] ?? 'Why is there a timer?',     'a' => $lang['faq_a3'] ?? 'Security measure. Expired sessions are invalidated to prevent unauthorized access.'],
];
?>
<div class="mdl-bg" id="mFaq" onclick="closeOut(event,'mFaq')" role="dialog" aria-modal="true" aria-label="FAQ">
  <div class="mdl-box">
    <div class="flex items-center justify-between mb-5">
      <p class="text-[15px] font-extrabold"><?= $e($lang['faq'] ?? 'FAQ') ?></p>
      <button type="button" onclick="closeMdl('mFaq')" class="w-8 h-8 rounded-lg flex items-center justify-center hover:bg-[#F5F6FA]" aria-label="Close"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="#7A84A0" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>
    </div>
    <div class="space-y-4">
      <?php foreach ($faqs as $faq): ?>
        <div>
          <p class="text-[13px] font-bold mb-1"><?= $e($faq['q']) ?></p>
          <p class="text-[11px] text-[#7A84A0] leading-relaxed"><?= $e($faq['a']) ?></p>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>
