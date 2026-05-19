<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex,nofollow">
    <title>Admin Account · Own Pay Setup</title>
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
        <div class="ins-step active"><span class="ins-step-num">3</span><span>Admin</span></div>
        <div class="ins-step-line"></div>
        <div class="ins-step"><span class="ins-step-num">4</span><span>Settings</span></div>
    </div>
</header>

<main class="ins-main">
    <div class="ins-card">
        <h1>Create Admin Account</h1>
        <p class="ins-sub">Set up your superadmin account. This will be the primary login for managing your payment gateway.</p>

        <form id="adminForm" class="ins-form" autocomplete="off">
            <div class="ins-field">
                <label for="admin_name">Full Name</label>
                <input id="admin_name" name="name" required placeholder="John Doe">
            </div>
            <div class="ins-field">
                <label for="admin_email">Email Address</label>
                <input id="admin_email" name="email" type="email" required placeholder="admin@example.com">
            </div>
            <div class="ins-field">
                <label for="admin_username">Username</label>
                <input id="admin_username" name="username" required placeholder="admin">
            </div>
            <div class="ins-field">
                <label for="admin_password">Password</label>
                <input id="admin_password" name="password" type="password" required minlength="8" placeholder="Minimum 8 characters">
                <div class="ins-pw-meter" id="pwMeter">
                    <div class="ins-pw-bar"></div>
                </div>
            </div>
            <div id="adminMsg" class="ins-msg"></div>
            <button type="submit" class="ins-btn" id="adminBtn">
                <span id="adminBtnText">Create Admin Account</span>
            </button>
        </form>
    </div>
</main>

<div class="ins-footer">Own Pay · Secure Payment Gateway · v0.1.0</div>

<script>
// Password strength meter
document.getElementById('admin_password').addEventListener('input', function() {
    var meter = document.getElementById('pwMeter');
    var val = this.value;
    meter.className = 'ins-pw-meter';
    if (val.length === 0) return;
    if (val.length < 8) { meter.classList.add('ins-pw-weak'); return; }
    var score = 0;
    if (/[a-z]/.test(val)) score++;
    if (/[A-Z]/.test(val)) score++;
    if (/[0-9]/.test(val)) score++;
    if (/[^a-zA-Z0-9]/.test(val)) score++;
    if (val.length >= 12) score++;
    meter.classList.add(score >= 4 ? 'ins-pw-strong' : score >= 2 ? 'ins-pw-med' : 'ins-pw-weak');
});

document.getElementById('adminForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    var btn = document.getElementById('adminBtn');
    var btnText = document.getElementById('adminBtnText');
    var msg = document.getElementById('adminMsg');

    btn.disabled = true;
    btnText.innerHTML = '<span class="ins-spinner"></span> Creating account...';
    msg.textContent = '';
    msg.className = 'ins-msg';

    var fd = new FormData(this), body = {};
    fd.forEach(function(v, k) { body[k] = v; });

    try {
        var r = await fetch('/install/create-admin', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(body)
        });
        var d = await r.json();
        if (d.success) {
            msg.className = 'ins-msg ins-msg-ok';
            msg.textContent = '✓ Admin account created successfully';
            btnText.textContent = 'Success! Redirecting...';
            setTimeout(function() { location.href = '?step=4'; }, 1000);
        } else {
            msg.className = 'ins-msg ins-msg-err';
            msg.textContent = '✗ ' + (d.error || 'Failed to create account');
            btn.disabled = false;
            btnText.textContent = 'Create Admin Account';
        }
    } catch (err) {
        msg.className = 'ins-msg ins-msg-err';
        msg.textContent = '✗ Network error. Please check your server.';
        btn.disabled = false;
        btnText.textContent = 'Create Admin Account';
    }
});
</script>
</body>
</html>
