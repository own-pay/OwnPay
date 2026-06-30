<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex,nofollow">
    <title>Server Requirements · OwnPay Setup</title>
    <link rel="stylesheet" href="/assets/css/installer.css?v=4">
    <script nonce="<?php echo bin2hex(random_bytes(16)); ?>">
        (function(){var t=localStorage.getItem('op-theme');if(t==='dark')document.documentElement.setAttribute('data-theme','dark');})();
    </script>
</head>
<body>
<header class="ins-header">
    <div class="ins-brand">
        <img src="/assets/img/logo-light.svg" alt="OwnPay" class="op-logo-light" style="height: 32px; width: auto;">
        <img src="/assets/img/logo-dark.svg" alt="OwnPay" class="op-logo-dark" style="height: 32px; width: auto;">
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
                $isOk = !empty($r['ok']);
                if (!$isOk) {
                    $allOk = false;
                    $failed[] = $r['name'] ?? 'Unknown';
                }
            ?>
            <div class="ins-req <?= $isOk ? 'ins-req-ok' : 'ins-req-fail' ?>">
                <span class="ins-req-icon"><?= $isOk ? '✓' : '✗' ?></span>
                <span class="ins-req-name"><?= htmlspecialchars($r['name'] ?? '') ?></span>
                <span class="ins-req-val"><?= htmlspecialchars($r['current'] ?? 'Not found') ?></span>
                <span class="ins-req-need"><?= htmlspecialchars($r['required'] ?? '') ?></span>
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

<div class="ins-footer">OwnPay · Secure Payment Platform · v0.1.0</div>
</body>
</html>
