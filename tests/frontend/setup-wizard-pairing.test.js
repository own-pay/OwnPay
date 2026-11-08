/**
 * Tests for setup-wizard.js pairing panel behaviors:
 * - expired OTP renewal only when pairing panel is still active
 * - loading a new pairing request aborts the previous request and ignores abort errors
 * - gateway submission brand-id guard and preservation of a returned brand ID
 */
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { JSDOM } from 'jsdom';
import fs from 'fs';
import path from 'path';

const jsPath = path.join(__dirname, '../../public/assets/js/pages/setup-wizard.js');
const jsCode = fs.readFileSync(jsPath, 'utf-8');

function baseHtml() {
  return `
    <!DOCTYPE html>
    <html><body>
      <div id="op-setup-wizard">
        <div class="op-wizard-tracker">
          <div class="op-wizard-tracker-node" data-step="1"></div>
          <div class="op-wizard-tracker-node" data-step="2"></div>
          <div class="op-wizard-tracker-node" data-step="3"></div>
          <div class="op-wizard-tracker-node" data-step="4"></div>
          <div class="op-wizard-tracker-node" data-step="5"></div>
        </div>
        <div class="op-wizard-panel" id="panel-1"></div>
        <div class="op-wizard-panel" id="panel-2"></div>
        <div class="op-wizard-panel" id="panel-3"></div>
        <div class="op-wizard-panel" id="panel-4">
          <div class="op-choice-card" data-gateway="manual">Manual</div>
          <input id="manual_name" value="Bank Transfer">
          <input id="manual_details" value="Pay to account">
          <button id="btn-save-gateway">Save</button>
        </div>
        <div class="op-wizard-panel" id="panel-5">
          <img id="op-wizard-qr-img" style="display:none">
          <div id="op-wizard-qr-placeholder" style="display:block">QR</div>
          <span id="op-wizard-otp-display">------</span>
          <div id="op-wizard-otp-wrapper" style="display:none"></div>
          <span id="op-wizard-otp-timer"></span>
        </div>
        <div id="wizard-error" style="display:none"><span id="wizard-error-text"></span></div>
        <div id="wizard-success" style="display:none"><span id="wizard-success-text"></span></div>
        <button data-prev-step="5">go pairing</button>
        <button data-prev-step="1">go step1</button>
      </div>
    </body></html>
  `;
}

async function setupEnv(brandId) {
  const dom = new JSDOM(baseHtml(), { url: 'http://localhost/admin/setup-wizard', runScripts: 'dangerously', resources: 'usable' });
  const window = dom.window;
  const document = window.document;
  window.OP_ONBOARDING_BRAND_ID = brandId;
  window.OP_CSRF = 'test-csrf';
  window.confirm = vi.fn(() => true);
  window.fetch = vi.fn(() => Promise.resolve({ json: () => Promise.resolve({ success: true }) }));
  window.opCopyText = vi.fn();
  // let any auto-fired DOMContentLoaded complete before eval
  await new Promise(resolve => setTimeout(resolve, 0));
  window.eval(jsCode);
  window.document.dispatchEvent(new window.Event('DOMContentLoaded', { bubbles: true, cancelable: true }));
  await new Promise(resolve => setTimeout(resolve, 0));
  return { dom, window, document };
}

