/**
 * Regression test for the checkout-status "Download Receipt" button: it used to be
 * `<a href="javascript:window.print()">`, which the checkout CSP's `script-src` (no
 * 'unsafe-inline', nonces don't cover javascript: hrefs) silently blocks - confirmed empirically
 * in a real browser (window.print() never fired). Fixed by wiring it through the same
 * CSP-safe `data-action` click-delegation pattern checkout-status.js already uses elsewhere.
 */
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { JSDOM, VirtualConsole } from 'jsdom';
import fs from 'fs';
import path from 'path';

const statusJsPath = path.join(__dirname, '../../public/assets/js/checkout-status.js');
const statusJsCode = fs.readFileSync(statusJsPath, 'utf-8');

describe('checkout-status.js Download Receipt button', () => {
  let dom;
  let window;
  let document;

  beforeEach(() => {
    const html = `
      <!DOCTYPE html>
      <html>
      <body>
        <div class="st-actions">
          <a href="#" data-action="print-receipt" class="st-btn">
            <span class="st-btn-label">Download<br>Receipt</span>
          </a>
          <a href="/" class="st-btn">
            <span class="st-btn-label">Return to<br>Merchant</span>
          </a>
        </div>
      </body>
      </html>
    `;
    dom = new JSDOM(html, { url: 'http://localhost', runScripts: 'dangerously' });
    window = dom.window;
    document = window.document;
    window.print = vi.fn();
    const runScript = window['eval'];
    runScript.call(window, statusJsCode);
  });

  afterEach(() => {
    dom.window.close();
  });

  it('clicking the Download Receipt button calls window.print(), not a blocked javascript: href', () => {
    const btn = document.querySelector('[data-action="print-receipt"]');
    expect(btn).not.toBeNull();
    expect(btn.getAttribute('href')).not.toMatch(/^javascript:/);

    btn.dispatchEvent(new window.MouseEvent('click', { bubbles: true, cancelable: true }));

    expect(window.print).toHaveBeenCalledTimes(1);
  });

  it('clicking Download Receipt does not navigate the page (default is prevented)', () => {
    const btn = document.querySelector('[data-action="print-receipt"]');
    const event = new window.MouseEvent('click', { bubbles: true, cancelable: true });
    btn.dispatchEvent(event);

    expect(event.defaultPrevented).toBe(true);
  });
});

/**
 * Regression test for the pending/processing status page's "Refresh Status" button: same
 * javascript:-href CSP block as Download Receipt above (`<a href="javascript:location.reload()">`),
 * fixed via the identical data-action click-delegation pattern.
 */
describe('checkout-status.js Refresh Status button', () => {
  let dom;
  let window;
  let document;
  let jsdomErrors;

  beforeEach(() => {
    const html = `
      <!DOCTYPE html>
      <html>
      <body>
        <div class="st-actions">
          <a href="#" data-action="refresh-status" class="st-btn">
            <span class="st-btn-label">Refresh<br>Status</span>
          </a>
        </div>
      </body>
      </html>
    `;
    // jsdom's window.location.reload is a real, non-configurable, non-mockable implementation
    // (calling it emits a "Not implemented: navigation" jsdomError rather than throwing) - a
    // VirtualConsole listener is the only reliable way to observe that it was actually invoked.
    jsdomErrors = [];
    const virtualConsole = new VirtualConsole();
    virtualConsole.on('jsdomError', (e) => jsdomErrors.push(e.message));

    dom = new JSDOM(html, { url: 'http://localhost', runScripts: 'dangerously', virtualConsole });
    window = dom.window;
    document = window.document;
    const runScript = window['eval'];
    runScript.call(window, statusJsCode);
  });

  afterEach(() => {
    dom.window.close();
  });

  it('clicking Refresh Status reloads the page, not a blocked javascript: href', () => {
    const btn = document.querySelector('[data-action="refresh-status"]');
    expect(btn).not.toBeNull();
    expect(btn.getAttribute('href')).not.toMatch(/^javascript:/);
    expect(jsdomErrors).toHaveLength(0);

    btn.dispatchEvent(new window.MouseEvent('click', { bubbles: true, cancelable: true }));

    expect(jsdomErrors).toHaveLength(1);
    expect(jsdomErrors[0]).toMatch(/navigation/i);
  });

  it('clicking Refresh Status does not navigate via the anchor itself (default is prevented)', () => {
    const btn = document.querySelector('[data-action="refresh-status"]');
    const event = new window.MouseEvent('click', { bubbles: true, cancelable: true });
    btn.dispatchEvent(event);

    expect(event.defaultPrevented).toBe(true);
  });
});
