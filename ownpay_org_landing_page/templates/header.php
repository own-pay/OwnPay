<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo isset($title) ? htmlspecialchars($title) : 'OwnPay | Enterprise Self-Hosted Payment Infrastructure'; ?></title>
    <link rel="icon" type="image/png" href="/ownpay_icon.png">
    
    <!-- Canonical URL -->
    <link rel="canonical" href="<?php echo htmlspecialchars(APP_URL . $_SERVER['REQUEST_URI']); ?>">

    <!-- SEO Meta Tags -->
    <meta name="description" content="<?php echo isset($description) ? htmlspecialchars($description) : 'The 100% open-source, self-hosted payment gateway automation platform.'; ?>">
    <meta name="keywords" content="OwnPay, self-hosted payments, open source payment gateway, white-label gateway, Stripe alternative">
    
    <!-- Open Graph -->
    <meta property="og:type" content="website">
    <meta property="og:url" content="<?php echo htmlspecialchars(APP_URL . $_SERVER['REQUEST_URI']); ?>">
    <meta property="og:title" content="<?php echo isset($title) ? htmlspecialchars($title) : 'OwnPay'; ?>">
    <meta property="og:description" content="<?php echo isset($description) ? htmlspecialchars($description) : 'The 100% open-source, self-hosted payment gateway automation platform.'; ?>">
    <meta property="og:image" content="<?php echo APP_URL; ?>/donate_og.jpg">

    <!-- Twitter Card -->
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:url" content="<?php echo htmlspecialchars(APP_URL . $_SERVER['REQUEST_URI']); ?>">
    <meta name="twitter:title" content="<?php echo isset($title) ? htmlspecialchars($title) : 'OwnPay'; ?>">
    <meta name="twitter:description" content="<?php echo isset($description) ? htmlspecialchars($description) : 'The 100% open-source, self-hosted payment gateway automation platform.'; ?>">
    <meta name="twitter:image" content="<?php echo APP_URL; ?>/donate_og.jpg">

    <!-- Google Fonts with swap -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Outfit:wght@400;500;600;700;800;900&family=JetBrains+Mono:wght@400;500;700&display=swap" rel="stylesheet">
    
    <!-- Phosphor Icons via CDN (Non-render blocking, defer or load asynchronously) -->
    <link rel="stylesheet" href="https://unpkg.com/@phosphor-icons/web@2.1.1/src/regular/style.css">

    <!-- Custom CSS (Obsidian Dark & Gold Theme) -->
    <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>

    <!-- Announcement Bar -->
    <?php if (isset($settings['announcement_bar_enabled']) && $settings['announcement_bar_enabled'] === '1' && !empty($settings['announcement_bar_text'])): ?>
        <div class="announcement-bar" id="announcement-bar">
            <?php echo htmlspecialchars($settings['announcement_bar_text']); ?>
            <span id="close-announcement" style="float: right; cursor: pointer; padding-left: 10px;">&times;</span>
        </div>
    <?php endif; ?>

    <!-- Navigation Header -->
    <nav class="navbar" id="navbar">
        <div class="container">
            <a href="/" class="nav-logo">
                <img src="/ownpay_icon.png" alt="OwnPay Logo" style="height: 30px;">
                <span>OwnPay</span>
            </a>
            
            <div class="nav-links">
                <a href="/#how-it-works">How It Works</a>
                <a href="/#roadmap">Roadmap</a>
                <a href="/#faq">FAQ</a>
                <a href="/donors">Donor Hall of Fame</a>
                <a href="/donate" style="color: #ef4444;">Sponsor &amp; Donate ❤️</a>
            </div>

            <div class="nav-actions">
                <a href="https://github.com/own-pay/ownpay" target="_blank" rel="noopener noreferrer" class="btn btn-secondary" style="padding: 8px 16px;">
                    <i class="ph ph-github-logo" style="font-size: 1.1rem; vertical-align: middle;"></i>
                    Star on GitHub
                </a>
            </div>
        </div>
    </nav>
