<?php
declare(strict_types=1);
if (!defined('OWNPAY_INIT')) { http_response_code(403); exit('Direct access not allowed'); }
$e = static fn(mixed $v): string => htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8');
$lang = $lang ?? [];
$supportEmail = $supportEmail ?? '';
$helpUrl = $helpUrl ?? '';

// URL safety: only show http(s) links; never javascript:/data:/etc.
$safeHelpUrl = (filter_var($helpUrl, FILTER_VALIDATE_URL) && preg_match('#^https?://#i', $helpUrl)) ? $helpUrl : '';
?>
<div class="mdl-bg" id="mHelp" onclick="closeOut(event,'mHelp')" role="dialog" aria-modal="true" aria-label="Support">
  <div class="mdl-box">
    <div class="flex items-center justify-between mb-5">
      <p class="text-[15px] font-extrabold"><?= $e($lang['support'] ?? 'Support') ?></p>
      <button type="button" onclick="closeMdl('mHelp')" class="w-8 h-8 rounded-lg flex items-center justify-center hover:bg-[#F5F6FA]" aria-label="Close"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="#7A84A0" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>
    </div>
    <div class="space-y-2">
      <?php if ($safeHelpUrl !== ''): ?>
        <a href="<?= $e($safeHelpUrl) ?>" target="_blank" rel="noopener noreferrer" class="flex items-center gap-3 p-3 rounded-xl hover:bg-[#F5F6FA] transition">
          <div class="w-9 h-9 rounded-lg bg-[#CCFBF1] flex items-center justify-center"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="var(--teal)" stroke-width="2"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg></div>
          <div><p class="text-[13px] font-bold"><?= $e($lang['help_center'] ?? 'Help Center') ?></p><p class="text-[10px] text-[#7A84A0]"><?= $e($safeHelpUrl) ?></p></div>
        </a>
      <?php endif; ?>
      <?php if ($supportEmail !== '' && filter_var($supportEmail, FILTER_VALIDATE_EMAIL)): ?>
        <a href="mailto:<?= $e($supportEmail) ?>" class="flex items-center gap-3 p-3 rounded-xl hover:bg-[#F5F6FA] transition">
          <div class="w-9 h-9 rounded-lg bg-blue-50 flex items-center justify-center"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#3B82F6" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg></div>
          <div><p class="text-[13px] font-bold"><?= $e($lang['email'] ?? 'Email') ?></p><p class="text-[10px] text-[#7A84A0]"><?= $e($supportEmail) ?></p></div>
        </a>
      <?php endif; ?>
      <?php if ($safeHelpUrl === '' && ($supportEmail === '' || !filter_var($supportEmail, FILTER_VALIDATE_EMAIL))): ?>
        <p class="text-[12px] text-[#7A84A0] py-4 text-center"><?= $e($lang['no_support_configured'] ?? 'Support contact not configured.') ?></p>
      <?php endif; ?>
    </div>
  </div>
</div>
