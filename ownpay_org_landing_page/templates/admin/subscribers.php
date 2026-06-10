<?php
declare(strict_types=1);
include TEMPLATE_PATH . '/admin/header.php';
?>

<!-- Search Bar & CSV Export -->
<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: var(--space-6); flex-wrap: wrap; gap: var(--space-4);">
    <form action="/admin/subscribers" method="GET" style="display: flex; gap: var(--space-2); max-width: 400px; width: 100%;">
        <div class="form-group" style="margin-bottom: 0; flex: 1;">
            <input type="text" name="q" placeholder="Search by email..." value="<?php echo htmlspecialchars($search); ?>" style="padding: 10px; font-size: 0.85rem;">
        </div>
        <button type="submit" class="btn btn-secondary" style="padding: 10px 16px;"><i class="ph ph-magnifying-glass"></i> Search</button>
    </form>
    <div>
        <a href="/admin/subscribers?action=export" class="btn btn-secondary" style="font-size: 0.85rem; padding: 10px 16px;">
            <i class="ph ph-download-simple"></i> Export CSV
        </a>
    </div>
</div>

<!-- Bulk Actions Form -->
<form action="/admin/subscribers" method="POST">
    <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
    
    <div style="display: flex; align-items: center; gap: var(--space-3); margin-bottom: var(--space-4);">
        <select name="bulk_action" style="padding: var(--space-2); background-color: var(--color-surface); border: 1px solid var(--color-border); color: var(--color-text-main); font-size: 0.85rem; border-radius: var(--radius-sm);">
            <option value="">Bulk Actions</option>
            <option value="sync">Sync Selected to MailerLite</option>
            <option value="delete">Delete Selected</option>
        </select>
        <button type="submit" class="btn btn-secondary" style="font-size: 0.8rem; padding: 8px 16px;">Apply</button>
    </div>

    <!-- Table -->
    <div class="admin-table-container">
        <table class="admin-table">
            <thead>
                <tr>
                    <th style="width: 40px; text-align: center;"><input type="checkbox" id="select-all"></th>
                    <th>Email Address</th>
                    <th>Subscribed At</th>
                    <th>Source</th>
                    <th>MailerLite Sync</th>
                    <th>MailerLite ID</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($subscribers)): ?>
                    <tr>
                        <td colspan="6" style="text-align: center; padding: var(--space-6);">No subscribers found.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($subscribers as $s): ?>
                        <tr>
                            <td style="text-align: center;"><input type="checkbox" name="selected_emails[]" value="<?php echo htmlspecialchars($s['email']); ?>" class="sub-checkbox"></td>
                            <td><strong><?php echo htmlspecialchars($s['email']); ?></strong></td>
                            <td><?php echo date('M j, Y H:i:s', strtotime($s['subscribed_at'])); ?></td>
                            <td><?php echo htmlspecialchars($s['source'] ?? 'landing_page'); ?></td>
                            <td>
                                <?php if ($s['mailerlite_synced']): ?>
                                    <span class="admin-badge-synced">Synced</span>
                                <?php else: ?>
                                    <span class="admin-badge-pending">Pending</span>
                                <?php endif; ?>
                            </td>
                            <td><span style="font-family: var(--font-mono); font-size: 0.75rem;"><?php echo htmlspecialchars($s['mailerlite_id'] ?: 'N/A'); ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</form>

<!-- Pagination -->
<?php if ($totalPages > 1): ?>
    <div style="display: flex; justify-content: center; gap: var(--space-2); margin-top: var(--space-4);">
        <?php for ($i = 1; $i <= $totalPages; $i++): ?>
            <a href="/admin/subscribers?page=<?php echo $i; ?>&q=<?php echo urlencode($search); ?>" class="btn <?php echo ($page === $i) ? 'btn-primary' : 'btn-secondary'; ?>" style="padding: 6px 12px; font-size: 0.8rem;">
                <?php echo $i; ?>
            </a>
        <?php endfor; ?>
    </div>
<?php endif; ?>

<script nonce="<?php echo $cspNonce; ?>">
document.addEventListener('DOMContentLoaded', () => {
    const selectAll = document.getElementById('select-all');
    const checkboxes = document.querySelectorAll('.sub-checkbox');

    if (selectAll) {
        selectAll.addEventListener('change', () => {
            checkboxes.forEach(cb => cb.checked = selectAll.checked);
        });
    }
});
</script>

<?php
include TEMPLATE_PATH . '/admin/footer.php';
?>
