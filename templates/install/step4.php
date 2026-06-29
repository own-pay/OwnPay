<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex,nofollow">
    <title>Settings · OwnPay Setup</title>
    <link rel="stylesheet" href="/assets/css/installer.css?v=4">
    <script nonce="<?php echo bin2hex(random_bytes(16)); ?>">
        (function(){var t=localStorage.getItem('op-theme');if(t==='dark')document.documentElement.setAttribute('data-theme','dark');})();
    </script>
</head>
<body>
<header class="ins-header">
    <div class="ins-brand">
        <img src="/assets/img/logo-light.svg" alt="OwnPay" class="op-logo-light" style="height: 32px; width: auto;">
        <img src="/assets/img/logo-dark.svg" alt="OwnPay" class="op-logo-dark" style="height: 32px; width: auto;">
        <span class="ins-name">OwnPay <span>Setup</span></span>
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
            <p class="ins-sub">Configure your system preferences. These represent base settings and defaults across all white-labeled merchant brands.</p>

            <div class="ins-field">
                <label for="app_name">Application Name</label>
                <input id="app_name" name="app_name" value="OwnPay" required>
            </div>
            <div class="ins-row">
                <div class="ins-field">
                    <label for="currency">Default Currency</label>
                    <select id="currency" name="currency">
                        <option value="BDT">BDT - Bangladeshi Taka</option>
                        <option value="USD">USD - US Dollar</option>
                        <option value="EUR">EUR - Euro</option>
                        <option value="GBP">GBP - British Pound</option>
                        <option value="INR">INR - Indian Rupee</option>
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
            <button type="submit" class="ins-btn ins-btn-primary" id="settingsBtn">
                <span id="settingsBtnText">Complete Platform Installation</span>
            </button>
        </form>

        <div id="donePanel" class="ins-done" style="display:none">
            <div class="ins-done-icon">✓</div>
            <h2>Installation Complete!</h2>
            <p>OwnPay is fully initialized. Environment configurations and secure cryptographic key rings are successfully locked.</p>
            <div class="ins-done-actions">
                <a href="/admin/login" class="ins-btn">Open Master Admin Dashboard →</a>
                <a href="/" class="ins-btn ins-btn-outline">Visit Portal Homepage</a>
            </div>
            <div class="ins-hint">
                <strong>🔒 Cryptography Hardening Summary:</strong><br>
                • Distinct random secure session key, HMAC token key, and AES decryption key seeded.<br>
                • Production isolation set up: <code>APP_DEBUG</code> set to <code>false</code> in your new <code>.env</code> file.<br>
                • Re-runs are strictly blocked: The installer routing group is permanently locked down.
            </div>
        </div>
    </div>
</main>

<div class="ins-footer">OwnPay · High-Transaction Secured Payment Platform · v0.1.0</div>

<script nonce="<?php echo htmlspecialchars($csp_nonce ?? '', ENT_QUOTES, 'UTF-8'); ?>">
document.getElementById('settingsForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    var btn = document.getElementById('settingsBtn');
    var btnText = document.getElementById('settingsBtnText');
    var msg = document.getElementById('settingsMsg');

    btn.disabled = true;
    btnText.innerHTML = '<span class="ins-spinner"></span> Finalizing setup...';
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
        
        var text = await r.text();
        var d;
        try {
            d = JSON.parse(text);
        } catch (e) {
            msg.className = 'ins-msg ins-msg-err';
            msg.innerHTML = '<strong>Server Error</strong><br>' + (text.substring(0, 300).replace(/</g, '&lt;').replace(/>/g, '&gt;') || 'Invalid response from server.');
            btn.disabled = false;
            btnText.textContent = 'Complete Platform Installation';
            return;
        }

        if (d.success) {
            document.getElementById('settingsForm').style.display = 'none';
            document.getElementById('donePanel').style.display = 'block';
        } else {
            msg.className = 'ins-msg ins-msg-err';
            msg.innerHTML = '<strong>Finalization Failed</strong><br>' + (d.error || 'Could not complete the installation. Check file permissions and try again.');
            btn.disabled = false;
            btnText.textContent = 'Complete Platform Installation';
        }
    } catch (err) {
        msg.className = 'ins-msg ins-msg-err';
        msg.innerHTML = '<strong>Network Error</strong><br>' + (err.message || 'Could not reach the server. Ensure your PHP server is running.');
        btn.disabled = false;
        btnText.textContent = 'Complete Platform Installation';
    }
});
</script>
</body>
</html>
