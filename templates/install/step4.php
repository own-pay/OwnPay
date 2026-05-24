<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex,nofollow">
    <title>Settings · Own Pay Setup</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/assets/css/installer.css?v=2">
</head>
<body>
<header class="ins-header">
    <div class="ins-brand">
        <span class="ins-mark">OP</span>
        <span class="ins-name">Own Pay <span>Setup</span></span>
    </div>
    <div class="ins-steps">
        <div class="ins-step done"><span class="ins-step-num">✓</span><span>Requirements</span></div>
        <div class="ins-step-line done"></div>
        <div class="ins-step done"><span class="ins-step-num">✓</span><span>Database</span></div>
        <div class="ins-step-line done"></div>
        <div class="ins-step done"><span class="ins-step-num">✓</span><span>Admin</span></div>
        <div class="ins-step-line done"></div>
        <div class="ins-step active"><span class="ins-step-num">4</span><span>Settings</span></div>
    </div>
</header>

<main class="ins-main">
    <div class="ins-card">
        <form id="settingsForm" class="ins-form">
            <h1>Application Settings</h1>
            <p class="ins-sub">Configure your payment gateway defaults. These can be changed later from the admin panel.</p>

            <div class="ins-field">
                <label for="app_name">Application Name</label>
                <input id="app_name" name="app_name" value="Own Pay" required>
            </div>
            <div class="ins-row">
                <div class="ins-field">
                    <label for="currency">Default Currency</label>
                    <select id="currency" name="currency">
                        <option value="BDT">BDT — Bangladeshi Taka</option>
                        <option value="USD">USD — US Dollar</option>
                        <option value="EUR">EUR — Euro</option>
                        <option value="GBP">GBP — British Pound</option>
                        <option value="INR">INR — Indian Rupee</option>
                    </select>
                </div>
                <div class="ins-field">
                    <label for="timezone">Timezone</label>
                    <select id="timezone" name="timezone">
                        <option value="Asia/Dhaka">Asia/Dhaka (GMT+6)</option>
                        <option value="UTC">UTC (GMT+0)</option>
                        <option value="America/New_York">America/New_York (GMT-5)</option>
                        <option value="Europe/London">Europe/London (GMT+0)</option>
                        <option value="Asia/Kolkata">Asia/Kolkata (GMT+5:30)</option>
                        <option value="Asia/Tokyo">Asia/Tokyo (GMT+9)</option>
                        <option value="America/Los_Angeles">America/Los_Angeles (GMT-8)</option>
                        <option value="Europe/Berlin">Europe/Berlin (GMT+1)</option>
                        <option value="Australia/Sydney">Australia/Sydney (GMT+11)</option>
                    </select>
                </div>
            </div>
            <div id="settingsMsg" class="ins-msg"></div>
            <button type="submit" class="ins-btn" id="settingsBtn">
                <span id="settingsBtnText">Complete Installation</span>
            </button>
        </form>

        <div id="donePanel" class="ins-done" style="display:none">
            <div class="ins-done-icon">✓</div>
            <h2>Installation Complete!</h2>
            <p>Own Pay has been installed and configured successfully. Your secure payment gateway is ready to use.</p>
            <div class="ins-done-actions">
                <a href="/login" class="ins-btn">Open Admin Dashboard →</a>
                <a href="/" class="ins-btn ins-btn-outline">Visit Homepage</a>
            </div>
            <div class="ins-hint">
                <strong>🔒 Security Notes:</strong><br>
                • Your encryption keys have been automatically generated<br>
                • <code>APP_DEBUG</code> is set to <code>false</code> by default<br>
                • Set up HTTPS and configure your firewall before going live
            </div>
        </div>
    </div>
</main>

<div class="ins-footer">Own Pay · Secure Payment Gateway · v0.1.0</div>

<script nonce="<?php echo htmlspecialchars($csp_nonce ?? '', ENT_QUOTES, 'UTF-8'); ?>">
document.getElementById('settingsForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    var btn = document.getElementById('settingsBtn');
    var btnText = document.getElementById('settingsBtnText');
    var msg = document.getElementById('settingsMsg');

    btn.disabled = true;
    btnText.innerHTML = '<span class="ins-spinner"></span> Installing...';
    msg.textContent = '';
    msg.className = 'ins-msg';

    var fd = new FormData(this), body = {};
    fd.forEach(function(v, k) { body[k] = v; });

    try {
        var r = await fetch('/install/finalize', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(body)
        });
        var d = await r.json();
        if (d.success) {
            document.getElementById('settingsForm').style.display = 'none';
            document.getElementById('donePanel').style.display = 'block';
        } else {
            msg.className = 'ins-msg ins-msg-err';
            msg.textContent = '✗ ' + (d.error || 'Installation failed');
            btn.disabled = false;
            btnText.textContent = 'Complete Installation';
        }
    } catch (err) {
        msg.className = 'ins-msg ins-msg-err';
        msg.textContent = '✗ Network error. Please check your server.';
        btn.disabled = false;
        btnText.textContent = 'Complete Installation';
    }
});
</script>
</body>
</html>
