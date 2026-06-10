<?php
declare(strict_types=1);
include TEMPLATE_PATH . '/admin/header.php';
?>

<?php if ($action === 'create'): ?>
    <!-- Create Contributor Form -->
    <div class="page-card" style="max-width: 600px; margin: 0 auto; text-align: left;">
        <h3>Add New Contributor</h3>
        <p class="mb-6" style="font-size: 0.8rem; color: var(--color-text-dim);">Register a new open source developer or designer.</p>

        <form action="/admin/contributors?action=create" method="POST" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">

            <div class="form-group">
                <label for="name">Contributor Name *</label>
                <input type="text" id="name" name="name" required placeholder="e.g. Fattain Naime">
            </div>

            <div class="form-group">
                <label for="role">Role / Contribution *</label>
                <input type="text" id="role" name="role" required placeholder="e.g. Lead Core Architect">
            </div>

            <div class="form-group">
                <label for="avatar">Avatar Image (Max 2MB, JPG/PNG/WEBP)</label>
                <input type="file" id="avatar" name="avatar" accept="image/*">
            </div>

            <div class="form-group">
                <label for="profile_url">Profile URL (GitHub / LinkedIn / Website)</label>
                <input type="url" id="profile_url" name="profile_url" placeholder="https://linkedin.com/in/...">
            </div>

            <div class="form-group">
                <label for="display_order">Display Order (Ascending)</label>
                <input type="number" id="display_order" name="display_order" value="0">
            </div>

            <div class="form-group">
                <label for="description">Short description of contribution details</label>
                <textarea id="description" name="description" rows="3" placeholder="Explain what they designed, developed, or advisory..."></textarea>
            </div>

            <div class="form-group" style="display: flex; align-items: center; gap: var(--space-2); margin-bottom: var(--space-6);">
                <input type="checkbox" id="active" name="active" value="1" checked style="width: auto;">
                <label for="active" style="margin-bottom: 0;">Contributor is Active (Display on Showcase)</label>
            </div>

            <div style="display: flex; gap: var(--space-3);">
                <button type="submit" class="btn btn-primary" style="flex: 1;">Save Contributor</button>
                <a href="/admin/contributors" class="btn btn-secondary">Cancel</a>
            </div>
        </form>
    </div>

<?php else: ?>
    <!-- Contributors List Table -->
    <div style="display: flex; justify-content: flex-end; margin-bottom: var(--space-4);">
        <a href="/admin/contributors?action=create" class="btn btn-primary" style="font-size: 0.85rem; padding: 10px 16px;">
            <i class="ph ph-plus"></i> Add Contributor
        </a>
    </div>

    <div class="admin-table-container">
        <table class="admin-table">
            <thead>
                <tr>
                    <th>Avatar</th>
                    <th>Name</th>
                    <th>Role / Contribution</th>
                    <th>Profile URL</th>
                    <th>Order</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($contributors)): ?>
                    <tr>
                        <td colspan="7" style="text-align: center; padding: var(--space-6);">No contributors configured.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($contributors as $c): ?>
                        <tr>
                            <td>
                                <?php if (!empty($c['avatar_path']) && file_exists(PUBLIC_PATH . '/' . $c['avatar_path'])): ?>
                                    <img src="/<?php echo htmlspecialchars($c['avatar_path']); ?>" alt="Avatar" style="height: 36px; width: 36px; border-radius: 50%; object-fit: cover;">
                                <?php else: ?>
                                    <div style="width: 36px; height: 36px; border-radius: 50%; background-color: var(--color-border); color: var(--color-primary); display: flex; align-items: center; justify-content: center; font-size: 0.8rem; font-weight: 700;">
                                        <?php 
                                            $words = explode(' ', $c['name']);
                                            $initials = '';
                                            foreach ($words as $w) {
                                                $initials .= strtoupper(substr($w, 0, 1));
                                            }
                                            echo htmlspecialchars(substr($initials, 0, 2));
                                        ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td><strong><?php echo htmlspecialchars($c['name']); ?></strong></td>
                            <td><?php echo htmlspecialchars($c['role']); ?></td>
                            <td>
                                <?php if (!empty($c['profile_url'])): ?>
                                    <a href="<?php echo htmlspecialchars($c['profile_url']); ?>" target="_blank" rel="noopener noreferrer">Profile Link &rarr;</a>
                                <?php else: ?>
                                    <span style="color: var(--color-text-dim);">No link</span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo $c['display_order']; ?></td>
                            <td>
                                <?php if ($c['active']): ?>
                                    <span class="admin-badge-synced">Active</span>
                                <?php else: ?>
                                    <span class="admin-badge-pending">Inactive</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div style="display: flex; gap: var(--space-2);">
                                    <a href="/admin/contributors?action=edit&id=<?php echo $c['id']; ?>" class="btn btn-secondary" style="font-size: 0.75rem; padding: 6px 10px;">
                                        Edit
                                    </a>
                                    <form action="/admin/contributors?action=delete&id=<?php echo $c['id']; ?>" method="POST" style="display: inline;" onsubmit="return confirm('Are you sure you want to delete this contributor?');">
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
