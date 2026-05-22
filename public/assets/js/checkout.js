/**
 * OwnPay Checkout JS
 * REQUIRES: window.opFetch (op-fetch.js loaded BEFORE)
 * REQUIRES: window.OP_CHECKOUT_CONFIG (injected by template)
 * REQUIRES: window.OP_MANUAL_GATEWAYS (injected by template)
 * CSP-safe: no eval/innerHTML
 */
(function () {
    'use strict';
    var cfg = window.OP_CHECKOUT_CONFIG || {};
    var manualGateways = window.OP_MANUAL_GATEWAYS || {};
    // Resolve the base path for all checkout XHR/form actions.
    // PaymentIntentCheckoutController sets checkoutBasePath = '/checkout/intent/{token}'
    // Legacy CheckoutController leaves it unset, so fallback to '/checkout/{token}'
    var basePath = cfg.checkoutBasePath || ('/checkout/' + cfg.txnRef);

    // ---------- TIMER ----------
    var tEl = document.getElementById('timer');
    if (cfg.timeoutEnabled && tEl) {
        var storageKey = 'op_timer_' + cfg.txnRef;
        var serverRemaining = Number(cfg.timeoutRemaining) || 0;
        var sec;

        // Check localStorage for persisted expiry timestamp
        var storedExpiry = localStorage.getItem(storageKey);
        if (storedExpiry) {
            sec = Math.max(0, Math.round((Number(storedExpiry) - Date.now()) / 1000));
        } else {
            // First visit — use server-calculated remaining, store expiry
            sec = serverRemaining;
            if (sec > 0) {
                localStorage.setItem(storageKey, String(Date.now() + sec * 1000));
            }
        }

        // Render immediately (no 1s delay)
        var renderTimer = function () {
            var m = String(Math.floor(sec / 60)).padStart(2, '0');
            var s = String(sec % 60).padStart(2, '0');
            tEl.textContent = m + ':' + s;
            if (sec <= 60) tEl.style.color = '#EF4444';
        };
        renderTimer();

        if (sec > 0) {
            var iv = setInterval(function () {
                if (sec <= 0) {
                    tEl.textContent = '00:00';
                    clearInterval(iv);
                    localStorage.removeItem(storageKey);
                    // M-4 FIX: POST cancel on timeout instead of just redirecting
                    var csrf = document.getElementById('op-csrf');
                    var hashEl = document.getElementById('op-checkout-hash');
                    var cancelForm = document.createElement('form');
                    cancelForm.method = 'POST';
                    cancelForm.action = basePath + '/cancel';
                    if (csrf) {
                        var csrfInput = document.createElement('input');
                        csrfInput.type = 'hidden';
                        csrfInput.name = '_csrf_token';
                        csrfInput.value = csrf.value;
                        cancelForm.appendChild(csrfInput);
                    }
                    // H-03 FIX: Include checkout_hash for cancel auth
                    if (hashEl) {
                        var hashInput = document.createElement('input');
                        hashInput.type = 'hidden';
                        hashInput.name = 'checkout_hash';
                        hashInput.value = hashEl.value;
                        cancelForm.appendChild(hashInput);
                    }
                    document.body.appendChild(cancelForm);
                    cancelForm.submit();
                    return;
                }
                sec--;
                renderTimer();
            }, 1000);
        }
    }

    // ---------- TABS ----------
    window.goT = function (t) {
        document.querySelectorAll('.ck-tab').forEach(function (b) { b.classList.remove('on'); });
        var tab = document.querySelector('.ck-tab[data-t="' + t + '"]');
        if (tab) tab.classList.add('on');
        document.querySelectorAll('.ck-tc').forEach(function (c) { c.classList.add('ck-hidden'); });
        var pane = document.getElementById('t-' + t);
        if (pane) pane.classList.remove('ck-hidden');
    };

    // ---------- GATEWAY PICK ----------
    var gwState = { card: empty(), mfs: empty(), bank: empty() };
    function empty() { return { slug: '', name: '', mode: '' }; }

    window.pickGW = function (cardEl, tab, slug, name, mode) {
        var gridId = tab === 'card' ? 'cardG' : tab === 'mfs' ? 'mfsG' : 'bankG';
        var grid = document.getElementById(gridId);
        if (grid) grid.querySelectorAll('.ck-gw').forEach(function (c) { c.classList.remove('on'); });
        cardEl.classList.add('on');
        gwState[tab] = { slug: slug, name: name, mode: mode };

        var btnId = tab === 'card' ? 'cardBtn' : tab === 'mfs' ? 'mfsBtn' : 'bankBtn';
        var btn = document.getElementById(btnId);
        if (!btn) return;
        btn.disabled = false;
        btn.className = 'ck-pay-btn ck-pay-active';
        btn.textContent = mode === 'manual' ? ('Pay manually via ' + name) : ('Continue with ' + name);
        btn.onclick = function () { executeGW(tab); };
    };

    function executeGW(tab) {
        var s = gwState[tab];
        if (!s.slug) return;
        if (s.mode === 'manual') {
            openManualPopup(s.slug, s.name);
            return;
        }

        // ARCHITECTURE FIX: Use AJAX POST instead of form submit.
        // Server returns JSON { success, redirect_url } or { success: false, error }.
        // On success: browser does hard redirect OUT to external gateway (Stripe/bKash).
        // On failure: inline error shown on checkout page — user can retry.
        showLoading();

        var csrf = document.getElementById('op-csrf');
        var hashEl = document.getElementById('op-checkout-hash');

        var payload = {
            gateway: s.slug,
            gateway_mode: 'api',
            checkout_hash: hashEl ? hashEl.value : '',
            _csrf_token: csrf ? csrf.value : ''
        };

        window.opPost(basePath + '/pay', payload)
            .then(function (res) {
                if (res.ok && res.data && res.data.success && res.data.redirect_url) {
                    // SUCCESS: External gateway returned a payment URL.
                    // Force the browser to LEAVE OwnPay entirely and go to Stripe/bKash.
                    // Status stays 'pending' → transitions to 'processing' only after this redirect.
                    window.location.href = res.data.redirect_url;
                    return;
                }

                // FAILURE: Gateway API returned an error — show on checkout page.
                hideLoading();
                var errorMsg = (res.data && res.data.error)
                    ? res.data.error
                    : 'Payment gateway is temporarily unavailable. Please try another method.';
                showCheckoutError(errorMsg);
            })
            .catch(function () {
                hideLoading();
                showCheckoutError('Network error. Please check your connection and try again.');
            });
    }

    // U-01 FIX: Loading overlay for gateway redirects
    function showLoading() {
        var existing = document.getElementById('ck-loading');
        if (existing) return;
        var overlay = document.createElement('div');
        overlay.id = 'ck-loading';
        overlay.style.cssText = 'position:fixed;inset:0;background:rgba(15,23,42,0.7);display:flex;align-items:center;justify-content:center;z-index:9999;backdrop-filter:blur(4px);';
        overlay.innerHTML = '<div style="text-align:center;color:#fff;"><div style="width:40px;height:40px;border:3px solid rgba(255,255,255,0.2);border-top-color:#5EEAD4;border-radius:50%;animation:ck-spin 0.8s linear infinite;margin:0 auto 1rem;"></div><p style="font-family:Outfit,sans-serif;font-size:0.9rem;">Connecting to payment gateway…</p></div>';
        var style = document.createElement('style');
        style.textContent = '@keyframes ck-spin{to{transform:rotate(360deg)}}';
        document.head.appendChild(style);
        document.body.appendChild(overlay);
    }

    function hideLoading() {
        var overlay = document.getElementById('ck-loading');
        if (overlay) overlay.remove();
    }

    // Show inline error toast on the checkout page (no page navigation)
    function showCheckoutError(msg) {
        // Remove any existing error toast
        var existing = document.getElementById('ck-error-toast');
        if (existing) existing.remove();

        var toast = document.createElement('div');
        toast.id = 'ck-error-toast';
        toast.style.cssText = 'position:fixed;top:1.5rem;left:50%;transform:translateX(-50%);z-index:10000;max-width:480px;width:calc(100% - 2rem);padding:1rem 1.25rem;background:#1E293B;color:#F8FAFC;border-radius:12px;box-shadow:0 8px 32px rgba(0,0,0,0.3);font-family:Outfit,sans-serif;font-size:0.9rem;display:flex;align-items:flex-start;gap:0.75rem;animation:ck-slideDown 0.3s ease;border-left:4px solid #EF4444;';

        var iconSpan = document.createElement('span');
        iconSpan.style.cssText = 'flex-shrink:0;font-size:1.2rem;line-height:1;';
        iconSpan.textContent = '⚠';
        toast.appendChild(iconSpan);

        var msgSpan = document.createElement('span');
        msgSpan.style.cssText = 'flex:1;line-height:1.4;';
        msgSpan.textContent = msg;
        toast.appendChild(msgSpan);

        var closeBtn = document.createElement('button');
        closeBtn.style.cssText = 'flex-shrink:0;background:none;border:none;color:#94A3B8;cursor:pointer;font-size:1.1rem;padding:0;line-height:1;';
        closeBtn.textContent = '✕';
        closeBtn.onclick = function () { toast.remove(); };
        toast.appendChild(closeBtn);

        // Add animation keyframes
        var animStyle = document.getElementById('ck-error-anim');
        if (!animStyle) {
            animStyle = document.createElement('style');
            animStyle.id = 'ck-error-anim';
            animStyle.textContent = '@keyframes ck-slideDown{from{opacity:0;transform:translateX(-50%) translateY(-20px);}to{opacity:1;transform:translateX(-50%) translateY(0);}}';
            document.head.appendChild(animStyle);
        }

        document.body.appendChild(toast);

        // Auto-dismiss after 8 seconds
        setTimeout(function () {
            if (toast.parentNode) {
                toast.style.opacity = '0';
                toast.style.transition = 'opacity 0.3s ease';
                setTimeout(function () { toast.remove(); }, 300);
            }
        }, 8000);
    }

    // ---------- MANUAL POPUP ----------
    function openManualPopup(slug, name) {
        var meta = (cfg.gatewayMeta && cfg.gatewayMeta[slug]) || { color: '#0D9488', type: 'Send Money', logoText: '' };
        var nameEl = document.getElementById('mpName');
        if (nameEl) nameEl.textContent = name;
        var typeEl = document.getElementById('mpType');
        if (typeEl) typeEl.textContent = meta.type || 'Send Money';
        var iconEl = document.getElementById('mpIcon');
        if (iconEl) {
            iconEl.style.background = meta.color;
            iconEl.textContent = meta.logoText || name.slice(0, 2).toUpperCase();
        }

        // C-5 FIX: Read from embedded OP_MANUAL_GATEWAYS instead of missing API endpoint
        var gwData = manualGateways[slug] || {};
        var stepsEl = document.getElementById('mpSteps');
        if (stepsEl) {
            stepsEl.textContent = ''; // Clear previous
            var instructions = gwData.instructions || [];
            if (typeof instructions === 'string') {
                instructions = [instructions];
            } else if (!Array.isArray(instructions)) {
                instructions = [];
            }
            instructions.forEach(function (s, i) {
                var div = document.createElement('div');
                div.className = 'ck-popup-step';
                div.textContent = (i + 1) + '. ' + s;
                stepsEl.appendChild(div);
            });
        }

        // CK-07 FIX: Populate payment number from input_fields
        var numEl = document.getElementById('mpNumber');
        if (numEl) {
            var fields = gwData.input_fields || [];
            var paymentNumber = '';
            for (var f = 0; f < fields.length; f++) {
                if (fields[f].type === 'payment_number' || fields[f].name === 'payment_number') {
                    paymentNumber = fields[f].value || fields[f].default || '';
                    break;
                }
            }
            // Fallback: try top-level number field
            if (!paymentNumber && gwData.payment_number) {
                paymentNumber = gwData.payment_number;
            }
            numEl.textContent = paymentNumber || 'N/A';
        }

        // CROSS-CURRENCY FIX: If gateway has a converted_amount (e.g. BDT for bKash
        // when invoice is in USD), update the popup amount display dynamically.
        var amountEl = document.querySelector('#mpStep1 .ck-popup-value');
        if (amountEl && gwData.converted_amount && gwData.converted_currency) {
            var convSymbol = gwData.converted_currency === 'BDT' ? '৳' : gwData.converted_currency + ' ';
            var formatted = parseFloat(gwData.converted_amount).toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
            amountEl.textContent = convSymbol + formatted;
            // Also add a small conversion note
            var noteEl = document.getElementById('mpConvNote');
            if (!noteEl) {
                noteEl = document.createElement('p');
                noteEl.id = 'mpConvNote';
                noteEl.style.cssText = 'font-size:11px;color:#7A84A0;margin-top:4px;text-align:center;';
                amountEl.parentElement.appendChild(noteEl);
            }
            noteEl.textContent = 'Converted from ' + (cfg.originalCurrency || 'USD') + ' at current exchange rate';
        } else if (amountEl) {
            // Reset to original amount for non-converted gateways
            var origSymbol = cfg.originalCurrencySymbol || '$';
            var origAmount = parseFloat(cfg.originalAmount || '0').toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
            amountEl.textContent = origSymbol + origAmount;
            var existingNote = document.getElementById('mpConvNote');
            if (existingNote) existingNote.remove();
        }

        var popup = document.getElementById('manualPopup');
        if (popup) popup.classList.remove('ck-hidden');
    }

    window.closeManual = function () {
        var p = document.getElementById('manualPopup');
        if (p) p.classList.add('ck-hidden');
    };

    // L-05 FIX: Support both forward and backward navigation in manual popup
    window.goMpStep = function (step) {
        var s1 = document.getElementById('mpStep1');
        var s2 = document.getElementById('mpStep2');
        if (step === 2) {
            s1.classList.add('ck-hidden');
            s2.classList.remove('ck-hidden');
        } else {
            s2.classList.add('ck-hidden');
            s1.classList.remove('ck-hidden');
        }
    };

    window.submitManual = function (e) {
        e.preventDefault();
        var form = document.getElementById('mpVerifyForm');
        var data = new FormData(form);

        // Determine active gateway slug from any active tab
        var activeGw = gwState.mfs.slug || gwState.bank.slug || gwState.card.slug;

        // First submit gateway selection via POST form (manual mode needs checkout_hash)
        var payForm = document.createElement('form');
        payForm.method = 'POST';
        payForm.action = basePath + '/pay';
        payForm.style.display = 'none';

        var fields = {
            'gateway': activeGw,
            'gateway_mode': 'manual',
            '_csrf_token': '',
            'checkout_hash': ''
        };

        var csrfEl = document.getElementById('op-csrf');
        if (csrfEl) fields['_csrf_token'] = csrfEl.value;
        var hashEl = document.getElementById('op-checkout-hash');
        if (hashEl) fields['checkout_hash'] = hashEl.value;

        // Add verification data as payment_details
        fields['payment_details[sender_number]'] = data.get('sender_number') || '';
        fields['payment_details[transaction_id]'] = data.get('transaction_id') || '';

        for (var key in fields) {
            var input = document.createElement('input');
            input.type = 'hidden';
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
        if (e) e.classList.remove('ck-hidden');
    };
    window.closeMdl = function (id) {
        var e = document.getElementById(id);
        if (e) e.classList.add('ck-hidden');
    };

    // ---------- COPY ----------
    window.copyNum = function () {
        var num = document.getElementById('mpNumber');
        if (num && navigator.clipboard) {
            navigator.clipboard.writeText(num.textContent).then(function () {
                var t = document.getElementById('cToast');
                if (t) { t.classList.add('vis'); setTimeout(function () { t.classList.remove('vis'); }, 1800); }
            });
        }
    };

    // ---------- EXPRESS CHECKOUT ----------
    window.doQP = function (provider) {
        if (typeof window.opPost !== 'function') return;
        showLoading();
        var csrf = document.getElementById('op-csrf');
        var hashEl = document.getElementById('op-checkout-hash');
        
        var payload = {
            provider: provider,
            checkout_hash: hashEl ? hashEl.value : '',
            _csrf_token: csrf ? csrf.value : ''
        };
        
        window.opPost(basePath + '/express', payload)
            .then(function (res) {
                if (res.ok && res.data && res.data.success && res.data.redirect_url) {
                    window.location.href = res.data.redirect_url;
                    return;
                }
                hideLoading();
                var errorMsg = (res.data && res.data.error)
                    ? res.data.error
                    : 'Express checkout failed. Please try another method.';
                alert(errorMsg);
            })
            .catch(function () {
                hideLoading();
                alert('Express checkout is temporarily unavailable. Please try another method.');
            });
    };
})();
