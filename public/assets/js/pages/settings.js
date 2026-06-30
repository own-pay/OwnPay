/**
 * OwnPay Admin - Settings Page JS
 * Handles: tab switching, URL hash navigation, maintenance mode toggle,
 *          FAQ dynamic add/remove, Feature card dynamic add/remove.
 */
(function () {
    "use strict";

    window.opInitSettingsUI = function () {
        var container = document.querySelector(".op-settings-layout");
        if (!container) { return; }

        // --- Tab Switching --------------------------------------------------------
        document.querySelectorAll(".op-tab").forEach(function (t) {
            t.addEventListener("click", function () {
                document.querySelectorAll(".op-tab, .op-tab-panel").forEach(function (e) {
                    e.classList.remove("active");
                });
                this.classList.add("active");
                var panel = document.getElementById("tab-" + this.dataset.tab);
                if (panel) { panel.classList.add("active"); }
                var tabInput = document.getElementById("active-tab-input");
                if (tabInput) { tabInput.value = this.dataset.tab; }
                if (window.location.hash !== "#tab-" + this.dataset.tab) {
                    history.replaceState(null, null, "#tab-" + this.dataset.tab);
                }

                // Toggle visibility of Save Settings button for Maintenance and Queue tabs
                var formActions = document.querySelector(".op-form-actions");
                if (formActions) {
                    if (this.dataset.tab === "optimization" || this.dataset.tab === "queue") {
                        formActions.style.display = "none";
                    } else {
                        formActions.style.display = "";
                    }
                }
            });
        });

        // --- Hash-based or POST-redirect tab activation ---------------------------
        var activeTabInput = document.getElementById("active-tab-input");
        var defaultTab = activeTabInput ? activeTabInput.value : "general";
        var targetTab = null;
        if (window.location.hash) {
            targetTab = window.location.hash.replace("#tab-", "");
        } else if (defaultTab && defaultTab !== "general") {
            targetTab = defaultTab;
        }
        if (targetTab) {
            var tab = document.querySelector('.op-tab[data-tab="' + targetTab + '"]');
            if (tab) { tab.click(); }
        }

        // --- Maintenance Mode Warning Toggle -------------------------------------
        var maintToggle = document.getElementById("maintenance-toggle");
        var maintWarn = document.getElementById("maintenance-warning");
        if (maintToggle && maintWarn) {
            maintToggle.addEventListener("change", function () {
                maintWarn.style.display = this.checked ? "block" : "none";
                if (this.checked && !confirm("Enable maintenance mode? Public users will see a 503 page.")) {
                    this.checked = false;
                    maintWarn.style.display = "none";
                }
            });
        }

        // --- FAQ Dynamic Add -----------------------------------------------------
        var addFaqBtn = document.getElementById("add-faq");
        if (addFaqBtn) {
            var faqContainer = document.getElementById("faq-container");
            var faqIdx = faqContainer ? faqContainer.querySelectorAll(".op-faq-row").length : 0;
            addFaqBtn.addEventListener("click", function () {
                faqContainer.insertAdjacentHTML("beforeend",
                    '<div class="op-faq-row op-card op-card-bordered op-mb-2 op-p-3">' +
                    '<div class="op-form-group"><label>Question</label><input type="text" name="faqs[' + faqIdx + '][question]" class="op-input"></div>' +
                    '<div class="op-form-group"><label>Answer</label><textarea name="faqs[' + faqIdx + '][answer]" rows="2" class="op-input"></textarea></div>' +
                    '<button type="button" class="op-btn op-btn-sm op-btn-danger op-faq-remove">Remove</button>' +
                    "</div>"
                );
                faqIdx++;
            });
            faqContainer.addEventListener("click", function (e) {
                if (e.target.classList.contains("op-faq-remove")) {
                    e.target.closest(".op-faq-row").remove();
                }
            });
        }

        // --- Feature Card Dynamic Add ---------------------------------------------
        var addFeatureBtn = document.getElementById("add-feature");
        if (addFeatureBtn) {
            var featContainer = document.getElementById("features-container");
            var featureIdx = featContainer ? featContainer.querySelectorAll(".op-faq-row").length : 0;
            addFeatureBtn.addEventListener("click", function () {
                featContainer.insertAdjacentHTML("beforeend",
                    '<div class="op-faq-row op-card op-card-bordered op-mb-2 op-p-3">' +
                    '<div class="op-form-row">' +
                    '<div class="op-form-group op-col-6"><label>Title</label><input type="text" name="features[' + featureIdx + '][title]" class="op-input"></div>' +
                    '<div class="op-form-group op-col-6"><label>Description</label><input type="text" name="features[' + featureIdx + '][description]" class="op-input"></div>' +
                    "</div>" +
                    '<button type="button" class="op-btn op-btn-sm op-btn-danger op-faq-remove">Remove</button>' +
                    "</div>"
                );
                featureIdx++;
            });
            featContainer.addEventListener("click", function (e) {
                if (e.target.classList.contains("op-faq-remove")) {
                    e.target.closest(".op-faq-row").remove();
                }
            });
        }

        function toggleCronSecret() {
            const input = document.getElementById("cron-secret-input");
            const icon = document.getElementById("secret-eye-icon");
            if (input && input.type === "password") {
                input.type = "text";
                if (icon) {
                    icon.innerHTML = '<path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/>';
                }
            } else if (input) {
                input.type = "password";
                if (icon) {
                    icon.innerHTML = '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>';
                }
            }
        }

        function copyToClipboard(selector, button) {
            const input = document.querySelector(selector);
            if (!input) { return; }

            const prevType = input.type;
            if (prevType === "password") {
                input.type = "text";
            }
            input.select();
            input.setSelectionRange(0, 99999);
            try {
                navigator.clipboard.writeText(input.value).then(() => {
                    showTooltip(button, "Copied!");
                }).catch(() => {
                    document.execCommand("copy");
                    showTooltip(button, "Copied!");
                });
            } catch {
                document.execCommand("copy");
                showTooltip(button, "Copied!");
            }
            if (prevType === "password") {
                input.type = "password";
            }
        }

        function showTooltip(button, text) {
            const existing = button.querySelector(".op-tooltip");
            if (existing) { existing.remove(); }

            const tooltip = document.createElement("span");
            tooltip.className = "op-tooltip";
            tooltip.innerText = text;

            const arrow = document.createElement("span");
            arrow.className = "op-tooltip-arrow";
            tooltip.appendChild(arrow);

            button.classList.add("op-relative");
            button.appendChild(tooltip);

            setTimeout(() => {
                tooltip.style.opacity = "1";
                tooltip.style.transform = "translateX(-50%) translateY(-2px)";
            }, 10);

            setTimeout(() => {
                tooltip.style.opacity = "0";
                tooltip.style.transform = "translateX(-50%)";
                setTimeout(() => {
                    tooltip.remove();
                    button.classList.remove("op-relative");
                }, 200);
            }, 1500);
        }

        // Logo drop zone click
        const logoDropZone = document.getElementById("logo-drop-zone");
        if (logoDropZone) {
            logoDropZone.addEventListener("click", function () {
                const input = document.getElementById("logo-file-input");
                if (input) { input.click(); }
            });
        }

        // Favicon drop zone click
        const faviconDropZone = document.getElementById("favicon-drop-zone");
        if (faviconDropZone) {
            faviconDropZone.addEventListener("click", function () {
                const input = document.getElementById("favicon-file-input");
                if (input) { input.click(); }
            });
        }

        // File inputs change
        const logoFileInput = document.getElementById("logo-file-input");
        if (logoFileInput) {
            logoFileInput.addEventListener("change", function () {
                const form = document.getElementById("logo-upload-form");
                if (form) { form.submit(); }
            });
        }
        const faviconFileInput = document.getElementById("favicon-file-input");
        if (faviconFileInput) {
            faviconFileInput.addEventListener("change", function () {
                const form = document.getElementById("favicon-upload-form");
                if (form) { form.submit(); }
            });
        }

        // Cron secret toggle click
        const btnToggleCronSecret = document.getElementById("btn-toggle-cron-secret");
        if (btnToggleCronSecret) {
            btnToggleCronSecret.addEventListener("click", toggleCronSecret);
        }

        // Cron copy buttons
        document.querySelectorAll(".btn-copy-cron").forEach(function (btn) {
            btn.addEventListener("click", function () {
                const target = this.getAttribute("data-target");
                copyToClipboard(target, this);
            });
        });

        // Cron secret regenerate click
        const btnCronRegenerate = document.getElementById("btn-cron-regenerate");
        if (btnCronRegenerate) {
            btnCronRegenerate.addEventListener("click", function (e) {
                if (!confirm("WARNING: Regenerating the secret will invalidate your current crontab setup. Continue?")) {
                    e.preventDefault();
                }
            });
        }

        // API Key revoke confirmation click
        document.querySelectorAll(".btn-revoke-key").forEach(function (btn) {
            btn.addEventListener("click", function (e) {
                if (!confirm("Revoke this key?")) {
                    e.preventDefault();
                }
            });
        });

        // Sync rates click
        const btnSyncRates = document.getElementById("btn-sync-rates");
        if (btnSyncRates) {
            btnSyncRates.addEventListener("click", function () {
                const syncForm = document.getElementById("currency-sync-form");
                if (syncForm) {
                    syncForm.submit();
                }
            });
        }

        // Add currency click
        const btnAddCurrency = document.getElementById("btn-add-currency");
        if (btnAddCurrency) {
            btnAddCurrency.addEventListener("click", function () {
                const modal = document.getElementById("add-currency-modal");
                if (modal) {
                    modal.removeAttribute("hidden");
                }
            });
        }

        // Close currency modal click
        const closeTriggers = document.querySelectorAll(".close-currency-modal-trigger");
        closeTriggers.forEach(function (trigger) {
            trigger.addEventListener("click", function () {
                const modal = document.getElementById("add-currency-modal");
                if (modal) {
                    modal.setAttribute("hidden", "true");
                }
            });
        });

        // Currency search keyup
        const searchInput = document.getElementById("currency-search");
        if (searchInput) {
            searchInput.addEventListener("keyup", function () {
                const query = this.value.toLowerCase();
                const rows = document.querySelectorAll(".currency-row");
                rows.forEach(function (row) {
                    const code = row.getAttribute("data-code").toLowerCase();
                    const name = row.getAttribute("data-name").toLowerCase();
                    if (code.includes(query) || name.includes(query)) {
                        row.classList.remove("op-d-none");
                    } else {
                        row.classList.add("op-d-none");
                    }
                });
            });
        }

        // Toggle currency status click
        const toggleButtons = document.querySelectorAll(".btn-toggle-currency");
        toggleButtons.forEach(function (btn) {
            btn.addEventListener("click", function () {
                const code = this.getAttribute("data-code");
                if (confirm("Are you sure you want to toggle the status of " + code + "?")) {
                    const form = document.getElementById("currency-toggle-form");
                    if (form) {
                        form.action = "/admin/currencies/toggle/" + encodeURIComponent(code);
                        form.submit();
                    }
                }
            });
        });

        // --- Custom Domain Operations -------------------------------------------
        document.querySelectorAll(".op-copy-btn").forEach(function (btn) {
            btn.addEventListener("click", function (e) {
                e.stopPropagation();
                var el = document.getElementById(this.dataset.copy);
                if (!el) { return; }
                var self = this;
                window.opCopyText(el.textContent.trim(), self, function () {
                    var orig = self.textContent;
                    self.textContent = "✓ Copied!";
                    self.classList.add("op-btn-success");
                    setTimeout(function () { self.textContent = orig; self.classList.remove("op-btn-success"); }, 1800);
                });
            });
        });

        // Populate the Edit Settings modal dynamically
        document.querySelectorAll(".op-edit-domain-btn").forEach(function (btn) {
            btn.addEventListener("click", function (e) {
                e.stopPropagation();
                var id = this.dataset.id;
                var domain = this.dataset.domain;
                var type = this.dataset.type;
                var redirectUrl = this.dataset.redirectUrl;
                var status = this.dataset.status;
                var dnsVerified = this.dataset.dnsVerified;
                var isPrimary = this.dataset.isPrimary;

                var modal = document.getElementById("edit-domain-modal");
                if (modal) {
                    var form = modal.querySelector("form");
                    if (form) {
                        form.action = "/admin/domains/" + id + "/update";
                    }

                    var domainDisplay = modal.querySelector('input[name="domain_display"]');
                    if (domainDisplay) {
                        domainDisplay.value = domain;
                    }

                    var typeInput = modal.querySelector('select[name="type"]');
                    if (typeInput) {
                        typeInput.value = type;
                    }

                    var redirectInput = modal.querySelector('input[name="redirect_url"]');
                    if (redirectInput) {
                        redirectInput.value = (redirectUrl === "null" || redirectUrl === null || !redirectUrl) ? "" : redirectUrl;
                    }

                    var statusInput = modal.querySelector('select[name="status"]');
                    if (statusInput) {
                        statusInput.value = status;
                    }

                    var dnsVerifiedInput = modal.querySelector('select[name="dns_verified"]');
                    if (dnsVerifiedInput) {
                        dnsVerifiedInput.value = dnsVerified;
                    }

                    var isPrimaryInput = modal.querySelector('select[name="is_primary"]');
                    if (isPrimaryInput) {
                        isPrimaryInput.value = isPrimary;
                    }
                }
            });
        });

        // --- Tab Scroll Arrows --------------------------------------------------
        var sidebar = document.getElementById("settings-tabs");
        var scrollLeft = document.getElementById("tab-scroll-left");
        var scrollRight = document.getElementById("tab-scroll-right");
        if (sidebar && scrollLeft && scrollRight) {
            scrollLeft.addEventListener("click", function () {
                sidebar.scrollBy({ left: -120, behavior: "smooth" });
            });
            scrollRight.addEventListener("click", function () {
                sidebar.scrollBy({ left: 120, behavior: "smooth" });
            });
        }
    };

    window.opInitSettingsUI();
}());
