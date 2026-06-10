<?php
declare(strict_types=1);
include TEMPLATE_PATH . '/admin/header.php';
?>

<!-- Statistics Overview -->
<div class="admin-card-grid" style="grid-template-columns: repeat(2, 1fr); margin-bottom: var(--space-6);">
    <div class="admin-stat-card">
        <div class="admin-stat-info">
            <h3><?php echo (int)($stats['count'] ?? 0); ?></h3>
            <p>Total Supporters</p>
        </div>
        <div class="admin-stat-icon"><i class="ph ph-hand-heart"></i></div>
    </div>
    <div class="admin-stat-card">
        <div class="admin-stat-info">
            <h3>৳<?php echo number_format((float)($stats['total'] ?? 0.0), 2); ?></h3>
            <p>Total Funds Raised (BDT)</p>
        </div>
        <div class="admin-stat-icon"><i class="ph ph-currency-circle-dollar"></i></div>
    </div>
</div>

<!-- Donations Table -->
<div class="admin-table-container">
    <table class="admin-table">
        <thead>
            <tr>
                <th>Donor Name</th>
                <th>Email Address</th>
                <th>Amount (BDT)</th>
                <th>Tier</th>
                <th>Message</th>
                <th>Visibility</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($donations)): ?>
                <tr>
                    <td colspan="7" style="text-align: center; padding: var(--space-6);">No donations found.</td>
                </tr>
            <?php else: ?>
                <?php foreach ($donations as $d): ?>
                    <tr>
                        <td><strong><?php echo htmlspecialchars($d['donor_name'] ?: 'Anonymous Support'); ?></strong></td>
                        <td><?php echo htmlspecialchars($d['email'] ?: 'N/A'); ?></td>
                        <td style="color: var(--color-primary); font-weight: 700;">৳<?php echo number_format((float)$d['amount'], 2); ?></td>
                        <td><?php echo htmlspecialchars($d['tier'] ?? 'Community'); ?></td>
                        <td>
                            <?php if (!empty($d['message'])): ?>
                                <span style="font-size: 0.8rem; font-style: italic;">&ldquo;<?php echo htmlspecialchars($d['message']); ?>&rdquo;</span>
                            <?php else: ?>
                                <span style="color: var(--color-text-dim);">No message.</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($d['public_display']): ?>
                                <span class="admin-badge-synced">Public</span>
                            <?php else: ?>
                                <span class="admin-badge-pending">Hidden</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div style="display: flex; gap: var(--space-2);">
                                <form action="/admin/donations?action=toggle&id=<?php echo $d['id']; ?>" method="POST" style="display: inline;">
                                    <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                                    <button type="submit" class="btn btn-secondary" style="font-size: 0.75rem; padding: 6px 10px;">
                                        Toggle Visibility
                                    </button>
                                </form>
                                <form action="/admin/donations?action=delete&id=<?php echo $d['id']; ?>" method="POST" style="display: inline;" onsubmit="return confirm('Are you sure you want to delete this donation record?');">
                                    <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                                    <button type="submit" class="btn btn-danger" style="font-size: 0.75rem; padding: 6px 10px;">
                                        Delete
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<?php
include TEMPLATE_PATH . '/admin/footer.php';
?>
