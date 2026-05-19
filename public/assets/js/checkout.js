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
                    cancelForm.action = '/checkout/' + cfg.txnRef + '/cancel';
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
        // API gateway — submit form with checkout_hash + gateway_mode (C-2 fix)
        var form = document.createElement('form');
        form.method = 'POST';
        form.action = '/checkout/' + cfg.txnRef + '/pay';

        var gwInput = document.createElement('input');
        gwInput.type = 'hidden'; gwInput.name = 'gateway'; gwInput.value = s.slug;
        form.appendChild(gwInput);

        // C-2 FIX: Add gateway_mode so controller knows this is an API gateway
        var modeInput = document.createElement('input');
        modeInput.type = 'hidden'; modeInput.name = 'gateway_mode'; modeInput.value = 'api';
        form.appendChild(modeInput);

        // C-2 FIX: Add checkout_hash for HMAC integrity verification
        var hashEl = document.getElementById('op-checkout-hash');
        if (hashEl) {
            var hashInput = document.createElement('input');
            hashInput.type = 'hidden'; hashInput.name = 'checkout_hash'; hashInput.value = hashEl.value;
            form.appendChild(hashInput);
        }

        var csrf = document.getElementById('op-csrf');
        if (csrf) {
            var csrfInput = document.createElement('input');
            csrfInput.type = 'hidden'; csrfInput.name = '_csrf_token'; csrfInput.value = csrf.value;
            form.appendChild(csrfInput);
        }
        document.body.appendChild(form);
        // U-01 FIX: Show loading overlay to prevent double-click
        showLoading();
        form.submit();
    }

    // U-01 FIX: Loading overlay for gateway redirects
    function showLoading() {
        var existing = document.getElementById('ck-loading');
        if (existing) return;
        var overlay = document.createElement('div');
        overlay.id = 'ck-loading';
        overlay.style.cssText = 'position:fixed;inset:0;background:rgba(15,23,42,0.7);display:flex;align-items:center;justify-content:center;z-index:9999;backdrop-filter:blur(4px);';
        overlay.innerHTML = '<div style="text-align:center;color:#fff;"><div style="width:40px;height:40px;border:3px solid rgba(255,255,255,0.2);border-top-color:#5EEAD4;border-radius:50%;animation:ck-spin 0.8s linear infinite;margin:0 auto 1rem;"></div><p style="font-family:Outfit,sans-serif;font-size:0.9rem;">Processing payment…</p></div>';
        var style = document.createElement('style');
        style.textContent = '@keyframes ck-spin{to{transform:rotate(360deg)}}';
        document.head.appendChild(style);
        document.body.appendChild(overlay);
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
        payForm.action = '/checkout/' + cfg.txnRef + '/pay';
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
        window.opPost('/checkout/' + cfg.txnRef + '/express', { provider: provider });
    };
})();
