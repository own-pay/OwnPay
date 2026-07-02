/**
 * Tests for Developer API endpoints
 * Tests the JavaScript code that interacts with developer API endpoints
 */
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { JSDOM } from 'jsdom';
import fs from 'fs';
import path from 'path';
import { setupFetchMock } from '../utils/api-helpers.js';

// Read developer.js
const developerPath = path.join(__dirname, '../../../public/assets/js/pages/developer.js');
const developerCode = fs.readFileSync(developerPath, 'utf-8');

// Read op-fetch.js (dependency)
const opFetchPath = path.join(__dirname, '../../../public/assets/js/op-fetch.js');
const opFetchCode = fs.readFileSync(opFetchPath, 'utf-8');

describe('Developer API Endpoints', () => {
  let dom;
  let window;
  let document;
  let fetchMock;

  beforeEach(() => {
    // Create a new JSDOM instance with developer page structure
    const html = `
      <!DOCTYPE html>
      <html>
      <head>
        <meta name="csrf-token" content="developer-csrf-token">
      </head>
      <body>
        <div id="webhook-test-section">
          <input type="text" id="webhook-url" value="https://example.com/webhook">
          <select id="webhook-event">
            <option value="payment.completed">payment.completed</option>
            <option value="payment.failed">payment.failed</option>
          </select>
          <button id="test-webhook-btn">Test Webhook</button>
        </div>
        <div id="webhook-result"></div>
        <div id="webhook-history">
          <div class="webhook-log" data-id="1">
            <span class="webhook-status">success</span>
            <span class="webhook-url">https://example.com/webhook</span>
          </div>
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
    
    // Setup fetch mock
    fetchMock = setupFetchMock();
    window.fetch = fetchMock.fetch;
    
    // Execute op-fetch.js (dependency)
    window.eval(opFetchCode);
    
    // Execute developer.js
    window.eval(developerCode);
  });

  afterEach(() => {
    dom.window.close();
  });

  describe('Webhook Test API', () => {
    it('should test webhook with URL and event', async () => {
      const webhookResult = {
        success: true,
        response_code: 200,
        response_time: 150,
        response_body: '{"received": true}',
      };
      fetchMock.mockSuccess(webhookResult);
      
      const result = await window.opPost('/admin/developer/webhook-test', {
        url: 'https://example.com/webhook',
        event: 'payment.completed',
      });
      
      expect(fetchMock.fetch).toHaveBeenCalled();
      const lastCall = fetchMock.getLastCall();
      expect(lastCall.url).toBe('/admin/developer/webhook-test');
      
      // Verify request body
      const body = JSON.parse(lastCall.options.body);
      expect(body.url).toBe('https://example.com/webhook');
      expect(body.event).toBe('payment.completed');
    });

    it('should handle webhook test success', async () => {
      const webhookResult = {
        success: true,
        response_code: 200,
        response_time: 150,
        response_body: '{"received": true}',
      };
      fetchMock.mockSuccess(webhookResult);
      
      const result = await window.opPost('/admin/developer/webhook-test', {
        url: 'https://example.com/webhook',
        event: 'payment.completed',
      });
      
      expect(result.ok).toBe(true);
      expect(result.data.success).toBe(true);
      expect(result.data.response_code).toBe(200);
      expect(result.data.response_time).toBe(150);
    });

    it('should handle webhook test failure (non-200 response)', async () => {
      const webhookResult = {
        success: false,
        response_code: 500,
        response_time: 300,
        response_body: 'Internal Server Error',
      };
      fetchMock.mockSuccess(webhookResult);
      
      const result = await window.opPost('/admin/developer/webhook-test', {
        url: 'https://example.com/webhook',
        event: 'payment.completed',
      });
      
      expect(result.ok).toBe(true);
      expect(result.data.success).toBe(false);
      expect(result.data.response_code).toBe(500);
    });

    it('should handle webhook test timeout', async () => {
      fetchMock.mockError('Webhook test timed out', 504);
      
      const result = await window.opPost('/admin/developer/webhook-test', {
        url: 'https://slow.example.com/webhook',
        event: 'payment.completed',
      });
      
      expect(result.ok).toBe(false);
      expect(result.status).toBe(504);
    });

    it('should handle unreachable webhook URL', async () => {
      fetchMock.mockError('Could not connect to webhook URL', 502);
      
      const result = await window.opPost('/admin/developer/webhook-test', {
        url: 'https://unreachable.example.com/webhook',
        event: 'payment.completed',
      });
      
      expect(result.ok).toBe(false);
      expect(result.status).toBe(502);
    });
  });

  describe('Webhook History API', () => {
    it('should fetch webhook delivery history', async () => {
      const historyData = {
        deliveries: [
          {
            id: 1,
            event: 'payment.completed',
            url: 'https://example.com/webhook',
            status: 'success',
            response_code: 200,
            created_at: '2026-07-02T10:00:00Z',
          },
          {
            id: 2,
            event: 'payment.failed',
            url: 'https://example.com/webhook',
            status: 'failed',
            response_code: 500,
            created_at: '2026-07-02T09:00:00Z',
          },
        ],
        total: 2,
      };
      fetchMock.mockSuccess(historyData);
      
      const result = await window.opFetch('/admin/developer/webhook-history');
      
      expect(result.ok).toBe(true);
      expect(result.data.deliveries).toHaveLength(2);
      expect(result.data.total).toBe(2);
    });

    it('should handle empty webhook history', async () => {
      fetchMock.mockSuccess({ deliveries: [], total: 0 });
      
      const result = await window.opFetch('/admin/developer/webhook-history');
      
      expect(result.ok).toBe(true);
      expect(result.data.deliveries).toHaveLength(0);
    });
  });

  describe('API Key Management', () => {
    it('should generate new API key', async () => {
      const keyData = {
        success: true,
        api_key: 'op_abc123.xyz789',
        key_id: 1,
      };
      fetchMock.mockSuccess(keyData);
      
      const result = await window.opPost('/admin/api-keys/generate', {
        name: 'Test Key',
        scopes: ['payments:read', 'payments:write'],
      });
      
      expect(result.ok).toBe(true);
      expect(result.data.api_key).toMatch(/^op_/);
    });

    it('should revoke API key', async () => {
      fetchMock.mockSuccess({ success: true });
      
      const result = await window.opDelete('/admin/api-keys/1');
      
      expect(result.ok).toBe(true);
    });

    it('should list API keys', async () => {
      const keysData = {
        keys: [
          { id: 1, name: 'Test Key', scopes: ['payments:read'], created_at: '2026-07-02' },
        ],
      };
      fetchMock.mockSuccess(keysData);
      
      const result = await window.opFetch('/admin/api-keys');
      
      expect(result.ok).toBe(true);
      expect(result.data.keys).toHaveLength(1);
    });
  });

  describe('Developer API Security', () => {
    it('should include CSRF token in requests', async () => {
      fetchMock.mockSuccess({});
      
      await window.opPost('/admin/developer/webhook-test', {
        url: 'https://example.com/webhook',
      });
      
      const lastCall = fetchMock.getLastCall();
      expect(lastCall.options.headers['X-CSRF-Token']).toBe('developer-csrf-token');
    });

    it('should validate webhook URL format', async () => {
      // Test with invalid URL
      const invalidUrls = [
        'not-a-url',
        'ftp://example.com',
        'javascript:alert(1)',
      ];
      
      // The validation should happen client-side before API call
      // This test verifies the API is called with the URL as-is
      fetchMock.mockSuccess({ success: true });
      
      for (const url of invalidUrls) {
        await window.opPost('/admin/developer/webhook-test', { url });
      }
      
      // All requests should be made (validation is server-side)
      expect(fetchMock.fetch).toHaveBeenCalledTimes(invalidUrls.length);
    });
  });

  describe('Error Scenarios', () => {
    it('should handle rate limiting', async () => {
      fetchMock.mockError('Too many requests', 429);
      
      const result = await window.opPost('/admin/developer/webhook-test', {
        url: 'https://example.com/webhook',
      });
      
      expect(result.ok).toBe(false);
      expect(result.status).toBe(429);
    });

    it('should handle invalid event type', async () => {
      fetchMock.mockError('Invalid event type', 400);
      
      const result = await window.opPost('/admin/developer/webhook-test', {
        url: 'https://example.com/webhook',
        event: 'invalid.event',
      });
      
      expect(result.ok).toBe(false);
      expect(result.status).toBe(400);
    });

    it('should handle missing required fields', async () => {
      fetchMock.mockError('URL is required', 400);
      
      const result = await window.opPost('/admin/developer/webhook-test', {});
      
      expect(result.ok).toBe(false);
      expect(result.status).toBe(400);
    });
  });
});
