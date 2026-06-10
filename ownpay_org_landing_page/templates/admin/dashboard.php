<?php
declare(strict_types=1);
include TEMPLATE_PATH . '/admin/header.php';
?>

<!-- 1. Stats Grid -->
<div class="admin-card-grid">
    <div class="admin-stat-card">
        <div class="admin-stat-info">
            <h3><?php echo $subscribersCount; ?></h3>
            <p>Total Subscribers</p>
        </div>
        <div class="admin-stat-icon"><i class="ph ph-envelope-simple"></i></div>
    </div>
    <div class="admin-stat-card">
        <div class="admin-stat-info">
            <h3><?php echo $subscribersToday; ?></h3>
            <p>Subscribers Today</p>
        </div>
        <div class="admin-stat-icon"><i class="ph ph-envelope-open"></i></div>
    </div>
    <div class="admin-stat-card">
        <div class="admin-stat-info">
            <h3>৳<?php echo number_format($donationsTotalBDT, 2); ?></h3>
            <p>BDT Donations</p>
        </div>
        <div class="admin-stat-icon"><i class="ph ph-currency-circle-dollar"></i></div>
    </div>
    <div class="admin-stat-card">
        <div class="admin-stat-info">
            <h3><?php echo $sponsorsCount; ?> / <?php echo $contributorsCount; ?></h3>
            <p>Sponsors / Contributors</p>
        </div>
        <div class="admin-stat-icon"><i class="ph ph-users-three"></i></div>
    </div>
</div>

<!-- 2. System Status & Quick Actions -->
<div class="admin-card-grid" style="grid-template-columns: repeat(1, 1fr) repeat(1, 1fr); gap: var(--space-6); margin-bottom: var(--space-8);">
    <!-- System Checks -->
    <div class="feature-card" style="text-align: left; padding: var(--space-6);">
        <h3>System Integration Checks</h3>
        <p class="mb-6" style="font-size: 0.8rem; color: var(--color-text-dim);">Verify connected gateway API adapters and sync state.</p>
        <ul style="list-style: none; font-size: 0.9rem; display: flex; flex-direction: column; gap: var(--space-3);">
            <li style="display: flex; justify-content: space-between; border-bottom: 1px solid var(--color-border); padding-bottom: var(--space-2);">
                <span>MailerLite Sync Hook:</span>
                <strong style="color: <?php echo ($mailerliteStatus === 'Configured') ? '#10b981' : '#f59e0b'; ?>;"><?php echo $mailerliteStatus; ?></strong>
            </li>
            <li style="display: flex; justify-content: space-between; border-bottom: 1px solid var(--color-border); padding-bottom: var(--space-2);">
                <span>SMTP Transactional Mail:</span>
                <strong style="color: <?php echo ($smtpStatus === 'Configured') ? '#10b981' : '#f59e0b'; ?>;"><?php echo $smtpStatus; ?></strong>
            </li>
            <li style="display: flex; justify-content: space-between; padding-bottom: var(--space-2);">
                <span>Last Sitemap Regeneration:</span>
                <strong><?php echo $lastSitemap; ?></strong>
            </li>
        </ul>
    </div>

    <!-- Quick Actions -->
    <div class="feature-card" style="text-align: left; padding: var(--space-6); display: flex; flex-direction: column; justify-content: space-between;">
        <div>
            <h3>Quick Engine Actions</h3>
            <p style="font-size: 0.8rem; color: var(--color-text-dim); margin-bottom: var(--space-4);">Manually trigger core functions or sync utilities.</p>
        </div>
        <div style="display: flex; flex-direction: column; gap: var(--space-3);">
            <form action="/admin/sitemap" method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo $this->csrfToken(); ?>">
                <button type="submit" class="btn btn-secondary" style="width: 100%; font-size: 0.85rem;">
                    <i class="ph ph-globe"></i> Regenerate public/sitemap.xml
                </button>
            </form>
            <a href="/admin/subscribers?action=export" class="btn btn-secondary" style="width: 100%; font-size: 0.85rem;">
                <i class="ph ph-download-simple"></i> Export Subscribers CSV
            </a>
        </div>
    </div>
</div>

<!-- 3. Tables Row -->
<div class="admin-card-grid" style="grid-template-columns: repeat(1, 1fr) repeat(1, 1fr); gap: var(--space-6);">
    <!-- Recent Waitlist Subscribers -->
    <div class="admin-table-container" style="margin-bottom: 0; padding: var(--space-4); text-align: left;">
        <h3 style="font-size: 1.1rem; margin-bottom: var(--space-4);">Recent Waitlist Subscribers</h3>
        <table class="admin-table">
            <thead>
                <tr>
                    <th>Email Address</th>
                    <th>Subscribed At</th>
                    <th>MailerLite</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($recentSubscribers)): ?>
                    <tr>
                        <td colspan="3" style="text-align: center;">No subscribers yet.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($recentSubscribers as $s): ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($s['email']); ?></strong></td>
                            <td><?php echo date('M j, Y H:i', strtotime($s['subscribed_at'])); ?></td>
                            <td>
                                <?php if ($s['mailerlite_synced']): ?>
                                    <span class="admin-badge-synced">Synced</span>
                                <?php else: ?>
                                    <span class="admin-badge-pending">Pending</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Recent Donations -->
    <div class="admin-table-container" style="margin-bottom: 0; padding: var(--space-4); text-align: left;">
        <h3 style="font-size: 1.1rem; margin-bottom: var(--space-4);">Recent Donations</h3>
        <table class="admin-table">
            <thead>
                <tr>
                    <th>Donor Name</th>
                    <th>Amount (BDT)</th>
                    <th>Tier</th>
                    <th>Date</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($recentDonations)): ?>
                    <tr>
                        <td colspan="4" style="text-align: center;">No donations yet.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($recentDonations as $d): ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($d['donor_name'] ?: 'Anonymous Support'); ?></strong></td>
                            <td style="color: var(--color-primary); font-weight: 700;">৳<?php echo number_format((float)$d['amount'], 2); ?></td>
                            <td><?php echo htmlspecialchars($d['tier'] ?? 'Community'); ?></td>
                            <td><?php echo date('M j, Y', strtotime($d['created_at'])); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php
include TEMPLATE_PATH . '/admin/footer.php';
?>
