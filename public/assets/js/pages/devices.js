/**
 * OwnPay Admin — Devices Page JS
 * Handles: tab switching, pairing panel toggle, OTP generation + QR code, countdown timer, bulk select.
 */
(function () {
    "use strict";

    var csrf = window.OP_CSRF || "";

    // ─── Tab Switching ────────────────────────────────────────────────────────
    document.querySelectorAll(".op-tab").forEach(function (t) {
        t.addEventListener("click", function () {
            document.querySelectorAll(".op-tab, .op-tab-panel").forEach(function (e) {
                e.classList.remove("active");
            });
            this.classList.add("active");
            var panel = document.getElementById("tab-" + this.dataset.tab);
            if (panel) { panel.classList.add("active"); }
            if (window.location.hash !== "#tab-" + this.dataset.tab) {
                history.replaceState(null, null, "#tab-" + this.dataset.tab);
            }
        });
    });

    // Hash-based tab activation
    if (window.location.hash) {
        var hashTab = document.querySelector('.op-tab[data-tab="' + window.location.hash.replace("#tab-", "") + '"]');
        if (hashTab) { hashTab.click(); }
    }

    // ─── Toggle Pairing Panel ─────────────────────────────────────────────────
    var pairBtn = document.getElementById("pair-device-btn");
    if (pairBtn) {
        pairBtn.addEventListener("click", function () {
            var panel = document.getElementById("pairing-panel");
            if (!panel) {return;}
            panel.classList.toggle("op-d-none");
            if (!panel.classList.contains("op-d-none")) {
                panel.scrollIntoView({ behavior: "smooth", block: "start" });
            }
        });
    }

    var closePairPanelBtn = document.getElementById("close-pairing-panel");
    if (closePairPanelBtn) {
        closePairPanelBtn.addEventListener("click", function () {
            var panel = document.getElementById("pairing-panel");
            if (panel) {
                panel.classList.add("op-d-none");
            }
        });
    }

    // ─── Generate OTP & QR Code ───────────────────────────────────────────────
    var timerInterval = null;
    var genOtpBtn = document.getElementById("gen-otp-btn");
    if (genOtpBtn) {
        genOtpBtn.addEventListener("click", function () {
            var btn = this;
            btn.disabled = true;
            btn.textContent = "Generating…";

            fetch("/admin/devices/generate-otp", {
                method: "POST",
                headers: { "Content-Type": "application/json", "X-CSRF-Token": csrf },
                body: "{}"
            })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.success) {
                    // Update CSRF token for subsequent requests
                    if (data.csrf_token) { csrf = data.csrf_token; }

                    var display = document.getElementById("otp-display");
                    var otpEl   = document.getElementById("otp-value");
                    var timerEl = document.getElementById("otp-timer");
                    var qrContainer = document.getElementById("qr-container");

                    if (display) {
                        display.classList.remove("op-d-none");
                    }
                    otpEl.textContent = data.otp;

                    if (qrContainer && data.qr_svg) {
                        if (data.qr_svg.indexOf("<svg") === 0) {
                            qrContainer.innerHTML = data.qr_svg;
                        } else {
                            qrContainer.innerHTML = '<img src="' + data.qr_svg + '" alt="QR Code" style="width: 100%; height: 100%; display: block; margin: 0 auto;">';
                        }
                    }

                    if (timerInterval) { clearInterval(timerInterval); }
                    var secs = data.expires_in || 300;
                    var tick = function () {
                        var m = Math.floor(secs / 60);
                        var s = secs % 60;
                        timerEl.textContent = m + ":" + String(s).padStart(2, "0");
                        if (--secs < 0) {
                            clearInterval(timerInterval);
                            otpEl.textContent = "EXPIRED";
                            timerEl.textContent = "";
                            if (qrContainer) {
                                qrContainer.innerHTML = '<div class="op-qr-placeholder" style="color: var(--op-danger);">Expired</div>';
                            }
                        }
                    };
                    tick();
                    timerInterval = setInterval(tick, 1000);
                } else {
                    alert("Error: " + (data.error || "Unknown error"));
                }
            })
            .catch(function () { alert("Network error"); })
            .finally(function () { btn.disabled = false; btn.textContent = "Generate Code"; });
        });
    }

    // ─── Bulk Device Select ───────────────────────────────────────────────────
    var selectAll = document.getElementById("select-all");
    if (selectAll) {
        var updateBulkBtn = function () {
            var checked = document.querySelectorAll(".device-checkbox:checked").length;
            var btn = document.getElementById("bulk-revoke-btn");
            if (btn) {
                btn.disabled = checked === 0;
                btn.textContent = checked ? "Revoke Selected (" + checked + ")" : "Revoke Selected";
            }
        };

        selectAll.addEventListener("change", function () {
            document.querySelectorAll(".device-checkbox").forEach(function (cb) {
                cb.checked = selectAll.checked;
            });
            updateBulkBtn();
        });

        document.querySelectorAll(".device-checkbox").forEach(function (cb) {
            cb.addEventListener("change", updateBulkBtn);
        });
    }

    var pairFirstDeviceBtn = document.getElementById("pair-first-device-btn");
    if (pairFirstDeviceBtn) {
        pairFirstDeviceBtn.addEventListener("click", function () {
            var mainPairBtn = document.getElementById("pair-device-btn");
            if (mainPairBtn) {
                mainPairBtn.click();
            }
        });
    }

}());
