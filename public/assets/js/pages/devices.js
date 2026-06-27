/**
 * OwnPay Admin — Devices Page JS
 * Handles: tab switching, pairing dialog phases, OTP generation + QR, countdown, bulk select.
 */
(function () {
    "use strict";

    var csrf = window.OP_CSRF || "";
    var timerInterval = null;

    // ─── Move modal outside <main> stacking context ───────────────────────────
    var pairingModal = document.getElementById("pairing-modal");
    if (pairingModal && pairingModal.parentElement !== document.body) {
        document.body.appendChild(pairingModal);
    }

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

    if (window.location.hash) {
        var hashTab = document.querySelector('.op-tab[data-tab="' + window.location.hash.replace("#tab-", "") + '"]');
        if (hashTab) { hashTab.click(); }
    }

    // ─── Pairing Dialog Phase Management ──────────────────────────────────────
    var phase1 = document.getElementById("pairing-phase-1");
    var phase2 = document.getElementById("pairing-phase-2");

    function resetPairingDialog() {
        if (timerInterval) { clearInterval(timerInterval); timerInterval = null; }
        if (phase1) { phase1.classList.remove("op-d-none"); }
        if (phase2) { phase2.classList.add("op-d-none"); }
        var genBtn = document.getElementById("gen-otp-btn");
        if (genBtn) { genBtn.disabled = false; var s = genBtn.querySelector("span"); if (s) { s.textContent = "Generate Pairing Code"; } }
    }

    // Reset when modal opens (via delegated click on data-open-modal)
    document.addEventListener("click", function (e) {
        var opener = e.target.closest('[data-open-modal="pairing-modal"]');
        if (opener) {
            resetPairingDialog();
            document.body.style.overflow = "hidden";
        }
    });

    // Reset when modal closes
    if (pairingModal) {
        var observer = new MutationObserver(function (mutations) {
            mutations.forEach(function (m) {
                if (m.attributeName === "hidden" && pairingModal.hidden) {
                    resetPairingDialog();
                    document.body.style.overflow = "";
                }
            });
        });
        observer.observe(pairingModal, { attributes: true });
    }

    // ─── Generate / Regenerate OTP & QR Code ─────────────────────────────────
    function fetchNewCode(btn) {
        var btnText = btn.querySelector("span") || btn;
        var originalText = btnText.textContent;
        btn.disabled = true;
        btnText.textContent = "Generating\u2026";

        fetch("/admin/devices/generate-otp", {
            method: "POST",
            headers: { "Content-Type": "application/json", "X-CSRF-Token": csrf },
            body: "{}"
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (data.success) {
                if (data.csrf_token) { csrf = data.csrf_token; }

                var otpEl = document.getElementById("otp-value");
                var timerEl = document.getElementById("otp-timer");
                var qrContainer = document.getElementById("qr-container");

                otpEl.textContent = data.otp;
                otpEl.style.color = "";

                if (qrContainer && data.qr_svg) {
                    if (data.qr_svg.indexOf("<svg") === 0) {
                        qrContainer.innerHTML = data.qr_svg;
                    } else {
                        qrContainer.innerHTML = '<img src="' + data.qr_svg + '" alt="QR Code" style="width:100%;height:100%;display:block;">';
                    }
                }

                if (phase1) { phase1.classList.add("op-d-none"); }
                if (phase2) { phase2.classList.remove("op-d-none"); }

                if (timerInterval) { clearInterval(timerInterval); }
                var secs = data.expires_in || 300;
                var tick = function () {
                    var m = Math.floor(secs / 60);
                    var s = secs % 60;
                    timerEl.textContent = m + ":" + String(s).padStart(2, "0");
                    if (--secs < 0) {
                        clearInterval(timerInterval);
                        otpEl.textContent = "EXPIRED";
                        otpEl.style.color = "var(--op-danger)";
                        timerEl.textContent = "";
                        if (qrContainer) {
                            qrContainer.innerHTML = '<div class="op-qr-placeholder" style="color:var(--op-danger)">Expired</div>';
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
        .finally(function () { btn.disabled = false; btnText.textContent = originalText; });
    }

    var genOtpBtn = document.getElementById("gen-otp-btn");
    if (genOtpBtn) {
        genOtpBtn.addEventListener("click", function () { fetchNewCode(this); });
    }

    var regenBtn = document.getElementById("pairing-regenerate-btn");
    if (regenBtn) {
        regenBtn.addEventListener("click", function () { fetchNewCode(this); });
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
