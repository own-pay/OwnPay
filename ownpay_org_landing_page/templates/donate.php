<?php
declare(strict_types=1);
include TEMPLATE_PATH . '/header.php';
?>

<section class="section page-hero">
    <div class="container reveal">
        <span class="badge mb-4">Support OwnPay</span>
        <h1>Supporter Center</h1>
        <p style="max-width: 600px; margin: 0 auto;">Help keep the OwnPay open-source payment ecosystem free, secure, and independent.</p>
    </div>
</section>

<section class="page-content">
    <div class="container text-center reveal">
        
        <?php if (($_GET['view'] ?? '') === 'success_message' && isset($_SESSION['completed_donation'])): ?>
            <!-- Thank You / Message Submission Form -->
            <div class="page-card" style="max-width: 500px;">
                <div style="font-size: 3rem; color: #10b981; margin-bottom: var(--space-4);">
                    <i class="ph ph-shield-check"></i>
                </div>
                <h3>Thank You for Your Support!</h3>
                <p class="mb-6">Your donation has been verified successfully. Enter your name and a short message to be featured on our Donor Hall of Fame.</p>
                
                <form action="/donate/message" method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo $this->csrfToken(); ?>">
                    
                    <div class="form-group">
                        <label for="custom_name">Display Name (Optional)</label>
                        <input type="text" id="custom_name" name="custom_name" placeholder="Leave empty for Anonymous" value="<?php echo htmlspecialchars($_SESSION['completed_donation']['name'] ?? ''); ?>">
                    </div>

                    <div class="form-group">
                        <label for="message">Your Message (Optional)</label>
                        <textarea id="message" name="message" rows="3" placeholder="Write a short message..."></textarea>
                    </div>

                    <button type="submit" class="btn btn-primary" style="width: 100%;">Submit &amp; View Hall of Fame</button>
                </form>
            </div>

        <?php else: ?>
            <!-- Main Donation Form -->
            <div class="page-card">
                <h3>Make a Secure Donation</h3>
                <p class="mb-6" style="font-size: 0.9rem;">Your donation directly funds security audits, VPS hosting infrastructure, domain services, and core engineering time.</p>
                
                <?php if (!empty($error)): ?>
                    <div class="badge badge-planned mb-6" style="padding: 12px 24px; text-transform: none; display: block; color: #ef4444; border-color: rgba(239, 68, 68, 0.2); background: rgba(239, 68, 68, 0.05); text-align: left;">
                        <strong>Error:</strong> <?php echo htmlspecialchars($error); ?>
                    </div>
                <?php endif; ?>

                <form action="/donate/initiate" method="POST" id="donation-form">
                    <input type="hidden" name="csrf_token" value="<?php echo $this->csrfToken(); ?>">

                    <!-- Amount Selection -->
                    <div class="form-group">
                        <label>Select Amount</label>
                        <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: var(--space-2); margin-bottom: var(--space-3);">
                            <button type="button" class="btn btn-secondary amount-btn" data-val="5">$5</button>
                            <button type="button" class="btn btn-secondary amount-btn" data-val="10">$10</button>
                            <button type="button" class="btn btn-secondary amount-btn" data-val="25">$25</button>
                            <button type="button" class="btn btn-secondary amount-btn" data-val="50">$50</button>
                        </div>
                        <input type="number" step="0.01" id="amount" name="amount" placeholder="Enter custom amount" required min="1" style="text-align: center; font-size: 1.1rem; font-weight: 700;">
                    </div>

                    <!-- Currency Selection -->
                    <div class="form-group">
                        <label for="currency">Currency</label>
                        <select id="currency" name="currency">
                            <option value="USD">USD ($)</option>
                            <option value="BDT" selected>BDT (৳)</option>
                            <option value="EUR">EUR (€)</option>
                            <option value="GBP">GBP (£)</option>
                        </select>
                    </div>

                    <!-- Anonymous Toggle -->
                    <div class="form-group" style="display: flex; align-items: center; gap: var(--space-2); margin-bottom: var(--space-6);">
                        <input type="checkbox" id="anonymous" name="anonymous" value="1" style="width: auto;">
                        <label for="anonymous" style="margin-bottom: 0; cursor: pointer;">Donate Anonymously</label>
                    </div>

                    <!-- Donor Details (Hidden if anonymous checked) -->
                    <div id="donor-details">
                        <div class="form-group">
                            <label for="name">Your Name</label>
                            <input type="text" id="name" name="name" placeholder="John Doe">
                        </div>

                        <div class="form-group">
                            <label for="email">Email Address</label>
                            <input type="email" id="email" name="email" placeholder="john@company.com">
                        </div>

                        <div class="form-group">
                            <label for="phone">Phone Number</label>
                            <input type="text" id="phone" name="phone" placeholder="017XXXXXXXX">
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary" style="width: 100%; font-size: 1rem; padding: 14px 28px; margin-top: var(--space-4);">
                        Proceed to Secure Payment Adapter
                    </button>
                </form>
            </div>
            
            <!-- Dynamic Metrics showcase -->
            <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: var(--space-4); max-width: 640px; margin: var(--space-8) auto 0 auto;">
                <div class="feature-card" style="padding: var(--space-6);">
                    <h3 style="color: var(--color-primary); font-size: 2rem;"><?php echo $totalMonetarySponsors; ?></h3>
                    <p style="font-size: 0.75rem; text-transform: uppercase;">Total Donors</p>
                </div>
                <div class="feature-card" style="padding: var(--space-6);">
                    <h3 style="color: var(--color-primary); font-size: 2rem;">৳<?php echo number_format($totalAmountBDT, 2); ?></h3>
                    <p style="font-size: 0.75rem; text-transform: uppercase;">Total BDT Raised</p>
                </div>
            </div>
        <?php endif; ?>

    </div>
</section>

<script nonce="<?php echo $cspNonce; ?>">
document.addEventListener('DOMContentLoaded', () => {
    const amountBtns = document.querySelectorAll('.amount-btn');
    const amountInput = document.getElementById('amount');
    const anonymousCheck = document.getElementById('anonymous');
    const donorDetails = document.getElementById('donor-details');

    if (amountBtns && amountInput) {
        amountBtns.forEach(btn => {
            btn.addEventListener('click', () => {
                amountInput.value = btn.getAttribute('data-val');
                amountBtns.forEach(b => b.classList.remove('btn-primary'));
                amountBtns.forEach(b => b.classList.add('btn-secondary'));
                btn.classList.remove('btn-secondary');
                btn.classList.add('btn-primary');
            });
        });

        amountInput.addEventListener('input', () => {
            amountBtns.forEach(b => b.classList.remove('btn-primary'));
            amountBtns.forEach(b => b.classList.add('btn-secondary'));
        });
    }

    if (anonymousCheck && donorDetails) {
        anonymousCheck.addEventListener('change', () => {
            if (anonymousCheck.checked) {
                donorDetails.style.display = 'none';
                document.getElementById('name').required = false;
                document.getElementById('email').required = false;
                document.getElementById('phone').required = false;
            } else {
                donorDetails.style.display = 'block';
                document.getElementById('name').required = true;
                document.getElementById('email').required = true;
                document.getElementById('phone').required = true;
            }
        });
    }
});
</script>

<?php
include TEMPLATE_PATH . '/footer.php';
?>
