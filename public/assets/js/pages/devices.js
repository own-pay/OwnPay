/**
 * OwnPay Admin — Devices Page JS
 * Extracted from templates/admin/devices/index.twig
 * Handles: pairing panel toggle, OTP generation + countdown timer, bulk select.
 */
(function () {
    "use strict";

    var csrf = window.OP_CSRF || "";

    // ─── Toggle Pairing Panel ─────────────────────────────────────────────────
    var pairBtn = document.getElementById("pair-device-btn");
    if (pairBtn) {
        pairBtn.addEventListener("click", function () {
            var panel = document.getElementById("pairing-panel");
            if (!panel) {return;}
            var isHidden = panel.style.display === "none" || panel.style.display === "";
            panel.style.display = isHidden ? "block" : "none";
            if (isHidden) {panel.scrollIntoView({ behavior: "smooth", block: "start" });}
        });
    }

    // ─── Generate OTP ─────────────────────────────────────────────────────────
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
                    // Update CSRF token for subsequent requests (rotated by server)
                    if (data.csrf_token) {csrf = data.csrf_token;}

                    var display = document.getElementById("otp-display");
                    var otpEl   = document.getElementById("otp-value");
                    var timerEl = document.getElementById("otp-timer");

                    display.style.display = "block";
                    otpEl.textContent = data.otp;

                    if (timerInterval) {clearInterval(timerInterval);}
                    var secs = data.expires_in || 300;
                    var tick = function () {
                        var m = Math.floor(secs / 60);
                        var s = secs % 60;
                        timerEl.textContent = m + ":" + String(s).padStart(2, "0");
                        if (--secs < 0) {
                            clearInterval(timerInterval);
                            otpEl.textContent = "EXPIRED";
                            timerEl.textContent = "";
                        }
                    };
                    tick();
                    timerInterval = setInterval(tick, 1000);
                } else {
                    alert("Error: " + (data.error || "Unknown error"));
                }
            })
            .catch(function () { alert("Network error"); })
            .finally(function () { btn.disabled = false; btn.textContent = "Generate Pairing Code"; });
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

}());
