/**
 * OwnPay Admin JS — sidebar, dropdowns, flash alerts, mobile UX.
 * Prefix: op-
 * OWASP: CSRF token in all requests, no inline eval, CSP-friendly.
 */
(function () {
    "use strict";

    // ─── CSRF Token ───────────────────────────────────────────
    var meta = document.querySelector('meta[name="csrf-token"]');
    window.OP_CSRF = meta ? meta.getAttribute("content") : "";

    var isMobile = function () { return window.innerWidth < 768; };

    // ─── Sidebar Toggle ───────────────────────────────────────
    var sidebar = document.getElementById("sidebar");
    var toggle = document.getElementById("sidebar-toggle");
    var backdrop = null;

    function createBackdrop() {
        if (backdrop) {return;}
        backdrop = document.createElement("div");
        backdrop.id = "op-sidebar-backdrop";
        document.body.appendChild(backdrop);
        backdrop.addEventListener("click", closeMobileSidebar);
    }

    function openMobileSidebar() {
        if (!sidebar) {return;}
        sidebar.classList.remove("op-sidebar-collapsed");
        sidebar.classList.add("op-sidebar-open");
        if (backdrop) {backdrop.style.display = "block";}
        document.body.style.overflow = "hidden";
    }

    function closeMobileSidebar() {
        if (!sidebar) {return;}
        sidebar.classList.remove("op-sidebar-open");
        if (backdrop) {backdrop.style.display = "none";}
        document.body.style.overflow = "";
    }

    function toggleDesktopSidebar() {
        if (!sidebar) {return;}
        sidebar.classList.toggle("op-sidebar-collapsed");
        localStorage.setItem("op-sidebar", sidebar.classList.contains("op-sidebar-collapsed") ? "collapsed" : "expanded");
    }

    if (toggle && sidebar) {
        createBackdrop();
        toggle.addEventListener("click", function () {
            if (isMobile()) {
                if (sidebar.classList.contains("op-sidebar-open")) {
                    closeMobileSidebar();
                } else {
                    openMobileSidebar();
                }
            } else {
                toggleDesktopSidebar();
            }
        });

        // Restore desktop collapse state
        if (!isMobile() && localStorage.getItem("op-sidebar") === "collapsed") {
            sidebar.classList.add("op-sidebar-collapsed");
        }
    }

    // Close mobile sidebar on resize to desktop
    window.addEventListener("resize", function () {
        if (!isMobile()) {
            closeMobileSidebar();
            if (backdrop) {backdrop.style.display = "none";}
            document.body.style.overflow = "";
        }
    });

    // ─── Sub-nav Expand / Collapse ────────────────────────────
    document.querySelectorAll(".op-nav-has-sub").forEach(function (item) {
        var link = item.querySelector(":scope > .op-nav-link");
        if (!link) {return;}
        link.addEventListener("click", function (e) {
            // If sub-nav exists, toggle expand. Still allow navigation (href works).
            var sub = item.querySelector(":scope > .op-sub-nav");
            if (sub) {
                e.preventDefault(); // prevent navigation on first click — expands first
                var isExpanded = item.classList.contains("op-nav-expanded");
                // Close all other expanded items
                document.querySelectorAll(".op-nav-has-sub.op-nav-expanded").forEach(function (other) {
                    if (other !== item) {other.classList.remove("op-nav-expanded");}
                });
                if (isExpanded) {
                    item.classList.remove("op-nav-expanded");
                } else {
                    item.classList.add("op-nav-expanded");
                }
            }
        });
    });

    // ─── User Menu Dropdown ───────────────────────────────────
    var userBtn = document.getElementById("user-menu-btn");
    var userMenu = document.getElementById("user-menu");
    if (userBtn && userMenu) {
        userBtn.addEventListener("click", function (e) {
            e.stopPropagation();
            userMenu.classList.toggle("op-dropdown-open");
        });
        document.addEventListener("click", function () {
            userMenu.classList.remove("op-dropdown-open");
        });
    }

    // ─── Flash Alert Dismissal ─────────────────────────────
    function dismissAlert(alert) {
        alert.style.opacity = "0";
        alert.style.transform = "translateY(-10px)";
        setTimeout(function () { alert.remove(); }, 300);
    }
    document.querySelectorAll(".op-alert-dismissible").forEach(function (alert) {
        setTimeout(function () {
            dismissAlert(alert);
        }, 5000);
    });
    document.addEventListener("click", function (e) {
        if (e.target && e.target.classList.contains("op-alert-close")) {
            var alert = e.target.closest(".op-alert");
            if (alert) {
                dismissAlert(alert);
            }
        }
    });

    // ─── Global Search ────────────────────────────────────────
    var searchInput = document.getElementById("global-search");
    if (searchInput) {
        var debounce = null;
        searchInput.addEventListener("input", function () {
            clearTimeout(debounce);
            debounce = setTimeout(function () {
                var q = searchInput.value.trim();
                if (q.length >= 2) {
                    window.location.href = "/admin/transactions?q=" + encodeURIComponent(q);
                }
            }, 500);
        });
    }

    // ─── Table row click → detail ─────────────────────────────
    document.querySelectorAll(".op-table tbody tr[data-href]").forEach(function (row) {
        row.style.cursor = "pointer";
        row.addEventListener("click", function () {
            window.location.href = row.dataset.href;
        });
    });

    // ─── Confirm dangerous forms (Delegated to support dynamic forms & CSP safety) ──────────────────────────────
    document.addEventListener("submit", function (e) {
        if (e.target && e.target.tagName === "FORM") {
            var msg = e.target.getAttribute("data-confirm") || e.target.dataset.confirm;
            if (msg && !confirm(msg)) {
                e.preventDefault();
            }
        }
    });

    // ─── Copy to clipboard ────────────────────────────────────
    window.opCopyText = function (text, button, successCallback) {
        var onCopySuccess = function () {
            if (typeof successCallback === "function") {
                successCallback();
            } else if (button) {
                var orig = button.textContent;
                button.textContent = "Copied!";
                setTimeout(function () { button.textContent = orig; }, 1500);
            }
        };

        // Try synchronous copy first (always works inside user gesture, HTTP/HTTPS safe)
        var copyUsingExecCommand = function () {
            var textArea = document.createElement("textarea");
            textArea.value = text;
            textArea.setAttribute("readonly", ""); // Prevent iOS keyboard popup
            textArea.style.fontSize = "12pt";
            textArea.style.position = "absolute";
            textArea.style.left = "-9999px";
            textArea.style.top = (window.pageYOffset || document.documentElement.scrollTop) + "px";
            document.body.appendChild(textArea);

            textArea.focus();
            textArea.select();
            textArea.setSelectionRange(0, 999999);

            var successful = false;
            try {
                successful = document.execCommand("copy");
            } catch (err) {
                console.warn("execCommand copy failed", err);
            }

            document.body.removeChild(textArea);
            return successful;
        };

        if (copyUsingExecCommand()) {
            onCopySuccess();
            return;
        }

        // Fall back to modern async Clipboard API if execCommand is unsupported/blocked
        if (navigator.clipboard && typeof navigator.clipboard.writeText === "function") {
            navigator.clipboard.writeText(text).then(function () {
                onCopySuccess();
            }).catch(function (err) {
                console.error("Async copy failed completely", err);
                alert("Could not copy link automatically. Here is the link:\n\n" + text);
            });
        } else {
            console.error("No clipboard support available");
            alert("Could not copy link automatically. Here is the link:\n\n" + text);
        }
    };

    document.addEventListener("click", function (e) {
        if (!e || !e.target) {
            return;
        }
        var target = e.target;
        if (target.nodeType === 3) { // Text Node
            target = target.parentNode;
        }
        if (target && typeof target.closest === "function") {
            var btn = target.closest("[data-copy]");
            if (btn && !btn.classList.contains("op-copy-btn")) {
                e.preventDefault();
                var text = btn.getAttribute("data-copy") || btn.dataset.copy || "";
                window.opCopyText(text, btn);
            }
        }
    });

    // ─── Theme Toggle (light/dark) ─────────────────────────────
    var THEME_KEY = "op-theme";
    var htmlEl = document.documentElement;

    function applyTheme(theme) {
        htmlEl.setAttribute("data-theme", theme);
        localStorage.setItem(THEME_KEY, theme);
        // Sync toggle icon if present
        var icon = document.getElementById("theme-toggle-icon");
        if (icon) { icon.textContent = theme === "dark" ? "☀" : "☾"; }
    }

    // Apply saved theme immediately (before paint)
    var savedTheme = localStorage.getItem(THEME_KEY);
    if (savedTheme === "light" || savedTheme === "dark") {
        applyTheme(savedTheme);
    }

    // Expose toggle for button onclick
    window.opToggleTheme = function () {
        var current = htmlEl.getAttribute("data-theme") || "dark";
        applyTheme(current === "dark" ? "light" : "dark");
    };

    var themeToggleBtn = document.getElementById("theme-toggle");
    if (themeToggleBtn) {
        themeToggleBtn.addEventListener("click", window.opToggleTheme);
    }

    // ─── Brand Switcher ───────────────────────────────────────
    var brandSelect = document.getElementById("brand-switcher-select");
    var brandForm = document.getElementById("brand-switcher-form");
    if (brandSelect && brandForm) {
        brandSelect.addEventListener("change", function () {
            brandForm.submit();
        });
    }

    // ─── Global Modal Functions & CSP Delegated Handlers ──────────────────────────────
    window.openDeleteModal = function (action, itemName) {
        var form = document.getElementById("delete-form");
        var nameEl = document.getElementById("delete-item-name");
        var modal = document.getElementById("confirm-delete-modal");
        if (form && nameEl && modal) {
            form.action = action;
            nameEl.textContent = itemName;
            modal.hidden = false;
        }
    };

    window.closeModal = function (id) {
        var modal = document.getElementById(id);
        if (modal) {
            modal.hidden = true;
        }
    };

    window.openDetailModal = function (title, url) {
        var titleEl = document.getElementById("detail-modal-title");
        var modal = document.getElementById("detail-modal");
        var content = document.getElementById("detail-modal-content");
        if (titleEl && modal && content) {
            titleEl.textContent = title;
            modal.hidden = false;
            content.innerHTML = '<div class="op-loading">Loading...</div>';
            fetch(url).then(function (r) { return r.text(); }).then(function (html) { content.innerHTML = html; });
        }
    };

    // Global click listener for CSP-compliant delegated handlers
    document.addEventListener("click", function (e) {
        if (!e || !e.target) {
            return;
        }
        var target = e.target;
        if (target.nodeType === 3) {
            target = target.parentNode;
        }

        // 1. Delegated Modal Close
        var closeBtn = target.closest("[data-close-modal]");
        if (closeBtn) {
            var modalId = closeBtn.getAttribute("data-close-modal") || closeBtn.dataset.closeModal;
            window.closeModal(modalId);
            return;
        }

        // 1b. Delegated Modal Open
        var openBtn = target.closest("[data-open-modal]");
        if (openBtn) {
            var modalId = openBtn.getAttribute("data-open-modal") || openBtn.dataset.openModal;
            var modal = document.getElementById(modalId);
            if (modal) {
                modal.hidden = false;
                var focusEl = modal.querySelector("[autofocus]");
                if (focusEl) {
                    focusEl.focus();
                }
            }
            return;
        }

        // 2. Delegated Open Delete Modal
        var deleteBtn = target.closest("[data-open-delete-modal]");
        if (deleteBtn) {
            var action = deleteBtn.getAttribute("data-open-delete-modal") || deleteBtn.dataset.openDeleteModal;
            var itemName = deleteBtn.getAttribute("data-item-name") || deleteBtn.dataset.itemName || "";
            window.openDeleteModal(action, itemName);
            return;
        }

        // 3. Delegated Open Detail Modal
        var detailBtn = target.closest("[data-open-detail-modal]");
        if (detailBtn) {
            var url = detailBtn.getAttribute("data-open-detail-modal") || detailBtn.dataset.openDetailModal;
            var title = detailBtn.getAttribute("data-modal-title") || detailBtn.dataset.modalTitle || "";
            window.openDetailModal(title, url);
            return;
        }
    });

    document.addEventListener("keydown", function (e) {
        if (e.key === "Escape") {
            document.querySelectorAll(".op-modal:not([hidden])").forEach(function (m) { m.hidden = true; });
        }
    });

    // Centralized capturing-phase error listener for admin gateway logos
    document.addEventListener("error", function (e) {
        if (e.target && e.target.classList.contains("op-gateway-logo-img")) {
            e.target.classList.add("op-img-error");
        }
    }, true);

})();
