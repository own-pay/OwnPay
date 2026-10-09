<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex,nofollow">
    <title>Server Requirements · OwnPay Setup</title>
    <link rel="stylesheet" href="/assets/css/installer.css?v=5">
    <script nonce="<?php echo htmlspecialchars($csp_nonce ?? '', ENT_QUOTES, 'UTF-8'); ?>">
        (function(){var t=localStorage.getItem('op-theme');if(t==='dark')document.documentElement.setAttribute('data-theme','dark');})();
    </script>
</head>
<body>
<header class="ins-header">
    <div class="ins-brand">
        <img src="/assets/img/logo-light.svg?v=002" alt="OwnPay" class="op-logo-light" style="height: 32px; width: auto;">
        <img src="/assets/img/logo-dark.svg?v=002" alt="OwnPay" class="op-logo-dark" style="height: 32px; width: auto;">
        <span class="ins-name">OwnPay <span>Setup</span></span>
    </div>
    <div class="ins-steps">
        <div class="ins-step active"><span class="ins-step-num">1</span><span>Requirements</span></div>
        <div class="ins-step-line"></div>
        <div class="ins-step"><span class="ins-step-num">2</span><span>Database</span></div>
        <div class="ins-step-line"></div>
        <div class="ins-step"><span class="ins-step-num">3</span><span>Admin</span></div>
        <div class="ins-step-line"></div>
        <div class="ins-step"><span class="ins-step-num">4</span><span>Settings</span></div>
        <button id="insThemeToggle" type="button" class="ins-theme-btn" aria-label="Toggle theme" title="Toggle theme">
            <svg id="insMoon" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z"/></svg>
            <svg id="insSun" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" style="display:none;"><path d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z"/></svg>
        </button>
    </div>
</header>

<main class="ins-main">
    <div class="ins-card">
        <h1>Server Requirements</h1>
        <p class="ins-sub">Verifying that your server meets the minimum requirements for running OwnPay securely.</p>

        <?php if (!empty($requirements) && is_array($requirements)): ?>
        <div class="ins-req-list">
            <?php
            $failed = [];
            $allOk = true;
            foreach ($requirements as $r):
                if (!is_array($r)) {
                    continue;
                }
                $isOk = !empty($r['ok']);
                $rName = is_string($r['name'] ?? null) ? $r['name'] : 'Unknown';
                $rCurrent = is_string($r['current'] ?? null) ? $r['current'] : 'Not found';
                $rRequired = is_string($r['required'] ?? null) ? $r['required'] : '';
                if (!$isOk) {
                    $allOk = false;
                    $failed[] = $rName;
                }
            ?>
            <div class="ins-req <?= $isOk ? 'ins-req-ok' : 'ins-req-fail' ?>">
                <span class="ins-req-icon"><?= $isOk ? '✓' : '✗' ?></span>
                <span class="ins-req-name"><?= htmlspecialchars($rName) ?></span>
                <span class="ins-req-val"><?= htmlspecialchars($rCurrent) ?></span>
                <span class="ins-req-need"><?= htmlspecialchars($rRequired) ?></span>
            </div>
            <?php endforeach; ?>
        </div>

        <?php if ($allOk): ?>
        <a href="?step=2" class="ins-btn ins-btn-primary">Continue to Database Setup →</a>
        <?php else: ?>
        <div class="ins-warn">
            <strong>⚠ <?= count($failed) ?> requirement<?= count($failed) > 1 ? 's' : '' ?> failed:</strong><br>
            <?= htmlspecialchars(implode(', ', $failed)) ?>.<br><br>
            Please install or enable the missing extensions, then refresh this page.
        </div>
        <a href="/install?step=1" class="ins-btn">Re-check Requirements</a>
        <?php endif; ?>

        <?php else: ?>
        <div class="ins-warn">
            <strong>⚠ Unable to load requirements</strong><br>
            The installer could not read the server configuration. Ensure all installation files are present and try again.
        </div>
        <a href="/install?step=1" class="ins-btn">Retry</a>
        <?php endif; ?>
    </div>
</main>

<div class="ins-footer">OwnPay · Secure Payment Platform · v<?php echo \OwnPay\Support\Version::CURRENT; ?></div>

<script nonce="<?php echo htmlspecialchars($csp_nonce ?? '', ENT_QUOTES, 'UTF-8'); ?>">
(function(){
    var btn = document.getElementById('insThemeToggle');
    if (!btn) return;
    var moon = document.getElementById('insMoon');
    var sun = document.getElementById('insSun');
    function updateIcons(theme) {
        if (theme === 'dark') {
            moon.style.display = 'none';
            sun.style.display = 'block';
        } else {
            moon.style.display = 'block';
            sun.style.display = 'none';
        }
    }
    updateIcons(document.documentElement.getAttribute('data-theme'));
    btn.addEventListener('click', function(){
        var current = document.documentElement.getAttribute('data-theme') || 'light';
        var next = current === 'dark' ? 'light' : 'dark';
        document.documentElement.setAttribute('data-theme', next);
        localStorage.setItem('op-theme', next);
        updateIcons(next);
    });
})();
</script>
</body>
</html>
