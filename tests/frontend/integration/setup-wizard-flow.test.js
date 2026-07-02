/**
 * Integration tests for Setup Wizard Flow
 * Tests the complete setup wizard from start to finish
 */
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { JSDOM } from 'jsdom';
import fs from 'fs';
import path from 'path';
import { setupFetchMock } from '../utils/api-helpers.js';

// Read JavaScript files
const opFetchPath = path.join(__dirname, '../../../public/assets/js/op-fetch.js');
const opFetchCode = fs.readFileSync(opFetchPath, 'utf-8');

describe('Setup Wizard Flow Integration', () => {
  let dom;
  let window;
  let document;
  let fetchMock;

  beforeEach(() => {
    const html = `
      <!DOCTYPE html>
      <html>
      <head>
        <meta name="csrf-token" content="setup-csrf-token">
      </head>
      <body>
        <div id="setup-wizard">
          <div id="step-welcome" class="setup-step active">
            <h2>Welcome to OwnPay</h2>
            <button id="start-setup-btn">Get Started</button>
          </div>
          <div id="setup-progress">
            <div class="progress-bar">
              <div class="progress-fill" style="width: 0%"></div>
            </div>
            <div class="progress-text">Step 1 of 6</div>
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

  describe('Settings Step', () => {
    it('should save basic settings', async () => {
      fetchMock.mockSuccess({ success: true });
      
      const result = await window.opPost('/admin/setup-wizard/save-settings', {
        app_name: 'My Store',
        currency: 'BDT',
        timezone: 'Asia/Dhaka',
      });
      
      expect(result.ok).toBe(true);
      expect(result.data.success).toBe(true);
    });

    it('should handle settings save failure', async () => {
      fetchMock.mockError('Invalid settings', 400);
      
      const result = await window.opPost('/admin/setup-wizard/save-settings', {
        app_name: '',
        currency: 'INVALID',
      });
      
      expect(result.ok).toBe(false);
      expect(result.status).toBe(400);
    });
  });

  describe('Brand Creation Step', () => {
    it('should create brand', async () => {
      fetchMock.mockSuccess({ success: true, brand_id: 1 });
      
      const result = await window.opPost('/admin/setup-wizard/create-brand', {
        name: 'My Brand',
        domain: 'mybrand.example.com',
        slug: 'my-brand',
      });
      
      expect(result.ok).toBe(true);
      expect(result.data.brand_id).toBe(1);
    });

    it('should handle brand creation failure', async () => {
      fetchMock.mockError('Brand name already exists', 409);
      
      const result = await window.opPost('/admin/setup-wizard/create-brand', {
        name: 'Existing Brand',
      });
      
      expect(result.ok).toBe(false);
      expect(result.status).toBe(409);
    });
  });

  describe('Mail Configuration Step', () => {
    it('should save mail settings', async () => {
      fetchMock.mockSuccess({ success: true });
      
      const result = await window.opPost('/admin/setup-wizard/setup-mail', {
        driver: 'smtp',
        host: 'smtp.example.com',
        port: 587,
        username: 'user@example.com',
        password: 'password',
      });
      
      expect(result.ok).toBe(true);
      expect(result.data.success).toBe(true);
    });

    it('should handle mail configuration failure', async () => {
      fetchMock.mockError('SMTP connection failed', 500);
      
      const result = await window.opPost('/admin/setup-wizard/setup-mail', {
        driver: 'smtp',
        host: 'invalid.host',
        port: 587,
      });
      
      expect(result.ok).toBe(false);
      expect(result.status).toBe(500);
    });
  });

  describe('Gateway Configuration Step', () => {
    it('should save gateway settings', async () => {
      fetchMock.mockSuccess({ success: true });
      
      const result = await window.opPost('/admin/setup-wizard/setup-gateway', {
        gateway: 'stripe',
        api_key: 'sk_test_123',
        api_secret: 'sk_secret_456',
      });
      
      expect(result.ok).toBe(true);
      expect(result.data.success).toBe(true);
    });

    it('should handle gateway configuration failure', async () => {
      fetchMock.mockError('Invalid API key', 400);
      
      const result = await window.opPost('/admin/setup-wizard/setup-gateway', {
        gateway: 'stripe',
        api_key: 'invalid_key',
      });
      
      expect(result.ok).toBe(false);
      expect(result.status).toBe(400);
    });
  });

  describe('Completion Step', () => {
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
      expect(result.data.success).toBe(true);
    });
  });

  describe('Progress Tracking', () => {
    it('should show progress bar', () => {
      const progressBar = document.querySelector('.progress-fill');
      expect(progressBar).toBeDefined();
    });

    it('should show progress text', () => {
      const progressText = document.querySelector('.progress-text');
      expect(progressText).toBeDefined();
      expect(progressText.textContent).toContain('Step 1 of 6');
    });
  });

  describe('Error Handling', () => {
    it('should handle network errors', async () => {
      fetchMock.mockNetworkError('Connection refused');
      
      const result = await window.opPost('/admin/setup-wizard/save-settings', {
        app_name: 'Test',
      });
      
      expect(result.ok).toBe(false);
      expect(result.error).toBe('Connection refused');
    });

    it('should handle server errors', async () => {
      fetchMock.mockError('Internal server error', 500);
      
      const result = await window.opPost('/admin/setup-wizard/save-settings', {
        app_name: 'Test',
      });
      
      expect(result.ok).toBe(false);
      expect(result.status).toBe(500);
    });
  });

  describe('Security', () => {
    it('should include CSRF token in all requests', async () => {
      fetchMock.mockSuccess({});
      
      await window.opPost('/admin/setup-wizard/save-settings', {
        app_name: 'Test',
      });
      
      const lastCall = fetchMock.getLastCall();
      expect(lastCall.options.headers['X-CSRF-Token']).toBe('setup-csrf-token');
    });

    it('should use same-origin credentials', async () => {
      fetchMock.mockSuccess({});
      
      await window.opPost('/admin/setup-wizard/save-settings', {
        app_name: 'Test',
      });
      
      const lastCall = fetchMock.getLastCall();
      expect(lastCall.options.credentials).toBe('same-origin');
    });
  });
});
