/**
 * Integration tests for Device Management Flow
 * Tests device pairing, status monitoring, and revocation
 */
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { JSDOM } from 'jsdom';
import fs from 'fs';
import path from 'path';
import { setupFetchMock, mockDeviceData } from '../utils/api-helpers.js';

// Read JavaScript files
const opFetchPath = path.join(__dirname, '../../../public/assets/js/op-fetch.js');
const opFetchCode = fs.readFileSync(opFetchPath, 'utf-8');

describe('Device Management Flow Integration', () => {
  let dom;
  let window;
  let document;
  let fetchMock;

  beforeEach(() => {
    const html = `
      <!DOCTYPE html>
      <html>
      <head>
        <meta name="csrf-token" content="admin-csrf-token">
      </head>
      <body>
        <div id="device-list">
          <div class="device-item" data-uuid="dev-uuid-123">
            <span class="device-name">Pixel 8</span>
            <span class="device-status">connected</span>
          </div>
          <div class="device-item" data-uuid="dev-uuid-456">
            <span class="device-name">iPhone 15</span>
            <span class="device-status">disconnected</span>
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

  describe('Device Pairing Flow', () => {
    it('should generate OTP for device pairing', async () => {
      fetchMock.mockSuccess({
        otp: '123456',
        expires_in: 300,
        pairing_token: 'pair-token-123',
      });
      
      const result = await window.opPost('/admin/devices/generate-otp', {});
      
      expect(result.ok).toBe(true);
      expect(result.data.otp).toBe('123456');
      expect(result.data.expires_in).toBe(300);
    });

    it('should handle OTP generation failure', async () => {
      fetchMock.mockError('Failed to generate OTP', 500);
      
      const result = await window.opPost('/admin/devices/generate-otp', {});
      
      expect(result.ok).toBe(false);
      expect(result.status).toBe(500);
    });

    it('should check pairing status', async () => {
      fetchMock.mockSuccess({
        status: 'paired',
        device: {
          uuid: 'dev-uuid-123',
          name: 'Pixel 8',
          paired_at: '2026-07-02T10:00:00Z',
        },
      });
      
      const result = await window.opFetch('/admin/devices/pairing-status?since=0');
      
      expect(result.ok).toBe(true);
      expect(result.data.status).toBe('paired');
      expect(result.data.device.uuid).toBe('dev-uuid-123');
    });

    it('should handle pairing timeout', async () => {
      fetchMock.mockSuccess({
        status: 'pending',
        expires_in: 60,
      });
      
      const result = await window.opFetch('/admin/devices/pairing-status?since=0');
      
      expect(result.ok).toBe(true);
      expect(result.data.status).toBe('pending');
    });
  });

  describe('Device Status Monitoring', () => {
    it('should fetch device statuses', async () => {
      fetchMock.mockSuccess(mockDeviceData.status);
      
      const result = await window.opFetch('/admin/devices/statuses');
      
      expect(result.ok).toBe(true);
      expect(result.data.devices).toHaveLength(1);
      expect(result.data.devices[0].status).toBe('connected');
    });

    it('should display device list', () => {
      const devices = document.querySelectorAll('.device-item');
      expect(devices.length).toBe(2);
    });

    it('should show device status', () => {
      const device = document.querySelector('.device-item[data-uuid="dev-uuid-123"]');
      const statusEl = device.querySelector('.device-status');
      expect(statusEl.textContent).toBe('connected');
    });

    it('should handle status fetch failure', async () => {
      fetchMock.mockError('Failed to fetch statuses', 500);
      
      const result = await window.opFetch('/admin/devices/statuses');
      
      expect(result.ok).toBe(false);
      expect(result.status).toBe(500);
    });
  });

  describe('Device Revocation Flow', () => {
    it('should revoke a device', async () => {
      fetchMock.mockSuccess({ success: true, message: 'Device revoked' });
      
      const result = await window.opPost('/admin/devices/revoke', {
        device_uuid: 'dev-uuid-456',
      });
      
      expect(result.ok).toBe(true);
      expect(result.data.success).toBe(true);
    });

    it('should handle revocation failure', async () => {
      fetchMock.mockError('Device not found', 404);
      
      const result = await window.opPost('/admin/devices/revoke', {
        device_uuid: 'invalid-uuid',
      });
      
      expect(result.ok).toBe(false);
      expect(result.status).toBe(404);
    });
  });

  describe('Device Details Flow', () => {
    it('should fetch device details', async () => {
      fetchMock.mockSuccess({
        device: {
          uuid: 'dev-uuid-123',
          name: 'Pixel 8',
          model: 'Pixel 8 Pro',
          os: 'Android 14',
          app_version: '1.0.0',
          last_seen: '2026-07-02T10:00:00Z',
          status: 'connected',
        },
      });
      
      const result = await window.opFetch('/admin/devices/dev-uuid-123');
      
      expect(result.ok).toBe(true);
      expect(result.data.device.name).toBe('Pixel 8');
    });

    it('should handle device not found', async () => {
      fetchMock.mockError('Device not found', 404);
      
      const result = await window.opFetch('/admin/devices/invalid-uuid');
      
      expect(result.ok).toBe(false);
      expect(result.status).toBe(404);
    });
  });

  describe('Error Handling', () => {
    it('should handle network errors gracefully', async () => {
      fetchMock.mockNetworkError('Connection refused');
      
      const result = await window.opFetch('/admin/devices/statuses');
      
      expect(result.ok).toBe(false);
      expect(result.error).toBe('Connection refused');
    });

    it('should handle unauthorized access', async () => {
      fetchMock.mockError('Unauthorized', 401);
      
      const result = await window.opFetch('/admin/devices/statuses');
      
      expect(result.ok).toBe(false);
      expect(result.status).toBe(401);
    });

    it('should handle rate limiting', async () => {
      fetchMock.mockError('Too many requests', 429);
      
      const result = await window.opFetch('/admin/devices/statuses');
      
      expect(result.ok).toBe(false);
      expect(result.status).toBe(429);
    });
  });

  describe('Security', () => {
    it('should include CSRF token in requests', async () => {
      fetchMock.mockSuccess({});
      
      await window.opPost('/admin/devices/generate-otp', {});
      
      const lastCall = fetchMock.getLastCall();
      expect(lastCall.options.headers['X-CSRF-Token']).toBe('admin-csrf-token');
    });

    it('should use same-origin credentials', async () => {
      fetchMock.mockSuccess({});
      
      await window.opFetch('/admin/devices/statuses');
      
      const lastCall = fetchMock.getLastCall();
      expect(lastCall.options.credentials).toBe('same-origin');
    });
  });
});
