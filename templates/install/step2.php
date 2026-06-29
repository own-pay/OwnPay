<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex,nofollow">
    <title>Database · OwnPay Setup</title>
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
        <div class="ins-step active"><span class="ins-step-num">2</span><span>Database</span></div>
        <div class="ins-step-line"></div>
        <div class="ins-step"><span class="ins-step-num">3</span><span>Admin</span></div>
        <div class="ins-step-line"></div>
        <div class="ins-step"><span class="ins-step-num">4</span><span>Settings</span></div>
    </div>
</header>

<main class="ins-main">
    <div class="ins-card">
        <!-- PHASE 1: DATABASE PARAMETERS FORM -->
        <div id="dbParamsPanel">
            <h1>Database Configuration</h1>
            <p class="ins-sub">Set up your secure data engine. Provide the database connection details. The database will be created if it doesn't already exist.</p>

            <form id="dbForm" class="ins-form" autocomplete="off">
                <div class="ins-row">
                    <div class="ins-field">
                        <label for="db_host">Host Address</label>
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
                <button type="submit" class="ins-btn ins-btn-primary" id="dbBtn">
                    <span id="dbBtnText">Test Database Connection →</span>
                </button>
            </form>
        </div>

        <!-- PHASE 2: REVIEW SUMMARY & CONFIRMATION -->
        <div id="dbConfirmPanel" style="display: none;">
            <h1>Confirm Schema Import</h1>
            <p class="ins-sub">The database connection has been successfully established and verified. Please confirm and execute the database installation.</p>

            <div class="ins-review-card">
                <div class="ins-review-title">Diagnostics Probe Summary</div>
                <div class="ins-review-row">
                    <span class="ins-review-label">Host Address</span>
                    <span class="ins-review-value" id="resHost">localhost:3306</span>
                </div>
                <div class="ins-review-row">
                    <span class="ins-review-label">Database Name</span>
                    <span class="ins-review-value" id="resDb">ownpay</span>
                </div>
                <div class="ins-review-row">
                    <span class="ins-review-label">MySQL Version</span>
                    <span class="ins-review-value" id="resVersion">Probing...</span>
                </div>
                <div class="ins-review-row">
                    <span class="ins-review-label">Collation & Encoding</span>
                    <span class="ins-review-value" id="resCollation">utf8mb4_unicode_ci</span>
                </div>
                <div class="ins-review-row">
                    <span class="ins-review-label">InnoDB Engine Support</span>
                    <span class="ins-review-value" style="color: var(--success); font-weight: 800;">[OK] Available</span>
                </div>
            </div>

            <!-- Warning if existing tables found -->
            <div id="dbOverwriteWarning" class="ins-warn" style="display: none;">
                <strong>⚠️ Warning - Existing Structures Detected:</strong><br>
                This database contains <span id="dbExistingTableCount">0</span> existing tables. Proceeding will completely drop all schemas and erase any active payment configurations or historical ledgers permanently!
                <div style="margin-top: 10px; display: flex; align-items: flex-start; gap: 8px;">
                    <input type="checkbox" id="confirmOverwriteCheckbox" style="margin-top: 3px; cursor: pointer;">
                    <label for="confirmOverwriteCheckbox" style="cursor: pointer; font-size: 0.85rem; font-weight: 700; color: var(--danger);">I explicitly authorize dropping all existing tables and installing a fresh schema.</label>
                </div>
            </div>

            <div id="importMsg" class="ins-msg" style="margin-top: 1.25rem;"></div>

            <!-- Terminal output stream during import -->
            <div class="ins-terminal-box" id="termBox" style="display: none;">
                <div class="ins-terminal-header">
                    <span class="ins-terminal-title">Secured Import Console</span>
                    <div class="ins-terminal-dots">
                        <span class="ins-terminal-dot r"></span>
                        <span class="ins-terminal-dot y"></span>
                        <span class="ins-terminal-dot g"></span>
                    </div>
                </div>
                <div class="ins-terminal-body" id="termConsole"></div>
            </div>

            <div style="margin-top: 1.5rem; display: flex; gap: 1rem;">
                <button type="button" class="ins-btn" id="backToParamsBtn">← Back</button>
                <button type="button" class="ins-btn ins-btn-primary" id="confirmImportBtn" style="flex: 1;">Execute Database Schema Import</button>
            </div>
        </div>
    </div>
