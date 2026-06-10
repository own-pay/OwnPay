<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo isset($title) ? htmlspecialchars($title) : 'Admin Login'; ?></title>
    <link rel="icon" type="image/png" href="/ownpay_icon.png">
    <link rel="stylesheet" href="/assets/css/style.css">
    
    <!-- Phosphor Icons -->
    <link rel="stylesheet" href="https://unpkg.com/@phosphor-icons/web@2.1.1/src/regular/style.css">

    <style>
        body {
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            padding: var(--space-6);
        }
    </style>
</head>
<body>

    <div class="page-card" style="max-width: 400px; width: 100%; text-align: center; padding: var(--space-8);">
        <div class="nav-logo" style="justify-content: center; margin-bottom: var(--space-6);">
            <img src="/ownpay_icon.png" alt="Logo" style="height: 32px;">
            <span style="font-size: 1.5rem;">OwnPay Portal</span>
        </div>
        
        <h3 class="mb-4">Administrative Login</h3>
        <p class="mb-6" style="font-size: 0.85rem; color: var(--color-text-dim);">Access restricted to authorized personnel only.</p>

        <?php if (!empty($error)): ?>
            <div class="badge badge-planned mb-6" style="padding: 12px 24px; text-transform: none; display: block; color: #ef4444; border-color: rgba(239, 68, 68, 0.2); background: rgba(239, 68, 68, 0.05); text-align: left;">
                <strong>Error:</strong> <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <form action="/admin/login" method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">

            <div class="form-group">
                <label for="username">Username</label>
                <input type="text" id="username" name="username" required placeholder="Enter username" autocomplete="username">
            </div>

            <div class="form-group" style="margin-bottom: var(--space-6);">
                <label for="password">Password</label>
                <input type="password" id="password" name="password" required placeholder="Enter password" autocomplete="current-password">
            </div>

            <button type="submit" class="btn btn-primary" style="width: 100%; padding: 12px 0;">
                Authenticate &amp; Initialize Session
            </button>
        </form>
    </div>

</body>
</html>
