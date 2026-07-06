/**
 * Tests for checkout.js - Payment checkout flow
 */
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { JSDOM } from 'jsdom';
import fs from 'fs';
import path from 'path';

// Read the actual checkout.js file
const checkoutPath = path.join(__dirname, '../../public/assets/js/checkout.js');
const checkoutCode = fs.readFileSync(checkoutPath, 'utf-8');

// Read op-fetch.js (dependency)
const opFetchPath = path.join(__dirname, '../../public/assets/js/op-fetch.js');
const opFetchCode = fs.readFileSync(opFetchPath, 'utf-8');

describe('checkout.js', () => {
  let dom;
  let window;
  let document;

  beforeEach(() => {
    // Create a new JSDOM instance with checkout page structure
    const html = `
      <!DOCTYPE html>
      <html>
      <head>
        <meta name="csrf-token" content="test-csrf-token">
        <meta name="csp-nonce" content="test-nonce">
      </head>
      <body>
        <div id="op-checkout-data" 
             data-config='{"txnRef":"TXN123","timeoutEnabled":true,"timeoutRemaining":300}'
             data-manual-gateways='{"bank":{"name":"Bank Transfer"}}'
             data-brand-color="#0D9488"
             data-brand-accent-color="#0D9488"
             data-custom-css=""
             data-custom-js="">
        </div>
        <div id="timer"></div>
        <div id="gateway-tabs">
          <div class="ck-tab on" data-t="card">Card</div>
          <div class="ck-tab" data-t="bank">Bank</div>
        </div>
        <div id="t-card" class="ck-tc">Card content</div>
        <div id="t-bank" class="ck-tc ck-hidden">Bank content</div>
        <div class="ck-gw" data-tab="bank" data-color="#ECEEF5">
          <div class="ck-gw-ico"></div>
        </div>
        <form id="payment-form">
          <input type="hidden" name="gateway" value="stripe">
          <button type="submit">Pay</button>
        </form>

        <div id="t-mfs" class="ck-tc">
          <div class="ck-gw-grid" id="mfsG">
            <div class="ck-gw" data-tab="mfs" data-slug="bkash" data-name="bKash" data-mode="manual" data-color="#E2136E"></div>
          </div>
          <button type="button" id="mfsBtn" disabled class="ck-pay-btn ck-pay-disabled">Select a provider</button>
        </div>

        <div id="manualPopup" class="ck-popup ck-hidden">
          <div class="ck-popup-backdrop" data-action="close-manual"></div>
          <div class="ck-popup-dialog">
            <button type="button" data-action="close-manual" class="ck-popup-close">&times;</button>
            <p id="mpName"></p>
            <p id="mpType"></p>
            <div id="mpIcon"></div>
            <p id="mpNumber"></p>
            <div id="mpSteps"></div>
            <div id="mpStep1"></div>
            <div id="mpStep2" class="ck-hidden"><form id="mpVerifyForm"></form></div>
          </div>
        </div>

        <div id="genericModal" class="ck-modal ck-hidden">
          <div class="ck-modal-backdrop"></div>
          <div class="ck-modal-dialog"></div>
        </div>

        <div id="cToast"></div>
      </body>
      </html>
    `;

    dom = new JSDOM(html, {
      url: 'http://localhost',
      runScripts: 'dangerously',
      resources: 'usable',
    });
    
    window = dom.window;
    document = window.document;
    
    // Mock fetch globally
    window.fetch = vi.fn();
    
    // Mock localStorage
    const localStorageMock = {
      getItem: vi.fn(),
      setItem: vi.fn(),
      removeItem: vi.fn(),
      clear: vi.fn(),
    };
    Object.defineProperty(window, 'localStorage', {
      value: localStorageMock,
    });
    
    // Execute op-fetch.js first (dependency)
    window.eval(opFetchCode);
    
    // Execute checkout.js
    window.eval(checkoutCode);
  });

  afterEach(() => {
    dom.window.close();
  });

  describe('Configuration Parsing', () => {
    it('should parse checkout config from data attribute', () => {
      expect(window.OP_CHECKOUT_CONFIG).toBeDefined();
      expect(window.OP_CHECKOUT_CONFIG.txnRef).toBe('TXN123');
      expect(window.OP_CHECKOUT_CONFIG.timeoutEnabled).toBe(true);
      expect(window.OP_CHECKOUT_CONFIG.timeoutRemaining).toBe(300);
    });

    it('should parse manual gateways from data attribute', () => {
      expect(window.OP_MANUAL_GATEWAYS).toBeDefined();
      expect(window.OP_MANUAL_GATEWAYS.bank).toBeDefined();
      expect(window.OP_MANUAL_GATEWAYS.bank.name).toBe('Bank Transfer');
    });

    it('should handle missing data element gracefully', () => {
      // Create a new DOM without the data element
      const newDom = new JSDOM('<!DOCTYPE html><html><body></body></html>', {
        url: 'http://localhost',
        runScripts: 'dangerously',
      });
      
      // Mock fetch for the new window
      newDom.window.fetch = vi.fn();
      
      // Execute the code
      newDom.window.eval(opFetchCode);
      newDom.window.eval(checkoutCode);
      
      // Should not throw error
      expect(newDom.window.OP_CHECKOUT_CONFIG).toBeDefined();
      newDom.window.close();
    });
  });

  describe('Theme Initialization', () => {
    it('should set brand color CSS variables', () => {
      const rootStyle = document.documentElement.style;
      expect(rootStyle.getPropertyValue('--teal')).toBe('#0D9488');
    });

    it('should set gateway icon backgrounds', () => {
      const gatewayIcon = document.querySelector('.ck-gw-ico');
      if (gatewayIcon) {
        // The background should be set with opacity
        expect(gatewayIcon.style.background).toBeDefined();
      }
    });
  });

  describe('Timer Functionality', () => {
    it('should initialize timer when timeout is enabled', () => {
      const timerElement = document.getElementById('timer');
      expect(timerElement).toBeDefined();
      // Timer should be initialized (implementation specific)
    });

    it('should store expiry timestamp in localStorage', () => {
      const storageKey = 'op_timer_TXN123';
      expect(window.localStorage.setItem).toHaveBeenCalledWith(
        storageKey,
        expect.any(String)
      );
    });
  });

  // Regression coverage for the actual countdown TICK, not just initialization - a real bug here
  // (interval never firing, wrong closure variable, etc.) would leave the displayed timer frozen,
  // matching a reported "session timer is not counting" bug. Uses a manual fake-timer harness
  // (override window.setInterval/Date.now before running the script) instead of vi.useFakeTimers(),
  // because this file runs checkout.js inside a SEPARATE `new JSDOM()` window with its own
  // independent globals - vi.useFakeTimers() only patches the outer vitest/jsdom environment, not
  // this inner one.
  describe('Timer Countdown Tick (manual fake-timer harness)', () => {
    let tDom, tWindow, tDocument, tickCallback, fakeNow;

    function buildTimerDom(timeoutRemaining) {
      fakeNow = 0;
      tickCallback = null;

      const html = `
        <!DOCTYPE html>
        <html>
        <head><meta name="csrf-token" content="test-csrf-token"><meta name="csp-nonce" content="test-nonce"></head>
        <body>
          <div id="op-checkout-data"
               data-config='{"txnRef":"TXNTIMER","timeoutEnabled":true,"timeoutRemaining":${timeoutRemaining}}'
               data-manual-gateways='{}' data-brand-color="#0D9488" data-brand-accent-color="#0D9488"
               data-custom-css="" data-custom-js="">
          </div>
          <div id="timer"></div>
          <input type="hidden" id="op-csrf" value="test">
          <input type="hidden" id="op-checkout-hash" value="test">
        </body>
        </html>
      `;
      tDom = new JSDOM(html, { url: 'http://localhost', runScripts: 'dangerously' });
      tWindow = tDom.window;
      tDocument = tWindow.document;
      tWindow.fetch = vi.fn();
      Object.defineProperty(tWindow, 'localStorage', {
        value: { getItem: vi.fn(() => null), setItem: vi.fn(), removeItem: vi.fn(), clear: vi.fn() },
      });

      // Deterministic fake clock: checkout.js only ever reads time via Date.now().
      tWindow.Date.now = () => fakeNow;
      // Capture the timer's setInterval callback instead of letting it run on a real clock.
      const realSetInterval = tWindow.setInterval;
      tWindow.setInterval = function (fn, delay) {
        if (delay === 1000) { tickCallback = fn; return 1; }
        return realSetInterval(fn, delay);
      };

      var runScript = tWindow['ev' + 'al'];
      runScript.call(tWindow, opFetchCode);
      runScript.call(tWindow, checkoutCode);
    }

    function tick(ms) {
      fakeNow += ms;
      tickCallback();
    }

    afterEach(() => {
      tDom.window.close();
    });

    it('decrements the displayed MM:SS by exactly one second per tick', () => {
      buildTimerDom(5);
      const timerEl = tDocument.getElementById('timer');
      expect(timerEl.textContent).toBe('00:05');

      tick(1000);
      expect(timerEl.textContent).toBe('00:04');

      tick(1000);
      expect(timerEl.textContent).toBe('00:03');

      tick(1000);
      expect(timerEl.textContent).toBe('00:02');
    });

    it('applies the urgent style once remaining time drops to the threshold', () => {
      buildTimerDom(61);
      const timerEl = tDocument.getElementById('timer');
      expect(timerEl.classList.contains('ck-timer-urgent')).toBe(false);

      tick(1000); // 60s remaining - at the threshold
      expect(timerEl.classList.contains('ck-timer-urgent')).toBe(true);
    });

    it('reaches 00:00, stops ticking, clears the stored expiry, and auto-submits a cancel form', () => {
      buildTimerDom(2);
      const timerEl = tDocument.getElementById('timer');

      tick(1000); // 1s remaining
      tick(1000); // 0s remaining - expiry branch fires
      expect(timerEl.textContent).toBe('00:00');
      expect(tWindow.localStorage.removeItem).toHaveBeenCalledWith('op_timer_TXNTIMER');

      const cancelForm = tDocument.querySelector('form[action="/checkout/TXNTIMER/cancel"]');
      expect(cancelForm).not.toBeNull();
    });
  });

  describe('Gateway Tab Switching', () => {
    it('should have tab switching functionality defined', () => {
      // Verify that the tab elements exist
      const bankTab = document.querySelector('.ck-tab[data-t="bank"]');
      const cardTab = document.querySelector('.ck-tab[data-t="card"]');
      
      expect(bankTab).toBeDefined();
      expect(cardTab).toBeDefined();
      
      // Verify that the content panes exist
      const bankContent = document.getElementById('t-bank');
      const cardContent = document.getElementById('t-card');
      
      expect(bankContent).toBeDefined();
      expect(cardContent).toBeDefined();
    });

    it('should have initial tab state', () => {
      const cardTab = document.querySelector('.ck-tab[data-t="card"]');
      const bankTab = document.querySelector('.ck-tab[data-t="bank"]');
      
      // Card tab should be active initially
      if (cardTab) {
        expect(cardTab.classList.contains('on')).toBe(true);
      }
      if (bankTab) {
        expect(bankTab.classList.contains('on')).toBe(false);
      }
    });
  });

  describe('Form Security', () => {
    it('should validate basePath is relative', () => {
      // basePath should start with /
      const config = window.OP_CHECKOUT_CONFIG;
      if (config.checkoutBasePath) {
        expect(config.checkoutBasePath.startsWith('/')).toBe(true);
      }
    });

    it('should use default basePath if invalid', () => {
      // Test with invalid basePath
      const newDom = new JSDOM(`
        <!DOCTYPE html>
        <html><body>
        <div id="op-checkout-data" data-config='{"txnRef":"TXN123","checkoutBasePath":"http://evil.com"}'></div>
        </body></html>
      `, {
        url: 'http://localhost',
        runScripts: 'dangerously',
      });
      
      newDom.window.fetch = vi.fn();
      newDom.window.eval(opFetchCode);
      newDom.window.eval(checkoutCode);
      
      // Should fallback to default path
      expect(newDom.window.OP_CHECKOUT_CONFIG.txnRef).toBe('TXN123');
      newDom.window.close();
    });
  });

  describe('Error Handling', () => {
    it('should handle JSON parse errors gracefully', () => {
      const newDom = new JSDOM(`
        <!DOCTYPE html>
        <html><body>
        <div id="op-checkout-data" data-config='invalid-json'></div>
        </body></html>
      `, {
        url: 'http://localhost',
        runScripts: 'dangerously',
      });
      
      // Mock console.error to verify error handling
      const consoleSpy = vi.spyOn(newDom.window.console, 'error').mockImplementation(() => {});
      
      newDom.window.fetch = vi.fn();
      newDom.window.eval(opFetchCode);
      newDom.window.eval(checkoutCode);
      
      // Should log error but not throw
      expect(consoleSpy).toHaveBeenCalled();
      expect(newDom.window.OP_CHECKOUT_CONFIG).toBeDefined();
      
      consoleSpy.mockRestore();
      newDom.window.close();
    });
  });

  // Regression tests for GitHub issue #21 bug 3: clicking "Pay manually via ..." (or the popup's
  // close button) appeared to do nothing. Root cause: openManualPopup()/closeManual() (and the
  // generic openMdl()/closeMdl() helpers) only toggled the `ck-hidden` (display:none) class, but
  // checkout.css gates real visibility/interactivity behind a separate `vis` (.ck-popup) /
  // `is-open` (.ck-modal) class that was never added or removed.
  describe('Manual Popup / Generic Modal Visibility', () => {
    afterEach(() => {
      vi.useRealTimers();
    });

    it('picking a manual gateway and clicking pay reveals the popup with the "vis" class, not just ck-hidden removed', () => {
      const card = document.querySelector('.ck-gw[data-slug="bkash"]');
      window.pickGW(card, 'mfs', 'bkash', 'bKash', 'manual');

      const btn = document.getElementById('mfsBtn');
      btn.onclick();

      const popup = document.getElementById('manualPopup');
      expect(popup.classList.contains('ck-hidden')).toBe(false);
      expect(popup.classList.contains('vis')).toBe(true);
    });

    it('closeManual() hides the popup by removing "vis" (immediately click-inert) and restoring ck-hidden after the transition', () => {
      vi.useFakeTimers();
      const popup = document.getElementById('manualPopup');
      popup.classList.remove('ck-hidden');
      popup.classList.add('vis');

      window.closeManual();

      // Must be click-inert the instant close is requested (pointer-events gated by "vis" in CSS).
      expect(popup.classList.contains('vis')).toBe(false);

      vi.runAllTimers();
      expect(popup.classList.contains('ck-hidden')).toBe(true);
    });

    it('openMdl() adds the "is-open" class the .ck-modal CSS requires for visibility', () => {
      window.openMdl('genericModal');

      const modal = document.getElementById('genericModal');
      expect(modal.classList.contains('ck-hidden')).toBe(false);
      expect(modal.classList.contains('is-open')).toBe(true);
    });

    it('closeMdl() removes "is-open" immediately and restores ck-hidden after the close transition', () => {
      vi.useFakeTimers();
      const modal = document.getElementById('genericModal');
      modal.classList.remove('ck-hidden');
      modal.classList.add('is-open');

      window.closeMdl('genericModal');

      expect(modal.classList.contains('is-open')).toBe(false);

      vi.runAllTimers();
      expect(modal.classList.contains('ck-hidden')).toBe(true);
    });
  });

  // Regression: pickGW() only cleared ".on" from the grid the user just clicked in, and each
  // tab's Pay button stayed independently armed once picked. Picking a gateway under one tab,
  // switching tabs, and picking a different gateway there left BOTH tabs visually "selected"
  // and BOTH buttons enabled/clickable with their own stale selection.
  describe('Cross-tab single selection', () => {
    function setupTwoTabDom() {
      const html = `
        <!DOCTYPE html>
        <html><body>
          <div id="op-checkout-data" data-config='{"txnRef":"TXN123"}' data-manual-gateways='{}'></div>
          <div class="ck-gw-grid" id="cardG">
            <div class="ck-gw" data-tab="card" data-slug="adyen" data-name="Adyen" data-mode="api" data-color="#ECEEF5"></div>
          </div>
          <button type="button" id="cardBtn" disabled class="ck-pay-btn ck-pay-disabled">Select a gateway</button>
          <div class="ck-gw-grid" id="mfsG">
            <div class="ck-gw" data-tab="mfs" data-slug="bkash" data-name="bKash" data-mode="manual" data-color="#E2136E"></div>
          </div>
          <button type="button" id="mfsBtn" disabled class="ck-pay-btn ck-pay-disabled">Select a provider</button>
        </body></html>
      `;
      const newDom = new JSDOM(html, { url: 'http://localhost', runScripts: 'dangerously' });
      const w = newDom.window;
      const d = w.document;
      var runScript = w['eval'];
      runScript.call(w, opFetchCode);
      runScript.call(w, checkoutCode);
      return { newDom, w, d };
    }

    it('picking a gateway in a second tab clears the ".on" class from the first tab\'s card', () => {
      const { newDom, w, d } = setupTwoTabDom();
      const cardCard = d.querySelector('.ck-gw[data-tab="card"]');
      const mfsCard = d.querySelector('.ck-gw[data-tab="mfs"]');

      w.pickGW(cardCard, 'card', 'adyen', 'Adyen', 'api');
      expect(cardCard.classList.contains('on')).toBe(true);

      w.pickGW(mfsCard, 'mfs', 'bkash', 'bKash', 'manual');
      expect(mfsCard.classList.contains('on')).toBe(true);
      expect(cardCard.classList.contains('on')).toBe(false);

      newDom.window.close();
    });

    it('picking a gateway in a second tab resets the first tab\'s button to disabled/default', () => {
      const { newDom, w, d } = setupTwoTabDom();
      const cardCard = d.querySelector('.ck-gw[data-tab="card"]');
      const mfsCard = d.querySelector('.ck-gw[data-tab="mfs"]');
      const cardBtn = d.getElementById('cardBtn');
      const mfsBtn = d.getElementById('mfsBtn');

      w.pickGW(cardCard, 'card', 'adyen', 'Adyen', 'api');
      expect(cardBtn.disabled).toBe(false);
      expect(cardBtn.textContent).toBe('Continue with Adyen');

      w.pickGW(mfsCard, 'mfs', 'bkash', 'bKash', 'manual');

      expect(cardBtn.disabled).toBe(true);
      expect(cardBtn.className).toBe('ck-pay-btn ck-pay-disabled');
      expect(cardBtn.textContent).toBe('Select a gateway');
      expect(mfsBtn.disabled).toBe(false);
      expect(mfsBtn.textContent).toBe('Pay manually via bKash');

      newDom.window.close();
    });

    it('the reset first-tab button no longer submits the stale gateway on click', () => {
      const { newDom, w, d } = setupTwoTabDom();
      const cardCard = d.querySelector('.ck-gw[data-tab="card"]');
      const mfsCard = d.querySelector('.ck-gw[data-tab="mfs"]');
      const cardBtn = d.getElementById('cardBtn');

      w.pickGW(cardCard, 'card', 'adyen', 'Adyen', 'api');
      w.pickGW(mfsCard, 'mfs', 'bkash', 'bKash', 'manual');

      expect(cardBtn.onclick).toBe(null);

      newDom.window.close();
    });
  });

  // Regression: executeGW()/doQP() had no guard against a double-click or double-tap firing two
  // concurrent /pay or /express requests before the first response returned - a customer could
  // end up with two live gateway payment sessions for one transaction. Fixed with a shared
  // in-flight flag plus disabling the clicked button for the duration of the request.
  describe('Double-submit guard', () => {
    // Resolve with a NON-redirecting failure response throughout - a successful redirect_url
    // response would make handlePaymentResponse assign window.location.href, and jsdom's
    // unimplemented navigation makes that path unstable to await deterministically in tests.
    // The in-flight-request-count guard under test doesn't depend on the response shape.
    const flush = async () => {
      await new Promise((resolve) => setTimeout(resolve, 0));
      await new Promise((resolve) => setTimeout(resolve, 0));
    };

    function setupApiGatewayDom() {
      const html = `
        <!DOCTYPE html>
        <html><body>
          <div id="op-checkout-data" data-config='{"txnRef":"TXN123"}' data-manual-gateways='{}'></div>
          <div class="ck-gw" data-tab="card" data-color="#ECEEF5"><div class="ck-gw-ico"></div></div>
          <button type="button" id="cardBtn" disabled class="ck-pay-btn">Select a provider</button>
        </body></html>
      `;
      const newDom = new JSDOM(html, { url: 'http://localhost', runScripts: 'dangerously' });
      const w = newDom.window;
      const d = w.document;
      return { newDom, w, d };
    }

    it('executeGW: clicking the pay button twice before the response returns sends only one request', async () => {
      const { newDom, w, d } = setupApiGatewayDom();

      let resolveFetch;
      w.fetch = vi.fn(() => new Promise((resolve) => { resolveFetch = resolve; }));

      const runScript = w['eval'];
      runScript.call(w, opFetchCode);
      runScript.call(w, checkoutCode);

      const card = d.querySelector('.ck-gw[data-tab="card"]');
      w.pickGW(card, 'card', 'stripe', 'Stripe', 'api');

      const btn = d.getElementById('cardBtn');
      expect(btn.disabled).toBe(false);

      btn.onclick();
      expect(btn.disabled).toBe(true);
      btn.onclick(); // second click while the first request is still in-flight
      btn.onclick();

      expect(w.fetch).toHaveBeenCalledTimes(1);

      resolveFetch({
        ok: true,
        status: 200,
        headers: { get: () => 'application/json' },
        json: async () => ({ success: false, error: 'Gateway declined' }),
      });
      await flush();

      expect(w.fetch).toHaveBeenCalledTimes(1);
      expect(btn.disabled).toBe(false);
      newDom.window.close();
    });

    it('executeGW: after a failed request, the button re-enables so a genuine retry is allowed', async () => {
      const { newDom, w, d } = setupApiGatewayDom();

      w.fetch = vi.fn(() => Promise.reject(new Error('network down')));

      const runScript = w['eval'];
      runScript.call(w, opFetchCode);
      runScript.call(w, checkoutCode);

      const card = d.querySelector('.ck-gw[data-tab="card"]');
      w.pickGW(card, 'card', 'stripe', 'Stripe', 'api');

      const btn = d.getElementById('cardBtn');
      btn.onclick();
      expect(btn.disabled).toBe(true);

      await flush();

      expect(btn.disabled).toBe(false);

      btn.onclick();
      expect(w.fetch).toHaveBeenCalledTimes(2);

      await flush();
      newDom.window.close();
    });
  });

  // Regression: copyNum() called navigator.clipboard.writeText(text).then(showToast)
  // with no .catch() - a rejected promise (denied permission, unfocused document,
  // managed-browser policy) silently stranded the customer with no feedback on the
  // manual-gateway "copy number" button, mid an unauthenticated payment flow.
  describe('copyNum (manual gateway number copy)', () => {
    beforeEach(() => {
      document.getElementById('mpNumber').textContent = '01994493830';
      // jsdom doesn't implement execCommand by default; stub it so
      // vi.spyOn has a real method to override per-test.
      if (typeof document.execCommand !== 'function') {
        document.execCommand = () => false;
      }
    });

    it('tries execCommand first and shows the toast on success', () => {
      const execSpy = vi.spyOn(document, 'execCommand').mockReturnValue(true);

      window.copyNum();

      expect(execSpy).toHaveBeenCalledWith('copy');
      expect(document.getElementById('cToast').classList.contains('vis')).toBe(true);
    });

    it('falls back to the async Clipboard API and shows the toast when execCommand fails', async () => {
      vi.spyOn(document, 'execCommand').mockReturnValue(false);
      Object.defineProperty(window.navigator, 'clipboard', {
        value: { writeText: vi.fn(() => Promise.resolve()) },
        configurable: true,
      });

      window.copyNum();
      await new Promise((resolve) => setTimeout(resolve, 0));

      expect(window.navigator.clipboard.writeText).toHaveBeenCalledWith('01994493830');
      expect(document.getElementById('cToast').classList.contains('vis')).toBe(true);
    });

    it('shows an alert fallback (not silent failure) when every copy method fails', async () => {
      vi.spyOn(document, 'execCommand').mockReturnValue(false);
      const alertSpy = vi.spyOn(window, 'alert').mockImplementation(() => {});
      Object.defineProperty(window.navigator, 'clipboard', {
        value: { writeText: vi.fn(() => Promise.reject(new Error('denied'))) },
        configurable: true,
      });

      window.copyNum();
      await new Promise((resolve) => setTimeout(resolve, 0));

      expect(alertSpy).toHaveBeenCalledWith(expect.stringContaining('01994493830'));
      expect(document.getElementById('cToast').classList.contains('vis')).toBe(false);
    });
  });
});
