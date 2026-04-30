/**
 * OwnPay Checkout JS
 * REQUIRES: window.opFetch (op-fetch.js loaded BEFORE)
 * REQUIRES: window.OP_CHECKOUT_CONFIG (injected by template)
 * CSP-safe: no eval/innerHTML
 */
(function () {
    'use strict';
    var cfg = window.OP_CHECKOUT_CONFIG || {};

    // ---------- TIMER ----------
    var sec = (cfg.timeoutEnabled && cfg.timeoutSeconds) ? Number(cfg.timeoutSeconds) : 0;
    var tEl = document.getElementById('timer');
    if (sec > 0 && tEl) {
        var iv = setInterval(function () {
            if (sec <= 0) {
                tEl.textContent = '00:00';
                clearInterval(iv);
                window.location.href = '/checkout/' + cfg.txnRef + '/status';
                return;
            }
            sec--;
            var m = String(Math.floor(sec / 60)).padStart(2, '0');
            var s = String(sec % 60).padStart(2, '0');
            tEl.textContent = m + ':' + s;
            if (sec <= 60) tEl.style.color = '#EF4444';
        }, 1000);
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
        // API gateway — submit form
        var form = document.createElement('form');
        form.method = 'POST';
        form.action = '/checkout/' + cfg.txnRef + '/pay';
        var gwInput = document.createElement('input');
        gwInput.type = 'hidden'; gwInput.name = 'gateway'; gwInput.value = s.slug;
        form.appendChild(gwInput);
        var csrf = document.getElementById('op-csrf');
        if (csrf) {
            var csrfInput = document.createElement('input');
            csrfInput.type = 'hidden'; csrfInput.name = '_csrf'; csrfInput.value = csrf.value;
            form.appendChild(csrfInput);
        }
        document.body.appendChild(form);
        form.submit();
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

        // Fetch manual gateway details
        if (typeof window.opFetch === 'function') {
            window.opFetch('/api/v1/payments/' + cfg.txnRef + '/manual-info?gateway=' + slug).then(function (resp) {
                if (resp.ok && resp.data) {
                    var num = document.getElementById('mpNumber');
                    if (num) num.textContent = resp.data.account_number || '';
                    var stepsEl = document.getElementById('mpSteps');
                    if (stepsEl && resp.data.steps) {
                        stepsEl.innerHTML = '';
                        resp.data.steps.forEach(function (s, i) {
                            var div = document.createElement('div');
                            div.className = 'ck-popup-step';
                            div.textContent = (i + 1) + '. ' + s;
                            stepsEl.appendChild(div);
                        });
                    }
                }
            });
        }

        var popup = document.getElementById('manualPopup');
        if (popup) popup.classList.remove('ck-hidden');
    }

    window.closeManual = function () {
        var p = document.getElementById('manualPopup');
        if (p) p.classList.add('ck-hidden');
    };

    window.goMpStep = function (step) {
        document.getElementById('mpStep1').classList.add('ck-hidden');
        document.getElementById('mpStep2').classList.remove('ck-hidden');
    };

    window.submitManual = function (e) {
        e.preventDefault();
        var form = document.getElementById('mpVerifyForm');
        var data = new FormData(form);
        data.append('gateway', gwState.mfs.slug || gwState.bank.slug || gwState.card.slug);
        data.append('ref', cfg.txnRef);

        if (typeof window.opPost === 'function') {
            window.opPost('/checkout/' + cfg.txnRef + '/manual-verify', {
                gateway: data.get('gateway'),
                sender_number: data.get('sender_number'),
                txn_id: data.get('txn_id'),
                ref: cfg.txnRef
            }).then(function (resp) {
                closeManual();
                window.location.href = '/checkout/' + cfg.txnRef + '/status';
            });
        }
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