describe('setup-wizard pairing panel', () => {
  let dom, window, document;

  beforeEach(async () => {
    const env = await setupEnv(7);
    dom = env.dom; window = env.window; document = env.document;
  });

  afterEach(() => {
    dom.window.close();
    vi.useRealTimers();
  });

  it('expired OTP renews when pairing panel is still active', async () => {
    vi.useFakeTimers();
    window.fetch = vi.fn(() => Promise.resolve({
      json: () => Promise.resolve({ success: true, otp: '123456', expires_in: 1, qr_svg: '' }),
    }));

    document.querySelector('[data-prev-step="5"]').click();
    await vi.advanceTimersByTimeAsync(0);
    expect(document.getElementById('panel-5').classList.contains('active')).toBe(true);

    await vi.advanceTimersByTimeAsync(1000);
    await vi.advanceTimersByTimeAsync(1500);

    expect(window.fetch).toHaveBeenCalledTimes(2);
  });

  it('expired OTP does not renew when pairing panel is inactive', async () => {
    vi.useFakeTimers();
    window.fetch = vi.fn(() => Promise.resolve({
      json: () => Promise.resolve({ success: true, otp: '123456', expires_in: 1, qr_svg: '' }),
    }));

    document.querySelector('[data-prev-step="5"]').click();
    await vi.advanceTimersByTimeAsync(0);

    document.getElementById('panel-5').classList.remove('active');

    await vi.advanceTimersByTimeAsync(1000);
    await vi.advanceTimersByTimeAsync(1500);

    expect(window.fetch).toHaveBeenCalledTimes(1);
  });

  it('loading a new pairing request aborts the previous request', async () => {
    let firstSignal = null;
    window.fetch = vi.fn()
      .mockImplementationOnce((url, opts) => {
        firstSignal = opts && opts.signal;
        return new Promise((resolve, reject) => {
          if (opts && opts.signal) {
            opts.signal.addEventListener('abort', () => reject(Object.assign(new Error('aborted'), { name: 'AbortError' })));
          }
        });
      })
      .mockResolvedValueOnce({ json: () => Promise.resolve({ success: true, otp: '999999', expires_in: 300, qr_svg: '' }) });

    document.querySelector('[data-prev-step="5"]').click();
    await new Promise(resolve => setTimeout(resolve, 0));

    document.querySelector('[data-prev-step="5"]').click();
    await new Promise(resolve => setTimeout(resolve, 0));

    expect(window.fetch).toHaveBeenCalledTimes(2);
    expect(firstSignal).not.toBeNull();
    expect(firstSignal.aborted).toBe(true);
  });

  it('ignores AbortError in catch handler', async () => {
    window.fetch = vi.fn(() => Promise.reject(Object.assign(new Error('aborted'), { name: 'AbortError' })));

    document.querySelector('[data-prev-step="5"]').click();
    await new Promise(resolve => setTimeout(resolve, 0));

    const errEl = document.getElementById('wizard-error');
    expect(errEl.style.display).toBe('none');
  });
});

describe('setup-wizard gateway brand id', () => {
  it('rejects missing brand id', async () => {
    const { dom, window, document } = await setupEnv(0);
    window.fetch = vi.fn(() => Promise.resolve({ json: () => Promise.resolve({ success: true }) }));
    document.querySelector('.op-choice-card[data-gateway="manual"]').click();
    document.getElementById('btn-save-gateway').click();
    await new Promise(resolve => setTimeout(resolve, 0));
    expect(window.fetch).not.toHaveBeenCalled();
    expect(document.getElementById('wizard-error-text').textContent).toContain('Brand not created yet');
    dom.window.close();
  });

  it('rejects non-positive brand id', async () => {
    const { dom, window, document } = await setupEnv(-5);
    window.fetch = vi.fn(() => Promise.resolve({ json: () => Promise.resolve({ success: true }) }));
    document.querySelector('.op-choice-card[data-gateway="manual"]').click();
    document.getElementById('btn-save-gateway').click();
    await new Promise(resolve => setTimeout(resolve, 0));
    expect(window.fetch).not.toHaveBeenCalled();
    expect(document.getElementById('wizard-error-text').textContent).toContain('Brand not created yet');
    dom.window.close();
  });

  it('preserves a returned brand id on subsequent submission', async () => {
    const { dom, window, document } = await setupEnv(7);
    window.fetch = vi.fn()
      .mockResolvedValueOnce({ json: () => Promise.resolve({ success: true, brand_id: 99 }) })
      .mockResolvedValueOnce({ json: () => Promise.resolve({ success: true }) });

    document.querySelector('.op-choice-card[data-gateway="manual"]').click();
    document.getElementById('btn-save-gateway').click();
    await new Promise(resolve => setTimeout(resolve, 0));

    document.getElementById('btn-save-gateway').click();
    await new Promise(resolve => setTimeout(resolve, 0));

    const gatewayCalls = window.fetch.mock.calls.filter(c => String(c[0]).includes('setup-gateway'));
    const secondCall = gatewayCalls[1];
    const body = JSON.parse(secondCall[1].body);
    expect(body.brand_id).toBe(99);
    dom.window.close();
  });
});
