/**
 * Integration tests for Webhook Testing Flow
 * Tests webhook endpoint testing, history viewing, and retry logic
 */
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { JSDOM } from 'jsdom';
import fs from 'fs';
import path from 'path';
import { setupFetchMock } from '../utils/api-helpers.js';

// Read JavaScript files
const opFetchPath = path.join(__dirname, '../../../public/assets/js/op-fetch.js');
const opFetchCode = fs.readFileSync(opFetchPath, 'utf-8');

describe('Webhook Testing Flow Integration', () => {
  let dom;
  let window;
  let document;
  let fetchMock;

  beforeEach(() => {
    const html = `
      <!DOCTYPE html>
      <html>
      <head>
        <meta name="csrf-token" content="webhook-csrf-token">
      </head>
      <body>
        <div id="webhook-testing">
          <div id="webhook-history">
            <div class="webhook-log" data-id="1">
              <span class="webhook-status success">success</span>
              <span class="webhook-url">https://example.com/webhook</span>
            </div>
            <div class="webhook-log" data-id="2">
              <span class="webhook-status failed">failed</span>
              <span class="webhook-url">https://example.com/webhook</span>
            </div>
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
    
    fetchMock = setupFetchMock();
    window.fetch = fetchMock.fetch;
    
    window.eval(opFetchCode);
  });

  afterEach(() => {
    dom.window.close();
  });

  describe('Webhook Endpoint Testing', () => {
    it('should test webhook endpoint successfully', async () => {
      fetchMock.mockSuccess({
        success: true,
        response_code: 200,
        response_time: 150,
        response_body: '{"received": true}',
      });
      
      const result = await window.opPost('/admin/developer/webhook-test', {
        url: 'https://example.com/webhook',
        event: 'payment.completed',
      });
      
      expect(result.ok).toBe(true);
      expect(result.data.success).toBe(true);
      expect(result.data.response_code).toBe(200);
    });

    it('should handle webhook test failure (non-200 response)', async () => {
      fetchMock.mockSuccess({
        success: false,
        response_code: 500,
        response_time: 300,
        response_body: 'Internal Server Error',
      });
      
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
      });
      
      expect(result.ok).toBe(false);
      expect(result.status).toBe(504);
    });

    it('should handle unreachable webhook URL', async () => {
      fetchMock.mockError('Could not connect to webhook URL', 502);
      
      const result = await window.opPost('/admin/developer/webhook-test', {
        url: 'https://unreachable.example.com/webhook',
      });
      
      expect(result.ok).toBe(false);
      expect(result.status).toBe(502);
    });

    it('should test webhook with custom payload', async () => {
      fetchMock.mockSuccess({
        success: true,
        response_code: 200,
        response_time: 100,
      });
      
      const result = await window.opPost('/admin/developer/webhook-test', {
        url: 'https://example.com/webhook',
        event: 'payment.completed',
        payload: { custom: 'data' },
      });
      
      expect(result.ok).toBe(true);
    });
  });

  describe('Webhook History', () => {
    it('should fetch webhook history', async () => {
      fetchMock.mockSuccess({
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
        page: 1,
        per_page: 10,
      });
      
      const result = await window.opFetch('/admin/developer/webhook-history');
      
      expect(result.ok).toBe(true);
      expect(result.data.deliveries).toHaveLength(2);
      expect(result.data.total).toBe(2);
    });

    it('should handle empty webhook history', async () => {
      fetchMock.mockSuccess({
        deliveries: [],
        total: 0,
      });
      
      const result = await window.opFetch('/admin/developer/webhook-history');
      
      expect(result.ok).toBe(true);
      expect(result.data.deliveries).toHaveLength(0);
    });

    it('should filter webhook history by status', async () => {
      fetchMock.mockSuccess({
        deliveries: [
          {
            id: 1,
            event: 'payment.completed',
            status: 'success',
            response_code: 200,
          },
        ],
        total: 1,
      });
      
      const result = await window.opFetch('/admin/developer/webhook-history?status=success');
      
      expect(result.ok).toBe(true);
      expect(result.data.deliveries).toHaveLength(1);
      expect(result.data.deliveries[0].status).toBe('success');
    });
  });

  describe('Webhook Retry', () => {
    it('should retry failed webhook', async () => {
      fetchMock.mockSuccess({
        success: true,
        message: 'Webhook retry queued',
        retry_id: 'retry-123',
      });
      
      const result = await window.opPost('/admin/developer/webhook-retry', {
        delivery_id: 2,
      });
      
      expect(result.ok).toBe(true);
      expect(result.data.success).toBe(true);
      expect(result.data.retry_id).toBe('retry-123');
    });

    it('should handle retry failure', async () => {
      fetchMock.mockError('Retry failed', 500);
      
      const result = await window.opPost('/admin/developer/webhook-retry', {
        delivery_id: 999,
      });
      
      expect(result.ok).toBe(false);
      expect(result.status).toBe(500);
    });

    it('should handle retry rate limiting', async () => {
      fetchMock.mockError('Too many retry attempts', 429);
      
      const result = await window.opPost('/admin/developer/webhook-retry', {
        delivery_id: 2,
      });
      
      expect(result.ok).toBe(false);
      expect(result.status).toBe(429);
    });
  });

  describe('Webhook Logs', () => {
    it('should fetch webhook logs', async () => {
      fetchMock.mockSuccess({
        logs: [
          {
            id: 1,
            level: 'info',
            message: 'Webhook delivered successfully',
            timestamp: '2026-07-02T10:00:00Z',
          },
          {
            id: 2,
            level: 'error',
            message: 'Webhook delivery failed',
            timestamp: '2026-07-02T09:00:00Z',
          },
        ],
        total: 2,
      });
      
      const result = await window.opFetch('/admin/developer/webhook-logs');
      
      expect(result.ok).toBe(true);
      expect(result.data.logs).toHaveLength(2);
      expect(result.data.logs[0].level).toBe('info');
      expect(result.data.logs[1].level).toBe('error');
    });

    it('should handle empty logs', async () => {
      fetchMock.mockSuccess({
        logs: [],
        total: 0,
      });
      
      const result = await window.opFetch('/admin/developer/webhook-logs');
      
      expect(result.ok).toBe(true);
      expect(result.data.logs).toHaveLength(0);
    });
  });

  describe('Error Handling', () => {
    it('should handle network errors', async () => {
      fetchMock.mockNetworkError('Connection refused');
      
      const result = await window.opPost('/admin/developer/webhook-test', {
        url: 'https://example.com/webhook',
      });
      
      expect(result.ok).toBe(false);
      expect(result.error).toBe('Connection refused');
    });

    it('should handle server errors', async () => {
      fetchMock.mockError('Internal server error', 500);
      
      const result = await window.opPost('/admin/developer/webhook-test', {
        url: 'https://example.com/webhook',
      });
      
      expect(result.ok).toBe(false);
      expect(result.status).toBe(500);
    });

    it('should handle unauthorized access', async () => {
      fetchMock.mockError('Unauthorized', 401);
      
      const result = await window.opPost('/admin/developer/webhook-test', {
        url: 'https://example.com/webhook',
      });
      
      expect(result.ok).toBe(false);
      expect(result.status).toBe(401);
    });
  });

  describe('Security', () => {
    it('should include CSRF token in requests', async () => {
      fetchMock.mockSuccess({});
      
      await window.opPost('/admin/developer/webhook-test', {
        url: 'https://example.com/webhook',
      });
      
      const lastCall = fetchMock.getLastCall();
      expect(lastCall.options.headers['X-CSRF-Token']).toBe('webhook-csrf-token');
    });

    it('should use same-origin credentials', async () => {
      fetchMock.mockSuccess({});
      
      await window.opPost('/admin/developer/webhook-test', {
        url: 'https://example.com/webhook',
      });
      
      const lastCall = fetchMock.getLastCall();
      expect(lastCall.options.credentials).toBe('same-origin');
    });

    it('should block cross-origin webhook test requests', async () => {
      const result = await window.opFetch('https://evil.com/admin/developer/webhook-test');
      
      expect(result.ok).toBe(false);
      expect(result.error).toBe('Cross-origin requests blocked');
    });
  });
});
