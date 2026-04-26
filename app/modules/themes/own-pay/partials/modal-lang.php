<?php
declare(strict_types=1);
if (!defined('OWNPAY_INIT')) { http_response_code(403); exit('Direct access not allowed'); }
$e = static fn(mixed $v): string => htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8');
$lang = $lang ?? [];

$langs = $data['lang']['available'] ?? [
    ['code' => 'en', 'name' => 'English'],
    ['code' => 'bn', 'name' => 'বাংলা'],
];
$current = (string) ($_COOKIE['ownpay_lang'] ?? 'en');
?>
<div class="mdl-bg" id="mLang" onclick="closeOut(event,'mLang')" role="dialog" aria-modal="true" aria-label="Language Selection">
  <div class="mdl-box">
    <div class="flex items-center justify-between mb-5">
      <p class="text-[15px] font-extrabold"><?= $e($lang['language'] ?? 'Language') ?></p>
      <button type="button" onclick="closeMdl('mLang')" class="w-8 h-8 rounded-lg flex items-center justify-center hover:bg-[#F5F6FA]" aria-label="Close"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="#7A84A0" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>
    </div>
    <div class="space-y-2">
      <?php foreach ($langs as $L): ?>
        <?php $isCur = ((string) $L['code']) === $current; ?>
        <a href="?lang=<?= $e($L['code']) ?>" class="<?= $isCur ? 'bg-[var(--teal-pale)] border border-[var(--teal)] text-[var(--teal-deep)]' : 'hover:bg-[#F5F6FA] text-[#4A5578]' ?> w-full text-left p-3 rounded-xl text-[13px] font-bold flex items-center justify-between transition no-underline">
          <?= $e($L['name']) ?>
          <?php if ($isCur): ?>
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
          <?php endif; ?>
        </a>
      <?php endforeach; ?>
    </div>
  </div>
</div>
