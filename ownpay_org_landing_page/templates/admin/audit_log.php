<?php
declare(strict_types=1);
include TEMPLATE_PATH . '/admin/header.php';
?>

<!-- CSV Export -->
<div style="display: flex; justify-content: flex-end; margin-bottom: var(--space-4);">
    <a href="/admin/audit-log?action=export" class="btn btn-secondary" style="font-size: 0.85rem; padding: 10px 16px;">
        <i class="ph ph-download-simple"></i> Export Audit Log CSV
    </a>
</div>

<!-- Logs Table -->
<div class="admin-table-container">
    <table class="admin-table">
        <thead>
            <tr>
                <th>Timestamp</th>
                <th>User</th>
                <th>Action</th>
                <th>Details</th>
                <th>IP Address</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($logs)): ?>
                <tr>
                    <td colspan="5" style="text-align: center; padding: var(--space-6);">No audit logs recorded.</td>
                </tr>
            <?php else: ?>
                <?php foreach ($logs as $log): ?>
                    <tr>
                        <td><span style="font-family: var(--font-mono); font-size: 0.8rem;"><?php echo date('Y-m-d H:i:s', strtotime($log['created_at'])); ?></span></td>
                        <td><strong><?php echo htmlspecialchars($log['username'] ?: 'Public User / Waitlist'); ?></strong></td>
                        <td>
                            <span class="admin-badge-planned" style="text-transform: none; font-size: 0.7rem; font-weight: 700; color: var(--color-primary); background: rgba(212,175,55,0.05); border-color: rgba(212,175,55,0.15);">
                                <?php echo htmlspecialchars($log['action']); ?>
                            </span>
                        </td>
                        <td>
                            <span style="font-size: 0.8rem;"><?php echo htmlspecialchars($log['details'] ?? 'N/A'); ?></span>
                        </td>
                        <td><span style="font-family: var(--font-mono); font-size: 0.8rem;"><?php echo htmlspecialchars($log['ip_address']); ?></span></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<?php
include TEMPLATE_PATH . '/admin/footer.php';
?>
