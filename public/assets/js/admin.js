/**
 * OwnPay Admin JS - sidebar, dropdowns, flash alerts, mobile UX.
 * Prefix: op-
 * OWASP: CSRF token in all requests, no inline eval, CSP-friendly.
 */
(function () {
    "use strict";

    // --- CSRF Token -------------------------------------------
    var meta = document.querySelector('meta[name="csrf-token"]');
    window.OP_CSRF = meta ? meta.getAttribute("content") : "";

    var isMobile = function () { return window.innerWidth < 768; };

    // --- Sidebar Toggle ---------------------------------------
    var sidebar = document.getElementById("sidebar");
    var toggle = document.getElementById("sidebar-toggle");
    var backdrop = null;

    function createBackdrop() {
        if (backdrop) { return; }
        backdrop = document.createElement("div");
        backdrop.id = "op-sidebar-backdrop";
        document.body.appendChild(backdrop);
        backdrop.addEventListener("click", closeMobileSidebar);
    }

    function openMobileSidebar() {
        if (!sidebar) { return; }
        sidebar.classList.remove("op-sidebar-collapsed");
        sidebar.classList.add("op-sidebar-open");
        if (backdrop) { backdrop.style.display = "block"; }
        document.body.style.overflow = "hidden";
    }

    function closeMobileSidebar() {
        if (!sidebar) { return; }
        sidebar.classList.remove("op-sidebar-open");
        if (backdrop) { backdrop.style.display = "none"; }
        document.body.style.overflow = "";
    }

    function toggleDesktopSidebar() {
        if (!sidebar) { return; }
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
            if (backdrop) { backdrop.style.display = "none"; }
            document.body.style.overflow = "";
        }
    });

    // --- Sub-nav Expand / Collapse ----------------------------
    document.querySelectorAll(".op-nav-group").forEach(function (item) {
        var link = item.querySelector(":scope > .op-nav-item-link");
        if (!link) { return; }
        link.addEventListener("click", function (e) {
            var sub = item.querySelector(":scope > .op-sub-nav");
            if (sub) {
                e.preventDefault();
                var isExpanded = item.classList.contains("op-nav-expanded");
                document.querySelectorAll(".op-nav-group.op-nav-expanded").forEach(function (other) {
                    if (other !== item) { other.classList.remove("op-nav-expanded"); }
                });
                if (isExpanded) {
                    item.classList.remove("op-nav-expanded");
                } else {
                    item.classList.add("op-nav-expanded");
                }
            }
        });
    });

    // --- User Menu Dropdown (Navbar) --------------------------
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

    // --- Sidebar User Menu ----------------------------------
    var sidebarUserBtn = document.getElementById("sidebar-user-menu-btn");
    var sidebarUserMenu = document.getElementById("sidebar-user-menu");
    if (sidebarUserBtn && sidebarUserMenu) {
        sidebarUserBtn.addEventListener("click", function (e) {
            e.stopPropagation();
            sidebarUserMenu.classList.toggle("open");
        });
        document.addEventListener("click", function () {
            sidebarUserMenu.classList.remove("open");
        });
    }

    // --- Notification Panel --------------------------------
    var notifPanel = document.getElementById("notification-panel");
    var notifBackdrop = document.getElementById("notif-backdrop");
    var alertBell = document.getElementById("alert-bell");

    function openNotifPanel() {
        if (notifPanel) { notifPanel.classList.add("open"); }
        if (notifBackdrop) { notifBackdrop.classList.add("open"); }
    }

    function closeNotifPanel() {
        if (notifPanel) { notifPanel.classList.remove("open"); }
        if (notifBackdrop) { notifBackdrop.classList.remove("open"); }
    }

    if (alertBell) {
        alertBell.querySelector(".op-icon-btn").addEventListener("click", function (e) {
            e.stopPropagation();
            if (notifPanel && notifPanel.classList.contains("open")) {
                closeNotifPanel();
            } else {
                openNotifPanel();
            }
        });
    }

    if (notifBackdrop) {
        notifBackdrop.addEventListener("click", closeNotifPanel);
    }

    var notifMarkRead = document.getElementById("notif-mark-read");
    if (notifMarkRead) {
        notifMarkRead.addEventListener("click", function () {
            document.querySelectorAll(".op-notif-unread").forEach(function (item) {
                item.classList.remove("op-notif-unread");
            });
            document.querySelectorAll(".op-notif-dot").forEach(function (dot) {
                dot.remove();
            });
            var badge = document.querySelector(".op-badge-dot");
            if (badge) { badge.style.display = "none"; }
        });
    }

    document.addEventListener("click", function (e) {
        var deleteBtn = e.target.closest(".op-notif-delete");
        if (deleteBtn) {
            e.stopPropagation();
            var item = deleteBtn.closest(".op-notif-item");
            if (item) {
                item.style.opacity = "0";
                item.style.transform = "translateX(20px)";
                setTimeout(function () {
                    item.remove();
                    var remaining = document.querySelectorAll(".op-notif-item");
                    if (remaining.length === 0) {
                        var body = document.querySelector(".op-notif-panel-body");
                        if (body) {
                            body.innerHTML = '<div style="padding: 40px 24px; text-align: center; color: var(--op-text-muted); font-size: 13px;">No notifications</div>';
                        }
                    }
                    updateNotifBadge();
                }, 200);
            }
        }
    });

    function updateNotifBadge() {
        var unread = document.querySelectorAll(".op-notif-unread").length;
        var badge = document.querySelector(".op-badge-dot");
        if (badge) {
            if (unread > 0) {
                badge.textContent = unread;
                badge.style.display = "flex";
            } else {
                badge.style.display = "none";
            }
        }
    }

    // --- Initialize Page UI (Dropdowns, Datepickers, Table rows) -----------------
    window.opInitPageUI = function () {
        // 1. Generic Custom Dropdowns
        document.querySelectorAll(".op-custom-dropdown").forEach(function (wrap) {
            if (wrap.hasAttribute("data-op-initialized")) { return; }
            wrap.setAttribute("data-op-initialized", "true");

            var btn = wrap.querySelector(".op-custom-dropdown-btn");
            var menu = wrap.querySelector(".op-custom-dropdown-menu");
            var label = wrap.querySelector(".op-custom-dropdown-label");
            var hidden = wrap.querySelector('input[type="hidden"]');
            if (!btn || !menu) { return; }

            btn.addEventListener("click", function (e) {
                e.stopPropagation();
                document.querySelectorAll(".op-custom-dropdown.open").forEach(function (other) {
                    if (other !== wrap) { other.classList.remove("open"); }
                });
                wrap.classList.toggle("open");
            });

            menu.addEventListener("click", function (e) {
                e.stopPropagation();
            });

            menu.querySelectorAll(".op-custom-dropdown-option").forEach(function (opt) {
                opt.addEventListener("click", function () {
                    var value = this.getAttribute("data-value");
                    var text = this.textContent;
                    menu.querySelectorAll(".op-custom-dropdown-option").forEach(function (o) { o.classList.remove("active"); });
                    this.classList.add("active");
                    if (label) { label.textContent = text; }
                    if (hidden) {
                        hidden.value = value;
                        hidden.dispatchEvent(new Event("change", { bubbles: true }));
                    }
                    wrap.classList.remove("open");
                    var form = wrap.closest("form");
                    if (form && wrap.hasAttribute("data-auto-submit")) {
                        form.dispatchEvent(new Event("submit", { cancelable: true, bubbles: true }));
                    }
                });
            });
        });

        // 2. Custom Date Picker
        var monthYearFormatter = new Intl.DateTimeFormat("en-US", { month: "long", year: "numeric" });
        document.querySelectorAll(".op-date-picker").forEach(function (wrapper) {
            if (wrapper.hasAttribute("data-op-initialized")) { return; }
            wrapper.setAttribute("data-op-initialized", "true");

            var inputEl = wrapper.querySelector(".op-date-picker-input");
            var triggerBtn = wrapper.querySelector(".op-date-picker-trigger");
            var popover = wrapper.querySelector(".op-calendar-popover");
            var headingEl = wrapper.querySelector(".op-calendar-heading");
            var gridBody = wrapper.querySelector(".op-calendar-grid-body");
            var prevBtn = wrapper.querySelector(".op-calendar-prev");
            var nextBtn = wrapper.querySelector(".op-calendar-next");
            var hiddenInput = wrapper.querySelector('input[type="hidden"]');

            if (!inputEl || !popover || !gridBody) { return; }

            var selectedDate = null;
            var currentViewDate = new Date();

            if (hiddenInput && hiddenInput.value) {
                var parts = hiddenInput.value.split("-");
                if (parts.length === 3) {
                    selectedDate = new Date(parseInt(parts[0]), parseInt(parts[1]) - 1, parseInt(parts[2]));
                    currentViewDate = new Date(selectedDate.getFullYear(), selectedDate.getMonth(), 1);
                }
            } else {
                currentViewDate = new Date(currentViewDate.getFullYear(), currentViewDate.getMonth(), 1);
            }

            function formatDate(d) {
                var y = d.getFullYear();
                var m = String(d.getMonth() + 1).padStart(2, "0");
                var day = String(d.getDate()).padStart(2, "0");
                return y + "-" + m + "-" + day;
            }

            function formatDisplay(d) {
                return new Intl.DateTimeFormat("en-US", { month: "short", day: "numeric", year: "numeric" }).format(d);
            }

            function renderCalendar() {
                gridBody.innerHTML = "";
                headingEl.textContent = monthYearFormatter.format(currentViewDate);

                var year = currentViewDate.getFullYear();
                var month = currentViewDate.getMonth();
                var firstDay = new Date(year, month, 1).getDay();
                var daysInMonth = new Date(year, month + 1, 0).getDate();
                var today = new Date();

                for (var i = 0; i < firstDay; i++) {
                    var emptyCell = document.createElement("div");
                    emptyCell.className = "op-calendar-cell empty";
                    gridBody.appendChild(emptyCell);
                }

                for (var day = 1; day <= daysInMonth; day++) {
                    var cell = document.createElement("button");
                    cell.type = "button";
                    cell.className = "op-calendar-cell";
                    cell.textContent = day;

                    var cellDate = new Date(year, month, day);

                    if (selectedDate && cellDate.getDate() === selectedDate.getDate() && cellDate.getMonth() === selectedDate.getMonth() && cellDate.getFullYear() === selectedDate.getFullYear()) {
                        cell.classList.add("selected");
                    }

                    if (cellDate.getDate() === today.getDate() && cellDate.getMonth() === today.getMonth() && cellDate.getFullYear() === today.getFullYear()) {
                        cell.classList.add("today");
                    }

                    (function (cd) {
                        cell.addEventListener("click", function () {
                            selectedDate = cd;
                            inputEl.value = formatDisplay(cd);
                            if (hiddenInput) { hiddenInput.value = formatDate(cd); }
                            popover.classList.remove("active");
                            renderCalendar();
                            var form = wrapper.closest("form");
                            if (form && wrapper.hasAttribute("data-auto-submit")) {
                                form.dispatchEvent(new Event("submit", { cancelable: true, bubbles: true }));
                            }
                        });
                    })(cellDate);

                    gridBody.appendChild(cell);
                }
            }

            function togglePopover(e) {
                if (e) { e.stopPropagation(); }
                if (!popover.classList.contains("active") && selectedDate) {
                    currentViewDate = new Date(selectedDate.getFullYear(), selectedDate.getMonth(), 1);
                }
                popover.classList.toggle("active");
                if (popover.classList.contains("active")) {
                    renderCalendar();
                    popover.classList.remove("flip-up");
                    var rect = popover.getBoundingClientRect();
                    if (rect.bottom > window.innerHeight - 16) {
                        popover.classList.add("flip-up");
                    }
                }
            }

            inputEl.addEventListener("click", togglePopover);
            if (triggerBtn) { triggerBtn.addEventListener("click", togglePopover); }

            if (prevBtn) {
                prevBtn.addEventListener("click", function () {
                    currentViewDate.setMonth(currentViewDate.getMonth() - 1);
                    renderCalendar();
                });
            }

            if (nextBtn) {
                nextBtn.addEventListener("click", function () {
                    currentViewDate.setMonth(currentViewDate.getMonth() + 1);
                    renderCalendar();
                });
            }

            document.addEventListener("click", function (e) {
                if (!wrapper.contains(e.target) && popover.classList.contains("active")) {
                    popover.classList.remove("active");
                }
            });

            renderCalendar();
        });

        // 3. Table row click -> detail
        document.querySelectorAll(".op-table tbody tr[data-href]").forEach(function (row) {
            if (row.hasAttribute("data-op-initialized")) { return; }
            row.setAttribute("data-op-initialized", "true");
            row.style.cursor = "pointer";
            row.addEventListener("click", function () {
                var targetUrl = this.dataset.href;
                var contentArea = document.querySelector(".op-content.t-panel-slide");
                if (contentArea) {
                    contentArea.setAttribute("data-open", "false");
                }
                setTimeout(function () {
                    window.location.href = targetUrl;
                }, 150);
            });
        });

        // 4. Page-specific custom loaders
        if (typeof window.opInitSettingsUI === "function") {
            window.opInitSettingsUI();
        }
        if (typeof window.opInitSmsCenterUI === "function") {
            window.opInitSmsCenterUI();
        }
        if (typeof window.opInitDeveloperUI === "function") {
            window.opInitDeveloperUI();
        }
    };

    // Run UI initializers once immediately
    window.opInitPageUI();

    // Close open dropdowns when clicking outside
    document.addEventListener("click", function () {
        document.querySelectorAll(".op-custom-dropdown.open").forEach(function (wrap) {
            wrap.classList.remove("open");
        });
    });

    // --- Same-Page AJAX Navigation and Submission ----------------------------
    var contentArea = document.querySelector(".op-content.t-panel-slide");

    // entrance animation trigger
    if (contentArea) {
        window.addEventListener("pageshow", function () {
            contentArea.setAttribute("data-open", "true");
        });
    }

    function executeScripts(container) {
        if (!container) { return; }
        container.querySelectorAll("script").forEach(function (oldScript) {
            var newScript = document.createElement("script");
            Array.from(oldScript.attributes).forEach(function (attr) {
                newScript.setAttribute(attr.name, attr.value);
            });
            if (oldScript.src) {
                newScript.src = oldScript.src;
            } else {
                newScript.textContent = oldScript.textContent;
            }
            oldScript.parentNode.replaceChild(newScript, oldScript);
        });
    }

    // Same-page links interceptor (pagination, filters, tabs)
    document.addEventListener("click", function (e) {
        if (e.defaultPrevented) { return; }
        var link = e.target.closest("a[href]");
        if (link) {
            var href = link.getAttribute("href");
            if (!href || href.startsWith("#") || href.startsWith("javascript:") || href.startsWith("mailto:") || href.startsWith("tel:") || link.target || link.hasAttribute("download")) {
                return;
            }

            // Same-origin, same-page navigation handled via AJAX
            if (link.hostname === window.location.hostname && link.pathname === window.location.pathname && !e.ctrlKey && !e.metaKey && !e.shiftKey) {
                e.preventDefault();

                if (contentArea) {
                    contentArea.setAttribute("data-open", "false");
                }

                var targetUrl = link.pathname + link.search + link.hash;

                fetch(targetUrl, {
                    headers: { "X-Requested-With": "XMLHttpRequest" }
                })
                    .then(function (res) {
                        if (!res.ok) { throw new Error("Navigation failed"); }
                        return res.text();
                    })
                    .then(function (html) {
                        history.pushState(null, null, targetUrl);

                        var parser = new DOMParser();
                        var doc = parser.parseFromString(html, "text/html");

                        var newContent = doc.querySelector(".op-content");
                        var currentContent = document.querySelector(".op-content");
                        if (newContent && currentContent) {
                            currentContent.innerHTML = newContent.innerHTML;
                            executeScripts(currentContent);
                            requestAnimationFrame(function () {
                                currentContent.setAttribute("data-open", "true");
                            });
                            window.opInitPageUI();
                        }
                    })
                    .catch(function () {
                        window.location.href = targetUrl;
                    });
            } else if (link.hostname === window.location.hostname && !link.target && !e.ctrlKey && !e.metaKey) {
                // Different page: animate exit only
                if (contentArea) {
                    contentArea.setAttribute("data-open", "false");
                }
            }
        }
    });

    // Same-page Form Submission Interceptor (AJAX CRUD / settings save)
    document.addEventListener("submit", function (e) {
        if (e.defaultPrevented) { return; }
        var form = e.target;
        if (form.target || form.hasAttribute("data-no-ajax") || form.action.startsWith("javascript:")) {
            return;
        }

        var actionUrl = new URL(form.action || window.location.href, window.location.origin);
        if (actionUrl.origin !== window.location.origin) {
            return;
        }

        // Exclude logout
        if (actionUrl.pathname === "/admin/logout") {
            return;
        }

        // Intercept all forms under /admin/
        if (actionUrl.pathname.startsWith("/admin/")) {
            e.preventDefault();

            var submitBtn = form.querySelector("button[type='submit']");
            var originalBtnHTML = submitBtn ? submitBtn.innerHTML : "";
            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.innerHTML = "<span class='op-spinner'></span> Saving...";
            }

            var formData = new FormData(form);
            var fetchPromise;
            if (form.method.toLowerCase() === "get") {
                var params = new URLSearchParams(formData).toString();
                fetchPromise = fetch(actionUrl.pathname + "?" + params, {
                    headers: { "X-Requested-With": "XMLHttpRequest" }
                });
            } else {
                fetchPromise = fetch(form.action || window.location.href, {
                    method: "POST",
                    body: formData,
                    headers: { "X-Requested-With": "XMLHttpRequest" }
                });
            }

            fetchPromise
                .then(function (res) {
                    return res.text().then(function (html) {
                        return { ok: res.ok, html: html, url: res.url };
                    });
                })
                .then(function (result) {
                    if (submitBtn) {
                        submitBtn.disabled = false;
                        submitBtn.innerHTML = originalBtnHTML;
                    }

                    if (!result.ok) {
                        window.opShowToast("Error processing request.", "danger");
                        return;
                    }

                    var responseUrl = new URL(result.url);
                    if (!responseUrl.pathname.startsWith("/admin/")) {
                        window.location.href = result.url;
                        return;
                    }

                    if (responseUrl.pathname + responseUrl.search !== window.location.pathname + window.location.search) {
                        history.pushState(null, null, responseUrl.pathname + responseUrl.search + responseUrl.hash);
                    }

                    var parser = new DOMParser();
                    var doc = parser.parseFromString(result.html, "text/html");

                    var newContent = doc.querySelector(".op-content");
                    var currentContent = document.querySelector(".op-content");
                    if (newContent && currentContent) {
                        var activeTab = document.querySelector(".op-tab.active");
                        var activeTabSlug = activeTab ? activeTab.dataset.tab : null;

                        var activeSandboxTab = document.querySelector(".op-sandbox-tab.active");
                        var activeSandboxTabSlug = activeSandboxTab ? activeSandboxTab.dataset.sandboxTab : null;

                        currentContent.innerHTML = newContent.innerHTML;
                        executeScripts(currentContent);
                        requestAnimationFrame(function () {
                            currentContent.setAttribute("data-open", "true");
                        });

                        // Re-activate active tabs
                        if (activeTabSlug) {
                            var tabBtn = document.querySelector(".op-tab[data-tab='" + activeTabSlug + "']");
                            if (tabBtn) { tabBtn.click(); }
                        }
                        if (activeSandboxTabSlug) {
                            var sandboxTabBtn = document.querySelector(".op-sandbox-tab[data-sandbox-tab='" + activeSandboxTabSlug + "']");
                            if (sandboxTabBtn) { sandboxTabBtn.click(); }
                        }

                        window.opInitPageUI();
                        window.opShowToast("Changes saved successfully!", "success");
                    }
                })
                .catch(function () {
                    if (submitBtn) {
                        submitBtn.disabled = false;
                        submitBtn.innerHTML = originalBtnHTML;
                    }
                    window.opShowToast("A network error occurred.", "danger");
                });
        }
    });

    // --- Brand Switcher Dropdown ------------------------------
    var brandSwitcher = document.getElementById("brand-switcher");
    var brandPillBtn = document.getElementById("brand-pill-btn");
    var brandDropdown = document.getElementById("brand-dropdown");
    if (brandPillBtn && brandDropdown && brandSwitcher) {
        brandPillBtn.addEventListener("click", function (e) {
            e.stopPropagation();
            brandSwitcher.classList.toggle("open");
        });
        document.addEventListener("click", function () {
            brandSwitcher.classList.remove("open");
        });
        brandDropdown.addEventListener("click", function (e) {
            e.stopPropagation();
        });
        // Handle brand selection and submit POST
        brandDropdown.querySelectorAll(".op-brand-dropdown-item[data-brand-id]").forEach(function (item) {
            item.addEventListener("click", function (e) {
                e.preventDefault();
                var brandId = item.getAttribute("data-brand-id");
                // Submit hidden form for server-side handling
                var form = document.createElement("form");
                form.method = "POST";
                form.action = "/admin/brands/switch";
                var csrfInput = document.createElement("input");
                csrfInput.type = "hidden";
                csrfInput.name = "_csrf_token";
                csrfInput.value = window.OP_CSRF || "";
                form.appendChild(csrfInput);
                var brandInput = document.createElement("input");
                brandInput.type = "hidden";
                brandInput.name = "brand_id";
                brandInput.value = brandId === "0" ? "global" : brandId;
                form.appendChild(brandInput);
                document.body.appendChild(form);
                form.submit();
            });
        });
    }

    // --- Date Switcher Dropdown ------------------------------
    var dateSwitcher = document.getElementById("date-switcher");
    var datePillBtn = document.getElementById("date-pill-btn");
    var dateDropdown = document.getElementById("date-dropdown");
    var datePillLabel = document.getElementById("date-pill-label");
    if (datePillBtn && dateDropdown && dateSwitcher) {
        datePillBtn.addEventListener("click", function (e) {
            e.stopPropagation();
            dateSwitcher.classList.toggle("open");
        });
        document.addEventListener("click", function () {
            dateSwitcher.classList.remove("open");
        });
        dateDropdown.addEventListener("click", function (e) {
            e.stopPropagation();
        });
        dateDropdown.querySelectorAll(".op-date-option").forEach(function (opt) {
            opt.addEventListener("click", function () {
                var value = opt.getAttribute("data-value");
                // Update active state
                dateDropdown.querySelectorAll(".op-date-option").forEach(function (o) { o.classList.remove("active"); });
                opt.classList.add("active");
                // Update label
                if (datePillLabel) { datePillLabel.textContent = opt.textContent; }
                // Close dropdown
                dateSwitcher.classList.remove("open");
                // Navigate with range param
                var url = new URL(window.location.href);
                url.searchParams.set("range", value);
                window.location.href = url.toString();
            });
        });
    }

    // --- Flash Alert Dismissal -----------------------------
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

    // --- Global Search ----------------------------------------
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



    // --- Confirm dangerous forms (Delegated to support dynamic forms & CSP safety) ------------------------------
    document.addEventListener("submit", function (e) {
        if (e.target && e.target.tagName === "FORM") {
            var msg = e.target.getAttribute("data-confirm") || e.target.dataset.confirm;
            if (msg && !confirm(msg)) {
                e.preventDefault();
            }
        }
    });

    // --- Copy to clipboard ------------------------------------
    window.opCopyText = function (text, button, successCallback) {
        var onCopySuccess = function () {
            if (typeof successCallback === "function") {
                successCallback();
            } else if (button) {
                button.classList.add("op-copied");
                setTimeout(function () {
                    button.classList.remove("op-copied");
                }, 1500);
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
            if (btn) {
                e.preventDefault();
                var text = btn.getAttribute("data-copy") || btn.dataset.copy || "";
                var element = null;
                if (text && text.indexOf(" ") === -1 && text.indexOf("\n") === -1) {
                    element = document.getElementById(text);
                }
                if (element) {
                    text = element.textContent.trim();
                }
                window.opCopyText(text, btn);
            }
        }
    });

    // --- Theme Toggle (light/dark) -----------------------------
    var THEME_KEY = "op-theme";
    var htmlEl = document.documentElement;

    function applyTheme(theme) {
        htmlEl.setAttribute("data-theme", theme);
        localStorage.setItem(THEME_KEY, theme);
        // Sync toggle icons if present
        var moonIcon = document.getElementById("moonIcon");
        var sunIcon = document.getElementById("sunIcon");
        if (moonIcon && sunIcon) {
            if (theme === "dark") {
                moonIcon.style.display = "none";
                sunIcon.style.display = "block";
            } else {
                moonIcon.style.display = "block";
                sunIcon.style.display = "none";
            }
        }
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


    // --- Global Modal Functions & CSP Delegated Handlers ------------------------------
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

    // --- Toast Notifications ----------------------------------
    window.opShowToast = function (message, type) {
        var container = document.getElementById("toast-container");
        if (!container) {
            container = document.createElement("div");
            container.id = "toast-container";
            container.className = "op-toast-container";
            document.body.appendChild(container);
        }

        var toast = document.createElement("div");
        toast.className = "op-toast op-toast-" + (type || "info");

        var icon = "";
        if (type === "success") {
            icon = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>';
        } else if (type === "error") {
            icon = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>';
        } else {
            icon = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>';
        }

        toast.innerHTML = '<span class="op-toast-icon">' + icon + "</span>" +
            '<span class="op-toast-message">' + message + "</span>" +
            '<button class="op-toast-close">&times;</button>';

        container.appendChild(toast);

        // Slide in
        setTimeout(function () {
            toast.classList.add("show");
        }, 10);

        // Auto remove
        var timer = setTimeout(function () {
            dismissToast(toast);
        }, 4000);

        toast.querySelector(".op-toast-close").addEventListener("click", function (e) {
            e.stopPropagation();
            clearTimeout(timer);
            dismissToast(toast);
        });
    };

    function dismissToast(toast) {
        toast.classList.remove("show");
        toast.classList.add("hide");
        setTimeout(function () {
            toast.remove();
        }, 300);
    }

    // Convert static flash alerts to sleek toasts on load
    document.querySelectorAll(".op-alert-dismissible").forEach(function (alert) {
        var type = alert.classList.contains("op-alert-success") ? "success" : "error";
        var text = alert.textContent.replace("×", "").trim();
        window.opShowToast(text, type);
        alert.remove();
    });

    // --- Custom Confirmation Modal -----------------------------
    var confirmCallback = null;

    window.opShowConfirm = function (title, message, confirmText, cancelText, callback) {
        var modal = document.getElementById("confirm-modal");
        if (!modal) {
            // Fallback if modal HTML is not present
            if (confirm(message)) {
                callback();
            }
            return;
        }

        document.getElementById("confirm-modal-title").textContent = title || "Confirmation";
        document.getElementById("confirm-modal-message").textContent = message || "Are you sure you want to proceed?";

        var submitBtn = document.getElementById("confirm-modal-submit");
        if (submitBtn) {
            submitBtn.textContent = confirmText || "Confirm";
        }

        var cancelBtn = document.getElementById("confirm-modal-cancel");
        if (cancelBtn) {
            cancelBtn.textContent = cancelText || "Cancel";
        }

        confirmCallback = callback;
        modal.hidden = false;
    };

    function closeConfirmModal() {
        var modal = document.getElementById("confirm-modal");
        if (modal) {
            modal.hidden = true;
        }
        confirmCallback = null;
    }

    var confirmCancel = document.getElementById("confirm-modal-cancel");
    if (confirmCancel) {
        confirmCancel.addEventListener("click", closeConfirmModal);
    }
    var confirmClose = document.getElementById("confirm-modal-close");
    if (confirmClose) {
        confirmClose.addEventListener("click", closeConfirmModal);
    }
    var confirmBackdrop = document.getElementById("confirm-modal-backdrop");
    if (confirmBackdrop) {
        confirmBackdrop.addEventListener("click", closeConfirmModal);
    }
    var confirmSubmit = document.getElementById("confirm-modal-submit");
    if (confirmSubmit) {
        confirmSubmit.addEventListener("click", function () {
            if (confirmCallback) {
                confirmCallback();
            }
            closeConfirmModal();
        });
    }

    // Intercept clicks on elements with data-confirm
    document.addEventListener("click", function (e) {
        var confirmEl = e.target.closest("[data-confirm]");
        if (confirmEl) {
            e.preventDefault();
            e.stopPropagation();

            var message = confirmEl.getAttribute("data-confirm");
            var title = confirmEl.getAttribute("data-confirm-title") || "Are you sure?";
            var confirmText = confirmEl.getAttribute("data-confirm-text") || "Confirm";
            var cancelText = confirmEl.getAttribute("data-confirm-cancel") || "Cancel";

            window.opShowConfirm(title, message, confirmText, cancelText, function () {
                if (confirmEl.type === "submit") {
                    var form = confirmEl.form || confirmEl.closest("form");
                    if (form) {
                        form.submit();
                    }
                } else if (confirmEl.tagName === "FORM") {
                    confirmEl.submit();
                } else if (confirmEl.tagName === "A") {
                    window.location.href = confirmEl.href;
                } else {
                    if (typeof confirmEl.click === "function") {
                        confirmEl.removeAttribute("data-confirm");
                        confirmEl.click();
                        confirmEl.setAttribute("data-confirm", message);
                    }
                }
            });
        }
    }, true);

    // --- Dynamic Documentation Helper -------------------------
    if (window.OP_DOC_URL && window.OP_DOC_URL !== "") {
        var header = document.querySelector(".op-page-header h1, .dash-header h1");
        if (header) {
            var docLink = document.createElement("a");
            docLink.href = window.OP_DOC_URL;
            docLink.target = "_blank";
            docLink.className = "op-help-doc-link";
            docLink.title = "View documentation guide for this page";
            docLink.innerHTML = '<span style="font-size: 1.1rem; line-height: 1; vertical-align: middle; margin-left: 8px; cursor: pointer; opacity: 0.8; transition: opacity 0.2s;">📖</span>';
            header.appendChild(docLink);

            docLink.addEventListener("mouseenter", function () { this.style.opacity = "1"; });
            docLink.addEventListener("mouseleave", function () { this.style.opacity = "0.8"; });
        }
    }

    // Centralized capturing-phase error listener for admin gateway logos
    document.addEventListener("error", function (e) {
        if (e.target && e.target.classList.contains("op-gateway-logo-img")) {
            e.target.classList.add("op-img-error");
        }
    }, true);

    // --- Custom Alert Override ----------------------------------
    window.alert = function (message) {
        var modal = document.getElementById("op-alert-modal");
        if (!modal) {
            modal = document.createElement("div");
            modal.className = "op-modal";
            modal.id = "op-alert-modal";
            modal.hidden = true;
            modal.innerHTML =
                '<div class="op-modal-backdrop" id="op-alert-modal-backdrop"></div>' +
                '<div class="op-modal-dialog">' +
                '    <div class="op-modal-header">' +
                '        <h4 id="op-alert-modal-title">Alert</h4>' +
                '        <button type="button" class="op-modal-close" id="op-alert-modal-close">&times;</button>' +
                "    </div>" +
                '    <div class="op-modal-body">' +
                '        <p id="op-alert-modal-message" style="word-break: break-word; line-height: 1.6; color: var(--op-text);"></p>' +
                "    </div>" +
                '    <div class="op-modal-footer">' +
                '        <button type="button" class="op-btn op-btn-primary" id="op-alert-modal-ok">OK</button>' +
                "    </div>" +
                "</div>";
            document.body.appendChild(modal);

            var closeModal = function () {
                modal.hidden = true;
            };
            document.getElementById("op-alert-modal-close").addEventListener("click", closeModal);
            document.getElementById("op-alert-modal-backdrop").addEventListener("click", closeModal);
            document.getElementById("op-alert-modal-ok").addEventListener("click", closeModal);
        }

        document.getElementById("op-alert-modal-message").textContent = message;
        modal.hidden = false;
    };

})();