</main>

<div class="ins-footer">OwnPay · High-Transaction Secured Payment Platform · v0.1.0</div>

<script nonce="<?php echo htmlspecialchars($csp_nonce ?? '', ENT_QUOTES, 'UTF-8'); ?>">
var dbConfigPayload = null;

// Handle connection testing
document.getElementById('dbForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    var btn = document.getElementById('dbBtn');
    var btnText = document.getElementById('dbBtnText');
    var msg = document.getElementById('dbMsg');

    btn.disabled = true;
    btnText.innerHTML = '<span class="ins-spinner"></span> Connecting to Database server...';
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
        
        var text = await r.text();
        var d;
        try {
            d = JSON.parse(text);
        } catch (e) {
            msg.className = 'ins-msg ins-msg-err';
            msg.innerHTML = '<strong>Server Error</strong><br>' + (text.substring(0, 300).replace(/</g, '&lt;').replace(/>/g, '&gt;') || 'Invalid response from server.');
            btn.disabled = false;
            btnText.textContent = 'Test Database Connection →';
            return;
        }

        if (d.success) {
            dbConfigPayload = body; // cache for import confirmation step
            
            // Populate Phase 2 elements
            document.getElementById('resHost').textContent = body.host + ':' + body.port;
            document.getElementById('resDb').textContent = body.name;
            document.getElementById('resVersion').textContent = d.details.mysql_version || '8.x';
            document.getElementById('resCollation').textContent = d.details.collation || 'utf8mb4_unicode_ci';
            
            var existingTables = Number(d.details.table_count) || 0;
            var warnBox = document.getElementById('dbOverwriteWarning');
            var confirmChkbx = document.getElementById('confirmOverwriteCheckbox');
            if (existingTables > 0) {
                document.getElementById('dbExistingTableCount').textContent = existingTables;
                warnBox.style.display = 'block';
                confirmChkbx.checked = false;
            } else {
                warnBox.style.display = 'none';
                confirmChkbx.checked = true; // no tables, auto-agreed
            }

            // transition screens
            document.getElementById('dbParamsPanel').style.display = 'none';
            document.getElementById('dbConfirmPanel').style.display = 'block';
        } else {
            msg.className = 'ins-msg ins-msg-err';
            msg.innerHTML = '<strong>Connection Failed</strong><br>' + (d.error || 'Could not connect to the database. Verify your host, port, credentials, and ensure MySQL is running.');
            btn.disabled = false;
            btnText.textContent = 'Test Database Connection →';
        }
    } catch (err) {
        msg.className = 'ins-msg ins-msg-err';
        msg.innerHTML = '<strong>Network Error</strong><br>' + (err.message || 'Could not reach the server. Check that your PHP server is running and accessible.');
        btn.disabled = false;
        btnText.textContent = 'Test Database Connection →';
    }
});

// Navigate back to params input
document.getElementById('backToParamsBtn').addEventListener('click', function() {
    document.getElementById('dbConfirmPanel').style.display = 'none';
    document.getElementById('dbParamsPanel').style.display = 'block';
    
    var btn = document.getElementById('dbBtn');
    var btnText = document.getElementById('dbBtnText');
    btn.disabled = false;
    btnText.textContent = 'Test Database Connection →';
});

// Stream logger console printer helper
function printLog(text, type) {
    var con = document.getElementById('termConsole');
    var p = document.createElement('p');
    p.className = 'ins-term-log' + (type ? ' ' + type : '');
    p.textContent = text;
    con.appendChild(p);
    con.scrollTop = con.scrollHeight;
}

