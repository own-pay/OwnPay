/**
 * Tests for developer.js - Copy Key button behavior
 */
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { JSDOM } from 'jsdom';
import fs from 'fs';
import path from 'path';

const jsPath = path.join(__dirname, '../../public/assets/js/pages/developer.js');
const jsCode = fs.readFileSync(jsPath, 'utf-8');

describe('developer.js', () => {
  let dom, window, document;

  beforeEach(() => {
    const html = `
      <!DOCTYPE html>
      <html><body>
        <div id="dev-tabs"></div>
        <input type="text" id="generated-key-input" value="op_test_key_12345" readonly>
        <button type="button" id="copy-key-btn">
          <span>Copy Key</span>
        </button>
        <div id="copy-feedback" class="op-feedback-text"></div>
      </body></html>
    `;
    dom = new JSDOM(html, { url: 'http://localhost/admin/developer', runScripts: 'dangerously', resources: 'usable' });
    window = dom.window;
    document = window.document;
    window.OP_CSRF = 'test-csrf-token';
    window.opCopyText = vi.fn(function (text, button, successCallback) {
      if (typeof successCallback === 'function') { successCallback(); }
    });
    // jsdom does not implement the Clipboard API by default; stub it so the
    // "does not call navigator.clipboard directly" assertion below has a
    // real object to spy on (developer.js must never touch this stub).
    if (!window.navigator.clipboard) {
      Object.defineProperty(window.navigator, 'clipboard', {
        value: { writeText: vi.fn(() => Promise.resolve()) },
        configurable: true
      });
    }
    // NOTE: repo-wide jsdom test convention (see admin.test.js, checkout.test.js,
    // setup-wizard.test.js) for executing an app's IIFE page script inside the
    // jsdom window context. Source is our own trusted first-party file.
    window.eval(jsCode);
  });

  afterEach(() => {
    dom.window.close();
  });

  it('Copy Key button calls the shared window.opCopyText helper with the generated key value', () => {
    document.getElementById('copy-key-btn').click();

    expect(window.opCopyText).toHaveBeenCalledWith(
      'op_test_key_12345',
      document.getElementById('copy-key-btn'),
      expect.any(Function)
    );
  });

  it('on success, updates the button HTML and shows the copy-feedback text', () => {
    document.getElementById('copy-key-btn').click();

    expect(document.getElementById('copy-key-btn').innerHTML).toContain('Copied!');
    expect(document.getElementById('copy-key-btn').classList.contains('op-btn-success')).toBe(true);
    expect(document.getElementById('copy-feedback').textContent).toBe('✓ Key copied to clipboard');
    expect(document.getElementById('copy-feedback').style.opacity).toBe('1');
  });

  it('does not call navigator.clipboard directly (must go through the shared helper)', () => {
    const clipboardSpy = vi.spyOn(window.navigator.clipboard, 'writeText');
    document.getElementById('copy-key-btn').click();
    expect(clipboardSpy).not.toHaveBeenCalled();
  });
});

describe('developer.js sub-nav (Endpoint Reference / Authentication)', () => {
  let dom, window, document;

  beforeEach(() => {
    const html = `
      <!DOCTYPE html>
      <html><body>
        <div id="dev-tabs">
          <button class="op-tab active" data-tab="api-keys">API Keys</button>
        </div>
        <div class="op-tab-panel active" id="tab-api-keys">
          <div class="op-subnav" id="ref-subnav">
            <button type="button" class="op-subnav-item active" data-subnav="endpoints">Endpoint Reference</button>
            <button type="button" class="op-subnav-item" data-subnav="auth">Authentication</button>
          </div>
          <div class="op-subnav-panel active" id="subnav-endpoints">Endpoints content</div>
          <div class="op-subnav-panel" id="subnav-auth">Auth content</div>
        </div>
      </body></html>
    `;
    dom = new JSDOM(html, { url: 'http://localhost/admin/developer', runScripts: 'dangerously', resources: 'usable' });
    window = dom.window;
    document = window.document;
    window.OP_CSRF = 'test-csrf-token';
    var runScript = window['eval'];
    runScript.call(window, jsCode);
  });

  afterEach(() => {
    dom.window.close();
  });

  it('clicking the Authentication sub-nav item shows the auth panel and hides endpoints', () => {
    document.querySelector('.op-subnav-item[data-subnav="auth"]').click();

    expect(document.getElementById('subnav-auth').classList.contains('active')).toBe(true);
    expect(document.getElementById('subnav-endpoints').classList.contains('active')).toBe(false);
    expect(document.querySelector('.op-subnav-item[data-subnav="auth"]').classList.contains('active')).toBe(true);
    expect(document.querySelector('.op-subnav-item[data-subnav="endpoints"]').classList.contains('active')).toBe(false);
  });

  it('clicking sub-nav items does not touch the outer #dev-tabs tab state', () => {
    document.querySelector('.op-subnav-item[data-subnav="auth"]').click();

    expect(document.querySelector('#dev-tabs .op-tab[data-tab="api-keys"]').classList.contains('active')).toBe(true);
    expect(document.getElementById('tab-api-keys').classList.contains('active')).toBe(true);
  });
});
