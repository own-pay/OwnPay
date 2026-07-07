/**
 * OwnPay Admin - Domains Page JS
 * Handles: card expand/collapse, scoped per-card tab switching, the Add Domain
 * wizard, and bespoke fetch-based submission for actions that must preserve
 * per-card expand/tab UI state (these forms carry data-no-ajax so admin.js's
 * generic full-page-swap interceptor skips them - see the redesign spec's
 * "Bespoke Per-Card Actions" section for why).
 */
(function () {
    "use strict";

    function setLoading(btn, isLoading) {
        if (!btn) { return; }
        if (isLoading) {
            btn.dataset.originalText = btn.textContent;
            btn.disabled = true;
            btn.textContent = "";
            var spinner = document.createElement("span");
            spinner.className = "op-spinner";
            btn.appendChild(spinner);
        } else {
            btn.disabled = false;
            if (btn.dataset.originalText !== undefined) {
                btn.textContent = btn.dataset.originalText;
            }
        }
    }

    function renderAlert(container, success, message) {
        if (!container) { return; }
        container.textContent = "";
        var alertDiv = document.createElement("div");
        alertDiv.className = "op-alert " + (success ? "op-alert-success" : "op-alert-danger");
        alertDiv.textContent = message;
        container.appendChild(alertDiv);
    }

    function applyDomainPayload(domainId, payload) {
        var card = document.querySelector('.op-domain-card[data-domain-id="' + domainId + '"]');
        if (!card || !payload) { return; }

        var pillEl = card.querySelector('[data-role="status-pill"]');
        if (pillEl && payload.status_pill) {
            pillEl.textContent = payload.status_pill.label;
            pillEl.className = "op-badge " + payload.status_pill.class;
        }

        var manage = card.querySelector('.op-domain-manage[data-domain-manage="' + domainId + '"]');
        if (!manage) { return; }

        var badges = manage.querySelectorAll(".op-domain-badges .op-badge");
        if (badges.length >= 2) {
            var sslBadge = badges[0];
            sslBadge.textContent = payload.ssl_status === "active" ? "🔒 SSL Active" : (payload.ssl_status === "expired" ? "SSL Expired" : "SSL Pending");
            sslBadge.className = "op-badge " + (payload.ssl_status === "active" ? "op-badge-success" : (payload.ssl_status === "expired" ? "op-badge-danger" : "op-badge-secondary"));

            var dnsBadge = badges[1];
            dnsBadge.textContent = payload.dns_verified ? "✓ DNS Verified" : "DNS Pending";
            dnsBadge.className = "op-badge " + (payload.dns_verified ? "op-badge-success" : "op-badge-warning");
        }
    }

    function submitFormAsJson(form) {
        return fetch(form.action, {
            method: "POST",
            body: new FormData(form),
            headers: { "X-Requested-With": "XMLHttpRequest" },
        }).then(function (res) { return res.json(); });
    }

    // --- Card expand/collapse -------------------------------------------
    // Only bind to the row (.op-domain-card-row) - the Manage button is
    // nested inside it, so a click there already bubbles up to this same
    // handler. Also binding the button would fire the toggle twice per
    // click (once on the button, once again on bubble-up to the row),
    // which cancels itself out and makes the panel appear to never open.
    document.querySelectorAll(".op-domain-card-row[data-manage-toggle]").forEach(function (el) {
        el.addEventListener("click", function (e) {
            if (e.target.closest("form")) { return; }
            var id = this.dataset.manageToggle;
            var manage = document.querySelector('.op-domain-manage[data-domain-manage="' + id + '"]');
            if (!manage) { return; }
            manage.hidden = !manage.hidden;
            var toggleBtn = document.querySelector('.op-domain-manage-btn[data-manage-toggle="' + id + '"]');
            if (toggleBtn) { toggleBtn.setAttribute("aria-expanded", manage.hidden ? "false" : "true"); }
        });
    });

    // --- Scoped per-card tab switching (NOT the global .op-tab convention -
    // that one assumes a single tab-group per page; here every card repeats
    // its own group, so all lookups are scoped to this card's .op-domain-manage) ---
    document.querySelectorAll(".op-domain-tab").forEach(function (tabBtn) {
        tabBtn.addEventListener("click", function () {
            var id = this.dataset.domainManage;
            var scope = document.querySelector('.op-domain-manage[data-domain-manage="' + id + '"]');
            if (!scope) { return; }
            scope.querySelectorAll(".op-domain-tab").forEach(function (t) { t.classList.remove("active"); });
            scope.querySelectorAll(".op-domain-tab-panel").forEach(function (p) { p.classList.remove("active"); });
            this.classList.add("active");
            var panel = scope.querySelector('.op-domain-tab-panel[data-domain-tab-panel="' + this.dataset.domainTab + '"]');
            if (panel) { panel.classList.add("active"); }
        });
    });

    // --- Confirm-before-submit for destructive domain forms ---------------
    // admin.js's own [data-confirm] click-interceptor calls confirmEl.submit()
    // on confirmation, which per spec does NOT dispatch a `submit` event (only
    // requestSubmit() or a real user-initiated submit-control click does) - so
    // this file's delegated `submit` listener below would never see it. Using
    // a separate `data-domain-confirm` attribute here (not `data-confirm`)
    // avoids that collision entirely: this handles its own confirm dialog via
    // the same global window.opShowConfirm admin.js exposes, then calls
    // requestSubmit() so the real `submit` event fires and the handler below
    // runs the AJAX flow normally.
    document.addEventListener("click", function (e) {
        var btn = e.target.closest('button[type="submit"]');
        if (!btn) { return; }
        var form = btn.closest("[data-domain-confirm]");
        if (!form) { return; }

        e.preventDefault();
        var message = form.getAttribute("data-domain-confirm");
        window.opShowConfirm("Are you sure?", message, "Confirm", "Cancel", function () {
            if (typeof form.requestSubmit === "function") {
                form.requestSubmit(btn);
            } else {
                form.submit();
            }
        });
    });

    // --- Bespoke fetch handling for every data-no-ajax domain form --------
    document.addEventListener("submit", function (e) {
        var form = e.target;
        if (!form.hasAttribute("data-no-ajax")) { return; }
        if (!form.matches("[data-domain-inline-update], [data-domain-primary-form], [data-domain-verify-form], [data-domain-ssl-form], [data-domain-remove-form], #add-domain-wizard-form")) {
            return;
        }
        e.preventDefault();

        var submitBtn = form.querySelector('button[type="submit"]');
        setLoading(submitBtn, true);

        submitFormAsJson(form)
            .then(function (data) {
                setLoading(submitBtn, false);

                if (form.id === "add-domain-wizard-form") {
                    if (!data.success) {
                        window.opShowToast(data.error || "Could not add domain.", "danger");
                        return;
                    }
                    document.getElementById("wizard-txt-host").textContent = "_ownpay-verify." + data.domain.domain;
                    document.getElementById("wizard-txt-val").textContent = "ownpay-verify=" + data.domain.verification_token;
                    document.getElementById("wizard-verify-btn").dataset.wizardDomainId = data.domain.id;
                    document.querySelectorAll(".op-wizard-step").forEach(function (s) { s.classList.remove("active"); });
                    document.querySelector('.op-wizard-step[data-wizard-step="2"]').classList.add("active");
                    var bar = document.querySelector("[data-wizard-bar-step]");
                    if (bar) { bar.dataset.wizardBarStep = "2"; }
                    window.opShowToast("Domain added - now set up DNS.", "success");
                    return;
                }

                if (form.hasAttribute("data-domain-remove-form")) {
                    if (data.success) {
                        var card = form.closest(".op-domain-card");
                        if (card) { card.remove(); }
                        window.opShowToast("Domain removed.", "success");
                    } else {
                        window.opShowToast(data.error || "Could not remove domain.", "danger");
                    }
                    return;
                }

                var domainCard = form.closest(".op-domain-card");
                var domainId = domainCard ? domainCard.dataset.domainId : null;

                if (!data.success) {
                    window.opShowToast(data.error || "Something went wrong.", "danger");
                    return;
                }

                if (domainId && data.domain) {
                    applyDomainPayload(domainId, data.domain);
                }
                if (data.warning) {
                    window.opShowToast(data.warning, "warning");
                } else {
                    window.opShowToast("Saved.", "success");
                }
            })
            .catch(function () {
                setLoading(submitBtn, false);
                window.opShowToast("A network error occurred.", "danger");
            });
    });

    // --- Wizard: Back / Next between steps 2 and 3 -------------------------
    document.querySelectorAll("[data-wizard-back]").forEach(function (btn) {
        btn.addEventListener("click", function () {
            var current = parseInt(this.dataset.wizardBack, 10);
            document.querySelectorAll(".op-wizard-step").forEach(function (s) { s.classList.remove("active"); });
            document.querySelector('.op-wizard-step[data-wizard-step="' + (current - 1) + '"]').classList.add("active");
            var bar = document.querySelector("[data-wizard-bar-step]");
            if (bar) { bar.dataset.wizardBarStep = String(current - 1); }
        });
    });
    document.querySelectorAll("[data-wizard-next]").forEach(function (btn) {
        btn.addEventListener("click", function () {
            var current = parseInt(this.dataset.wizardNext, 10);
            document.querySelectorAll(".op-wizard-step").forEach(function (s) { s.classList.remove("active"); });
            document.querySelector('.op-wizard-step[data-wizard-step="' + (current + 1) + '"]').classList.add("active");
            var bar = document.querySelector("[data-wizard-bar-step]");
            if (bar) { bar.dataset.wizardBarStep = String(current + 1); }
        });
    });

    // --- Wizard: Verify DNS button on step 3 -------------------------------
    var wizardVerifyBtn = document.getElementById("wizard-verify-btn");
    if (wizardVerifyBtn) {
        wizardVerifyBtn.addEventListener("click", function () {
            var id = this.dataset.wizardDomainId;
            if (!id) { return; }
            var csrfInput = document.querySelector('#add-domain-wizard-form input[name="_csrf_token"]');
            var csrf = csrfInput ? csrfInput.value : "";
            setLoading(this, true);
            var self = this;
            fetch("/admin/domains/" + id + "/verify", {
                method: "POST",
                body: new URLSearchParams({ _csrf_token: csrf }),
                headers: {
                    "X-Requested-With": "XMLHttpRequest",
                    "Content-Type": "application/x-www-form-urlencoded",
                },
            })
                .then(function (res) { return res.json(); })
                .then(function (data) {
                    setLoading(self, false);
                    var resultEl = document.getElementById("wizard-verify-result");
                    if (data.success) {
                        renderAlert(resultEl, true, "DNS verified!" + (data.warning ? " ⚠️ " + data.warning : ""));
                        applyDomainPayload(id, data.domain);
                    } else {
                        renderAlert(resultEl, false, data.error);
                    }
                })
                .catch(function () {
                    setLoading(self, false);
                    window.opShowToast("A network error occurred.", "danger");
                });
        });
    }

    // --- Copy-to-clipboard (unchanged from before the redesign) ------------
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
}());
