<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex,nofollow">
    <title>Database · Own Pay Setup</title>
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
        <div class="ins-step active"><span class="ins-step-num">2</span><span>Database</span></div>
        <div class="ins-step-line"></div>
        <div class="ins-step"><span class="ins-step-num">3</span><span>Admin</span></div>
        <div class="ins-step-line"></div>
        <div class="ins-step"><span class="ins-step-num">4</span><span>Settings</span></div>
    </div>
</header>

<main class="ins-main">
    <div class="ins-card">
        <h1>Database Configuration</h1>
        <p class="ins-sub">Enter your MySQL database credentials. The database will be created if it doesn't exist.</p>

        <form id="dbForm" class="ins-form" autocomplete="off">
            <div class="ins-row">
                <div class="ins-field">
                    <label for="db_host">Host</label>
                    <input id="db_host" name="host" value="localhost" required>
                </div>
                <div class="ins-field ins-field-sm">
                    <label for="db_port">Port</label>
                    <input id="db_port" name="port" value="3306" required>
                </div>
            </div>
            <div class="ins-field">
                <label for="db_name">Database Name</label>
                <input id="db_name" name="name" required placeholder="ownpay">
            </div>
            <div class="ins-row">
                <div class="ins-field">
                    <label for="db_user">Username</label>
                    <input id="db_user" name="user" required placeholder="root">
                </div>
                <div class="ins-field">
                    <label for="db_pass">Password</label>
                    <input id="db_pass" name="pass" type="password" placeholder="Database password">
                </div>
            </div>
            <div class="ins-field">
                <label for="db_prefix">Table Prefix</label>
                <input id="db_prefix" name="prefix" value="op_" required>
            </div>
            <div id="dbMsg" class="ins-msg"></div>
            <button type="submit" class="ins-btn" id="dbBtn">
                <span id="dbBtnText">Test Connection & Import Schema</span>
            </button>
        </form>
    </div>
</main>

<div class="ins-footer">Own Pay · Secure Payment Gateway · v0.1.0</div>

<script nonce="<?php echo htmlspecialchars($csp_nonce ?? '', ENT_QUOTES, 'UTF-8'); ?>">
document.getElementById('dbForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    var btn = document.getElementById('dbBtn');
    var btnText = document.getElementById('dbBtnText');
    var msg = document.getElementById('dbMsg');

    btn.disabled = true;
    btnText.innerHTML = '<span class="ins-spinner"></span> Testing connection...';
    msg.textContent = '';
    msg.className = 'ins-msg';

    var fd = new FormData(this), body = {};
    fd.forEach(function(v, k) { body[k] = v; });

    try {
        var r = await fetch('/install/test-db', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(body)
        });
        var d = await r.json();
        if (d.success) {
            msg.className = 'ins-msg ins-msg-ok';
            msg.textContent = '✓ ' + (d.message || 'Database configured successfully');
            btnText.textContent = 'Success! Redirecting...';
            setTimeout(function() { location.href = '?step=3'; }, 1000);
        } else {
            msg.className = 'ins-msg ins-msg-err';
            msg.textContent = '✗ ' + (d.error || 'Connection failed');
            btn.disabled = false;
            btnText.textContent = 'Test Connection & Import Schema';
        }
    } catch (err) {
        msg.className = 'ins-msg ins-msg-err';
        msg.textContent = '✗ Network error. Please check your server.';
        btn.disabled = false;
        btnText.textContent = 'Test Connection & Import Schema';
    }
});
</script>
</body>
</html>