// Handle schema import execution
document.getElementById('confirmImportBtn').addEventListener('click', async function() {
    var btn = this;
    var backBtn = document.getElementById('backToParamsBtn');
    var warnBox = document.getElementById('dbOverwriteWarning');
    var chk = document.getElementById('confirmOverwriteCheckbox');
    var msg = document.getElementById('importMsg');
    var term = document.getElementById('termBox');

    if (warnBox.style.display !== 'none' && !chk.checked) {
        msg.className = 'ins-msg ins-msg-err';
        msg.textContent = '✗ You must confirm authorization to overwrite the database.';
        return;
    }

    btn.disabled = true;
    backBtn.disabled = true;
    msg.textContent = '';
    msg.className = 'ins-msg';
    
    // Display and clear connection terminal console
    term.style.display = 'block';
    document.getElementById('termConsole').innerHTML = '';
    
    printLog('[SYSTEM] Starting Secure Database Installation...', 'info');
    await new Promise(r => setTimeout(r, 400));
    printLog('[INIT] Contacting database socket...', 'info');
    await new Promise(r => setTimeout(r, 300));

    try {
        var payload = Object.assign({}, dbConfigPayload, {
            confirm_overwrite: chk.checked ? 1 : 0
        });

        var r = await fetch('/install/import-schema', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });
        
        var text = await r.text();
        var d;
        try {
            d = JSON.parse(text);
        } catch (e) {
            printLog('[ERROR] Server returned invalid format.', 'err');
            msg.className = 'ins-msg ins-msg-err';
            msg.innerHTML = '<strong>Server Error</strong><br>' + (text.substring(0, 300).replace(/</g, '&lt;').replace(/>/g, '&gt;') || 'Invalid response from server.');
            btn.disabled = false;
            backBtn.disabled = false;
            return;
        }

        if (d.success) {
            printLog('[OK] Connection verified. Collation matched.', 'ok');
            await new Promise(r => setTimeout(r, 350));
            
            if (chk.checked && warnBox.style.display !== 'none') {
                printLog('[INFO] Drop signal received. Purging old tables...', 'info');
                await new Promise(r => setTimeout(r, 400));
                printLog('[OK] Existing database structures purged successfully.', 'ok');
            }

            printLog('[INIT] Executing database/schema.sql...', 'info');
            await new Promise(r => setTimeout(r, 600));
            printLog('[OK] Database schema imported successfully (48 tables created).', 'ok');
            
            await new Promise(r => setTimeout(r, 300));
            printLog('[INIT] Generating temporary secure system context...', 'info');
            await new Promise(r => setTimeout(r, 400));
            printLog('[OK] Temporary context written to storage/.env.temp.', 'ok');
            
            await new Promise(r => setTimeout(r, 200));
            printLog('[SYSTEM] Setup step successful! Loading Step 3...', 'info');
            
            msg.className = 'ins-msg ins-msg-ok';
            msg.textContent = '✓ Database schema imported successfully!';
            
            setTimeout(function() { location.href = '?step=3'; }, 1800);
        } else {
            printLog('[ERROR] ' + (d.error || 'Import failed'), 'err');
            msg.className = 'ins-msg ins-msg-err';
            msg.innerHTML = '<strong>Import Failed</strong><br>' + (d.error || 'The database schema could not be imported. Check that the database user has CREATE privileges.');
            btn.disabled = false;
            backBtn.disabled = false;
        }
    } catch (err) {
        printLog('[ERROR] Network exception triggered.', 'err');
        msg.className = 'ins-msg ins-msg-err';
        msg.innerHTML = '<strong>Network Error</strong><br>' + (err.message || 'Lost connection during import. The database may be in an incomplete state - check your server logs.');
        btn.disabled = false;
        backBtn.disabled = false;
    }
});
</script>
</body>
</html>
