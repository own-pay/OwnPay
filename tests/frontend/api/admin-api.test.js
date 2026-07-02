/**
 * Tests for Admin API endpoints
 * Tests the JavaScript code that interacts with admin API endpoints
 */
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { JSDOM } from 'jsdom';
import fs from 'fs';
import path from 'path';
import { setupFetchMock, mockDeviceData, mockSmsData } from '../utils/api-helpers.js';

// Read the actual admin.js file
const adminPath = path.join(__dirname, '../../../public/assets/js/admin.js');
const adminCode = fs.readFileSync(adminPath, 'utf-8');

// Read devices.js
const devicesPath = path.join(__dirname, '../../../public/assets/js/pages/devices.js');
const devicesCode = fs.readFileSync(devicesPath, 'utf-8');

// Read sms-center.js
const smsCenterPath = path.join(__dirname, '../../../public/assets/js/pages/sms-center.js');
const smsCenterCode = fs.readFileSync(smsCenterPath, 'utf-8');

// Read op-fetch.js (dependency)
const opFetchPath = path.join(__dirname, '../../../public/assets/js/op-fetch.js');
const opFetchCode = fs.readFileSync(opFetchPath, 'utf-8');

describe('Admin API Endpoints', () => {
  let dom;
  let window;
  let document;
  let fetchMock;

  beforeEach(() => {
    // Create a new JSDOM instance with admin page structure
    const html = `
      <!DOCTYPE html>
      <html>
      <head>
        <meta name="csrf-token" content="admin-csrf-token">
      </head>
      <body>
        <div id="sidebar" class="op-sidebar">
          <button id="sidebar-toggle">Toggle</button>
        </div>
        <div id="user-menu-btn">User</div>
        
        <!-- Devices page elements -->
        <div id="device-list">
          <div class="device-item" data-uuid="dev-uuid-123">
            <span class="device-status">connected</span>
          </div>
        </div>
        <button id="generate-otp-btn">Generate OTP</button>
        <div id="otp-display"></div>
        <div id="pairing-status"></div>
        
        <!-- SMS Center elements -->
        <div id="regex-input">
          <input type="text" id="regex-pattern" value="/OTP[:\\s]*(\\d{4,6})/i">
          <textarea id="sample-text">Your OTP is 123456</textarea>
          <button id="test-regex-btn">Test</button>
        </div>
        <div id="regex-result"></div>
        <div id="sms-messages">
          <textarea id="sms-input">You have received 500 BDT from BKASH</textarea>
          <button id="analyze-btn">Analyze</button>
        </div>
        <div id="analysis-result"></div>
        
        <!-- Webhook test elements -->
        <div id="webhook-test">
          <input type="text" id="webhook-url" value="https://example.com/webhook">
          <button id="test-webhook-btn">Test Webhook</button>
        </div>
        <div id="webhook-result"></div>
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
    
    // Execute admin.js
    window.eval(adminCode);
  });

  afterEach(() => {
    dom.window.close();
  });

  describe('Device Management API', () => {
    it('should call generate OTP endpoint', async () => {
      fetchMock.mockSuccess({ otp: '123456', expires_in: 300 });
      
      const result = await window.opPost('/admin/devices/generate-otp', {});
      
      expect(fetchMock.fetch).toHaveBeenCalled();
      const lastCall = fetchMock.getLastCall();
      expect(lastCall.url).toBe('/admin/devices/generate-otp');
      expect(lastCall.options.method).toBe('POST');
    });

    it('should handle OTP generation success', async () => {
      const otpData = { otp: '123456', expires_in: 300 };
      fetchMock.mockSuccess(otpData);
      
      const result = await window.opPost('/admin/devices/generate-otp', {});
      
      expect(result.ok).toBe(true);
      expect(result.data.otp).toBe('123456');
    });

    it('should handle OTP generation failure', async () => {
      fetchMock.mockError('Device not found', 404);
      
      const result = await window.opPost('/admin/devices/generate-otp', {});
      
      expect(result.ok).toBe(false);
      expect(result.status).toBe(404);
    });

    it('should fetch pairing status', async () => {
      const pairingStatus = { status: 'paired', device: { uuid: 'dev-uuid-123' } };
      fetchMock.mockSuccess(pairingStatus);
      
      const result = await window.opFetch('/admin/devices/pairing-status?since=0');
      
      expect(result.ok).toBe(true);
      expect(result.data.status).toBe('paired');
    });

    it('should fetch device statuses', async () => {
      fetchMock.mockSuccess(mockDeviceData.status);
      
      const result = await window.opFetch('/admin/devices/statuses');
      
      expect(result.ok).toBe(true);
      expect(result.data.devices).toHaveLength(1);
    });
  });

  describe('SMS Center API', () => {
    it('should test regex pattern', async () => {
      fetchMock.mockSuccess(mockSmsData.testRegex);
      
      const result = await window.opPost('/admin/sms-center/test-regex', {
        pattern: mockSmsData.testRegex.pattern,
        text: mockSmsData.testRegex.sample_text,
      });
      
      expect(fetchMock.fetch).toHaveBeenCalled();
      const lastCall = fetchMock.getLastCall();
      expect(lastCall.url).toBe('/admin/sms-center/test-regex');
    });

    it('should handle regex test success', async () => {
      fetchMock.mockSuccess(mockSmsData.testRegex);
      
      const result = await window.opPost('/admin/sms-center/test-regex', {
        pattern: mockSmsData.testRegex.pattern,
        text: mockSmsData.testRegex.sample_text,
      });
      
      expect(result.ok).toBe(true);
      expect(result.data.matches).toContain('123456');
    });

    it('should handle regex test failure', async () => {
      fetchMock.mockError('Invalid regex pattern', 400);
      
      const result = await window.opPost('/admin/sms-center/test-regex', {
        pattern: 'invalid regex',
        text: 'test',
      });
      
      expect(result.ok).toBe(false);
      expect(result.status).toBe(400);
    });

    it('should analyze SMS messages', async () => {
      fetchMock.mockSuccess(mockSmsData.analyze);
      
      const result = await window.opPost('/admin/sms-center/analyze', {
        messages: [{ sender: 'BKASH', body: 'You have received 500 BDT' }],
      });
      
      expect(fetchMock.fetch).toHaveBeenCalled();
      const lastCall = fetchMock.getLastCall();
      expect(lastCall.url).toBe('/admin/sms-center/analyze');
    });

    it('should handle SMS analysis success', async () => {
      fetchMock.mockSuccess(mockSmsData.analyze);
      
      const result = await window.opPost('/admin/sms-center/analyze', {
        messages: [{ sender: 'BKASH', body: 'You have received 500 BDT' }],
      });
      
      expect(result.ok).toBe(true);
      expect(result.data.messages).toHaveLength(1);
      expect(result.data.messages[0].parsed.amount).toBe(500);
    });
  });

  describe('Webhook Test API', () => {
    it('should test webhook endpoint', async () => {
      const webhookResult = { success: true, response_code: 200, response_time: 150 };
      fetchMock.mockSuccess(webhookResult);
      
      const result = await window.opPost('/admin/developer/webhook-test', {
        url: 'https://example.com/webhook',
      });
      
      expect(fetchMock.fetch).toHaveBeenCalled();
      const lastCall = fetchMock.getLastCall();
      expect(lastCall.url).toBe('/admin/developer/webhook-test');
    });

    it('should handle webhook test success', async () => {
      const webhookResult = { success: true, response_code: 200, response_time: 150 };
      fetchMock.mockSuccess(webhookResult);
      
      const result = await window.opPost('/admin/developer/webhook-test', {
        url: 'https://example.com/webhook',
      });
      
      expect(result.ok).toBe(true);
      expect(result.data.success).toBe(true);
      expect(result.data.response_code).toBe(200);
    });

    it('should handle webhook test failure', async () => {
      fetchMock.mockError('Webhook URL unreachable', 502);
      
      const result = await window.opPost('/admin/developer/webhook-test', {
        url: 'https://unreachable.example.com/webhook',
      });
      
      expect(result.ok).toBe(false);
      expect(result.status).toBe(502);
    });
  });

  describe('Setup Wizard API', () => {
    it('should save settings', async () => {
      fetchMock.mockSuccess({ success: true });
      
      const result = await window.opPost('/admin/setup-wizard/save-settings', {
        app_name: 'My Store',
        currency: 'USD',
      });
      
      expect(fetchMock.fetch).toHaveBeenCalled();
      const lastCall = fetchMock.getLastCall();
      expect(lastCall.url).toBe('/admin/setup-wizard/save-settings');
    });

    it('should create brand', async () => {
      fetchMock.mockSuccess({ success: true, brand_id: 1 });
      
      const result = await window.opPost('/admin/setup-wizard/create-brand', {
        name: 'My Brand',
        domain: 'mybrand.example.com',
      });
      
      expect(result.ok).toBe(true);
      expect(result.data.brand_id).toBe(1);
    });

    it('should setup mail', async () => {
      fetchMock.mockSuccess({ success: true });
      
      const result = await window.opPost('/admin/setup-wizard/setup-mail', {
        host: 'smtp.example.com',
        port: 587,
        username: 'user@example.com',
        password: 'password',
      });
      
      expect(result.ok).toBe(true);
    });

    it('should setup gateway', async () => {
      fetchMock.mockSuccess({ success: true });
      
      const result = await window.opPost('/admin/setup-wizard/setup-gateway', {
        gateway: 'stripe',
        api_key: 'sk_test_123',
      });
      
      expect(result.ok).toBe(true);
    });

    it('should complete setup', async () => {
      fetchMock.mockSuccess({ success: true, redirect: '/admin/dashboard' });
      
      const result = await window.opPost('/admin/setup-wizard/complete', {});
      
      expect(result.ok).toBe(true);
      expect(result.data.redirect).toBe('/admin/dashboard');
    });

    it('should dismiss setup wizard', async () => {
      fetchMock.mockSuccess({ success: true });
      
      const result = await window.opPost('/admin/setup-wizard/dismiss', {});
      
      expect(result.ok).toBe(true);
    });
  });

  describe('Admin API Security', () => {
    it('should include CSRF token in all requests', async () => {
      fetchMock.mockSuccess({});
      
      await window.opPost('/admin/devices/generate-otp', {});
      
      const lastCall = fetchMock.getLastCall();
      expect(lastCall.options.headers['X-CSRF-Token']).toBe('admin-csrf-token');
    });

    it('should include credentials for same-origin requests', async () => {
      fetchMock.mockSuccess({});
      
      await window.opFetch('/admin/devices/statuses');
      
      const lastCall = fetchMock.getLastCall();
      expect(lastCall.options.credentials).toBe('same-origin');
    });

    it('should block cross-origin admin requests', async () => {
      const result = await window.opFetch('https://evil.com/admin/devices');
      
      expect(result.ok).toBe(false);
      expect(result.error).toBe('Cross-origin requests blocked');
    });
  });

  describe('Error Handling', () => {
    it('should handle 401 unauthorized', async () => {
      fetchMock.mockError('Unauthorized', 401);
      
      const result = await window.opFetch('/admin/devices/statuses');
      
      expect(result.ok).toBe(false);
      expect(result.status).toBe(401);
    });

    it('should handle 403 forbidden', async () => {
      fetchMock.mockError('Forbidden', 403);
      
      const result = await window.opFetch('/admin/devices/statuses');
      
      expect(result.ok).toBe(false);
      expect(result.status).toBe(403);
    });

    it('should handle 500 server error', async () => {
      fetchMock.mockError('Internal server error', 500);
      
      const result = await window.opFetch('/admin/devices/statuses');
      
      expect(result.ok).toBe(false);
      expect(result.status).toBe(500);
    });

    it('should handle network timeout', async () => {
      fetchMock.mockNetworkError('Request timed out');
      
      const result = await window.opFetch('/admin/devices/statuses');
      
      expect(result.ok).toBe(false);
      expect(result.error).toBe('Request timed out');
    });
  });
});
