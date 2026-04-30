/**
 * OwnPay Admin JS — SPA fragments, sidebar, dropdowns, notifications.
 * Prefix: op-
 * OWASP: CSRF token in all requests, no inline eval, CSP-friendly.
 */
(function() {
    'use strict';

    // --- Sidebar Toggle ---
    const sidebar = document.getElementById('sidebar');
    const toggle = document.getElementById('sidebar-toggle');
    if (toggle && sidebar) {
        toggle.addEventListener('click', function() {
            sidebar.classList.toggle('op-sidebar-collapsed');
            localStorage.setItem('op-sidebar', sidebar.classList.contains('op-sidebar-collapsed') ? 'collapsed' : 'expanded');
        });
        // Restore state
        if (localStorage.getItem('op-sidebar') === 'collapsed') {
            sidebar.classList.add('op-sidebar-collapsed');
        }
    }

    // --- User Menu Dropdown ---
    const userBtn = document.getElementById('user-menu-btn');
    const userMenu = document.getElementById('user-menu');
    if (userBtn && userMenu) {
        userBtn.addEventListener('click', function(e) {
            e.stopPropagation();
            userMenu.classList.toggle('op-dropdown-open');
        });
        document.addEventListener('click', function() {
            userMenu.classList.remove('op-dropdown-open');
        });
    }

    // --- Flash Alert Auto-dismiss ---
    document.querySelectorAll('.op-alert-dismissible').forEach(function(alert) {
        setTimeout(function() {
            alert.style.opacity = '0';
            alert.style.transform = 'translateY(-10px)';
            setTimeout(function() { alert.remove(); }, 300);
        }, 5000);
    });

    // --- Global Search ---
    const searchInput = document.getElementById('global-search');
    if (searchInput) {
        let debounce = null;
        searchInput.addEventListener('input', function() {
            clearTimeout(debounce);
            debounce = setTimeout(function() {
                const q = searchInput.value.trim();
                if (q.length >= 2) {
                    window.location.href = '/admin/transactions?q=' + encodeURIComponent(q);
                }
            }, 500);
        });
    }

    // --- Responsive sidebar ---
    if (window.innerWidth < 768 && sidebar) {
        sidebar.classList.add('op-sidebar-collapsed');
    }

    // --- Table row click → detail ---
    document.querySelectorAll('.op-table tbody tr[data-href]').forEach(function(row) {
        row.style.cursor = 'pointer';
        row.addEventListener('click', function() {
            window.location.href = row.dataset.href;
        });
    });

    // --- Confirmation on dangerous forms ---
    document.querySelectorAll('form[data-confirm]').forEach(function(form) {
        form.addEventListener('submit', function(e) {
            if (!confirm(form.dataset.confirm)) {
                e.preventDefault();
            }
        });
    });

    // --- Copy to clipboard ---
    document.querySelectorAll('[data-copy]').forEach(function(btn) {
        btn.addEventListener('click', function() {
            navigator.clipboard.writeText(btn.dataset.copy).then(function() {
                const orig = btn.textContent;
                btn.textContent = 'Copied!';
                setTimeout(function() { btn.textContent = orig; }, 1500);
            });
        });
    });

})();
