/* ============================================================================
 * Own Pay Checkout Theme — checkout.js
 * Source: docs/theme/Own_pay_checkout_ui_v2.html (extracted)
 * REQUIRES: window.opFetch (loaded from /assets/js/op-fetch.js BEFORE this file)
 * REQUIRES: window.OP_CHECKOUT_CONFIG (injected by PHP)
 * ============================================================================ */
(function () {
  'use strict';
  var cfg = window.OP_CHECKOUT_CONFIG || {};

  // ---------- DOM helpers (XSS-safe) ----------
  function el(tag, attrs, children) {
    var n = document.createElement(tag);
    if (attrs) Object.keys(attrs).forEach(function (k) {
      if (k === 'className') n.className = attrs[k];
      else if (k === 'style') n.setAttribute('style', attrs[k]);
      else if (k === 'dataset') Object.keys(attrs[k]).forEach(function (d) { n.dataset[d] = attrs[k][d]; });
      else n.setAttribute(k, attrs[k]);
    });
    if (children) (Array.isArray(children) ? children : [children]).forEach(function (c) {
      if (c == null) return;
      n.appendChild(typeof c === 'string' ? document.createTextNode(c) : c);
    });
    return n;
  }
  function clear(node) { while (node && node.firstChild) node.removeChild(node.firstChild); }

  // ---------- TIMER ----------
  var sec = (cfg.timeoutEnabled && cfg.timeoutSeconds) ? Number(cfg.timeoutSeconds) : 0;
  var tEl = document.getElementById('timer');
  if (sec > 0 && tEl) {
    var iv = setInterval(function () {
      if (sec <= 0) {
        tEl.textContent = '00:00';
        tEl.classList.add('tw');
        clearInterval(iv);
        showExpired();
        return;
      }
      sec--;
      var m = String(Math.floor(sec / 60)).padStart(2, '0');
      var s = String(sec % 60).padStart(2, '0');
      tEl.textContent = m + ':' + s;
      if (sec <= 60) tEl.classList.add('tw'); else tEl.classList.remove('tw');
    }, 1000);
  }

  // ---------- TABS ----------
  window.goT = function (t) {
    document.querySelectorAll('.ptab').forEach(function (b) { b.classList.remove('on'); });
    var tab = document.querySelector('.ptab[data-t="' + t + '"]');
    if (tab) tab.classList.add('on');
    document.querySelectorAll('.tc').forEach(function (c) { c.classList.add('hidden'); });
    var pane = document.getElementById('t-' + t);
    if (pane) pane.classList.remove('hidden');
  };

  // ---------- GATEWAY PICK ----------
  var gwState = { card: emptyState(), mfs: emptyState(), bank: emptyState() };
  function emptyState() { return { el: null, slug: '', name: '', mode: '' }; }

  window.pickGW = function (cardEl, tab, slug, name, mode) {
    var gridId = tab === 'card' ? 'cardG' : tab === 'mfs' ? 'mfsG' : 'bankG';
    var grid = document.getElementById(gridId);
    if (grid) grid.querySelectorAll('.gw').forEach(function (c) { c.classList.remove('on'); });
    cardEl.classList.add('on');
    gwState[tab] = { el: cardEl, slug: slug, name: name, mode: mode };
    var btnId = tab === 'card' ? 'cardBtn' : tab === 'mfs' ? 'mfsBtn' : 'bankBtn';
    var btn = document.getElementById(btnId);
    if (!btn) return;
    btn.disabled = false;
    btn.className = 'w-full py-[15px] rounded-2xl bg-[var(--teal)] text-white font-bold text-[14px] cursor-pointer transition-all flex items-center justify-center gap-2 shadow-[0_4px_20px_rgba(13,148,136,.22)] hover:bg-[var(--teal-deep)] active:scale-[.98]';
    btn.textContent = mode === 'manual' ? ('Pay manually via ' + name) : ('Continue with ' + name);
    btn.onclick = function () { executeGW(tab); };
  };

  function executeGW(tab) {
    var s = gwState[tab];
    if (!s.slug) return;
    if (s.mode === 'manual') { openManualPopup(s.slug, s.name); return; }
    var url = (cfg.gatewayUrl || '?gateway=') + encodeURIComponent(s.slug);
    window.location.href = url;
  }

  // ---------- MANUAL POPUP ----------
  window.openManualPopup = function (slug, name) {
    var meta = (cfg.gatewayMeta && cfg.gatewayMeta[slug]) || { color: '#0D9488', steps: [], type: 'Send Money', logoText: '' };
    var nameEl = document.getElementById('mpName'); if (nameEl) nameEl.textContent = name || slug;
    var logoEl = document.getElementById('mpLogo');
    if (logoEl) {
      clear(logoEl);
      logoEl.style.background = meta.color + '12';
      var badge = el('span', { className: 'text-[15px] font-extrabold', style: 'color:' + meta.color }, meta.logoText || (name || '').slice(0, 2).toUpperCase());
      logoEl.appendChild(badge);
    }
    var cardEl = document.getElementById('mpCard');
    if (cardEl) cardEl.style.background = 'linear-gradient(135deg,' + meta.color + ',' + meta.color + 'cc)';
    var txnEl = document.getElementById('mpTxn'); if (txnEl) txnEl.value = '';
    var verEl = document.getElementById('mpVerify'); if (verEl) verEl.classList.add('hidden');

    var stepsEl = document.getElementById('mpSteps');
    if (stepsEl) {
      clear(stepsEl);
      (meta.steps || []).forEach(function (s, i) {
        var item = el('div', { className: 'm-item' }, [
          el('div', { className: 'm-idx' }, String(i + 1))
        ]);
        if (typeof s === 'string') {
          item.appendChild(el('p', { className: 'text-[13px] leading-relaxed text-white/90' }, s));
        } else {
          var p = el('p', { className: 'text-[13px] leading-relaxed text-white/90' }, [
            s.t + ' ',
            el('strong', { className: 'text-white font-mono' }, s.v),
          ]);
          if (s.copy) {
            var copyBtn = el('button', { className: 'm-copy', type: 'button' }, 'Copy');
            copyBtn.addEventListener('click', function () { doCopy(s.v); });
            p.appendChild(copyBtn);
          }
          item.appendChild(p);
        }
        stepsEl.appendChild(item);
      });
    }
    var pop = document.getElementById('manualPopup');
    if (pop) pop.classList.add('vis');
  };

  window.closeManual = function () {
    var p = document.getElementById('manualPopup');
    if (p) p.classList.remove('vis');
  };

  window.verifyManual = function () {
    var input = document.getElementById('mpTxn');
    var txn = (input.value || '').trim();
    if (!txn) {
      input.style.borderColor = 'var(--red)';
      input.style.boxShadow = '0 0 0 3px rgba(239,68,68,0.1)';
      return;
    }
    input.style.borderColor = '#ECEEF5';
    input.style.boxShadow = 'none';
    var verEl = document.getElementById('mpVerify');
    if (verEl) verEl.classList.remove('hidden');
    if (typeof window.opFetch !== 'function') {
      console.error('opFetch is not defined — verify op-fetch.js loaded before checkout.js');
      return;
    }
    window.opFetch('manual-verify', { gateway: gwState.mfs.slug || gwState.bank.slug, txn_id: txn, ref: cfg.txnRef })
      .then(function (resp) {
        closeManual();
        if (resp && resp.status === 'true') showSuccess(resp.transaction_id || txn);
        else showFailed(txn);
      })
      .catch(function () { closeManual(); showFailed(txn); });
  };

  // ---------- QUICK PAY ----------
  window.doQP = function (provider) {
    if (typeof window.opFetch !== 'function') return;
    window.opFetch('express-checkout', { provider: provider, ref: cfg.txnRef }).catch(function () {});
  };

  window.doCancel = function () {
    closeMdl('mCancel');
    if (typeof window.opFetch === 'function') {
      window.opFetch('cancel-transaction', { ref: cfg.txnRef }).then(function () { renderPostPage('cancelled', 'N/A'); });
    } else {
      renderPostPage('cancelled', 'N/A');
    }
  };

  // ---------- MODALS ----------
  window.openMdl  = function (id) { var e = document.getElementById(id); if (e) e.classList.add('vis'); };
  window.closeMdl = function (id) { var e = document.getElementById(id); if (e) e.classList.remove('vis'); };
  window.closeOut = function (e, id) { if (e.target === document.getElementById(id)) closeMdl(id); };

  // ---------- COPY ----------
  window.doCopy = function (v) {
    if (!navigator.clipboard) return;
    navigator.clipboard.writeText(v).then(function () {
      var t = document.getElementById('cToast');
      if (!t) return;
      t.textContent = 'Copied: ' + v;
      t.classList.add('vis');
      setTimeout(function () { t.classList.remove('vis'); }, 1800);
    });
  };

  // ---------- POST-TRANSACTION STATE PAGES (DOM-built, no innerHTML) ----------
  var PS = {
    success:   { title: 'Payment Successful', subtitle: 'Your transaction was successful.', color: '#059669', bg: 'linear-gradient(180deg,#ECFDF5,#F5F6FA)' },
    cancelled: { title: 'Payment Cancelled',  subtitle: 'No charges were made.',            color: '#E11D48', bg: 'linear-gradient(180deg,#FFF1F2,#F5F6FA)' },
    failed:    { title: 'Payment Failed',     subtitle: "We couldn't process your payment.",color: '#DC2626', bg: 'linear-gradient(180deg,#FEF2F2,#F5F6FA)' },
    pending:   { title: 'Payment Processing', subtitle: 'Your transaction is being verified.', color: '#2563EB', bg: 'linear-gradient(180deg,#EFF6FF,#F5F6FA)' },
    expired:   { title: 'Session Expired',    subtitle: 'Please start a new payment session.', color: '#EA580C', bg: 'linear-gradient(180deg,#FFF7ED,#F5F6FA)' }
  };

  function renderPostPage(state, txnId) {
    var S = PS[state] || PS.expired;
    clear(document.body);
    document.body.setAttribute('style', 'font-family:Outfit,sans-serif;background:' + S.bg + ';min-height:100vh;display:flex;flex-direction:column;align-items:center;justify-content:center;padding:24px');
    var card = el('div', { style: 'max-width:440px;width:100%;background:#fff;border-radius:28px;padding:32px;box-shadow:0 12px 32px rgba(8,13,26,0.04);text-align:center' }, [
      el('h1', { style: 'font-size:26px;font-weight:800;color:#080D1A;margin:0 0 14px' }, S.title),
      el('p',  { style: 'font-size:14px;color:#7A84A0;margin:0 0 24px;line-height:1.6' }, S.subtitle),
      el('div',{ style: 'font-family:JetBrains Mono,monospace;font-size:14px;font-weight:800;color:' + S.color + ';margin-bottom:28px' }, txnId || 'N/A')
    ]);
    var btn = el('button', { style: 'background:#080D1A;color:#fff;padding:14px 24px;border-radius:18px;border:0;font-weight:800;cursor:pointer;font-family:Outfit,sans-serif' }, 'Return to Merchant');
    btn.addEventListener('click', function () { window.location.href = cfg.merchantReturnUrl || '/'; });
    card.appendChild(btn);
    document.body.appendChild(card);
  }

  window.showSuccess = function (txnId) { renderPostPage('success', txnId); };
  window.showFailed  = function (txnId) { renderPostPage('failed',  txnId); };
  window.showPending = function (txnId) { renderPostPage('pending', txnId); };
  window.showExpired = function ()      { renderPostPage('expired', 'N/A'); };
})();
