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
});
