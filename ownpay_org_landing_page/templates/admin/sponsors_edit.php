<?php
declare(strict_types=1);
include TEMPLATE_PATH . '/admin/header.php';
?>

<div class="page-card" style="max-width: 600px; margin: 0 auto; text-align: left;">
    <h3>Edit Sponsor: <?php echo htmlspecialchars($sponsor['name']); ?></h3>
    <p class="mb-6" style="font-size: 0.8rem; color: var(--color-text-dim);">Modify the corporate or community infrastructure supporter record.</p>

    <form action="/admin/sponsors?action=edit&id=<?php echo $sponsor['id']; ?>" method="POST" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">

        <div class="form-group">
            <label for="name">Sponsor Name *</label>
            <input type="text" id="name" name="name" required placeholder="e.g. FlexoHost" value="<?php echo htmlspecialchars($sponsor['name']); ?>">
        </div>

        <div class="form-group">
            <label for="slug">Slug * (Lowercase, letters/numbers only)</label>
            <input type="text" id="slug" name="slug" required placeholder="e.g. flexohost" pattern="[a-z0-9_\-]+" value="<?php echo htmlspecialchars($sponsor['slug']); ?>">
        </div>

        <div class="form-group">
            <label for="logo">Sponsor Logo (Leave empty to keep existing)</label>
            <div style="display: flex; align-items: center; gap: var(--space-4); margin-bottom: var(--space-2);">
                <img src="/<?php echo htmlspecialchars($sponsor['logo_path']); ?>" alt="Current Logo" style="height: 30px; object-fit: contain;">
                <span style="font-size: 0.75rem; color: var(--color-text-dim);">Current File: <?php echo htmlspecialchars($sponsor['logo_path']); ?></span>
            </div>
            <input type="file" id="logo" name="logo" accept="image/*">
        </div>

        <div class="form-group">
            <label for="website_url">Website URL *</label>
            <input type="url" id="website_url" name="website_url" required placeholder="https://example.com" value="<?php echo htmlspecialchars($sponsor['website_url']); ?>">
        </div>

        <div class="form-group">
            <label for="tier">Sponsorship Tier</label>
            <select id="tier" name="tier">
                <option value="Gold" <?php echo ($sponsor['tier'] === 'Gold') ? 'selected' : ''; ?>>Gold Sponsor</option>
                <option value="Silver" <?php echo ($sponsor['tier'] === 'Silver') ? 'selected' : ''; ?>>Silver Sponsor</option>
                <option value="Bronze" <?php echo ($sponsor['tier'] === 'Bronze') ? 'selected' : ''; ?>>Bronze Sponsor</option>
                <option value="Community" <?php echo ($sponsor['tier'] === 'Community') ? 'selected' : ''; ?>>Community Sponsor</option>
            </select>
        </div>

        <div class="form-group">
            <label for="display_order">Display Order (Ascending)</label>
            <input type="number" id="display_order" name="display_order" value="<?php echo $sponsor['display_order']; ?>">
        </div>

        <div class="form-group">
            <label for="description">Short Description / Support Details</label>
            <textarea id="description" name="description" rows="3" placeholder="Explain what they provided/sponsored..."><?php echo htmlspecialchars($sponsor['description'] ?? ''); ?></textarea>
        </div>

        <div class="form-group" style="display: flex; align-items: center; gap: var(--space-2); margin-top: var(--space-4);">
            <input type="checkbox" id="nofollow" name="nofollow" value="1" <?php echo $sponsor['nofollow'] ? 'checked' : ''; ?> style="width: auto;">
            <label for="nofollow" style="margin-bottom: 0;">Enable `rel="nofollow"` (Paid/Commercial Link)</label>
        </div>

        <div class="form-group" style="display: flex; align-items: center; gap: var(--space-2); margin-bottom: var(--space-6);">
            <input type="checkbox" id="active" name="active" value="1" <?php echo $sponsor['active'] ? 'checked' : ''; ?> style="width: auto;">
            <label for="active" style="margin-bottom: 0;">Sponsor is Active (Display on Showcase)</label>
        </div>

        <div style="display: flex; gap: var(--space-3);">
            <button type="submit" class="btn btn-primary" style="flex: 1;">Update Sponsor</button>
            <a href="/admin/sponsors" class="btn btn-secondary">Cancel</a>
        </div>
    </form>
</div>

<?php
include TEMPLATE_PATH . '/admin/footer.php';
?>
