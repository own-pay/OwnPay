<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex,nofollow">
    <title>Locked · OwnPay Setup</title>
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
</header>

<main class="ins-main">
    <div class="ins-card ins-locked">
        <div class="ins-locked-icon">🔒</div>
        <h2>Installation Locked</h2>
        <p class="ins-sub ins-locked-p1">OwnPay is already installed and fully operational on this system.</p>
        <p class="ins-sub ins-locked-p2">For security reasons, the installation wizard cannot be re-run while a master configuration is active. If you must rebuild the gateway environment, you must manually delete the active lockout key from your local server terminal: <code>storage/.installed</code></p>
        <a href="/admin/login" class="ins-btn ins-btn-primary">Navigate to Login Dashboard</a>
    </div>
</main>

<div class="ins-footer">OwnPay · High-Transaction Secured Payment Platform · v0.1.0</div>
</body>
</html>
