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

        // Inject Custom JS if present (CSP nonce required for security)
        var customJs = dataEl.getAttribute("data-custom-js");
        if (customJs && nonce) {
            // SECURITY: Only allow custom JS when CSP nonce is present
            // This prevents execution if nonce is missing or compromised
            var script = document.createElement("script");
            script.setAttribute("nonce", nonce);
            script.textContent = customJs;
            document.body.appendChild(script);
        }
    }

    var basePath = cfg.checkoutBasePath || ("/checkout/" + cfg.txnRef);
    // Defense in depth: basePath drives form.action targets below - it must always be a
    // same-origin relative path, never an absolute URL with an attacker-influenceable scheme.
    if (typeof basePath !== "string" || !basePath.startsWith("/")) {
        basePath = "/checkout/" + (cfg.txnRef || "");
    }

    // ---------- TIMER ----------
    var TIMER_URGENCY_THRESHOLD_SEC = 60; // Show urgent style when less than 60 seconds remain
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
            if (sec <= TIMER_URGENCY_THRESHOLD_SEC) {
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

            // Cleanup timer on page unload to prevent memory leak
            window.addEventListener("beforeunload", function () {
                clearInterval(iv);
            });
        }
    }

    // ---------- TABS ----------
    window.goT = function (t) {
        if (!t || typeof t !== "string") { return; }
        // Sanitize: only allow alphanumeric, hyphens, underscores
        var safe = t.replace(/[^a-zA-Z0-9_-]/g, "");
        if (!safe) { return; }
        document.querySelectorAll(".ck-tab").forEach(function (b) { b.classList.remove("on"); });
        var tab = document.querySelector('.ck-tab[data-t="' + safe + '"]');
        if (tab) {tab.classList.add("on");}
        document.querySelectorAll(".ck-tc").forEach(function (c) { c.classList.add("ck-hidden"); });
        var pane = document.getElementById("t-" + safe);
        if (pane) {pane.classList.remove("ck-hidden");}
    };

    // ---------- GATEWAY PICK ----------
    var GW_TABS = ["card", "mfs", "bank"];
    var GW_GRID_IDS = { card: "cardG", mfs: "mfsG", bank: "bankG" };
    var GW_BTN_IDS = { card: "cardBtn", mfs: "mfsBtn", bank: "bankBtn" };
    var GW_BTN_DEFAULT_TEXT = { card: "Select a gateway", mfs: "Select a provider", bank: "Select a bank" };

    // Only one gateway can be selected at a time across all tabs (not one per tab) - picking a
    // gateway under any tab clears every other tab's card highlight and re-disables its button.
    var selectedGateway = empty();
    function empty() { return { tab: "", slug: "", name: "", mode: "" }; }

    function resetTabButton(tab) {
        var btn = document.getElementById(GW_BTN_IDS[tab]);
        if (!btn) {return;}
        btn.disabled = true;
        btn.className = "ck-pay-btn ck-pay-disabled";
        btn.textContent = GW_BTN_DEFAULT_TEXT[tab];
        btn.onclick = null;
    }

    window.pickGW = function (cardEl, tab, slug, name, mode) {
        GW_TABS.forEach(function (t) {
            var grid = document.getElementById(GW_GRID_IDS[t]);
            if (grid) {grid.querySelectorAll(".ck-gw").forEach(function (c) { c.classList.remove("on"); });}
            if (t !== tab) {resetTabButton(t);}
        });
        cardEl.classList.add("on");
        selectedGateway = { tab: tab, slug: slug, name: name, mode: mode };

        var btn = document.getElementById(GW_BTN_IDS[tab]);
        if (!btn) {return;}
        btn.disabled = false;
        btn.className = "ck-pay-btn ck-pay-active";
        btn.textContent = mode === "manual" ? ("Pay manually via " + name) : ("Continue with " + name);
        btn.onclick = function () { executeGW(tab); };
    };

    // Guards executeGW/doQP/submitManual against a double-click or double-tap firing two
    // concurrent /pay or /express requests before the first one's response comes back.
    var paymentInFlight = false;

    // Shared payment response handler
    function handlePaymentResponse(res, fallbackError) {
        if (res.ok && res.data && res.data.success && res.data.redirect_url) {
            window.location.href = res.data.redirect_url;
            return;
        }
        hideLoading();
        var errorMsg = (res.data && res.data.error) ? res.data.error : fallbackError;
        showCheckoutError(errorMsg);
    }

    function handlePaymentError(fallbackError) {
        hideLoading();
        showCheckoutError(fallbackError);
    }

    function executeGW(tab) {
        if (selectedGateway.tab !== tab || !selectedGateway.slug) {return;}
        var s = selectedGateway;
        if (s.mode === "manual") {
            openManualPopup(s.slug, s.name);
            return;
        }
        if (paymentInFlight) {return;}
        paymentInFlight = true;

        var btnId = GW_BTN_IDS[tab];
        var btn = document.getElementById(btnId);
        if (btn) {btn.disabled = true;}

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
                paymentInFlight = false;
                if (btn) {btn.disabled = false;}
                handlePaymentResponse(res, "Payment gateway is temporarily unavailable. Please try another method.");
            })
            .catch(function () {
                paymentInFlight = false;
                if (btn) {btn.disabled = false;}
                handlePaymentError("Network error. Please check your connection and try again.");
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

    var ERROR_TOAST_DURATION_MS = 8000; // Auto-dismiss error toast after 8 seconds
    var ERROR_TOAST_FADE_MS = 300; // Fade animation duration

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
                setTimeout(function () { toast.remove(); }, ERROR_TOAST_FADE_MS);
            }
        }, ERROR_TOAST_DURATION_MS);
    }

    // ---------- MANUAL POPUP ----------
    var POPUP_CLOSE_DUR_MS = 300; // Matches --dur-normal in checkout.css (.ck-popup opacity transition)

    function openManualPopup(slug, name) {
        var meta = (cfg.gatewayMeta && cfg.gatewayMeta[slug]) || { color: "#0D9488", type: "Send Money", logoText: "" };
        var gwData = manualGateways[slug] || {};
        var nameEl = document.getElementById("mpName");
        if (nameEl) {nameEl.textContent = name;}
        var typeEl = document.getElementById("mpType");
        if (typeEl) {typeEl.textContent = meta.type || "Send Money";}

        var logoEl = document.getElementById("mpLogo");
        var fallbackEl = document.getElementById("mpLogoFallback");
        if (fallbackEl) {
            var gwColor = meta.color || (gwData.colors && gwData.colors.primary) || "#0D9488";
            fallbackEl.style.setProperty("background", gwColor, "important");
            fallbackEl.textContent = meta.logoText || name.slice(0, 2).toUpperCase();
        }
        if (logoEl) {
            if (gwData.logo_path) {
                logoEl.src = gwData.logo_path;
                logoEl.classList.remove("ck-hidden");
                if (fallbackEl) {fallbackEl.classList.add("ck-hidden");}
            } else {
                logoEl.classList.add("ck-hidden");
                if (fallbackEl) {fallbackEl.classList.remove("ck-hidden");}
            }
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
            numEl.textContent = gwData.payment_number || "N/A";
        }

        var qrWrapEl = document.getElementById("mpQrWrap");
        var qrImgEl = document.getElementById("mpQr");
        if (qrWrapEl && qrImgEl) {
            if (gwData.qr_code_path) {
                qrImgEl.src = gwData.qr_code_path;
                qrWrapEl.classList.remove("ck-hidden");
            } else {
                qrWrapEl.classList.add("ck-hidden");
            }
        }

        var footerEl = document.getElementById("mpFooter");
        if (footerEl) {
            footerEl.textContent = "Secured by " + (cfg.brandName || "OwnPay");
        }

        var amountEl = document.getElementById("mpAmountValue");
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
            // Non-converted gateway: leave the server-rendered amount (set once from the
            // transaction at page load) untouched. cfg.originalAmount/originalCurrencySymbol
            // are only ever populated on the payment-intent checkout flow, not here, so
            // reading them unconditionally used to blank the amount to "$0.00" on every
            // manual-gateway popup open.
            var existingNote = document.getElementById("mpConvNote");
            if (existingNote) {existingNote.remove();}
        }

        var popup = document.getElementById("manualPopup");
        if (popup) {
            popup.classList.remove("ck-hidden");
            // Force a reflow so the display:none -> flex change is committed before adding "vis",
            // otherwise the opacity transition collapses into an instant, un-animated jump.
            void popup.offsetWidth;
            popup.classList.add("vis");
        }
    }

    window.closeManual = function () {
        var p = document.getElementById("manualPopup");
        if (!p) {return;}
        // "vis" gates both opacity and pointer-events in CSS - removing it makes the popup
        // instantly click-inert even before the fade-out transition finishes.
        p.classList.remove("vis");
        setTimeout(function () { p.classList.add("ck-hidden"); }, POPUP_CLOSE_DUR_MS);
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
        if (paymentInFlight) {return false;}
        paymentInFlight = true;

        var form = document.getElementById("mpVerifyForm");
        var submitBtn = form ? form.querySelector('[type="submit"]') : null;
        if (submitBtn) {submitBtn.disabled = true;}

        var data = new FormData(form);

        // Determine active gateway slug (single selection across all tabs)
        var activeGw = selectedGateway.slug;

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
    var MODAL_CLOSE_DUR_MS = 150; // Matches --modal-close-dur in checkout.css (.ck-modal.is-closing)

    window.openMdl = function (id) {
        var e = document.getElementById(id);
        if (!e) {return;}
        e.classList.remove("ck-hidden");
        // Force a reflow so the display:none -> flex change is committed before adding
        // "is-open", otherwise the opacity/transform transition collapses into an instant jump.
        void e.offsetWidth;
        e.classList.add("is-open");
    };
    window.closeMdl = function (id) {
        var e = document.getElementById(id);
        if (!e) {return;}
        // "is-open" gates visibility/pointer-events in CSS; swap to "is-closing" so the close
        // transition plays, then restore ck-hidden once it finishes.
        e.classList.remove("is-open");
        e.classList.add("is-closing");
        setTimeout(function () {
            e.classList.remove("is-closing");
            e.classList.add("ck-hidden");
        }, MODAL_CLOSE_DUR_MS);
    };

    // ---------- COPY ----------
    // Generalized copy-from-element helper - both the payment-number and amount copy buttons
    // use this. Checkout has no admin.js here (public, unauthenticated page) so this can't reuse
    // admin.js's shared opCopyText helper - same fallback chain, kept local to this file.
    window.copyTextFrom = function (elementId) {
        var el = document.getElementById(elementId);
        if (!el) { return; }
        var text = el.textContent;

        function showToast() {
            var t = document.getElementById("cToast");
            if (t) { t.classList.add("vis"); setTimeout(function () { t.classList.remove("vis"); }, 1800); }
        }

        // execCommand first: synchronous, works reliably within this click's user gesture, no
        // permission prompt. The old code went straight to the async Clipboard API with no
        // .catch(), so any rejection (denied permission, unfocused document, managed-browser
        // policy) left the customer clicking Copy with zero feedback mid-payment.
        var textarea = document.createElement("textarea");
        textarea.value = text;
        textarea.className = "ck-clipboard-textarea";
        textarea.setAttribute("readonly", "");
        document.body.appendChild(textarea);
        textarea.focus();
        textarea.select();

        var execCommandSucceeded = false;
        try {
            execCommandSucceeded = document.execCommand("copy");
        } catch (err) {
            console.warn("execCommand copy failed", err);
        }
        document.body.removeChild(textarea);

        if (execCommandSucceeded) {
            showToast();
            return;
        }

        if (navigator.clipboard && typeof navigator.clipboard.writeText === "function") {
            navigator.clipboard.writeText(text).then(showToast).catch(function (err) {
                console.error("Async copy failed completely", err);
                alert("Could not copy the number automatically. Here it is:\n\n" + text);
            });
        } else {
            alert("Could not copy the number automatically. Here it is:\n\n" + text);
        }
    };

    // ---------- EXPRESS CHECKOUT ----------
    window.doQP = function (provider) {
        if (typeof window.opPost !== "function") {return;}
        if (paymentInFlight) {return;}
        paymentInFlight = true;

        var qpBtn = document.querySelector('[data-action="do-qp"][data-provider="' + provider + '"]');
        if (qpBtn) {qpBtn.disabled = true;}

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
                paymentInFlight = false;
                if (qpBtn) {qpBtn.disabled = false;}
                handlePaymentResponse(res, "Express checkout failed. Please try another method.");
            })
            .catch(function () {
                paymentInFlight = false;
                if (qpBtn) {qpBtn.disabled = false;}
                handlePaymentError("Express checkout is temporarily unavailable. Please try another method.");
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
            window.copyTextFrom("mpNumber");
        } else if (action === "copy-amount") {
            window.copyTextFrom("mpAmountValue");
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
