<?php
declare(strict_types=1);
include TEMPLATE_PATH . '/admin/header.php';
?>

<div class="page-card" style="max-width: 700px; margin: 0 auto; text-align: left; padding: var(--space-8);">
    <h3>CMS settings &amp; configurations</h3>
    <p class="mb-8" style="font-size: 0.8rem; color: var(--color-text-dim);">Modify public page headers, announcement banners, MailerLite keys, and SMTP connections.</p>

    <form action="/admin/settings" method="POST">
        <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">

        <!-- 1. Hero CMS Section -->
        <h4 style="border-bottom: 1px solid var(--color-border); padding-bottom: var(--space-2); margin-bottom: var(--space-4); color: var(--color-primary);">Hero Section CMS</h4>
        
        <div class="form-group">
            <label for="hero_badge">Hero Pre-Headline Badge</label>
            <input type="text" id="hero_badge" name="hero_badge" value="<?php echo htmlspecialchars($settings['hero_badge'] ?? ''); ?>">
        </div>

        <div class="form-group">
            <label for="hero_headline">Hero Main Headline</label>
            <input type="text" id="hero_headline" name="hero_headline" value="<?php echo htmlspecialchars($settings['hero_headline'] ?? ''); ?>">
        </div>

        <div class="form-group" style="margin-bottom: var(--space-8);">
            <label for="hero_subheadline">Hero Sub-Headline / Description</label>
            <textarea id="hero_subheadline" name="hero_subheadline" rows="3"><?php echo htmlspecialchars($settings['hero_subheadline'] ?? ''); ?></textarea>
        </div>

        <!-- 2. Links & Socials -->
        <h4 style="border-bottom: 1px solid var(--color-border); padding-bottom: var(--space-2); margin-bottom: var(--space-4); color: var(--color-primary);">Links &amp; Socials</h4>
        
        <div class="form-group">
            <label for="github_repo_url">GitHub Repository URL (used globally)</label>
            <input type="url" id="github_repo_url" name="github_repo_url" value="<?php echo htmlspecialchars($settings['github_repo_url'] ?? ''); ?>">
        </div>

        <div class="form-group">
            <label for="social_twitter">Twitter Link</label>
            <input type="url" id="social_twitter" name="social_twitter" value="<?php echo htmlspecialchars($settings['social_twitter'] ?? ''); ?>">
        </div>

        <div class="form-group" style="margin-bottom: var(--space-8);">
            <label for="contact_email">Site Contact Email</label>
            <input type="email" id="contact_email" name="contact_email" value="<?php echo htmlspecialchars($settings['contact_email'] ?? ''); ?>">
        </div>

        <!-- 3. MailerLite Sync -->
        <h4 style="border-bottom: 1px solid var(--color-border); padding-bottom: var(--space-2); margin-bottom: var(--space-4); color: var(--color-primary);">MailerLite Integration</h4>
        
        <div class="form-group">
            <label for="mailerlite_api_key">MailerLite API Key</label>
            <input type="password" id="mailerlite_api_key" name="mailerlite_api_key" placeholder="Enter MailerLite API Key (Classic v2)" value="<?php echo htmlspecialchars($settings['mailerlite_api_key'] ?? ''); ?>">
        </div>

        <div class="form-group" style="margin-bottom: var(--space-8);">
            <label for="mailerlite_group_id">MailerLite Group ID</label>
            <input type="text" id="mailerlite_group_id" name="mailerlite_group_id" value="<?php echo htmlspecialchars($settings['mailerlite_group_id'] ?? ''); ?>">
        </div>

        <!-- 4. SMTP Transactional Mail -->
        <h4 style="border-bottom: 1px solid var(--color-border); padding-bottom: var(--space-2); margin-bottom: var(--space-4); color: var(--color-primary);">SMTP Mail Server</h4>
        
        <div class="form-group">
            <label for="smtp_host">SMTP Host</label>
            <input type="text" id="smtp_host" name="smtp_host" value="<?php echo htmlspecialchars($settings['smtp_host'] ?? ''); ?>">
        </div>

        <div class="form-group">
            <label for="smtp_port">SMTP Port</label>
            <input type="number" id="smtp_port" name="smtp_port" value="<?php echo htmlspecialchars($settings['smtp_port'] ?? ''); ?>">
        </div>

        <div class="form-group">
            <label for="smtp_username">SMTP Username</label>
            <input type="text" id="smtp_username" name="smtp_username" value="<?php echo htmlspecialchars($settings['smtp_username'] ?? ''); ?>">
        </div>

        <div class="form-group" style="margin-bottom: var(--space-8);">
            <label for="smtp_password">SMTP Password (Will be encrypted securely in DB)</label>
            <input type="password" id="smtp_password" name="smtp_password" placeholder="Enter new SMTP password">
            <span style="font-size: 0.75rem; color: var(--color-text-dim);">Leave empty to keep current encrypted password.</span>
        </div>

        <!-- 5. Announcement Dismissible Bar -->
        <h4 style="border-bottom: 1px solid var(--color-border); padding-bottom: var(--space-2); margin-bottom: var(--space-4); color: var(--color-primary);">Announcement Banner</h4>
        
        <div class="form-group">
            <label for="announcement_bar_text">Announcement Bar Text</label>
            <input type="text" id="announcement_bar_text" name="announcement_bar_text" value="<?php echo htmlspecialchars($settings['announcement_bar_text'] ?? ''); ?>">
        </div>

        <div class="form-group" style="display: flex; align-items: center; gap: var(--space-2); margin-bottom: var(--space-12);">
            <input type="checkbox" id="announcement_bar_enabled" name="announcement_bar_enabled" value="1" <?php echo ($settings['announcement_bar_enabled'] ?? '0') === '1' ? 'checked' : ''; ?> style="width: auto;">
            <label for="announcement_bar_enabled" style="margin-bottom: 0;">Show Announcement Banner at Top of Site</label>
        </div>

        <button type="submit" class="btn btn-primary" style="width: 100%; padding: 14px 0;">
            Update &amp; Encrypt Site Settings
        </button>
    </form>
</div>

<?php
include TEMPLATE_PATH . '/admin/footer.php';
?>
