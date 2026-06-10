<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo isset($title) ? htmlspecialchars($title) : 'OwnPay Admin'; ?></title>
    <link rel="icon" type="image/png" href="/ownpay_icon.png">
    
    <!-- Admin panel styles -->
    <link rel="stylesheet" href="/assets/css/style.css">
    
    <!-- Phosphor Icons -->
    <link rel="stylesheet" href="https://unpkg.com/@phosphor-icons/web@2.1.1/src/regular/style.css">

    <style>
        /* Admin-specific layout extensions */
        .admin-layout {
            display: flex;
            min-height: 100vh;
        }

        .admin-sidebar {
            width: 260px;
            background-color: #06070a;
            border-right: 1px solid var(--color-border);
            padding: var(--space-6) 0;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            position: fixed;
            top: 0;
            bottom: 0;
            left: 0;
            z-index: 99;
        }

        .admin-sidebar-nav {
            display: flex;
            flex-direction: column;
            gap: var(--space-2);
            padding: 0 var(--space-4);
        }

        .admin-sidebar-nav a {
            display: flex;
            align-items: center;
            gap: var(--space-3);
            padding: var(--space-3) var(--space-4);
            border-radius: var(--radius-sm);
            color: var(--color-text-muted);
            font-weight: 600;
            font-size: 0.9rem;
            transition: var(--transition);
        }

        .admin-sidebar-nav a:hover, .admin-sidebar-nav a.active {
            color: var(--color-primary);
            background-color: rgba(255, 255, 255, 0.03);
        }

        .admin-main {
            flex: 1;
            margin-left: 260px;
            padding: var(--space-12) var(--space-8);
            background-color: var(--color-bg);
        }

        .admin-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: var(--space-8);
            border-bottom: 1px solid var(--color-border);
            padding-bottom: var(--space-4);
        }

        .admin-card-grid {
            display: grid;
            grid-template-columns: repeat(1, 1fr);
            gap: var(--space-4);
            margin-bottom: var(--space-8);
        }

        @media (min-width: 768px) {
            .admin-card-grid {
                grid-template-columns: repeat(4, 1fr);
            }
        }

        .admin-stat-card {
            background-color: var(--color-surface);
            border: 1px solid var(--color-border);
            border-radius: var(--radius-md);
            padding: var(--space-6);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .admin-stat-info h3 {
            font-size: 1.8rem;
            color: var(--color-primary);
            margin-bottom: var(--space-1);
        }

        .admin-stat-info p {
            font-size: 0.75rem;
            text-transform: uppercase;
            font-weight: 700;
            color: var(--color-text-dim);
        }

        .admin-stat-icon {
            font-size: 2rem;
            color: var(--color-text-dim);
        }

        .admin-table-container {
            background-color: var(--color-surface);
            border: 1px solid var(--color-border);
            border-radius: var(--radius-md);
            overflow-x: auto;
            margin-bottom: var(--space-8);
        }

        .admin-table {
            width: 100%;
            border-collapse: collapse;
            text-align: left;
            font-size: 0.85rem;
        }

        .admin-table th, .admin-table td {
            padding: var(--space-4);
            border-bottom: 1px solid var(--color-border);
        }

        .admin-table th {
            font-weight: 700;
            color: var(--color-text-main);
            background-color: rgba(255, 255, 255, 0.01);
            text-transform: uppercase;
            font-size: 0.75rem;
            letter-spacing: 0.05em;
        }

        .admin-table td {
            color: var(--color-text-muted);
        }

        .admin-table tr:hover td {
            background-color: rgba(255, 255, 255, 0.01);
        }

        .admin-badge-synced {
            color: #10b981;
            background: rgba(16, 185, 129, 0.1);
            padding: var(--space-1) var(--space-2);
            border-radius: 4px;
            font-size: 0.7rem;
            font-weight: 700;
        }

        .admin-badge-pending {
            color: #f59e0b;
            background: rgba(245, 158, 11, 0.1);
            padding: var(--space-1) var(--space-2);
            border-radius: 4px;
            font-size: 0.7rem;
            font-weight: 700;
        }
    </style>
</head>
<body>

    <div class="admin-layout">
        
        <!-- Sidebar -->
        <aside class="admin-sidebar">
            <div>
                <!-- Logo -->
                <div class="nav-logo" style="padding: 0 var(--space-6) var(--space-6) var(--space-6); border-bottom: 1px solid var(--color-border); margin-bottom: var(--space-6);">
                    <img src="/ownpay_icon.png" alt="Logo" style="height: 24px;">
                    <span>OwnPay Admin</span>
                </div>

                <!-- Nav -->
                <nav class="admin-sidebar-nav">
                    <a href="/admin/dashboard" class="<?php echo (str_contains($_SERVER['REQUEST_URI'], '/admin/dashboard')) ? 'active' : ''; ?>">
                        <i class="ph ph-squares-four"></i> Dashboard
                    </a>
                    <a href="/admin/subscribers" class="<?php echo (str_contains($_SERVER['REQUEST_URI'], '/admin/subscribers')) ? 'active' : ''; ?>">
                        <i class="ph ph-envelope-simple-open"></i> Subscribers
                    </a>
                    <a href="/admin/donations" class="<?php echo (str_contains($_SERVER['REQUEST_URI'], '/admin/donations')) ? 'active' : ''; ?>">
                        <i class="ph ph-hand-heart"></i> Donations
                    </a>
                    <a href="/admin/sponsors" class="<?php echo (str_contains($_SERVER['REQUEST_URI'], '/admin/sponsors')) ? 'active' : ''; ?>">
                        <i class="ph ph-handshake"></i> Sponsors
                    </a>
                    <a href="/admin/contributors" class="<?php echo (str_contains($_SERVER['REQUEST_URI'], '/admin/contributors')) ? 'active' : ''; ?>">
                        <i class="ph ph-users-three"></i> Contributors
                    </a>
                    <a href="/admin/settings" class="<?php echo (str_contains($_SERVER['REQUEST_URI'], '/admin/settings')) ? 'active' : ''; ?>">
                        <i class="ph ph-gear"></i> Settings CMS
                    </a>
                    <a href="/admin/audit-log" class="<?php echo (str_contains($_SERVER['REQUEST_URI'], '/admin/audit-log')) ? 'active' : ''; ?>">
                        <i class="ph ph-activity"></i> Audit Log
                    </a>
                </nav>
            </div>

            <!-- Footer area in sidebar -->
            <div style="padding: 0 var(--space-6);">
                <a href="/admin/logout" class="btn btn-secondary" style="width: 100%; font-size: 0.8rem; padding: 10px 0;">
                    <i class="ph ph-sign-out"></i> Log Out
                </a>
            </div>
        </aside>

        <!-- Main Content Area -->
        <main class="admin-main">
            
            <!-- Shared header -->
            <div class="admin-header">
                <div>
                    <h2><?php echo isset($title) ? htmlspecialchars($title) : 'Control Panel'; ?></h2>
                    <p style="font-size: 0.8rem; color: var(--color-text-dim);">Logged in as: <strong><?php echo htmlspecialchars($_SESSION['admin_username'] ?? 'Admin'); ?></strong></p>
                </div>
                <div>
                    <a href="/" target="_blank" class="btn btn-secondary" style="font-size: 0.8rem; padding: 8px 16px;">
                        <i class="ph ph-arrow-square-out"></i> Visit Site
                    </a>
                </div>
            </div>

            <!-- Flash Error Message -->
            <?php if (!empty($_SESSION['admin_error'])): ?>
                <div class="badge badge-planned mb-6" style="padding: 12px 24px; text-transform: none; display: block; color: #ef4444; border-color: rgba(239, 68, 68, 0.2); background: rgba(239, 68, 68, 0.05); text-align: left;">
                    <strong>Error:</strong> <?php echo htmlspecialchars($_SESSION['admin_error']); unset($_SESSION['admin_error']); ?>
                </div>
            <?php endif; ?>

            <!-- Flash Success Message -->
            <?php if (!empty($_SESSION['admin_success'])): ?>
                <div class="badge badge-success mb-6" style="padding: 12px 24px; text-transform: none; display: block; text-align: left;">
                    <strong>Success:</strong> <?php echo htmlspecialchars($_SESSION['admin_success']); unset($_SESSION['admin_success']); ?>
                </div>
            <?php endif; ?>
