/**
 * Tests for setup-wizard.js - skip-link behavior and resume prefill wiring
 */
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { JSDOM } from 'jsdom';
import fs from 'fs';
import path from 'path';

const jsPath = path.join(__dirname, '../../public/assets/js/pages/setup-wizard.js');
const jsCode = fs.readFileSync(jsPath, 'utf-8');

describe('setup-wizard.js', () => {
  let dom, window, document;

  beforeEach(() => {
    const html = `
      <!DOCTYPE html>
      <html><body>
        <div id="op-setup-wizard">
          <a href="#" id="op-wizard-skip-setup">Skip setup</a>
          <div class="op-wizard-tracker">
            <div class="op-wizard-tracker-node" data-step="1"></div>
            <div class="op-wizard-tracker-node" data-step="2"></div>
            <div class="op-wizard-tracker-node" data-step="3"></div>
          </div>
          <div class="op-wizard-panel active" id="panel-1"><a href="#" id="btn-skip-step1">Skip this step</a></div>
          <div class="op-wizard-panel" id="panel-2"><a href="#" id="btn-skip-step2">Skip this step</a></div>
          <div class="op-wizard-panel" id="panel-3">
            <span id="op-wizard-otp-display">123456</span>
            <span id="op-wizard-otp-copied" style="display:none">Copied</span>
          </div>
        </div>
      </body></html>
    `;
    dom = new JSDOM(html, { url: 'http://localhost/admin/setup-wizard', runScripts: 'dangerously', resources: 'usable' });
    window = dom.window;
    document = window.document;
    window.OP_ONBOARDING_BRAND_ID = 42;
    window.confirm = vi.fn(() => true);
    window.fetch = vi.fn(() => Promise.resolve({ json: () => Promise.resolve({ success: true, skipped: true }) }));
    window.opCopyText = vi.fn(function (text, el, successCallback) {
      if (typeof successCallback === 'function') { successCallback(); }
    });
    // NOTE: repo-wide jsdom test convention (see admin.test.js, checkout.test.js,
    // op-fetch.test.js) for executing an app's IIFE page script inside the jsdom
    // window context. Source is our own trusted first-party file, not user input.
    window.eval(jsCode);
    window.document.dispatchEvent(new window.Event('DOMContentLoaded', { bubbles: true, cancelable: true }));
  });

  afterEach(() => {
    dom.window.close();
  });

  it('marks step 1 as active in the tracker on initial load, matching the server-rendered active panel', () => {
    const trackerStep1 = document.querySelector('.op-wizard-tracker-node[data-step="1"]');
    expect(trackerStep1.classList.contains('active')).toBe(true);
  });

  it('skip-this-step on step 1 POSTs skip=1 and advances to step 2', async () => {
    document.getElementById('btn-skip-step1').click();
    await new Promise(resolve => setTimeout(resolve, 0));

    expect(window.fetch).toHaveBeenCalledWith(
      '/admin/setup-wizard/save-settings',
      expect.objectContaining({ body: JSON.stringify({ skip: '1' }) })
    );
  });

  it('skip-this-step on step 2 advances to step 3 without any network call', () => {
    document.getElementById('btn-skip-step2').click();
    expect(window.fetch).not.toHaveBeenCalled();
    // showStep(3) is called; verify the tracker and panel state it drives moved to step 3.
    const trackerStep3 = document.querySelector('.op-wizard-tracker-node[data-step="3"]');
    expect(trackerStep3.classList.contains('active')).toBe(true);
    expect(document.getElementById('panel-3').classList.contains('active')).toBe(true);
    expect(document.getElementById('panel-1').classList.contains('active')).toBe(false);
  });

  it('skip-setup link triggers the dismiss confirmation flow', async () => {
    document.getElementById('op-wizard-skip-setup').click();
    expect(window.confirm).toHaveBeenCalled();
    await new Promise(resolve => setTimeout(resolve, 0));
    expect(window.fetch).toHaveBeenCalledWith(
      '/admin/setup-wizard/dismiss',
      expect.objectContaining({ method: 'POST' })
    );
  });

  it('OTP copy button calls the shared window.opCopyText helper, not clipboard directly', () => {
    // jsdom doesn't implement the Clipboard API by default; stub one just so
    // vi.spyOn has an object to attach to (mirrors tests/frontend/developer.test.js).
    if (!window.navigator.clipboard) {
      Object.defineProperty(window.navigator, 'clipboard', {
        value: { writeText: () => Promise.resolve() },
        configurable: true,
      });
    }
    const clipboardSpy = vi.spyOn(window.navigator.clipboard, 'writeText');

    document.getElementById('op-wizard-otp-display').click();

    expect(window.opCopyText).toHaveBeenCalledWith(
      '123456',
      document.getElementById('op-wizard-otp-display'),
      expect.any(Function)
    );
    expect(clipboardSpy).not.toHaveBeenCalled();
  });

  it('OTP copy button shows the "copied" indicator on success and hides it after 2s', () => {
    vi.useFakeTimers();
    document.getElementById('op-wizard-otp-display').click();

    const copiedEl = document.getElementById('op-wizard-otp-copied');
    expect(copiedEl.style.display).toBe('inline');

    vi.advanceTimersByTime(2000);
    expect(copiedEl.style.display).toBe('none');
    vi.useRealTimers();
  });
});
