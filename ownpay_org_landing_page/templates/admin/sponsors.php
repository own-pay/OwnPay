<?php
declare(strict_types=1);
include TEMPLATE_PATH . '/admin/header.php';
?>

<?php if ($action === 'create'): ?>
    <!-- Create Sponsor Form -->
    <div class="page-card" style="max-width: 600px; margin: 0 auto; text-align: left;">
        <h3>Add New Sponsor</h3>
        <p class="mb-6" style="font-size: 0.8rem; color: var(--color-text-dim);">Register a new corporate or community infrastructure supporter.</p>

        <form action="/admin/sponsors?action=create" method="POST" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">

            <div class="form-group">
                <label for="name">Sponsor Name *</label>
                <input type="text" id="name" name="name" required placeholder="e.g. FlexoHost">
            </div>

            <div class="form-group">
                <label for="slug">Slug * (Lowercase, letters/numbers only)</label>
                <input type="text" id="slug" name="slug" required placeholder="e.g. flexohost" pattern="[a-z0-9_\-]+">
            </div>

            <div class="form-group">
                <label for="logo">Sponsor Logo * (Max 2MB, JPG/PNG/WEBP/SVG)</label>
                <input type="file" id="logo" name="logo" required accept="image/*">
            </div>

            <div class="form-group">
                <label for="website_url">Website URL *</label>
                <input type="url" id="website_url" name="website_url" required placeholder="https://example.com">
            </div>

            <div class="form-group">
                <label for="tier">Sponsorship Tier</label>
                <select id="tier" name="tier">
                    <option value="Gold">Gold Sponsor</option>
                    <option value="Silver">Silver Sponsor</option>
                    <option value="Bronze">Bronze Sponsor</option>
                    <option value="Community" selected>Community Sponsor</option>
                </select>
            </div>

            <div class="form-group">
                <label for="display_order">Display Order (Ascending)</label>
                <input type="number" id="display_order" name="display_order" value="0">
            </div>

            <div class="form-group">
                <label for="description">Short Description / Support Details</label>
                <textarea id="description" name="description" rows="3" placeholder="Explain what they provided/sponsored..."></textarea>
            </div>

            <div class="form-group" style="display: flex; align-items: center; gap: var(--space-2); margin-top: var(--space-4);">
                <input type="checkbox" id="nofollow" name="nofollow" value="1" checked style="width: auto;">
                <label for="nofollow" style="margin-bottom: 0;">Enable `rel="nofollow"` (Paid/Commercial Link)</label>
            </div>

            <div class="form-group" style="display: flex; align-items: center; gap: var(--space-2); margin-bottom: var(--space-6);">
                <input type="checkbox" id="active" name="active" value="1" checked style="width: auto;">
                <label for="active" style="margin-bottom: 0;">Sponsor is Active (Display on Showcase)</label>
            </div>

            <div style="display: flex; gap: var(--space-3);">
                <button type="submit" class="btn btn-primary" style="flex: 1;">Save Sponsor</button>
                <a href="/admin/sponsors" class="btn btn-secondary">Cancel</a>
            </div>
        </form>
    </div>

<?php else: ?>
    <!-- Sponsors List Table -->
    <div style="display: flex; justify-content: flex-end; margin-bottom: var(--space-4);">
        <a href="/admin/sponsors?action=create" class="btn btn-primary" style="font-size: 0.85rem; padding: 10px 16px;">
            <i class="ph ph-plus"></i> Add Sponsor
        </a>
    </div>

    <div class="admin-table-container">
        <table class="admin-table">
            <thead>
                <tr>
                    <th>Logo</th>
                    <th>Name</th>
                    <th>Slug</th>
                    <th>Tier</th>
                    <th>Website URL</th>
                    <th>Order</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($sponsors)): ?>
                    <tr>
                        <td colspan="8" style="text-align: center; padding: var(--space-6);">No sponsors configured.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($sponsors as $s): ?>
                        <tr>
                            <td>
                                <img src="/<?php echo htmlspecialchars($s['logo_path']); ?>" alt="Logo" style="height: 30px; max-width: 100px; object-fit: contain;">
                            </td>
                            <td><strong><?php echo htmlspecialchars($s['name']); ?></strong></td>
                            <td><span style="font-family: var(--font-mono); font-size: 0.8rem;"><?php echo htmlspecialchars($s['slug']); ?></span></td>
                            <td><?php echo htmlspecialchars($s['tier']); ?></td>
                            <td><a href="<?php echo htmlspecialchars($s['website_url']); ?>" target="_blank" rel="noopener noreferrer"><?php echo htmlspecialchars($s['website_url']); ?></a></td>
                            <td><?php echo $s['display_order']; ?></td>
                            <td>
                                <?php if ($s['active']): ?>
                                    <span class="admin-badge-synced">Active</span>
                                <?php else: ?>
                                    <span class="admin-badge-pending">Inactive</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div style="display: flex; gap: var(--space-2);">
                                    <a href="/admin/sponsors?action=edit&id=<?php echo $s['id']; ?>" class="btn btn-secondary" style="font-size: 0.75rem; padding: 6px 10px;">
                                        Edit
                                    </a>
                                    <form action="/admin/sponsors?action=delete&id=<?php echo $s['id']; ?>" method="POST" style="display: inline;" onsubmit="return confirm('Are you sure you want to delete this sponsor?');">
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
<?php endif; ?>

<?php
include TEMPLATE_PATH . '/admin/footer.php';
?>
