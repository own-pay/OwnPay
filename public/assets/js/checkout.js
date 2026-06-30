/**
 * OwnPay Checkout JS
 * REQUIRES: window.opFetch (op-fetch.js loaded BEFORE)
 * CSP-safe: no eval/innerHTML
 */
(function () {
    "use strict";

    var dataEl = document.getElementById("op-checkout-data");
    var cfg = {};
    var manualGateways = {};
    if (dataEl) {
        try {
            cfg = JSON.parse(dataEl.getAttribute("data-config") || "{}");
            manualGateways = JSON.parse(dataEl.getAttribute("data-manual-gateways") || "{}");
        } catch (e) {
            console.error("Failed to parse checkout config", e);
        }
    }

    // Set globally for backward compatibility
    window.OP_CHECKOUT_CONFIG = cfg;
    window.OP_MANUAL_GATEWAYS = manualGateways;

    // ---------- INITIALIZE THEME AND CUSTOM STYLES ----------
    if (dataEl) {
        var brandColor = dataEl.getAttribute("data-brand-color") || "#0D9488";
        var brandAccentColor = dataEl.getAttribute("data-brand-accent-color") || brandColor;

        // Apply brand color variables dynamically
        document.documentElement.style.setProperty("--teal", brandColor);
        document.documentElement.style.setProperty("--teal-deep", brandAccentColor + "cc");
        document.documentElement.style.setProperty("--teal-glow", brandColor + "1a");

        // Apply gateway icon opacity backgrounds dynamically
        document.querySelectorAll(".ck-gw").forEach(function (el) {
            var tab = el.getAttribute("data-tab");
            var color = el.getAttribute("data-color") || "#ECEEF5";
            var cleanColor = color.replace("#", "");
            var ico = el.querySelector(".ck-gw-ico");
            if (ico) {
                var opacity = tab === "bank" ? "1A" : "0F";
                ico.style.setProperty("background", "#" + cleanColor + opacity, "important");
            }
        });

        var nonceEl = document.querySelector('meta[name="csp-nonce"]');
        var nonce = nonceEl ? nonceEl.getAttribute("content") : "";

        // Inject Custom CSS if present
        var customCss = dataEl.getAttribute("data-custom-css");
        if (customCss) {
            var style = document.createElement("style");
            if (nonce) {
                style.setAttribute("nonce", nonce);
            }
            style.textContent = customCss;
            document.head.appendChild(style);
        }

        // Inject Custom JS if present
        var customJs = dataEl.getAttribute("data-custom-js");
        if (customJs) {
            var script = document.createElement("script");
            if (nonce) {
                script.setAttribute("nonce", nonce);
            }
            script.textContent = customJs;
            document.body.appendChild(script);
        }
    }

    var basePath = cfg.checkoutBasePath || ("/checkout/" + cfg.txnRef);

    // ---------- TIMER ----------
    var tEl = document.getElementById("timer");
    if (cfg.timeoutEnabled && tEl) {
        var storageKey = "op_timer_" + cfg.txnRef;
        var serverRemaining = Number(cfg.timeoutRemaining) || 0;
        var expiryTimestamp;

        // Check localStorage for persisted expiry timestamp
        var storedExpiry = localStorage.getItem(storageKey);
        if (storedExpiry) {
            expiryTimestamp = Number(storedExpiry);
        } else {
            // First visit — use server-calculated remaining, store expiry
            expiryTimestamp = Date.now() + serverRemaining * 1000;
            if (serverRemaining > 0) {
                localStorage.setItem(storageKey, String(expiryTimestamp));
            }
        }

        var getRemainingSec = function () {
            return Math.max(0, Math.round((expiryTimestamp - Date.now()) / 1000));
        };

        var sec = getRemainingSec();

        // Render immediately (no 1s delay)
        var renderTimer = function () {
            var m = String(Math.floor(sec / 60)).padStart(2, "0");
            var s = String(sec % 60).padStart(2, "0");
            tEl.textContent = m + ":" + s;
            if (sec <= 60) {
                tEl.classList.add("ck-timer-urgent");
            } else {
                tEl.classList.remove("ck-timer-urgent");
            }
        };
        renderTimer();

        if (sec > 0) {
            var iv = setInterval(function () {
                sec = getRemainingSec();
                renderTimer();

                if (sec <= 0) {
                    tEl.textContent = "00:00";
                    clearInterval(iv);
                    localStorage.removeItem(storageKey);
                    var csrf = document.getElementById("op-csrf");
                    var hashEl = document.getElementById("op-checkout-hash");
                    var cancelForm = document.createElement("form");
                    cancelForm.method = "POST";
                    cancelForm.action = basePath + "/cancel";
                    if (csrf) {
                        var csrfInput = document.createElement("input");
                        csrfInput.type = "hidden";
                        csrfInput.name = "_csrf_token";
                        csrfInput.value = csrf.value;
                        cancelForm.appendChild(csrfInput);
                    }
                    if (hashEl) {
                        var hashInput = document.createElement("input");
                        hashInput.type = "hidden";
                        hashInput.name = "checkout_hash";
                        hashInput.value = hashEl.value;
                        cancelForm.appendChild(hashInput);
                    }
                    document.body.appendChild(cancelForm);
                    cancelForm.submit();
                }
            }, 1000);
        }
    }

    // ---------- TABS ----------
    window.goT = function (t) {
        document.querySelectorAll(".ck-tab").forEach(function (b) { b.classList.remove("on"); });
        var tab = document.querySelector('.ck-tab[data-t="' + t + '"]');
        if (tab) {tab.classList.add("on");}
        document.querySelectorAll(".ck-tc").forEach(function (c) { c.classList.add("ck-hidden"); });
        var pane = document.getElementById("t-" + t);
        if (pane) {pane.classList.remove("ck-hidden");}
    };

    // ---------- GATEWAY PICK ----------
    var gwState = { card: empty(), mfs: empty(), bank: empty() };
    function empty() { return { slug: "", name: "", mode: "" }; }

    window.pickGW = function (cardEl, tab, slug, name, mode) {
        var gridId = tab === "card" ? "cardG" : tab === "mfs" ? "mfsG" : "bankG";
        var grid = document.getElementById(gridId);
        if (grid) {grid.querySelectorAll(".ck-gw").forEach(function (c) { c.classList.remove("on"); });}
        cardEl.classList.add("on");
        gwState[tab] = { slug: slug, name: name, mode: mode };

        var btnId = tab === "card" ? "cardBtn" : tab === "mfs" ? "mfsBtn" : "bankBtn";
        var btn = document.getElementById(btnId);
        if (!btn) {return;}
        btn.disabled = false;
        btn.className = "ck-pay-btn ck-pay-active";
        btn.textContent = mode === "manual" ? ("Pay manually via " + name) : ("Continue with " + name);
        btn.onclick = function () { executeGW(tab); };
    };

    function executeGW(tab) {
        var s = gwState[tab];
        if (!s.slug) {return;}
        if (s.mode === "manual") {
            openManualPopup(s.slug, s.name);
            return;
        }

        showLoading();

        var csrf = document.getElementById("op-csrf");
        var hashEl = document.getElementById("op-checkout-hash");

        var payload = {
            gateway: s.slug,
            gateway_mode: "api",
            checkout_hash: hashEl ? hashEl.value : "",
            _csrf_token: csrf ? csrf.value : ""
        };

        window.opPost(basePath + "/pay", payload)
            .then(function (res) {
                if (res.ok && res.data && res.data.success && res.data.redirect_url) {
                    window.location.href = res.data.redirect_url;
                    return;
                }

                hideLoading();
                var errorMsg = (res.data && res.data.error)
                    ? res.data.error
                    : "Payment gateway is temporarily unavailable. Please try another method.";
                showCheckoutError(errorMsg);
            })
            .catch(function () {
                hideLoading();
                showCheckoutError("Network error. Please check your connection and try again.");
            });
    }

    function showLoading() {
        var existing = document.getElementById("ck-loading");
        if (existing) {return;}
        var overlay = document.createElement("div");
        overlay.id = "ck-loading";
        overlay.className = "ck-loading-overlay";
        
        var content = document.createElement("div");
        content.className = "ck-loading-content";
        
        var spinner = document.createElement("div");
        spinner.className = "ck-loading-spinner";
        
        var text = document.createElement("p");
        text.className = "ck-loading-text";
        text.textContent = "Connecting to payment gateway…";
        
        content.appendChild(spinner);
        content.appendChild(text);
        overlay.appendChild(content);
        document.body.appendChild(overlay);
    }

    function hideLoading() {
        var overlay = document.getElementById("ck-loading");
        if (overlay) {overlay.remove();}
    }

    function showCheckoutError(msg) {
        var existing = document.getElementById("ck-error-toast");
        if (existing) {existing.remove();}

        var toast = document.createElement("div");
        toast.id = "ck-error-toast";
        toast.className = "ck-error-toast";

        var iconSpan = document.createElement("span");
        iconSpan.className = "ck-error-toast-icon";
        iconSpan.textContent = "⚠";
        toast.appendChild(iconSpan);

        var msgSpan = document.createElement("span");
        msgSpan.className = "ck-error-toast-msg";
        msgSpan.textContent = msg;
        toast.appendChild(msgSpan);

        var closeBtn = document.createElement("button");
        closeBtn.className = "ck-error-toast-close";
        closeBtn.textContent = "✕";
        closeBtn.onclick = function () { toast.remove(); };
        toast.appendChild(closeBtn);

        document.body.appendChild(toast);

        setTimeout(function () {
            if (toast.parentNode) {
                toast.classList.add("ck-error-toast-fade");
                setTimeout(function () { toast.remove(); }, 300);
            }
        }, 8000);
    }

    // ---------- MANUAL POPUP ----------
    function openManualPopup(slug, name) {
        var meta = (cfg.gatewayMeta && cfg.gatewayMeta[slug]) || { color: "#0D9488", type: "Send Money", logoText: "" };
        var gwData = manualGateways[slug] || {};
        var nameEl = document.getElementById("mpName");
        if (nameEl) {nameEl.textContent = name;}
        var typeEl = document.getElementById("mpType");
        if (typeEl) {typeEl.textContent = meta.type || "Send Money";}
        var iconEl = document.getElementById("mpIcon");
        if (iconEl) {
            iconEl.className = "ck-popup-gw-icon";
            var gwColor = meta.color || (gwData.colors && gwData.colors.primary) || "#0D9488";
            iconEl.style.setProperty("background", gwColor, "important");
            iconEl.textContent = meta.logoText || name.slice(0, 2).toUpperCase();
        }

        var stepsEl = document.getElementById("mpSteps");
        if (stepsEl) {
            stepsEl.textContent = ""; // Clear previous
            var instructions = gwData.instructions || [];
            if (typeof instructions === "string") {
                instructions = [instructions];
            } else if (!Array.isArray(instructions)) {
                instructions = [];
            }
            instructions.forEach(function (s, i) {
                var div = document.createElement("div");
                div.className = "ck-popup-step";
                div.textContent = (i + 1) + ". " + s;
                stepsEl.appendChild(div);
            });
        }

        var numEl = document.getElementById("mpNumber");
        if (numEl) {
            var fields = gwData.input_fields || [];
            var paymentNumber = "";
            for (var f = 0; f < fields.length; f++) {
                if (fields[f].type === "payment_number" || fields[f].name === "payment_number") {
                    paymentNumber = fields[f].value || fields[f].default || "";
                    break;
                }
            }
            // Fallback: try top-level number field
            if (!paymentNumber && gwData.payment_number) {
                paymentNumber = gwData.payment_number;
            }
            numEl.textContent = paymentNumber || "N/A";
        }

        var amountEl = document.querySelector("#mpStep1 .ck-popup-value");
        if (amountEl && gwData.converted_amount && gwData.converted_currency) {
            var convSymbol = gwData.converted_currency === "BDT" ? "৳" : gwData.converted_currency + " ";
            var formatted = parseFloat(gwData.converted_amount).toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
            amountEl.textContent = convSymbol + formatted;
            // Also add a small conversion note
            var noteEl = document.getElementById("mpConvNote");
            if (!noteEl) {
                noteEl = document.createElement("p");
                noteEl.id = "mpConvNote";
                noteEl.className = "ck-popup-conv-note";
                amountEl.parentElement.appendChild(noteEl);
            }
            noteEl.textContent = "Converted from " + (cfg.originalCurrency || "USD") + " at current exchange rate";
        } else if (amountEl) {
            // Reset to original amount for non-converted gateways
            var origSymbol = cfg.originalCurrencySymbol || "$";
            var origAmount = parseFloat(cfg.originalAmount || "0").toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
            amountEl.textContent = origSymbol + origAmount;
            var existingNote = document.getElementById("mpConvNote");
            if (existingNote) {existingNote.remove();}
        }

        var popup = document.getElementById("manualPopup");
        if (popup) {popup.classList.remove("ck-hidden");}
    }

    window.closeManual = function () {
        var p = document.getElementById("manualPopup");
        if (p) {p.classList.add("ck-hidden");}
    };

    window.goMpStep = function (step) {
        var s1 = document.getElementById("mpStep1");
        var s2 = document.getElementById("mpStep2");
        if (step === 2) {
            s1.classList.add("ck-hidden");
            s2.classList.remove("ck-hidden");
        } else {
            s2.classList.add("ck-hidden");
            s1.classList.remove("ck-hidden");
        }
    };

    window.submitManual = function (e) {
        e.preventDefault();
        var form = document.getElementById("mpVerifyForm");
        var data = new FormData(form);

        // Determine active gateway slug from any active tab
        var activeGw = gwState.mfs.slug || gwState.bank.slug || gwState.card.slug;

        // First submit gateway selection via POST form (manual mode needs checkout_hash)
        var payForm = document.createElement("form");
        payForm.method = "POST";
        payForm.action = basePath + "/pay";
        payForm.className = "ck-hidden";

        var fields = {
            "gateway": activeGw,
            "gateway_mode": "manual",
            "_csrf_token": "",
            "checkout_hash": ""
        };

        var csrfEl = document.getElementById("op-csrf");
        if (csrfEl) {fields["_csrf_token"] = csrfEl.value;}
        var hashEl = document.getElementById("op-checkout-hash");
        if (hashEl) {fields["checkout_hash"] = hashEl.value;}

        // Add verification data as payment_details
        fields["payment_details[sender_number]"] = data.get("sender_number") || "";
        fields["payment_details[transaction_id]"] = data.get("transaction_id") || "";

        for (var key in fields) {
            var input = document.createElement("input");
            input.type = "hidden";
            input.name = key;
            input.value = fields[key];
            payForm.appendChild(input);
        }

        document.body.appendChild(payForm);
        showLoading();
        payForm.submit();
        return false;
    };

    // ---------- MODALS ----------
    window.openMdl = function (id) {
        var e = document.getElementById(id);
        if (e) {e.classList.remove("ck-hidden");}
    };
    window.closeMdl = function (id) {
        var e = document.getElementById(id);
        if (e) {e.classList.add("ck-hidden");}
    };

    // ---------- COPY ----------
    window.copyNum = function () {
        var num = document.getElementById("mpNumber");
        if (!num) { return; }
        var text = num.textContent;
        if (navigator.clipboard) {
            navigator.clipboard.writeText(text).then(showToast);
        } else {
            // Fallback for non-HTTPS or legacy browsers
            var textarea = document.createElement("textarea");
            textarea.value = text;
            textarea.className = "ck-clipboard-textarea";
            document.body.appendChild(textarea);
            textarea.focus();
            textarea.select();
            try {
                document.execCommand("copy");
                showToast();
            } catch (err) {
                console.error("Fallback copy failed", err);
            }
            document.body.removeChild(textarea);
        }

        function showToast() {
            var t = document.getElementById("cToast");
            if (t) { t.classList.add("vis"); setTimeout(function () { t.classList.remove("vis"); }, 1800); }
        }
    };

    // ---------- EXPRESS CHECKOUT ----------
    window.doQP = function (provider) {
        if (typeof window.opPost !== "function") {return;}
        showLoading();
        var csrf = document.getElementById("op-csrf");
        var hashEl = document.getElementById("op-checkout-hash");
        
        var payload = {
            provider: provider,
            checkout_hash: hashEl ? hashEl.value : "",
            _csrf_token: csrf ? csrf.value : ""
        };
        
        window.opPost(basePath + "/express", payload)
            .then(function (res) {
                if (res.ok && res.data && res.data.success && res.data.redirect_url) {
                    window.location.href = res.data.redirect_url;
                    return;
                }
                hideLoading();
                var errorMsg = (res.data && res.data.error)
                    ? res.data.error
                    : "Express checkout failed. Please try another method.";
                showCheckoutError(errorMsg);
            })
            .catch(function () {
                hideLoading();
                showCheckoutError("Express checkout is temporarily unavailable. Please try another method.");
            });
    };

    // ---------- CSP-SAFE EVENT DELEGATION ----------
    document.addEventListener("click", function (e) {
        var target = e.target.closest("[data-action]");
        if (!target) { return; }
        var action = target.getAttribute("data-action");

        if (action === "open-modal") {
            window.openMdl(target.getAttribute("data-target"));
        } else if (action === "close-modal") {
            window.closeMdl(target.getAttribute("data-target"));
        } else if (action === "go-tab") {
            window.goT(target.getAttribute("data-tab-name"));
        } else if (action === "pick-gw") {
            window.pickGW(
                target,
                target.getAttribute("data-tab"),
                target.getAttribute("data-slug"),
                target.getAttribute("data-name"),
                target.getAttribute("data-mode")
            );
        } else if (action === "do-qp") {
            window.doQP(target.getAttribute("data-provider"));
        } else if (action === "close-manual") {
            window.closeManual();
        } else if (action === "copy-num") {
            window.copyNum();
        } else if (action === "go-mp-step") {
            window.goMpStep(Number(target.getAttribute("data-step")));
        } else if (action === "toggle-mobile-summary") {
            var details = document.getElementById("ckMobileDetails");
            if (!details) { return; }
            var isOpen = details.classList.contains("is-open");
            if (isOpen) {
                details.style.maxHeight = "0";
                details.classList.remove("is-open");
                target.setAttribute("aria-expanded", "false");
            } else {
                details.classList.add("is-open");
                details.style.maxHeight = details.scrollHeight + "px";
                target.setAttribute("aria-expanded", "true");
            }
        }
    });

    // Programmatically bind submit event on the manual verification form
    var verifyForm = document.getElementById("mpVerifyForm");
    if (verifyForm) {
        verifyForm.addEventListener("submit", function (e) {
            window.submitManual(e);
        });
    }

    // Centralized capturing-phase error listener for gateway logos
    document.addEventListener("error", function (e) {
        if (e.target && e.target.classList.contains("ck-gw-logo")) {
            e.target.classList.add("op-img-error");
            var sibling = e.target.nextElementSibling;
            if (sibling && sibling.classList.contains("ck-gw-fallback")) {
                sibling.classList.add("op-show-fallback");
            }
        }
    }, true);
})();
