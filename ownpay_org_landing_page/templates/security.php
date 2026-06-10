<?php
declare(strict_types=1);
include TEMPLATE_PATH . '/header.php';
?>

<section class="section page-hero">
    <div class="container reveal">
        <span class="badge mb-4">Posture</span>
        <h1>Security &amp; Disclosure</h1>
        <p style="max-width: 600px; margin: 0 auto;">Our commitment to secure payment processing and zero-trust engineering.</p>
    </div>
</section>

<section class="page-content">
    <div class="container reveal" style="max-width: 700px; text-align: left;">
        
        <div class="feature-card mb-6" style="padding: var(--space-8); border-left: 4px solid var(--color-primary);">
            <h3 class="mb-4">1. Security is Our First-Class Concern</h3>
            <p class="mb-4">OwnPay handles real financial transactions. We believe a rushed release containing unresolved vulnerabilities is a disservice to the open-source community.</p>
            <p>Our release timeline is driven by quality and security milestones, not calendar deadlines. We refuse to publish code that has not been thoroughly audited.</p>
        </div>

        <div class="feature-card mb-6" style="padding: var(--space-8);">
            <h3 class="mb-4">2. Vulnerability Reporting Policy</h3>
            <p class="mb-4">If you discover a security vulnerability in the OwnPay core platform or official addons, please report it privately. Do not open public issues on GitHub.</p>
            <p class="mb-4">Send all vulnerability reports directly to: <a href="mailto:ping@ownpay.org">ping@ownpay.org</a>.</p>
            <p>We commit to acknowledging your report within 48 hours and providing a coordinate fix timeline within 7 days.</p>
        </div>

        <div class="feature-card mb-6" style="padding: var(--space-8);">
            <h3 class="mb-4">3. Pre-Release Security Audit</h3>
            <p class="mb-4">OwnPay is currently undergoing a comprehensive pre-release security audit (Alpha testing phase).</p>
            <p>We are hardening database inputs (PDO emulation disablement), enforcing strict sandboxing on plugin zip file uploads, auditing our double-entry ledger against concurrency race-conditions, and ensuring robust TOTP 2FA controls.</p>
        </div>

        <div class="feature-card" style="padding: var(--space-8); text-align: center;">
            <h3 class="mb-4">Looking for details?</h3>
            <p class="mb-6">Explore the custom framework boot pipeline and domain security resolver rules.</p>
            <a href="/architecture" class="btn btn-secondary">Read Architecture Deep Dive</a>
        </div>

    </div>
</section>

<?php
include TEMPLATE_PATH . '/footer.php';
?>
