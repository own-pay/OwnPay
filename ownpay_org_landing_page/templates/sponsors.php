<?php
declare(strict_types=1);
include TEMPLATE_PATH . '/header.php';
?>

<section class="section page-hero">
    <div class="container reveal">
        <span class="badge mb-4">Partners</span>
        <h1>Sponsors Showcase</h1>
        <p style="max-width: 600px; margin: 0 auto;">Honoring the corporate and organizational sponsors fueling OwnPay infrastructure.</p>
    </div>
</section>

<section class="page-content">
    <div class="container reveal" style="max-width: 800px;">
        
        <div style="display: flex; flex-direction: column; gap: var(--space-6); margin-bottom: var(--space-12);">
            <?php foreach ($sponsors as $s): ?>
                <div class="feature-card" style="display: flex; flex-direction: column; md-flex-direction: row; gap: var(--space-6); align-items: center; text-align: left; padding: var(--space-8); background-color: var(--color-surface); border: 1px solid var(--color-border); border-radius: var(--radius-lg);">
                    <div style="width: 140px; display: flex; align-items: center; justify-content: center; shrink-0: 0;">
                        <img src="/<?php echo htmlspecialchars($s['logo_path']); ?>" alt="<?php echo htmlspecialchars($s['name']); ?> Logo" style="max-width: 100%; max-height: 50px; object-fit: contain;">
                    </div>
                    <div style="flex: 1;">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: var(--space-2); flex-wrap: wrap; gap: var(--space-2);">
                            <h3 style="font-size: 1.5rem; margin-bottom: 0;"><?php echo htmlspecialchars($s['name']); ?></h3>
                            <span class="badge <?php 
                                $t = strtolower($s['tier'] ?? '');
                                echo ($t === 'gold') ? 'badge' : (($t === 'silver') ? 'badge-success' : 'badge-planned'); 
                            ?>"><?php echo htmlspecialchars($s['tier']); ?> Partner</span>
                        </div>
                        <p style="font-size: 0.95rem; line-height: 1.6; margin-bottom: var(--space-4);">
                            <?php echo htmlspecialchars($s['description'] ?? ''); ?>
                        </p>
                        <a href="<?php echo htmlspecialchars($s['website_url']); ?>" target="_blank" rel="nofollow noopener noreferrer" class="btn btn-secondary" style="padding: 8px 16px; font-size: 0.8rem; display: inline-flex;">
                            Visit Website &rarr;
                        </a>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <!-- Become a sponsor CTA -->
        <div class="page-card text-center" style="max-width: 600px; padding: var(--space-8);">
            <h3>Become an Infrastructure Partner</h3>
            <p class="mb-6" style="font-size: 0.9rem;">OwnPay welcomes sponsors who align with our open-source, data sovereign mission. Gain visibility in our developer ecosystem while supporting secure payment engineering.</p>
            <a href="mailto:ping@ownpay.org" class="btn btn-primary">Become a Sponsor</a>
        </div>

    </div>
</section>

<?php
include TEMPLATE_PATH . '/footer.php';
?>
