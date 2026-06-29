/**
 * OwnPay Admin - Devices Page JS
 * Handles: tab switching, pairing dialog phases (generate -> QR -> auto "connected"),
 * real-time device status refresh + manual refresh, bulk select.
 *
 * DOM is built with createElement/textContent (never innerHTML) so no value - even a
 * server-provided device name or QR payload - can inject markup.
 */
(function () {
    "use strict";

    var csrf = window.OP_CSRF || "";
    var timerInterval = null;
    var pollInterval = null;
    var pairingBaseline = null;

    // --- small DOM helpers (XSS-safe) -----------------------------------------
    function clear(el) { if (el) { el.replaceChildren(); } }

    function capitalize(s) {
        s = String(s || "");
        return s.charAt(0).toUpperCase() + s.slice(1);
    }

    function renderQr(container, svg) {
        if (!container) { return; }
        clear(container);
        if (typeof svg !== "string" || svg === "") { return; }
        if (svg.indexOf("<svg") === 0) {
            var parsed = new DOMParser().parseFromString(svg, "image/svg+xml");
            var node = parsed.documentElement;
            if (node && node.nodeName.toLowerCase() === "svg") {
                container.appendChild(document.importNode(node, true));
            }
            return;
        }
        var img = document.createElement("img");
        img.src = svg;
        img.alt = "QR Code";
        img.style.width = "100%";
        img.style.height = "100%";
        img.style.display = "block";
        container.appendChild(img);
    }

    function setPlaceholder(container, text, danger) {
        if (!container) { return; }
        clear(container);
        var div = document.createElement("div");
        div.className = "op-qr-placeholder";
        if (danger) { div.style.color = "var(--op-danger)"; }
        div.textContent = text;
        container.appendChild(div);
    }

    function buildBadge(status, online) {
        var span = document.createElement("span");
        var dot = document.createElement("span");
        dot.className = "op-status-dot";
        var label;
        if (status === "active" && online) {
            span.className = "op-badge op-badge-success";
            dot.classList.add("op-status-dot--pulse");
            label = "Online";
        } else if (status === "active") {
            span.className = "op-badge op-badge-muted";
            label = "Idle";
        } else if (status === "revoked") {
            span.className = "op-badge op-badge-danger";
            label = "Revoked";
        } else {
            span.className = "op-badge op-badge-muted";
            label = capitalize(status);
        }
        span.appendChild(dot);
        span.appendChild(document.createTextNode(label));
        return span;
    }

    // --- Move modal outside <main> stacking context ---------------------------
    var pairingModal = document.getElementById("pairing-modal");
    if (pairingModal && pairingModal.parentElement !== document.body) {
        document.body.appendChild(pairingModal);
    }

    // --- Tab Switching --------------------------------------------------------
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

    // --- Pairing Dialog Phase Management --------------------------------------
    var phase1 = document.getElementById("pairing-phase-1");
    var phase2 = document.getElementById("pairing-phase-2");
    var phase3 = document.getElementById("pairing-phase-3");

    function stopPairingPoll() {
        if (pollInterval) { clearInterval(pollInterval); pollInterval = null; }
    }

    function resetPairingDialog() {
        if (timerInterval) { clearInterval(timerInterval); timerInterval = null; }
        stopPairingPoll();
        pairingBaseline = null;
        if (phase1) { phase1.classList.remove("op-d-none"); }
        if (phase2) { phase2.classList.add("op-d-none"); }
        if (phase3) { phase3.classList.add("op-d-none"); }
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

    // Reset when modal closes (stops the poll + countdown)
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

    // --- Pairing detection poll (auto "device connected") ---------------------
    function showConnected(deviceName) {
        if (timerInterval) { clearInterval(timerInterval); timerInterval = null; }
        stopPairingPoll();
        var nameEl = document.getElementById("paired-device-name");
        if (nameEl) { nameEl.textContent = deviceName || "Your device"; }
        if (phase1) { phase1.classList.add("op-d-none"); }
        if (phase2) { phase2.classList.add("op-d-none"); }
        if (phase3) { phase3.classList.remove("op-d-none"); }
    }

    function checkPairing() {
        if (!pairingBaseline) { return; }
        fetch("/admin/devices/pairing-status?since=" + encodeURIComponent(pairingBaseline), {
            headers: { "Accept": "application/json" }
        })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data && data.connected) {
                    showConnected(data.device_name);
                }
            })
            .catch(function () { /* transient network error - keep polling */ });
    }

    function startPairingPoll() {
        stopPairingPoll();
        if (!pairingBaseline) { return; }
        pollInterval = setInterval(checkPairing, 3000);
    }

    // --- Generate / Regenerate OTP & QR Code ---------------------------------
    function fetchNewCode(btn) {
        var btnText = btn.querySelector("span") || btn;
        var originalText = btnText.textContent;
        btn.disabled = true;
        btnText.textContent = "Generating…";

        fetch("/admin/devices/generate-otp", {
            method: "POST",
            headers: { "Content-Type": "application/json", "X-CSRF-Token": csrf },
            body: "{}"
        })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.success) {
                    if (data.csrf_token) { csrf = data.csrf_token; }
                    pairingBaseline = data.generated_at || null;

                    var otpEl = document.getElementById("otp-value");
                    var timerEl = document.getElementById("otp-timer");
                    var qrContainer = document.getElementById("qr-container");
                    var waiting = document.getElementById("pairing-waiting");

                    otpEl.textContent = data.otp;
                    otpEl.style.color = "";

                    renderQr(qrContainer, data.qr_svg);

                    if (phase1) { phase1.classList.add("op-d-none"); }
                    if (phase3) { phase3.classList.add("op-d-none"); }
                    if (phase2) { phase2.classList.remove("op-d-none"); }
                    if (waiting) { waiting.classList.remove("op-d-none"); }

                    if (timerInterval) { clearInterval(timerInterval); }
                    var secs = data.expires_in || 300;
                    var tick = function () {
                        var m = Math.floor(secs / 60);
                        var s = secs % 60;
                        timerEl.textContent = m + ":" + String(s).padStart(2, "0");
                        if (--secs < 0) {
                            clearInterval(timerInterval);
                            stopPairingPoll();
                            otpEl.textContent = "EXPIRED";
                            otpEl.style.color = "var(--op-danger)";
                            timerEl.textContent = "";
                            setPlaceholder(qrContainer, "Expired", true);
                            if (waiting) { waiting.classList.add("op-d-none"); }
                        }
                    };
                    tick();
                    timerInterval = setInterval(tick, 1000);

                    // Start watching for the device to finish pairing.
                    startPairingPoll();
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

    // Phase 3 "Done" - reload so the new device appears in the table (authoritative render).
    var finishBtn = document.getElementById("pairing-finish-btn");
    if (finishBtn) {
        finishBtn.addEventListener("click", function () { window.location.reload(); });
    }

    // --- Bulk Device Select ---------------------------------------------------
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

    // --- Live device status refresh -------------------------------------------
    function relativeTime(raw) {
        if (!raw) { return "Never"; }
        var t = Date.parse(String(raw).replace(" ", "T"));
        if (isNaN(t)) { return String(raw); }
        var diff = Math.floor((Date.now() - t) / 1000);
        if (diff < 0) { diff = 0; }
        if (diff < 60) { return "just now"; }
        if (diff < 3600) { return Math.floor(diff / 60) + " min ago"; }
        if (diff < 86400) { return Math.floor(diff / 3600) + " hr ago"; }
        var d = Math.floor(diff / 86400);
        return d + " day" + (d === 1 ? "" : "s") + " ago";
    }

    function escapeId(id) {
        return (window.CSS && CSS.escape) ? CSS.escape(id) : String(id).replace(/"/g, '\\"');
    }

    function prettifyInitialLastSeen() {
        document.querySelectorAll(".op-device-lastseen[data-heartbeat]").forEach(function (cell) {
            var hb = cell.getAttribute("data-heartbeat");
            cell.textContent = hb ? relativeTime(hb) : "Never";
        });
    }

    function applyStatuses(devices) {
        devices.forEach(function (dev) {
            if (!dev || !dev.device_id) { return; }
            var row = document.querySelector('[data-device-row="' + escapeId(dev.device_id) + '"]');
            if (!row) { return; }
            var statusCell = row.querySelector("[data-device-status]");
            if (statusCell) {
                clear(statusCell);
                statusCell.appendChild(buildBadge(dev.status, dev.online));
            }
            var seenCell = row.querySelector(".op-device-lastseen");
            if (seenCell) {
                seenCell.setAttribute("data-heartbeat", dev.last_heartbeat || "");
                seenCell.textContent = relativeTime(dev.last_heartbeat);
            }
        });
    }

    var refreshHint = document.getElementById("devices-refresh-hint");
    var refreshBtn = document.getElementById("devices-refresh-btn");

    function refreshStatuses(manual) {
        if (refreshBtn && manual) { refreshBtn.classList.add("op-spin"); }
        fetch("/admin/devices/statuses", { headers: { "Accept": "application/json" } })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data && data.success && Array.isArray(data.devices)) {
                    applyStatuses(data.devices);
                    if (refreshHint) { refreshHint.textContent = "Updated just now"; }
                }
            })
            .catch(function () { /* keep last-known state on transient error */ })
            .finally(function () { if (refreshBtn) { refreshBtn.classList.remove("op-spin"); } });
    }

    if (refreshBtn) {
        refreshBtn.addEventListener("click", function () { refreshStatuses(true); });
    }

    // Auto-refresh only when a device table is present; pause while the browser tab is hidden.
    if (document.querySelector("[data-device-row]")) {
        prettifyInitialLastSeen();
        setInterval(function () {
            if (!document.hidden) { refreshStatuses(false); }
        }, 20000);
    }

}());
