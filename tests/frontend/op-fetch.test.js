/**
 * Tests for op-fetch.js - CSRF-protected fetch wrapper
 * 
 * This file tests the actual implementation of op-fetch.js
 * by loading it in a jsdom environment.
 */
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { JSDOM } from 'jsdom';
import fs from 'fs';
import path from 'path';

// Read the actual op-fetch.js file
const opFetchPath = path.join(__dirname, '../../public/assets/js/op-fetch.js');
const opFetchCode = fs.readFileSync(opFetchPath, 'utf-8');

describe('op-fetch.js', () => {
  let dom;
  let window;
  let document;

  beforeEach(() => {
    // Create a new JSDOM instance for each test
    dom = new JSDOM('<!DOCTYPE html><html><body></body></html>', {
      url: 'http://localhost',
      runScripts: 'dangerously',
      resources: 'usable',
    });
    
    window = dom.window;
    document = window.document;
    
    // Mock fetch globally
    window.fetch = vi.fn();
    
    // Execute the op-fetch.js code in the jsdom context
    window.eval(opFetchCode);
  });

  afterEach(() => {
    dom.window.close();
  });

  describe('CSRF Token Handling', () => {
    it('should get CSRF token from meta tag', () => {
      // Setup meta tag
      const meta = document.createElement('meta');
      meta.setAttribute('name', 'csrf-token');
      meta.setAttribute('content', 'test-csrf-token');
      document.head.appendChild(meta);

      // Call opFetch to trigger getCsrfToken
      window.fetch.mockResolvedValue({
        ok: true,
        status: 200,
        headers: { get: () => 'application/json' },
        json: () => Promise.resolve({}),
      });

      window.opFetch('/api/test');
      
      // Verify the CSRF token was used
      const fetchCall = window.fetch.mock.calls[0];
      const options = fetchCall[1];
      expect(options.headers['X-CSRF-Token']).toBe('test-csrf-token');
    });

    it('should get CSRF token from input field if meta not found', () => {
      // Setup input field
      const input = document.createElement('input');
      input.setAttribute('name', '_csrf_token');
      input.setAttribute('value', 'input-csrf-token');
      document.body.appendChild(input);

      window.fetch.mockResolvedValue({
        ok: true,
        status: 200,
        headers: { get: () => 'application/json' },
        json: () => Promise.resolve({}),
      });

      window.opFetch('/api/test');
      
      const fetchCall = window.fetch.mock.calls[0];
      const options = fetchCall[1];
      expect(options.headers['X-CSRF-Token']).toBe('input-csrf-token');
    });

    it('should use empty string if no CSRF token found', () => {
      window.fetch.mockResolvedValue({
        ok: true,
        status: 200,
        headers: { get: () => 'application/json' },
        json: () => Promise.resolve({}),
      });

      window.opFetch('/api/test');
      
      const fetchCall = window.fetch.mock.calls[0];
      const options = fetchCall[1];
      expect(options.headers['X-CSRF-Token']).toBe('');
    });
  });

  describe('URL Validation (OWASP)', () => {
    it('should block cross-origin requests', async () => {
      const result = await window.opFetch('https://evil.com/api/test');
      
      expect(result.ok).toBe(false);
      expect(result.error).toBe('Cross-origin requests blocked');
      expect(window.fetch).not.toHaveBeenCalled();
    });

    it('should block protocol-relative URLs', async () => {
      const result = await window.opFetch('//evil.com/api/test');
      
      expect(result.ok).toBe(false);
      expect(result.error).toBe('Cross-origin requests blocked');
      expect(window.fetch).not.toHaveBeenCalled();
    });

    it('should allow same-origin requests', async () => {
      window.fetch.mockResolvedValue({
        ok: true,
        status: 200,
        headers: { get: () => 'application/json' },
        json: () => Promise.resolve({ success: true }),
      });

      const result = await window.opFetch('http://localhost/api/test');
      
      expect(result.ok).toBe(true);
      expect(window.fetch).toHaveBeenCalled();
    });

    it('should allow relative URLs', async () => {
      window.fetch.mockResolvedValue({
        ok: true,
        status: 200,
        headers: { get: () => 'application/json' },
        json: () => Promise.resolve({ success: true }),
      });

      const result = await window.opFetch('/api/test');
      
      expect(result.ok).toBe(true);
      expect(window.fetch).toHaveBeenCalled();
    });
  });

  describe('Request Configuration', () => {
    it('should set default headers', async () => {
      window.fetch.mockResolvedValue({
        ok: true,
        status: 200,
        headers: { get: () => 'application/json' },
        json: () => Promise.resolve({}),
      });

      await window.opFetch('/api/test');
      
      const fetchCall = window.fetch.mock.calls[0];
      const options = fetchCall[1];
      
      expect(options.headers['X-Requested-With']).toBe('XMLHttpRequest');
      expect(options.credentials).toBe('same-origin');
      expect(options.method).toBe('GET');
    });

    it('should merge custom headers with defaults', async () => {
      window.fetch.mockResolvedValue({
        ok: true,
        status: 200,
        headers: { get: () => 'application/json' },
        json: () => Promise.resolve({}),
      });

      await window.opFetch('/api/test', {
        headers: { 'Custom-Header': 'custom-value' },
      });
      
      const fetchCall = window.fetch.mock.calls[0];
      const options = fetchCall[1];
      
      expect(options.headers['X-Requested-With']).toBe('XMLHttpRequest');
      expect(options.headers['Custom-Header']).toBe('custom-value');
    });

    it('should auto-convert object body to JSON', async () => {
      window.fetch.mockResolvedValue({
        ok: true,
        status: 200,
        headers: { get: () => 'application/json' },
        json: () => Promise.resolve({}),
      });

      const body = { key: 'value' };
      await window.opFetch('/api/test', { method: 'POST', body });
      
      const fetchCall = window.fetch.mock.calls[0];
      const options = fetchCall[1];
      
      expect(options.headers['Content-Type']).toBe('application/json');
      expect(options.body).toBe(JSON.stringify(body));
    });

    it('should not convert FormData body to JSON', async () => {
      window.fetch.mockResolvedValue({
        ok: true,
        status: 200,
        headers: { get: () => 'application/json' },
        json: () => Promise.resolve({}),
      });

      const formData = new window.FormData();
      formData.append('key', 'value');
      
      await window.opFetch('/api/test', { method: 'POST', body: formData });
      
      const fetchCall = window.fetch.mock.calls[0];
      const options = fetchCall[1];
      
      expect(options.headers['Content-Type']).toBeUndefined();
      expect(options.body).toBe(formData);
    });
  });

  describe('Response Handling', () => {
    it('should handle JSON responses', async () => {
      const responseData = { success: true, data: 'test' };
      window.fetch.mockResolvedValue({
        ok: true,
        status: 200,
        headers: { get: () => 'application/json' },
        json: () => Promise.resolve(responseData),
      });

      const result = await window.opFetch('/api/test');
      
      expect(result.ok).toBe(true);
      expect(result.status).toBe(200);
      expect(result.data).toEqual(responseData);
      expect(result.error).toBeNull();
    });

    it('should handle text responses', async () => {
      const responseText = '<div>HTML content</div>';
      window.fetch.mockResolvedValue({
        ok: true,
        status: 200,
        headers: { get: () => 'text/html' },
        text: () => Promise.resolve(responseText),
      });

      const result = await window.opFetch('/api/test');
      
      expect(result.ok).toBe(true);
      expect(result.status).toBe(200);
      expect(result.data).toBe(responseText);
    });

    it('should handle error responses', async () => {
      const errorData = { message: 'Not found' };
      window.fetch.mockResolvedValue({
        ok: false,
        status: 404,
        headers: { get: () => 'application/json' },
        json: () => Promise.resolve(errorData),
      });

      const result = await window.opFetch('/api/test');
      
      expect(result.ok).toBe(false);
      expect(result.status).toBe(404);
      expect(result.data).toEqual(errorData);
      expect(result.error).toBe('Not found');
    });

    it('should handle network errors', async () => {
      window.fetch.mockRejectedValue(new Error('Network failure'));

      const result = await window.opFetch('/api/test');
      
      expect(result.ok).toBe(false);
      expect(result.status).toBe(0);
      expect(result.data).toBeNull();
      expect(result.error).toBe('Network failure');
    });
  });

  describe('Helper Functions', () => {
    it('opPost should call opFetch with POST method', async () => {
      window.fetch.mockResolvedValue({
        ok: true,
        status: 200,
        headers: { get: () => 'application/json' },
        json: () => Promise.resolve({}),
      });

      const body = { key: 'value' };
      await window.opPost('/api/test', body);
      
      const fetchCall = window.fetch.mock.calls[0];
      const options = fetchCall[1];
      
      expect(options.method).toBe('POST');
      expect(options.body).toBe(JSON.stringify(body));
    });

    it('opDelete should call opFetch with DELETE method', async () => {
      window.fetch.mockResolvedValue({
        ok: true,
        status: 200,
        headers: { get: () => 'application/json' },
        json: () => Promise.resolve({}),
      });

      await window.opDelete('/api/test/1');
      
      const fetchCall = window.fetch.mock.calls[0];
      const options = fetchCall[1];
      
      expect(options.method).toBe('DELETE');
    });

    it('opLoadFragment should load HTML into container', async () => {
      const container = document.createElement('div');
      container.id = 'test-container';
      document.body.appendChild(container);

      window.fetch.mockResolvedValue({
        ok: true,
        status: 200,
        headers: { get: () => 'text/html' },
        text: () => Promise.resolve('<p>Loaded content</p>'),
      });

      await window.opLoadFragment('/api/fragment', 'test-container');
      
      expect(container.innerHTML).toBe('<p>Loaded content</p>');
    });

    it('opLoadFragment should show error on failure', async () => {
      const container = document.createElement('div');
      container.id = 'test-container';
      document.body.appendChild(container);

      window.fetch.mockResolvedValue({
        ok: false,
        status: 500,
        headers: { get: () => 'application/json' },
        json: () => Promise.resolve({ error: 'Server error' }),
      });

      await window.opLoadFragment('/api/fragment', 'test-container');
      
      expect(container.innerHTML).toContain('op-alert-danger');
      expect(container.innerHTML).toContain('Server error');
    });

    it('opLoadFragment should handle missing container', async () => {
      // Should not throw error when container doesn't exist
      await window.opLoadFragment('/api/fragment', 'non-existent-container');
      
      expect(window.fetch).not.toHaveBeenCalled();
    });
  });
});
