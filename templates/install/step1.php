<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex,nofollow">
    <title>Server Requirements · Own Pay Setup</title>
    <link rel="stylesheet" href="/assets/css/installer.css?v=3">
</head>
<body>
<header class="ins-header">
    <div class="ins-brand">
        <div class="ins-logo-fallback">OP</div>
        <span class="ins-name">Own Pay <span>Setup</span></span>
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
        <p class="ins-sub">Checking that your local system matches the strict, production-ready environment specifications required for secure Fintech payment processing.</p>

        <div class="ins-req-list">
            <?php $allOk = true; foreach ($requirements as $r): $allOk = $allOk && $r['ok']; ?>
            <div class="ins-req <?= $r['ok'] ? 'ins-req-ok' : 'ins-req-fail' ?>">
                <span class="ins-req-icon"><?= $r['ok'] ? '✓' : '✗' ?></span>
                <span class="ins-req-name"><?= htmlspecialchars($r['name']) ?></span>
                <span class="ins-req-val"><?= htmlspecialchars($r['current']) ?></span>
                <span class="ins-req-need"><?= htmlspecialchars($r['required']) ?></span>
            </div>
            <?php endforeach; ?>
        </div>

        <?php if ($allOk): ?>
        <a href="?step=2" class="ins-btn">Continue to Database Setup →</a>
        <?php else: ?>
        <div class="ins-warn">⚠ One or more critical system parameters are invalid. Please check your PHP configurations and directories, then try again.</div>
        <a href="/install?step=1" class="ins-btn ins-btn-outline">Re-check Server Parameters</a>
        <?php endif; ?>
    </div>
</main>

<div class="ins-footer">Own Pay · High-Transaction Secured Payment Platform · v0.1.0</div>
</body>
</html>
