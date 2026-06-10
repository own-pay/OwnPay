<?php
declare(strict_types=1);
include TEMPLATE_PATH . '/admin/header.php';
?>

<div class="page-card" style="max-width: 600px; margin: 0 auto; text-align: left;">
    <h3>Edit Contributor: <?php echo htmlspecialchars($contributor['name']); ?></h3>
    <p class="mb-6" style="font-size: 0.8rem; color: var(--color-text-dim);">Modify the open source developer or designer record.</p>

    <form action="/admin/contributors?action=edit&id=<?php echo $contributor['id']; ?>" method="POST" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">

        <div class="form-group">
            <label for="name">Contributor Name *</label>
            <input type="text" id="name" name="name" required placeholder="e.g. Fattain Naime" value="<?php echo htmlspecialchars($contributor['name']); ?>">
        </div>

        <div class="form-group">
            <label for="role">Role / Contribution *</label>
            <input type="text" id="role" name="role" required placeholder="e.g. Lead Core Architect" value="<?php echo htmlspecialchars($contributor['role']); ?>">
        </div>

        <div class="form-group">
            <label for="avatar">Avatar Image (Leave empty to keep existing)</label>
            <div style="display: flex; align-items: center; gap: var(--space-4); margin-bottom: var(--space-2);">
                <?php if (!empty($contributor['avatar_path']) && file_exists(PUBLIC_PATH . '/' . $contributor['avatar_path'])): ?>
                    <img src="/<?php echo htmlspecialchars($contributor['avatar_path']); ?>" alt="Current Avatar" style="height: 36px; width: 36px; border-radius: 50%; object-fit: cover;">
                <?php else: ?>
                    <span style="color: var(--color-text-dim);">No custom avatar. Using fallback.</span>
                <?php endif; ?>
                <span style="font-size: 0.75rem; color: var(--color-text-dim);">Current Path: <?php echo htmlspecialchars($contributor['avatar_path'] ?: 'None'); ?></span>
            </div>
            <input type="file" id="avatar" name="avatar" accept="image/*">
        </div>

        <div class="form-group">
            <label for="profile_url">Profile URL (GitHub / LinkedIn / Website)</label>
            <input type="url" id="profile_url" name="profile_url" placeholder="https://linkedin.com/in/..." value="<?php echo htmlspecialchars($contributor['profile_url'] ?? ''); ?>">
        </div>

        <div class="form-group">
            <label for="display_order">Display Order (Ascending)</label>
            <input type="number" id="display_order" name="display_order" value="<?php echo $contributor['display_order']; ?>">
        </div>

        <div class="form-group">
            <label for="description">Short description of contribution details</label>
            <textarea id="description" name="description" rows="3" placeholder="Explain what they designed, developed, or advisory..."><?php echo htmlspecialchars($contributor['description'] ?? ''); ?></textarea>
        </div>

        <div class="form-group" style="display: flex; align-items: center; gap: var(--space-2); margin-bottom: var(--space-6);">
            <input type="checkbox" id="active" name="active" value="1" <?php echo $contributor['active'] ? 'checked' : ''; ?> style="width: auto;">
            <label for="active" style="margin-bottom: 0;">Contributor is Active (Display on Showcase)</label>
        </div>

        <div style="display: flex; gap: var(--space-3);">
            <button type="submit" class="btn btn-primary" style="flex: 1;">Update Contributor</button>
            <a href="/admin/contributors" class="btn btn-secondary">Cancel</a>
        </div>
    </form>
</div>

<?php
include TEMPLATE_PATH . '/admin/footer.php';
?>
