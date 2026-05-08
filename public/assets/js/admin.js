/**
 * OwnPay Admin JS — sidebar, dropdowns, flash alerts, mobile UX.
 * Prefix: op-
 * OWASP: CSRF token in all requests, no inline eval, CSP-friendly.
 */
(function () {
    'use strict';

    var isMobile = function () { return window.innerWidth < 768; };

    // ─── Sidebar Toggle ───────────────────────────────────────
    var sidebar = document.getElementById('sidebar');
    var toggle = document.getElementById('sidebar-toggle');
    var backdrop = null;

    function createBackdrop() {
        if (backdrop) return;
        backdrop = document.createElement('div');
        backdrop.id = 'op-sidebar-backdrop';
        backdrop.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,0.6);z-index:999;display:none;';
        document.body.appendChild(backdrop);
        backdrop.addEventListener('click', closeMobileSidebar);
    }

    function openMobileSidebar() {
        if (!sidebar) return;
        sidebar.classList.remove('op-sidebar-collapsed');
        sidebar.classList.add('op-sidebar-open');
        if (backdrop) backdrop.style.display = 'block';
        document.body.style.overflow = 'hidden';
    }

    function closeMobileSidebar() {
        if (!sidebar) return;
        sidebar.classList.remove('op-sidebar-open');
        if (backdrop) backdrop.style.display = 'none';
        document.body.style.overflow = '';
    }

    function toggleDesktopSidebar() {
        if (!sidebar) return;
        sidebar.classList.toggle('op-sidebar-collapsed');
        localStorage.setItem('op-sidebar', sidebar.classList.contains('op-sidebar-collapsed') ? 'collapsed' : 'expanded');
    }

    if (toggle && sidebar) {
        createBackdrop();
        toggle.addEventListener('click', function () {
            if (isMobile()) {
                if (sidebar.classList.contains('op-sidebar-open')) {
                    closeMobileSidebar();
                } else {
                    openMobileSidebar();
                }
            } else {
                toggleDesktopSidebar();
            }
        });

        // Restore desktop collapse state
        if (!isMobile() && localStorage.getItem('op-sidebar') === 'collapsed') {
            sidebar.classList.add('op-sidebar-collapsed');
        }
    }

    // Close mobile sidebar on resize to desktop
    window.addEventListener('resize', function () {
        if (!isMobile()) {
            closeMobileSidebar();
            if (backdrop) backdrop.style.display = 'none';
            document.body.style.overflow = '';
        }
    });

    // ─── User Menu Dropdown ───────────────────────────────────
    var userBtn = document.getElementById('user-menu-btn');
    var userMenu = document.getElementById('user-menu');
    if (userBtn && userMenu) {
        userBtn.addEventListener('click', function (e) {
            e.stopPropagation();
            userMenu.classList.toggle('op-dropdown-open');
        });
        document.addEventListener('click', function () {
            userMenu.classList.remove('op-dropdown-open');
        });
    }

    // ─── Flash Alert Auto-dismiss ─────────────────────────────
    document.querySelectorAll('.op-alert-dismissible').forEach(function (alert) {
        setTimeout(function () {
            alert.style.opacity = '0';
            alert.style.transform = 'translateY(-10px)';
            setTimeout(function () { alert.remove(); }, 300);
        }, 5000);
    });

    // ─── Global Search ────────────────────────────────────────
    var searchInput = document.getElementById('global-search');
    if (searchInput) {
        var debounce = null;
        searchInput.addEventListener('input', function () {
            clearTimeout(debounce);
            debounce = setTimeout(function () {
                var q = searchInput.value.trim();
                if (q.length >= 2) {
                    window.location.href = '/admin/transactions?q=' + encodeURIComponent(q);
                }
            }, 500);
        });
    }

    // ─── Table row click → detail ─────────────────────────────
    document.querySelectorAll('.op-table tbody tr[data-href]').forEach(function (row) {
        row.style.cursor = 'pointer';
        row.addEventListener('click', function () {
            window.location.href = row.dataset.href;
        });
    });

    // ─── Confirm dangerous forms ──────────────────────────────
    document.querySelectorAll('form[data-confirm]').forEach(function (form) {
        form.addEventListener('submit', function (e) {
            if (!confirm(form.dataset.confirm)) {
                e.preventDefault();
            }
        });
    });

    // ─── Copy to clipboard ────────────────────────────────────
    document.querySelectorAll('[data-copy]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            navigator.clipboard.writeText(btn.dataset.copy).then(function () {
                var orig = btn.textContent;
                btn.textContent = 'Copied!';
                setTimeout(function () { btn.textContent = orig; }, 1500);
            });
        });
    });

    // ─── Theme Toggle (light/dark) ─────────────────────────────
    var THEME_KEY = 'op-theme';
    var htmlEl = document.documentElement;

    function applyTheme(theme) {
        htmlEl.setAttribute('data-theme', theme);
        localStorage.setItem(THEME_KEY, theme);
        // Sync toggle icon if present
        var icon = document.getElementById('theme-toggle-icon');
        if (icon) { icon.textContent = theme === 'dark' ? '☀' : '☾'; }
    }

    // Apply saved theme immediately (before paint)
    var savedTheme = localStorage.getItem(THEME_KEY);
    if (savedTheme === 'light' || savedTheme === 'dark') {
        applyTheme(savedTheme);
    }

    // Expose toggle for button onclick
    window.opToggleTheme = function () {
        var current = htmlEl.getAttribute('data-theme') || 'dark';
        applyTheme(current === 'dark' ? 'light' : 'dark');
    };

    var themeToggleBtn = document.getElementById('theme-toggle');
    if (themeToggleBtn) {
        themeToggleBtn.addEventListener('click', window.opToggleTheme);
    }

})();
