/**
 * Tests for Payment API endpoints
 * Tests the JavaScript code that interacts with /api/v1/payments
 */
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { JSDOM } from 'jsdom';
import fs from 'fs';
import path from 'path';
import { setupFetchMock, mockPaymentData } from '../utils/api-helpers.js';

// Read the actual op-fetch.js file (dependency)
const opFetchPath = path.join(__dirname, '../../../public/assets/js/op-fetch.js');
const opFetchCode = fs.readFileSync(opFetchPath, 'utf-8');

// Read checkout.js (uses payment API)
const checkoutPath = path.join(__dirname, '../../../public/assets/js/checkout.js');
const checkoutCode = fs.readFileSync(checkoutPath, 'utf-8');

describe('Payment API Endpoints', () => {
  let dom;
  let window;
  let document;
  let fetchMock;

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
             data-config='{"txnRef":"TXN123","checkoutBasePath":"/checkout/TXN123"}'
             data-manual-gateways='{}'
             data-brand-color="#0D9488"
             data-brand-accent-color="#0D9488">
        </div>
        <div id="timer"></div>
        <form id="payment-form">
          <input type="hidden" name="gateway" value="stripe">
          <button type="submit">Pay</button>
        </form>
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
    
    // Setup fetch mock
    fetchMock = setupFetchMock();
    window.fetch = fetchMock.fetch;
    
    // Execute op-fetch.js (dependency)
    window.eval(opFetchCode);
    
    // Execute checkout.js
    window.eval(checkoutCode);
  });

  afterEach(() => {
    dom.window.close();
  });

  describe('Payment Initiation', () => {
    it('should call payment API with correct parameters', async () => {
      fetchMock.mockSuccess(mockPaymentData.response);
      
      const result = await window.opPost('/api/v1/payments', mockPaymentData.initiate);
      
      expect(fetchMock.fetch).toHaveBeenCalled();
      const lastCall = fetchMock.getLastCall();
      expect(lastCall.url).toBe('/api/v1/payments');
      expect(lastCall.options.method).toBe('POST');
    });

    it('should handle successful payment initiation', async () => {
      fetchMock.mockSuccess(mockPaymentData.response);
      
      const result = await window.opPost('/api/v1/payments', mockPaymentData.initiate);
      
      expect(result.ok).toBe(true);
      expect(result.data.success).toBe(true);
      expect(result.data.payment_id).toBe('PAY-123');
    });

    it('should handle payment initiation errors', async () => {
      fetchMock.mockError('Invalid amount', 400);
      
      const result = await window.opPost('/api/v1/payments', {
        ...mockPaymentData.initiate,
        amount: '-100',
      });
      
      expect(result.ok).toBe(false);
      expect(result.status).toBe(400);
    });

    it('should handle network errors', async () => {
      fetchMock.mockNetworkError('Connection refused');
      
      const result = await window.opPost('/api/v1/payments', mockPaymentData.initiate);
      
      expect(result.ok).toBe(false);
      expect(result.error).toBe('Connection refused');
    });
  });

  describe('Payment Status Query', () => {
    it('should fetch payment status', async () => {
      fetchMock.mockSuccess(mockPaymentData.status);
      
      const result = await window.opFetch('/api/v1/payments/PAY-123');
      
      expect(result.ok).toBe(true);
      expect(result.data.status).toBe('completed');
    });

    it('should handle payment not found', async () => {
      fetchMock.mockError('Payment not found', 404);
      
      const result = await window.opFetch('/api/v1/payments/INVALID');
      
      expect(result.ok).toBe(false);
      expect(result.status).toBe(404);
    });
  });

  describe('Checkout Pay Endpoint', () => {
    it('should call checkout pay endpoint', async () => {
      fetchMock.mockSuccess({ success: true, redirect_url: 'https://example.com/success' });
      
      const result = await window.opPost('/checkout/TXN123/pay', {
        gateway: 'stripe',
      });
      
      expect(fetchMock.fetch).toHaveBeenCalled();
      const lastCall = fetchMock.getLastCall();
      expect(lastCall.url).toBe('/checkout/TXN123/pay');
      expect(lastCall.options.method).toBe('POST');
    });

    it('should handle checkout payment success', async () => {
      const redirectUrl = 'https://example.com/success';
      fetchMock.mockSuccess({ success: true, redirect_url: redirectUrl });
      
      const result = await window.opPost('/checkout/TXN123/pay', {
        gateway: 'stripe',
      });
      
      expect(result.ok).toBe(true);
      expect(result.data.redirect_url).toBe(redirectUrl);
    });

    it('should handle checkout payment failure', async () => {
      fetchMock.mockError('Payment failed', 402);
      
      const result = await window.opPost('/checkout/TXN123/pay', {
        gateway: 'stripe',
      });
      
      expect(result.ok).toBe(false);
      expect(result.status).toBe(402);
    });
  });

  describe('Express Checkout Endpoint', () => {
    it('should call express checkout endpoint', async () => {
      fetchMock.mockSuccess({ success: true, redirect_url: 'https://express.example.com' });
      
      const result = await window.opPost('/checkout/TXN123/express', {
        gateway: 'paypal',
      });
      
      expect(fetchMock.fetch).toHaveBeenCalled();
      const lastCall = fetchMock.getLastCall();
      expect(lastCall.url).toBe('/checkout/TXN123/express');
    });
  });

  describe('API Request Headers', () => {
    it('should include CSRF token in requests', async () => {
      fetchMock.mockSuccess({});
      
      await window.opPost('/api/v1/payments', mockPaymentData.initiate);
      
      const lastCall = fetchMock.getLastCall();
      expect(lastCall.options.headers['X-CSRF-Token']).toBe('test-csrf-token');
    });

    it('should include X-Requested-With header', async () => {
      fetchMock.mockSuccess({});
      
      await window.opPost('/api/v1/payments', mockPaymentData.initiate);
      
      const lastCall = fetchMock.getLastCall();
      expect(lastCall.options.headers['X-Requested-With']).toBe('XMLHttpRequest');
    });

    it('should include Content-Type for JSON requests', async () => {
      fetchMock.mockSuccess({});
      
      await window.opPost('/api/v1/payments', mockPaymentData.initiate);
      
      const lastCall = fetchMock.getLastCall();
      expect(lastCall.options.headers['Content-Type']).toBe('application/json');
    });
  });

  describe('API Response Handling', () => {
    it('should parse JSON responses', async () => {
      const responseData = { success: true, data: 'test' };
      fetchMock.mockSuccess(responseData);
      
      const result = await window.opFetch('/api/v1/payments/PAY-123');
      
      expect(result.data).toEqual(responseData);
    });

    it('should handle non-JSON responses', async () => {
      // Mock a text response
      fetchMock.fetch.mockResolvedValueOnce({
        ok: true,
        status: 200,
        headers: {
          get: () => 'text/html',
        },
        text: () => Promise.resolve('<div>HTML response</div>'),
      });
      
      const result = await window.opFetch('/api/v1/payments/PAY-123');
      
      expect(result.ok).toBe(true);
      expect(typeof result.data).toBe('string');
    });

    it('should extract error message from response', async () => {
      fetchMock.mockError('Invalid request parameters', 400);
      
      const result = await window.opFetch('/api/v1/payments');
      
      expect(result.error).toBe('Invalid request parameters');
    });

    it('should use default error message when none provided', async () => {
      // Mock response without error message
      fetchMock.fetch.mockResolvedValueOnce({
        ok: false,
        status: 500,
        headers: {
          get: () => 'application/json',
        },
        json: () => Promise.resolve({}),
      });
      
      const result = await window.opFetch('/api/v1/payments');
      
      expect(result.error).toBe('HTTP 500');
    });
  });
});
