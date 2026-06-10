<?php
declare(strict_types=1);
include TEMPLATE_PATH . '/header.php';
?>

<section class="section page-hero">
    <div class="container reveal">
        <span class="badge mb-4">Legal</span>
        <h1>Privacy Policy</h1>
        <p style="max-width: 600px; margin: 0 auto;">Honest, transparent, and tracker-free data practices of OwnPay.</p>
    </div>
</section>

<section class="page-content">
    <div class="container reveal" style="max-width: 700px; text-align: left;">
        
        <div class="feature-card" style="margin-bottom: var(--space-6); padding: var(--space-8);">
            <h3 class="mb-4">1. We Do Not Track You</h3>
            <p class="mb-4">OwnPay is built on a sovereign, self-hosted brand. The public landing site utilizes zero tracking pixels, zero analytics scripts, and zero marketing cookies.</p>
            <p>Our servers log standard connection details (IP address, user-agent) purely for security audit logs, rate-limiting, and preventing malicious queries.</p>
        </div>

        <div class="feature-card" style="margin-bottom: var(--space-6); padding: var(--space-8);">
            <h3 class="mb-4">2. What Data We Collect</h3>
            <p class="mb-4"><strong>Waitlist Emails:</strong> If you join our pre-release waitlist, we collect your email address, subscription date, and subscriber source. This email is stored securely in our database and synced to MailerLite for dispatching updates.</p>
            <p><strong>Donations:</strong> If you donate, we collect the donation amount, date, transaction ID, email, name, and phone number (if you don't choose anonymous). This is required to execute transaction verification with the payment gateway and generate the donor card.</p>
        </div>

        <div class="feature-card" style="margin-bottom: var(--space-6); padding: var(--space-8);">
            <h3 class="mb-4">3. Data Sharing &amp; Third-Parties</h3>
            <p class="mb-4">We do not sell, rent, or trade your email address or transaction history with any third-party. The only integrations we run are:</p>
            <ul>
                <li style="margin-left: 20px; margin-bottom: 5px;"><strong>MailerLite:</strong> Used strictly for mailing list dispatch. You can unsubscribe at any instant.</li>
                <li style="margin-left: 20px;"><strong>EPS Payment Gateway:</strong> Processed directly during Supporter Center donations.</li>
            </ul>
        </div>

        <div class="feature-card" style="margin-bottom: var(--space-6); padding: var(--space-8);">
            <h3 class="mb-4">4. Cookies Policy</h3>
            <p class="mb-4">We only set strict security session cookies to handle administrative authentication (CSRF protection, session ID). We do not set persistent advertising, tracking, or analytics cookies.</p>
        </div>

        <div class="feature-card" style="padding: var(--space-8);">
            <h3 class="mb-4">5. Contact Information</h3>
            <p>If you have any questions about our privacy guidelines, feel free to contact us at <a href="mailto:ping@ownpay.org">ping@ownpay.org</a>.</p>
        </div>

    </div>
</section>

<?php
include TEMPLATE_PATH . '/footer.php';
?>
