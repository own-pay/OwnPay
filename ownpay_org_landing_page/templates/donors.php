<?php
declare(strict_types=1);
include TEMPLATE_PATH . '/header.php';
?>

<section class="section page-hero">
    <div class="container reveal">
        <span class="badge mb-4">Gratitude</span>
        <h1>Donor Hall of Fame</h1>
        <p style="max-width: 600px; margin: 0 auto;">Honoring the individuals and companies who have backed OwnPay financially.</p>
    </div>
</section>

<section class="page-content">
    <div class="container reveal" style="max-width: 700px;">
        
        <?php if (empty($donors)): ?>
            <div class="page-card text-center" style="padding: var(--space-12);">
                <div style="font-size: 3rem; color: var(--color-text-dim); margin-bottom: var(--space-4);">
                    <i class="ph ph-hand-heart"></i>
                </div>
                <h3>No Public Donors Yet</h3>
                <p class="mb-6">Be the very first to support free, self-hosted payment infrastructure!</p>
                <a href="/donate" class="btn btn-primary">Donate to OwnPay</a>
            </div>
        <?php else: ?>
            <div style="display: flex; flex-direction: column; gap: var(--space-4); margin-bottom: var(--space-8);">
                <?php foreach ($donors as $d): ?>
                    <div class="feature-card" style="display: flex; gap: var(--space-4); text-align: left; align-items: flex-start; padding: var(--space-6);">
                        <div style="width: 44px; height: 44px; border-radius: 50%; background-color: var(--color-border); color: var(--color-primary); display: flex; align-items: center; justify-content: center; font-size: 1.1rem; shrink-0: 0;">
                            <i class="ph ph-heart-straight"></i>
                        </div>
                        <div style="flex: 1;">
                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: var(--space-1);">
                                <h4 style="font-size: 1.05rem;"><?php echo htmlspecialchars($d['donor_name'] ?: 'Anonymous Support'); ?></h4>
                                <span class="badge <?php 
                                    $t = strtolower($d['tier'] ?? '');
                                    echo ($t === 'gold') ? 'badge' : (($t === 'silver') ? 'badge-success' : (($t === 'bronze') ? 'badge-progress' : 'badge-planned')); 
                                ?>" style="font-size: 0.6rem;"><?php echo htmlspecialchars($d['tier'] ?? 'Community'); ?></span>
                            </div>
                            <div style="font-size: 0.75rem; color: var(--color-text-dim); margin-bottom: var(--space-2);">
                                <?php echo date('F j, Y', strtotime($d['created_at'])); ?>
                            </div>
                            <?php if (!empty($d['message'])): ?>
                                <p style="font-size: 0.9rem; color: var(--color-text-main); font-style: italic; background-color: rgba(255,255,255,0.02); padding: var(--space-3); border-radius: var(--radius-sm); border-left: 2px solid var(--color-primary);">
                                    &ldquo;<?php echo htmlspecialchars($d['message']); ?>&rdquo;
                                </p>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- Pagination -->
            <?php if ($totalPages > 1): ?>
                <div style="display: flex; justify-content: center; gap: var(--space-2);">
                    <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                        <a href="/donors?page=<?php echo $i; ?>" class="btn <?php echo ($page === $i) ? 'btn-primary' : 'btn-secondary'; ?>" style="padding: 6px 12px; font-size: 0.8rem;">
                            <?php echo $i; ?>
                        </a>
                    <?php endfor; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>

    </div>
</section>

<?php
include TEMPLATE_PATH . '/footer.php';
?>
