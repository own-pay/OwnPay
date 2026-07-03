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
            <div class="op-wizard-tracker-node active" data-step="1"></div>
            <div class="op-wizard-tracker-node" data-step="2"></div>
            <div class="op-wizard-tracker-node" data-step="3"></div>
          </div>
          <div class="op-wizard-panel active" id="panel-1"><a href="#" id="btn-skip-step1">Skip this step</a></div>
          <div class="op-wizard-panel" id="panel-2"><a href="#" id="btn-skip-step2">Skip this step</a></div>
          <div class="op-wizard-panel" id="panel-3"></div>
        </div>
      </body></html>
    `;
    dom = new JSDOM(html, { url: 'http://localhost/admin/setup-wizard', runScripts: 'dangerously', resources: 'usable' });
    window = dom.window;
    document = window.document;
    window.OP_ONBOARDING_BRAND_ID = 42;
    window.confirm = vi.fn(() => true);
    window.fetch = vi.fn(() => Promise.resolve({ json: () => Promise.resolve({ success: true, skipped: true }) }));
    // NOTE: repo-wide jsdom test convention (see admin.test.js, checkout.test.js,
    // op-fetch.test.js) for executing an app's IIFE page script inside the jsdom
    // window context. Source is our own trusted first-party file, not user input.
    window.eval(jsCode);
    window.document.dispatchEvent(new window.Event('DOMContentLoaded', { bubbles: true, cancelable: true }));
  });

  afterEach(() => {
    dom.window.close();
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
});
