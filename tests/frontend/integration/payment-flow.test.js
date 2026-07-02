/**
 * Integration tests for Payment Flow
 * Tests the complete checkout flow from start to finish
 */
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { JSDOM } from 'jsdom';
import fs from 'fs';
import path from 'path';
import { setupFetchMock, mockPaymentData } from '../utils/api-helpers.js';

// Read JavaScript files
const opFetchPath = path.join(__dirname, '../../../public/assets/js/op-fetch.js');
const opFetchCode = fs.readFileSync(opFetchPath, 'utf-8');
const checkoutPath = path.join(__dirname, '../../../public/assets/js/checkout.js');
const checkoutCode = fs.readFileSync(checkoutPath, 'utf-8');

describe('Payment Flow Integration', () => {
  let dom;
  let window;
  let document;
  let fetchMock;

  beforeEach(() => {
    const html = `
      <!DOCTYPE html>
      <html>
      <head>
        <meta name="csrf-token" content="test-csrf-token">
        <meta name="csp-nonce" content="test-nonce">
      </head>
      <body>
        <div id="op-checkout-data" 
             data-config='{"txnRef":"TXN123","checkoutBasePath":"/checkout/TXN123","timeoutEnabled":true,"timeoutRemaining":300}'
             data-manual-gateways='{"bank":{"name":"Bank Transfer","instructions":"Send money to 01712345678"}}'
             data-brand-color="#0D9488"
             data-brand-accent-color="#0D9488">
        </div>
        <div id="timer"></div>
        <div id="gateway-tabs">
          <div class="ck-tab on" data-t="card">Card</div>
          <div class="ck-tab" data-t="bank">Bank</div>
        </div>
        <div id="t-card" class="ck-tc">
          <div class="bank-instructions">Send money to 01712345678</div>
        </div>
        <div id="t-bank" class="ck-tc ck-hidden">
          <div class="bank-instructions">Send money to 01712345678</div>
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
    
    fetchMock = setupFetchMock();
    window.fetch = fetchMock.fetch;
    
    window.eval(opFetchCode);
    window.eval(checkoutCode);
  });

  afterEach(() => {
    dom.window.close();
  });

  describe('Checkout Configuration', () => {
    it('should initialize checkout with correct configuration', () => {
      expect(window.OP_CHECKOUT_CONFIG).toBeDefined();
      expect(window.OP_CHECKOUT_CONFIG.txnRef).toBe('TXN123');
      expect(window.OP_CHECKOUT_CONFIG.checkoutBasePath).toBe('/checkout/TXN123');
      expect(window.OP_CHECKOUT_CONFIG.timeoutEnabled).toBe(true);
    });

    it('should load manual gateways', () => {
      expect(window.OP_MANUAL_GATEWAYS).toBeDefined();
      expect(window.OP_MANUAL_GATEWAYS.bank).toBeDefined();
      expect(window.OP_MANUAL_GATEWAYS.bank.name).toBe('Bank Transfer');
    });

    it('should display timer when timeout is enabled', () => {
      const timerEl = document.getElementById('timer');
      expect(timerEl).toBeDefined();
    });

    it('should start with card tab active', () => {
      const activeTab = document.querySelector('.ck-tab.on');
      expect(activeTab).toBeDefined();
      expect(activeTab.dataset.t).toBe('card');
    });
  });

  describe('Card Payment Flow', () => {
    it('should submit card payment successfully', async () => {
      fetchMock.mockSuccess({
        success: true,
        redirect_url: 'https://example.com/success',
        payment_id: 'PAY-123',
      });
      
      const result = await window.opPost('/checkout/TXN123/pay', {
        gateway: 'stripe',
        card_number: '4242424242424242',
        card_expiry: '12/25',
        card_cvc: '123',
      });
      
      expect(result.ok).toBe(true);
      expect(result.data.success).toBe(true);
      expect(result.data.redirect_url).toBe('https://example.com/success');
    });

    it('should handle card payment failure', async () => {
      fetchMock.mockError('Payment declined', 402);
      
      const result = await window.opPost('/checkout/TXN123/pay', {
        gateway: 'stripe',
        card_number: '4000000000000002',
      });
      
      expect(result.ok).toBe(false);
      expect(result.status).toBe(402);
    });

    it('should validate required fields', async () => {
      fetchMock.mockError('Card number is required', 400);
      
      const result = await window.opPost('/checkout/TXN123/pay', {
        gateway: 'stripe',
      });
      
      expect(result.ok).toBe(false);
      expect(result.status).toBe(400);
    });
  });

  describe('Express Checkout Flow', () => {
    it('should initiate express checkout', async () => {
      fetchMock.mockSuccess({
        success: true,
        redirect_url: 'https://paypal.example.com/checkout',
      });
      
      const result = await window.opPost('/checkout/TXN123/express', {
        gateway: 'paypal',
      });
      
      expect(result.ok).toBe(true);
      expect(result.data.redirect_url).toBe('https://paypal.example.com/checkout');
    });

    it('should handle express checkout failure', async () => {
      fetchMock.mockError('Express checkout not available', 400);
      
      const result = await window.opPost('/checkout/TXN123/express', {
        gateway: 'paypal',
      });
      
      expect(result.ok).toBe(false);
      expect(result.status).toBe(400);
    });
  });

  describe('Bank Transfer Flow', () => {
    it('should display bank transfer instructions', () => {
      const instructions = document.querySelector('.bank-instructions');
      expect(instructions).toBeDefined();
      expect(instructions.textContent).toContain('01712345678');
    });

    it('should have manual gateways configured', () => {
      const gateways = window.OP_MANUAL_GATEWAYS;
      expect(gateways.bank.instructions).toContain('01712345678');
    });
  });

  describe('Payment Status Flow', () => {
    it('should check payment status', async () => {
      fetchMock.mockSuccess({
        status: 'completed',
        payment_id: 'PAY-123',
        amount: '100.00',
        currency: 'USD',
      });
      
      const result = await window.opFetch('/checkout/TXN123/status');
      
      expect(result.ok).toBe(true);
      expect(result.data.status).toBe('completed');
    });

    it('should handle pending payment status', async () => {
      fetchMock.mockSuccess({
        status: 'pending',
        payment_id: 'PAY-123',
      });
      
      const result = await window.opFetch('/checkout/TXN123/status');
      
      expect(result.ok).toBe(true);
      expect(result.data.status).toBe('pending');
    });

    it('should handle failed payment status', async () => {
      fetchMock.mockSuccess({
        status: 'failed',
        payment_id: 'PAY-123',
        error: 'Insufficient funds',
      });
      
      const result = await window.opFetch('/checkout/TXN123/status');
      
      expect(result.ok).toBe(true);
      expect(result.data.status).toBe('failed');
    });
  });

  describe('Error Recovery Flow', () => {
    it('should recover from network error', async () => {
      fetchMock.mockNetworkError('Network error');
      
      let result = await window.opPost('/checkout/TXN123/pay', { gateway: 'stripe' });
      expect(result.ok).toBe(false);
      
      fetchMock.mockSuccess({ success: true, redirect_url: 'https://example.com/success' });
      
      result = await window.opPost('/checkout/TXN123/pay', { gateway: 'stripe' });
      expect(result.ok).toBe(true);
    });

    it('should recover from server error', async () => {
      fetchMock.mockError('Server error', 500);
      
      let result = await window.opPost('/checkout/TXN123/pay', { gateway: 'stripe' });
      expect(result.ok).toBe(false);
      expect(result.status).toBe(500);
      
      fetchMock.mockSuccess({ success: true, redirect_url: 'https://example.com/success' });
      
      result = await window.opPost('/checkout/TXN123/pay', { gateway: 'stripe' });
      expect(result.ok).toBe(true);
    });
  });

  describe('Security Flow', () => {
    it('should include CSRF token in all requests', async () => {
      fetchMock.mockSuccess({});
      
      await window.opPost('/checkout/TXN123/pay', { gateway: 'stripe' });
      
      const lastCall = fetchMock.getLastCall();
      expect(lastCall.options.headers['X-CSRF-Token']).toBe('test-csrf-token');
    });

    it('should block cross-origin requests', async () => {
      const result = await window.opFetch('https://evil.com/checkout/TXN123/pay');
      
      expect(result.ok).toBe(false);
      expect(result.error).toBe('Cross-origin requests blocked');
    });

    it('should use same-origin credentials', async () => {
      fetchMock.mockSuccess({});
      
      await window.opFetch('/checkout/TXN123/status');
      
      const lastCall = fetchMock.getLastCall();
      expect(lastCall.options.credentials).toBe('same-origin');
    });
  });
});
