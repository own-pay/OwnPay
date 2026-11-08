/**
 * Tests for installer/admin CSS rules introduced for mobile overflow,
 * dark-mode readability, and OTP font sizes.
 */
import { describe, it, expect } from 'vitest';
import fs from 'fs';
import path from 'path';

const adminCss = fs.readFileSync(path.join(__dirname, '../../public/assets/css/admin.css'), 'utf-8');
const installerCss = fs.readFileSync(path.join(__dirname, '../../public/assets/css/installer.css'), 'utf-8');
const wizardCss = fs.readFileSync(path.join(__dirname, '../../public/assets/css/pages/setup-wizard.css'), 'utf-8');

describe('admin layout mobile overflow', () => {
  it('uses 100% max-width for .op-main in the mobile layout', () => {
    expect(adminCss).toContain('.op-main {\n    margin-left: 0;\n    max-width: 100%;');
  });
});

describe('dark-mode toggle and select option readability', () => {
  it('defines dark-mode backgrounds/borders for toggle controls', () => {
    expect(adminCss).toContain('[data-theme="dark"] .op-toggle-switch,');
    expect(adminCss).toContain('[data-theme="dark"] .op-toggle-slider {');
    expect(adminCss).toContain('background: #3f3f46;');
    expect(adminCss).toContain('border: 1px solid #52525b;');
    expect(adminCss).toContain('background: var(--op-primary);');
  });

  it('defines dark-mode background and text for installer select options', () => {
    expect(installerCss).toContain('[data-theme="dark"] .ins-field select option {');
    expect(installerCss).toContain('background-color: var(--op-bg-card);');
    expect(installerCss).toContain('color: var(--op-text);');
  });
});

describe('OTP font sizes', () => {
  it('uses 2rem at desktop and 1.5rem at mobile breakpoint', () => {
    expect(wizardCss).toContain('.op-otp-display-span {\n    font-size: 2rem;');
    expect(wizardCss).toContain('.op-otp-display-span {\n        font-size: 1.5rem;');
  });
});

describe('placeholder contrast', () => {
  it('reduces placeholder opacity for .op-input', () => {
    expect(adminCss).toContain('.op-input::placeholder {\n  color: var(--op-text-muted);\n  opacity: 0.6;\n}');
  });
});
